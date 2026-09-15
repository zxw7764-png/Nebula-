<?php
/**
 * 站点健康巡检
 * ------------------------------------------------------------------
 * 一次性检查「站点还能不能正常跑」的几项关键指标，供 cron 每轮执行时
 * 顺带体检，也可由后台「系统维护」页按下按钮即时查看。
 *
 * 与监控告警的差别：这里不做常驻进程，把结果写进日志（异常时用
 * Logger 记 warn/error），管理员看后台首页或 logs/ 即可发现。
 *
 * 检查项：
 *   · 磁盘可用空间（<15% 警告 / <5% 异常）
 *   · 数据库连通性与关键表是否存在
 *   · data/、logs/ 目录是否可写
 *   · 必需 / 可选 PHP 扩展
 *   · 近 24h 失败日志条数
 *   · 最近备份是否新鲜
 */
class Health
{
    /**
     * 执行全部检查
     * @return array{ok:bool, level:string, summary:string, checks:array, time:int, time_text:string}
     */
    public static function run(): array
    {
        $checks = [
            self::disk(),
            self::database(),
            self::dirs(),
            self::extensions(),
            self::errors(),
            self::backup(),
        ];

        $err = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if ($c['level'] === 'error') {
                $err++;
            } elseif ($c['level'] === 'warn') {
                $warn++;
            }
        }
        $level   = $err > 0 ? 'error' : ($warn > 0 ? 'warn' : 'ok');
        $summary = $err > 0
            ? "含 {$err} 项异常" . ($warn > 0 ? "、{$warn} 项警告" : '')
            : ($warn > 0 ? "含 {$warn} 项警告" : '全部正常');

        return [
            'ok'        => $err === 0,
            'level'     => $level,
            'summary'   => $summary,
            'checks'    => $checks,
            'time'      => time(),
            'time_text' => date('Y-m-d H:i:s'),
        ];
    }

    /** 磁盘可用空间 */
    private static function disk(): array
    {
        $root  = defined('NB_ROOT') ? NB_ROOT : dirname(__DIR__);
        $free  = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free === false || $total === false || $total <= 0) {
            return self::item('磁盘空间', 'warn', '无法读取磁盘信息（函数被禁用？）');
        }
        $pct   = $free / $total * 100;
        $level = $pct < 5 ? 'error' : ($pct < 15 ? 'warn' : 'ok');
        return self::item('磁盘空间', $level,
            '可用 ' . Backup::human((int) $free) . ' / ' . Backup::human((int) $total)
            . '（' . round($pct, 1) . '%）');
    }

    /** 数据库连通性 + 关键表 */
    private static function database(): array
    {
        try {
            $ok = Database::value('SELECT 1');
            if ((string) $ok !== '1') {
                return self::item('数据库', 'error', '连接测试返回异常');
            }
        } catch (Throwable $e) {
            return self::item('数据库', 'error', '连接失败');
        }

        $missing = [];
        foreach (['admins', 'settings', 'users', 'cards', 'logs'] as $t) {
            try {
                Database::one('SELECT 1 FROM ' . Database::t($t) . ' LIMIT 1');
            } catch (Throwable $e) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            return self::item('数据库', 'error', '缺少关键表：' . implode('、', $missing) . '（请执行 install/schema.sql 或对应迁移脚本）');
        }

        $ver = '';
        try {
            $ver = (string) Database::value('SELECT VERSION()');
        } catch (Throwable $e) {
        }
        return self::item('数据库', 'ok', '连接正常' . ($ver !== '' ? '（MySQL ' . $ver . '）' : ''));
    }

    /** 读写目录 */
    private static function dirs(): array
    {
        $root  = defined('NB_ROOT') ? NB_ROOT : dirname(__DIR__);
        $bad   = [];
        $warn  = [];
        foreach (['data' => '数据目录', 'logs' => '日志目录'] as $d => $label) {
            $p = $root . '/' . $d;
            if (!is_dir($p)) {
                $bad[] = $label . '缺失(' . $d . ')';
            } elseif (!is_writable($p)) {
                $bad[] = $label . '不可写(' . $d . ')';
            }
        }
        // 配置文件只需存在可读，生产环境不建议可写
        if (!is_file($root . '/config/config.php')) {
            $warn[] = '配置文件缺失';
        }

        if ($bad) {
            return self::item('目录权限', 'error', implode('、', $bad));
        }
        if ($warn) {
            return self::item('目录权限', 'warn', implode('、', $warn));
        }
        return self::item('目录权限', 'ok', 'data/、logs/ 可写');
    }

    /** PHP 扩展 */
    private static function extensions(): array
    {
        $need = ['pdo_mysql' => '数据库', 'openssl' => '加密', 'mbstring' => '多字节', 'json' => 'JSON'];
        $opt  = ['zlib' => '备份压缩', 'gd' => '图形验证码', 'curl' => '外部请求'];

        $missNeed = [];
        foreach ($need as $ext => $why) {
            if (!extension_loaded($ext)) {
                $missNeed[] = $ext . '(' . $why . ')';
            }
        }
        if ($missNeed) {
            return self::item('PHP 扩展', 'error', '缺少必需扩展：' . implode('、', $missNeed));
        }

        $missOpt = [];
        foreach ($opt as $ext => $why) {
            if (!extension_loaded($ext)) {
                $missOpt[] = $ext . '(' . $why . ')';
            }
        }
        if ($missOpt) {
            return self::item('PHP 扩展', 'warn', '缺少可选扩展：' . implode('、', $missOpt));
        }
        return self::item('PHP 扩展', 'ok', 'PHP ' . PHP_VERSION . '，扩展齐全');
    }

    /** 近 24h 失败日志 */
    private static function errors(): array
    {
        $since = time() - 86400;
        try {
            $n = (int) Database::value(
                'SELECT COUNT(*) FROM ' . Database::t('logs') . ' WHERE result = 0 AND created_at >= ?',
                [$since]
            );
        } catch (Throwable $e) {
            return self::item('失败日志', 'warn', '无法统计（logs 表结构异常？）');
        }
        $level = $n >= 500 ? 'error' : ($n >= 100 ? 'warn' : 'ok');
        return self::item('失败日志', $level, '近 24 小时 ' . $n . ' 条失败记录');
    }

    /** 备份新鲜度（按「不备份 / 自动备份 / 手动备份」模式分别判定） */
    private static function backup(): array
    {
        $mode = Backup::mode();
        if ($mode === 'off') {
            return self::item('数据备份', 'ok', '已关闭备份（不备份），不占用磁盘空间');
        }

        $last = Backup::lastRunAt();
        if ($mode === 'manual') {
            if ($last <= 0) {
                return self::item('数据备份', 'warn', '手动备份模式：尚无备份，建议到「系统设置 → 维护」点一次「立即备份」');
            }
            return self::item('数据备份', 'ok', '手动备份模式，最近一次 ' . date('Y-m-d H:i', $last));
        }

        // auto：按周期判定新鲜度
        if ($last <= 0) {
            return self::item('数据备份', 'warn', '尚无任何备份，请等待 cron 执行或手动备份一次');
        }
        $age   = time() - $last;
        $limit = Backup::intervalSeconds() * 2;
        $text  = '最近备份 ' . date('Y-m-d H:i', $last) . '（' . round($age / 3600, 1) . ' 小时前）';
        if ($age > $limit) {
            return self::item('数据备份', 'warn', $text . '，已超出预期周期，请检查 cron 是否在跑');
        }
        return self::item('数据备份', 'ok', $text);
    }

    /** 统一构造一项结果 */
    private static function item(string $name, string $level, string $text): array
    {
        return ['name' => $name, 'level' => $level, 'text' => $text];
    }
}
