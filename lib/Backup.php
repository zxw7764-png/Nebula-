<?php
/**
 * 数据备份
 * ------------------------------------------------------------------
 * 纯 PHP 导出（不依赖 mysqldump / exec，Serv00 等共享主机常常禁掉这些），
 * 产物是 gzip 压缩的 SQL 文件，存到 data/backups/（部署模板已整目录 deny，
 * 外部无法直接下载），文件名带时间戳：nb_YYYYmmdd_HHMMSS.sql.gz。
 *
 * 自动化：由 cron.php 每次执行时调用 auto()，内部按「备份模式 + 间隔」节流
 * —— cron 每分钟跑也没关系，只有到点才会真正备份。
 *
 * 备份模式（后台「系统设置 → 维护 → 数据备份」可改，存 settings 表）：
 *   backup_mode            auto    off=不备份 / auto=自动备份 / manual=手动备份
 *   backup_interval_hours  24      自动备份间隔（小时）
 *   backup_keep            7       保留最近 N 份，超出的自动删除
 *
 * 相关配置（config/config.php，均可省略，用下列默认值；仅作为出厂默认，
 * 后台改过之后以数据库为准）：
 *   backup.enable         true    总开关（仅用于推导默认模式）
 *   backup.interval_hours 24      备份间隔（小时）
 *   backup.keep           7       保留最近 N 份，超出的自动删除
 *   backup.skip_tables    []      不备份的表（只写表名，不含前缀），如 ['logs']
 *   backup.chunk          200     每条 INSERT 拼多少行（越大越快、越省行数）
 */
class Backup
{
    /** 备份目录（不存在则创建） */
    public static function dir(): string
    {
        $root = defined('NB_ROOT') ? NB_ROOT : dirname(__DIR__);
        $d = $root . '/data/backups';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    /**
     * 备份模式（后台「系统设置 → 维护」可改，存数据库 settings 表）
     *   off    不备份：cron 不自动备份，后台也禁止手动备份
     *   auto   自动备份：cron 按 interval_hours 周期备份，也可手动备份
     *   manual 手动备份：cron 不自动备份，只在后台点「立即备份」时生成
     *
     * 未配置过时回退到 config 的 backup.enable（true=自动 / false=不备份），
     * 保证老部署升级后行为不变。
     */
    public static function mode(): string
    {
        $v = Setting::get('backup_mode', '');
        if ($v === null || $v === '') {
            return (bool) Config::get('backup.enable', true) ? 'auto' : 'off';
        }
        $v = (string) $v;
        return in_array($v, ['off', 'auto', 'manual'], true) ? $v : 'auto';
    }

    /** 模式的中文名 */
    public static function modeText(): string
    {
        return ['off' => '不备份', 'auto' => '自动备份', 'manual' => '手动备份'][self::mode()];
    }

    /** 自动备份是否开启（「自动备份」模式） */
    public static function enabled(): bool
    {
        return self::mode() === 'auto';
    }

    /** 是否允许执行备份（「不备份」模式下彻底禁止） */
    public static function canRun(): bool
    {
        return self::mode() !== 'off';
    }

    /**
     * 读取一个「后台可改、config 里有出厂值」的整数设置
     * 注意：不能直接用 Setting::intWithConfig()——config.php 里通常并没有写
     * backup 段（出厂值靠代码默认参数给），那样回退会拿到 0。
     */
    private static function settingInt(string $key, string $configKey, int $default): int
    {
        if (Setting::isSet($key)) {
            return (int) Setting::get($key, (string) $default);
        }
        return (int) Config::get($configKey, $default);
    }

    /** 备份间隔（小时） */
    public static function intervalHours(): int
    {
        return max(1, self::settingInt('backup_interval_hours', 'backup.interval_hours', 24));
    }

    /** 备份间隔（秒） */
    public static function intervalSeconds(): int
    {
        return self::intervalHours() * 3600;
    }

    /** 保留份数 */
    public static function keep(): int
    {
        return max(1, self::settingInt('backup_keep', 'backup.keep', 7));
    }

    /**
     * 自动备份入口（给 cron 调用）
     * 未启用 / 未到周期时直接返回 ran=false，不产生任何文件与日志噪音。
     *
     * @return array{ok:bool, ran:bool, msg:string, file?:string, bytes?:int, rows?:int, tables?:int, removed?:int}
     */
    public static function auto(): array
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return ['ok' => true, 'ran' => false, 'msg' => '备份模式为「不备份」，cron 跳过'];
        }
        if ($mode === 'manual') {
            return ['ok' => true, 'ran' => false, 'msg' => '备份模式为「手动备份」，cron 不自动备份'];
        }
        $last = self::lastRunAt();
        if ($last > 0) {
            $next = $last + self::intervalSeconds();
            if (time() < $next) {
                return [
                    'ok'  => true,
                    'ran' => false,
                    'msg' => '未到备份周期（上次 ' . date('Y-m-d H:i', $last)
                           . '，下次 ' . date('Y-m-d H:i', $next) . '）',
                ];
            }
        }
        return self::run();
    }

    /**
     * 立即执行一次备份
     *
     * @return array{ok:bool, ran:bool, msg:string, file?:string, bytes?:int, rows?:int, tables?:int, removed?:int}
     */
    public static function run(): array
    {
        if (self::mode() === 'off') {
            return ['ok' => false, 'ran' => false, 'msg' => '备份模式为「不备份」，已禁止执行备份'];
        }
        $dir = self::dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'ran' => false, 'msg' => '备份目录不可写：' . $dir];
        }

        if (!function_exists('gzopen')) {
            return ['ok' => false, 'ran' => false, 'msg' => 'PHP 未启用 zlib 扩展，无法生成压缩备份'];
        }

        $name = 'nb_' . date('Ymd_His') . '.sql.gz';
        $path = $dir . '/' . $name;

        $fh = @gzopen($path, 'wb6');
        if ($fh === false) {
            return ['ok' => false, 'ran' => false, 'msg' => '无法创建备份文件：' . $name];
        }

        $start  = microtime(true);
        $pdo    = Database::pdo();
        $skip   = array_map('strval', (array) Config::get('backup.skip_tables', []));
        $chunk  = max(1, (int) Config::get('backup.chunk', 200));
        $tables = self::tables();
        $rows   = 0;
        $done   = 0;

        self::w($fh, "-- Nebula 数据备份\n");
        self::w($fh, '-- 生成时间: ' . date('Y-m-d H:i:s') . "\n");
        self::w($fh, '-- 程序版本: ' . (defined('NB_VERSION') ? NB_VERSION : '-') . "\n");
        self::w($fh, '-- 提示: 导入前请先建好库并确认字符集为 utf8mb4' . "\n\n");
        self::w($fh, "SET NAMES utf8mb4;\n");
        self::w($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $t) {
            if (in_array($t, $skip, true)) {
                continue;
            }
            $quoted = '`' . str_replace('`', '``', $t) . '`';

            // 结构
            try {
                $create = Database::one('SHOW CREATE TABLE ' . $quoted);
            } catch (Throwable $e) {
                self::w($fh, "-- 跳过 {$t}：读取结构失败\n\n");
                continue;
            }
            if (!$create) {
                continue;
            }
            $ddl = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
            self::w($fh, "DROP TABLE IF EXISTS {$quoted};\n");
            self::w($fh, $ddl . ";\n\n");

            // 数据（逐行游标，避免一次性把大表读进内存）
            try {
                $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            } catch (Throwable $e) {
                self::w($fh, "-- 跳过 {$t} 数据：查询失败\n\n");
                continue;
            }

            $buf = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $buf[] = self::rowSql($pdo, $row);
                $rows++;
                if (count($buf) >= $chunk) {
                    self::w($fh, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $buf) . ";\n");
                    $buf = [];
                }
            }
            if ($buf) {
                self::w($fh, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $buf) . ";\n");
            }
            self::w($fh, "\n");
            $done++;
        }

        self::w($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($fh);

        $bytes   = (int) @filesize($path);
        $keep    = self::keep();
        $removed = self::prune($keep);
        $cost    = round(microtime(true) - $start, 2);

        return [
            'ok'     => true,
            'ran'    => true,
            'file'   => $name,
            'bytes'  => $bytes,
            'rows'   => $rows,
            'tables' => $done,
            'removed' => $removed,
            'msg'    => "备份完成 {$name}（{$done} 表 / {$rows} 行 / " . self::human($bytes)
                      . "，清理旧备份 {$removed} 份，耗时 {$cost}s）",
        ];
    }

    /** 列出全部备份，按时间倒序 */
    public static function listFiles(): array
    {
        $dir  = self::dir();
        $out  = [];
        // 目录不可用（未创建成功 / 被清理）时返回空列表，避免 glob 抛错把整个接口带崩
        if (!is_dir($dir) || !is_readable($dir)) {
            return $out;
        }
        foreach ((array) glob($dir . '/nb_*.sql.gz') as $f) {
            $t = (int) @filemtime($f);
            $b = (int) @filesize($f);
            $out[] = [
                'name'      => basename($f),
                'path'      => $f,
                'bytes'     => $b,
                'size_text' => self::human($b),
                'time'      => $t,
                'time_text' => $t > 0 ? date('Y-m-d H:i:s', $t) : '-',
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        return $out;
    }

    /** 最近一次备份时间戳（无备份返回 0） */
    public static function lastRunAt(): int
    {
        $files = self::listFiles();
        return $files ? (int) $files[0]['time'] : 0;
    }

    /** 只保留最新 $keep 份，返回删除数量 */
    public static function prune(int $keep): int
    {
        $keep = max(1, $keep);
        $n    = 0;
        foreach (array_slice(self::listFiles(), $keep) as $f) {
            if (@unlink($f['path'])) {
                $n++;
            }
        }
        return $n;
    }

    /** 删除指定备份（后台手动清理用） */
    public static function remove(string $name): bool
    {
        $p = self::resolve($name);
        return $p !== null && @unlink($p);
    }

    /**
     * 把一个备份文件名解析成服务器上的真实路径。
     * 下载 / 删除前都必须过这里：basename 去掉任何目录成分 + 严格正则校验，
     * 双重收口杜绝 `../../config/config.php` 这类穿越。
     *
     * @return string|null 合法且存在时返回绝对路径，否则 null
     */
    public static function resolve(string $name): ?string
    {
        $name = basename(trim($name));
        if (!preg_match('/^nb_\d{8}_\d{6}\.sql\.gz$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /** 库中全部表名（不含前缀） */
    private static function tables(): array
    {
        $list = [];
        foreach (Database::all('SHOW TABLES') as $r) {
            $name = (string) reset($r);
            if ($name !== '') {
                $list[] = $name;
            }
        }
        return $list;
    }

    /** 单行 -> (v1,v2,...) */
    private static function rowSql(PDO $pdo, array $row): string
    {
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) {
                $vals[] = 'NULL';
            } elseif (is_int($v) || is_float($v)) {
                $vals[] = (string) $v;
            } else {
                $vals[] = $pdo->quote((string) $v);
            }
        }
        return '(' . implode(',', $vals) . ')';
    }

    /** 写入并忽略失败（备份过程不因单次写失败中断） */
    private static function w($fh, string $s): void
    {
        @gzwrite($fh, $s);
    }

    /** 字节 -> 可读 */
    public static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
