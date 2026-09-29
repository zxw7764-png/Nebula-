<?php
/**
 * Nebula 发布 / 更新包生成器（CLI）
 * ------------------------------------------------------------------
 * 把「手工打包、手工对版本、漏文件」的发布流程工具化。
 *
 * 用法：
 *   php make_release.php pack  [--src=DIR] [--out=DIR] [--version=x.y.z]
 *       打空白发布包：默认以 yanzheng/（发布源）打包出
 *       releases/<version>/nebula-<version>.zip + MANIFEST.txt（逐文件 md5）
 *
 *   php make_release.php diff <old_dir> <new_dir> --out=FILE.zip [--name=xxx]
 *       生成更新包：对比两棵目录树，收录「新增 + 变更」文件，
 *       生成 MANIFEST.txt（新文件 md5 清单）与 DELETED.txt（应删除清单）。
 *
 * 排除规则（发布包与更新包共用）：
 *   .git/ .gitignore, data/ 运行时数据（保留 .gitkeep）, logs/,
 *   *.bak, .DS_Store, Thumbs.db, nb9b6f51/（开发站后台目录，发布名 admin/ 已在 src 内）
 *
 * 设计原则：纯只读——除 --out 输出目录外不触碰源树任何文件。
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$EXCLUDE_DIRS = [
    '.git', '.github', 'node_modules', 'logs', '__pycache__',
    '.idea', '.vscode',
    // 开发站专用目录，不随空白包分发（README：发布工具仅开发站使用）
    'deploy', 'tests', 'update-system', '_pkg', 'releases',
    // 本机开发工具目录
    '.freebuff', '.catpaw', '.workbuddy',
];
$EXCLUDE_FILES = ['.gitignore', '.DS_Store', 'Thumbs.db'];
$EXCLUDE_EXT   = ['.bak', '.log', '.zip'];

function excluded(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    foreach (['data/', 'logs/', 'uploads/', 'pack/'] as $pre) {
        if (str_starts_with($rel, $pre)) {
            // data/ logs/ uploads/ pack/ 只保留 .gitkeep（运行时目录占位）
            if (!str_ends_with($rel, '.gitkeep')) {
                return true;
            }
        }
    }
    $parts = explode('/', $rel);
    foreach ($parts as $i => $p) {
        if ($i < count($parts) - 1 && in_array($p, $GLOBALS['EXCLUDE_DIRS'], true)) {
            return true;
        }
    }
    $base = basename($rel);
    if (in_array($base, $GLOBALS['EXCLUDE_FILES'], true)) {
        return true;
    }
    foreach ($GLOBALS['EXCLUDE_EXT'] as $ext) {
        if (str_ends_with($base, $ext)) {
            return true;
        }
    }
    // 升级脚本不随空白包分发（.gitignore 同款规则：老库升级另发）
    if (preg_match('#^install/migrate_#', $rel)) {
        return true;
    }
    return false;
}

/** 递归收集文件：返回 [相对路径 => 绝对路径] */
function collect(string $root, string $sub = ''): array
{
    $out = [];
    $dir = $sub === '' ? $root : $root . '/' . $sub;
    foreach (scandir($dir, SCANDIR_SORT_ASCENDING) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $rel = $sub === '' ? $item : $sub . '/' . $item;
        $abs = $root . '/' . $rel;
        if (is_dir($abs)) {
            // 目录级剪枝只看 EXCLUDE_DIRS（开发站/工具目录，整棵跳过）。
            // 运行时前缀目录（data/ logs/ uploads/ pack/）必须照常下钻，
            // 否则里面的 .gitkeep 占位文件永远收集不到（子树被目录级剪掉）。
            $pruned = false;
            foreach (explode('/', $rel) as $p) {
                if (in_array($p, $GLOBALS['EXCLUDE_DIRS'], true)) {
                    $pruned = true;
                    break;
                }
            }
            if (!$pruned) {
                $out += collect($root, $rel);
            }
        } elseif (is_file($abs)) {
            if (excluded($rel)) {
                continue;
            }
            $out[$rel] = $abs;
        }
    }
    return $out;
}

function manifest(array $files, string $root): string
{
    $lines = ["# Nebula 发布清单  生成时间: " . date('Y-m-d H:i:s'), '# 文件数: ' . count($files), ''];
    foreach ($files as $rel => $abs) {
        $lines[] = md5_file($abs) . '  ' . $rel;
    }
    return implode("\n", $lines) . "\n";
}

/**
 * 敏感文件硬阻断（2026-09-30 审计 P0）
 * ------------------------------------------------------------------
 * .gitignore 只能防 Git 提交，防不住打包流程。分发包历史上真的把
 * config/grace_keys.php（EC 私钥）和 tests/_certs/*.key 打了进去。
 * 因此在打包前对最终文件清单做逐项扫描：
 *   · 命中路径黑名单（grace_keys / *.key / *.pem / 证书目录等）→ 直接终止；
 *   · 其余 PHP 文件内容含 "BEGIN .*PRIVATE KEY" 也终止（防改名漏网）。
 * 发布服务器首次运行时 Grace::ensureKeys() 会自动生成密钥，无需分发。
 */
function assertNoSensitive(array $files, string $root): void
{
    $denyPath = [
        'config/grace_keys.php',      // Grace / 响应签名私钥
        'config/grace_keys.php.bak',
    ];
    $denySuffix = ['.key', '.pem', '.p12', '.pfx', '.crt.bak'];
    $denyDirPart = ['_certs'];        // tests/_certs 测试私钥目录

    foreach ($files as $rel => $abs) {
        $rel = str_replace('\\', '/', $rel);
        if (in_array($rel, $denyPath, true)) {
            fwrite(STDERR, "[阻断] 分发包包含服务器私钥: $rel\n");
            exit(1);
        }
        foreach ($denySuffix as $suf) {
            if (str_ends_with(strtolower($rel), $suf)) {
                fwrite(STDERR, "[阻断] 分发包包含密钥/证书文件: $rel\n");
                exit(1);
            }
        }
        foreach (explode('/', $rel) as $part) {
            if (in_array($part, $denyDirPart, true)) {
                fwrite(STDERR, "[阻断] 分发包包含证书目录内容: $rel\n");
                exit(1);
            }
        }
        // 内容兜底：文件内含「完整 PEM 私钥块」（头 + Base64 体 + 尾）才视为泄漏；
        // 只出现 "BEGIN ... PRIVATE KEY" 字面量（如 Pay.php 里的格式转换注释）不算
        if (preg_match('/\.(php|txt|md|json)$/i', $rel)) {
            $head = (string) @file_get_contents($abs, false, null, 0, 262144);
            if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----(?:[A-Za-z0-9+\/=\/\r\n]){40,}-----END [A-Z ]*PRIVATE KEY-----/', $head)) {
                fwrite(STDERR, "[阻断] 文件内容含私钥块: $rel\n");
                exit(1);
            }
        }
    }
}

function zipWrite(string $zipPath, array $files, string $root, array $extra = []): void
{
    $dir = dirname($zipPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "无法创建 $zipPath\n");
        exit(1);
    }
    foreach ($files as $rel => $abs) {
        $zip->addFile($abs, $rel);
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

// ------------------------------------------------------------------
$args  = array_slice($argv, 1);
$cmd   = $args[0] ?? '';
$opts  = [];
$pos   = [];
foreach (array_slice($args, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $kv = explode('=', substr($a, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    } else {
        $pos[] = $a;
    }
}

$WWW = dirname(__DIR__);

switch ($cmd) {
    case 'pack':
        $src     = $opts['src'] ?? $WWW . '/yanzheng';
        $outDir  = $opts['out'] ?? $WWW . '/releases';
        $version = $opts['version'] ?? trim((string) @file_get_contents($src . '/VERSION')) ?: date('Ymd');
        if (!is_dir($src)) {
            fwrite(STDERR, "源目录不存在: $src\n");
            exit(1);
        }
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $dst = $outDir . '/' . $version;
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        $files = collect($src);
        assertNoSensitive($files, $src);   // P0：私钥/证书进包直接终止
        $zipPath = $dst . '/nebula-' . $version . '.zip';
        zipWrite($zipPath, $files, $src, ['MANIFEST.txt' => manifest($files, $src)]);
        file_put_contents($dst . '/MANIFEST.txt', manifest($files, $src));
        echo "打包完成: $zipPath\n";
        echo '文件数: ' . count($files) . '  大小: ' . round(filesize($zipPath) / 1048576, 2) . " MB\n";
        break;

    case 'diff':
        if (count($pos) < 2) {
            fwrite(STDERR, "用法: php make_release.php diff <old_dir> <new_dir> --out=FILE.zip\n");
            exit(1);
        }
        [$old, $new] = $pos;
        if (!is_dir($old) || !is_dir($new)) {
            fwrite(STDERR, "新旧目录都必须存在\n");
            exit(1);
        }
        $oldF = collect($old);
        $newF = collect($new);
        $changed = [];
        foreach ($newF as $rel => $abs) {
            if (!isset($oldF[$rel]) || md5_file($oldF[$rel]) !== md5_file($abs)) {
                $changed[$rel] = $abs;
            }
        }
        $deleted = array_values(array_diff(array_keys($oldF), array_keys($newF)));
        assertNoSensitive($changed, $new);   // P0：更新包同样阻断
        if (!$changed && !$deleted) {
            echo "两版本无差异，无需更新包\n";
            exit(0);
        }
        $out = $opts['out'] ?? ($WWW . '/releases/update-' . date('Ymd-His') . '.zip');
        $manifest = "# Nebula 更新清单  生成时间: " . date('Y-m-d H:i:s')
            . "\n# 变更/新增 " . count($changed) . " 个文件，删除 " . count($deleted) . " 个文件\n\n"
            . manifest($changed, $new);
        $deletedTxt = $deleted ? "# 以下文件已在新版本中删除，部署时应从服务器移除：\n" . implode("\n", $deleted) . "\n" : "# 无删除文件\n";
        zipWrite($out, $changed, $new, ['MANIFEST.txt' => $manifest, 'DELETED.txt' => $deletedTxt]);
        echo "更新包: $out\n";
        echo '变更/新增: ' . count($changed) . '  删除: ' . count($deleted)
            . '  大小: ' . round(filesize($out) / 1048576, 2) . " MB\n";
        if ($deleted) {
            echo "注意：存在应删除文件（见包内 DELETED.txt）\n";
        }
        break;

    default:
        echo <<<TXT
Nebula 发布工具
  pack  [--src=DIR] [--out=DIR] [--version=x.y.z]   打空白发布包
  diff  <old_dir> <new_dir> [--out=FILE.zip]        生成增量更新包
TXT;
        echo "\n";
}
