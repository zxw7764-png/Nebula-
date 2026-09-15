<?php
/**
 * FileGuard — 文件安全防护：完整性基准 / Webshell 扫描 / 受控文件操作
 * ------------------------------------------------------------------
 * · 扫描范围：站点根目录下所有 .php/.phtml/.phar/.htaccess
 *   排除运行时目录（uploads/data/logs/.git 等，这些目录出现 php 即高危告警）
 * · 基准文件：data/file_baseline.json（路径 => sha256/size/mtime）
 * · 所有对外的文件读写/删除都必须经 safeRel() 白名单校验，杜绝路径穿越
 */
class FileGuard
{
    /** 排除扫描的相对目录（运行时/静态资源目录） */
    public const SKIP_DIRS = ['uploads', 'data', 'logs', '.git', '.svn', 'node_modules', 'runtime', 'cache'];

    /** 纳入基准/扫描的文件后缀（.htaccess 按文件名精确匹配） */
    public const EXT = ['php', 'phtml', 'phar'];

    /** 允许在线查看/删除的后缀 */
    public const VIEWABLE = ['php', 'phtml', 'phar', 'htaccess', 'json', 'css', 'js', 'html', 'htm', 'txt', 'md', 'ini', 'conf'];

    /** Webshell 特征规则：[名称, 正则, 权重] */
    public const RULES = [
        ['eval 变形执行',          '/\b(eval|assert)\s*\(/i',                                      10],
        ['base64/gz 解码执行',      '/(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',      8],
        ['命令执行函数',            '/\b(system|shell_exec|passthru|exec|popen|proc_open)\s*\(/i',   9],
        ['preg_replace /e 代码执行', '/preg_replace\s*\(\s*["\'][^"\']*\/[a-z]*e[a-z]*["\']/i',       9],
        ['请求变量直接拼接代码',     '/\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]*\]\s*\(/i',     12],
        ['可变函数调用',            '/\$\{?[a-zA-Z_]\w*\}\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',   12],
        ['动态变量函数定义',         '/(create_function|call_user_func(_array)?)\s*\(/i',             5],
        ['短标签+请求取值',         '/<\?=\s*\$_(GET|POST|REQUEST|COOKIE)/i',                       11],
        ['文件写入型后门',          '/(file_put_contents|fwrite)\s*\(.*\$_(GET|POST|REQUEST)/is',   11],
        ['include 请求参数',        '/(include|require)(_once)?\s*\(?\s*\$(_GET|_POST|_REQUEST|\w+)/i', 8],
        ['HTTP 头下发 payload',     '/\bgetenv\s*\(\s*["\']HTTP_[A-Z]+["\']\s*\)/i',                 6],
        ['超长单行混淆',            '/^\s*\$\w+\s*=\s*["\'][A-Za-z0-9+\/=]{300,}["\']\s*;/m',        8],
        ['异步/回调执行请求值',     '/(array_map|array_filter|usort|register_shutdown_function|register_tick_function)\s*\(\s*["\']?(eval|assert|system|exec|shell_exec|passthru)/i', 12],
        ['经典一句话连接口令',      '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*["\'][^"\']{1,12}["\']\s*\]\s*\(\s*\$[_\w]+/i', 12],
    ];

    /** 高危判定分数阈值 */
    public const SCORE_LIMIT = 8;

    /* ------------------------------------------------------------------
       路径安全
    ------------------------------------------------------------------ */

    /** 把相对路径规整为绝对路径并校验：必须在站点根内、后缀白名单、无穿越 */
    public static function safePath(string $rel): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return '';
        }
        $rel = ltrim($rel, '/');
        $abs = NB_ROOT . '/' . $rel;
        $rp  = realpath($abs);
        if ($rp === false || !is_file($rp)) {
            return '';
        }
        $root = realpath(NB_ROOT);
        if ($root === false || !str_starts_with($rp, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }
        $ext = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
        if (!in_array($ext, self::VIEWABLE, true)) {
            return '';
        }
        return $rp;
    }

    /** 相对路径（正斜杠、不带前导 /） */
    public static function rel(string $abs): string
    {
        return str_replace('\\', '/', substr($abs, strlen(realpath(NB_ROOT)) + 1));
    }

    /* ------------------------------------------------------------------
       文件枚举
    ------------------------------------------------------------------ */

    /** 枚举所有受管文件（绝对路径 => mtime），跳过运行时目录 */
    public static function enumerate(): array
    {
        $root = realpath(NB_ROOT);
        $out  = [];
        $stack = [$root];
        while ($stack) {
            $dir = array_pop($stack);
            $dh  = @opendir($dir);
            if ($dh === false) continue;
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') continue;
                $p = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_dir($p)) {
                    if (!in_array($name, self::SKIP_DIRS, true)) {
                        $stack[] = $p;
                    }
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXT, true) || $name === '.htaccess') {
                    $out[$p] = @filemtime($p);
                }
            }
            closedir($dh);
        }
        return $out;
    }

    /* ------------------------------------------------------------------
       完整性基准
    ------------------------------------------------------------------ */

    public static function baselineFile(): string
    {
        return NB_ROOT . '/data/file_baseline.json';
    }

    /** 生成基准，返回文件数；失败返回 -1 */
    public static function buildBaseline(): int
    {
        $dir = dirname(self::baselineFile());
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return -1;
        }
        $items = [];
        foreach (self::enumerate() as $abs => $mtime) {
            $rel = self::rel($abs);
            $items[$rel] = [
                'h' => hash_file('sha256', $abs),
                's' => (int) @filesize($abs),
                'm' => (int) $mtime,
            ];
        }
        $ok = @file_put_contents(self::baselineFile(), json_encode([
            'built_at' => time(),
            'count'    => count($items),
            'files'    => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $ok === false ? -1 : count($items);
    }

    /** 读取基准：[meta, files]，无基准返回 null */
    public static function loadBaseline(): ?array
    {
        $f = self::baselineFile();
        if (!is_file($f)) return null;
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) && isset($j['files']) ? $j : null;
    }

    /** 与基准比对：modified / missing / added（各返回 [rel, mtime, size] 列表） */
    public static function diffBaseline(): array
    {
        $base = self::loadBaseline();
        if ($base === null) return [];
        $old = $base['files'];
        $modified = $missing = $added = [];

        foreach (self::enumerate() as $abs => $mtime) {
            $rel = self::rel($abs);
            if (!isset($old[$rel])) {
                $added[] = ['file' => $rel, 'mtime' => $mtime, 'size' => (int) @filesize($abs)];
                continue;
            }
            $b = $old[$rel];
            if (($b['s'] ?? -1) !== (int) @filesize($abs)
                || ($b['h'] ?? '') !== hash_file('sha256', $abs)) {
                $modified[] = ['file' => $rel, 'mtime' => $mtime, 'size' => (int) @filesize($abs)];
            }
            unset($old[$rel]);
        }
        foreach ($old as $rel => $b) {
            $missing[] = ['file' => $rel, 'mtime' => $b['m'] ?? 0, 'size' => $b['s'] ?? 0];
        }
        return [
            'modified' => $modified,
            'missing'  => $missing,
            'added'    => $added,
            'built_at' => $base['built_at'] ?? 0,
            'total'    => count($base['files']),
        ];
    }

    /* ------------------------------------------------------------------
       Webshell 扫描
    ------------------------------------------------------------------ */

    /**
     * 扫描可疑文件。返回：
     *   hits  [file, score, mtime, matches:[[rule,line,snippet]]]（按分排序，截前 80）
     *   uploads_php  上传目录内出现的一切可执行文件（最高危）
     *   recent       最近 7 天被修改的受管文件
     */
    public static function scan(): array
    {
        $hits = [];
        $uploadsPhp = [];
        $recent = [];
        $recentCut = time() - 7 * 86400;

        foreach (self::enumerate() as $abs => $mtime) {
            $rel = self::rel($abs);
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

            // 运行时目录（uploads 等）内出现可执行文件：无论内容直接最高危
            $parts = explode('/', $rel);
            if (in_array($parts[0] ?? '', self::SKIP_DIRS, true) && $ext === 'php') {
                $uploadsPhp[] = ['file' => $rel, 'mtime' => $mtime, 'size' => (int) @filesize($abs)];
            }

            if ($mtime >= $recentCut) {
                $recent[] = ['file' => $rel, 'mtime' => $mtime, 'size' => (int) @filesize($abs)];
            }

            $size = (int) @filesize($abs);
            if ($size > 2 * 1024 * 1024) continue; // 超大文件跳过内容匹配
            $src = @file_get_contents($abs);
            if ($src === false || $src === '') continue;
            // utf8 不合法时先清洗，避免正则按字节误切
            if (@preg_match('//u', $src) !== 1) $src = function_exists('mb_scrub') ? mb_scrub($src, 'UTF-8') : '';

            $score = 0;
            $matches = [];
            foreach (self::RULES as [$rule, $re, $w]) {
                if (@preg_match($re, $src, $m, PREG_OFFSET_CAPTURE) !== 1) continue;
                $score += $w;
                if (count($matches) < 6) {
                    $line = 1 + substr_count(substr($src, 0, (int) $m[0][1]), "\n");
                    $snippet = trim(mb_substr(preg_replace('/\s+/', ' ', $m[0][0]), 0, 120));
                    $matches[] = ['rule' => $rule, 'line' => $line, 'snippet' => $snippet];
                }
            }
            if ($score >= self::SCORE_LIMIT) {
                $hits[] = ['file' => $rel, 'score' => $score, 'mtime' => $mtime, 'size' => $size, 'matches' => $matches];
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);
        usort($recent, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return [
            'hits'        => array_slice($hits, 0, 80),
            'uploads_php' => $uploadsPhp,
            'recent'      => array_slice($recent, 0, 50),
        ];
    }

    /* ------------------------------------------------------------------
       受控读取 / 删除
    ------------------------------------------------------------------ */

    /** 读取文件前 64KB（返回 null 表示路径非法） */
    public static function read(string $rel): ?string
    {
        $p = self::safePath($rel);
        if ($p === '') return null;
        $c = @file_get_contents($p, false, null, 0, 65536);
        return $c === false ? null : $c;
    }

    /** 删除文件（路径校验 + 禁删运行必需文件），成功 true */
    public static function remove(string $rel): bool
    {
        $p = self::safePath($rel);
        if ($p === '') return false;
        $rel2 = str_replace('\\', '/', strtolower($rel));
        // 兜底：绝不允许删除运行必需的入口与公共库。
        // 后台入口目录名由安装时随机生成（本地 nb9b6f51 / 空白包 admin），
        // 所以不能写死目录名 —— 按「任意一级子目录下的 index.php / home.php」匹配。
        if (preg_match('#^(index|home)\.php$#', $rel2)
            || preg_match('#^[^/]+/(index|home)\.php$#', $rel2)
            || preg_match('#^lib/(bootstrap|config|database)\.php$#', $rel2)) {
            return false;
        }
        return @unlink($p);
    }
}
