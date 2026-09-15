<?php
/**
 * admin action: files_integrity
 * 文件完整性基准：op=build 生成基准 / op=check 与基准比对
 * ------------------------------------------------------------------
 * 基准覆盖站点根目录全部 PHP / .htaccess（排除 uploads/data/logs 等运行时目录）。
 * 生成基准后，任何代码文件被改动/新增/删除都能在校验中暴露。
 *
 * 权限：settings.business（仅超管）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_BUSINESS);

$op = Util::str($input, 'op', 'check');

if ($op === 'build') {
    $n = FileGuard::buildBaseline();
    if ($n < 0) {
        Response::error(5000, '基准文件写入失败（检查 data/ 目录可写）');
    }
    Audit::log($admin, 'files_integrity', '文件基准', "重建文件完整性基准（{$n} 个文件）", [], ['count' => $n]);
    Response::ok(['count' => $n, 'built_at' => time()], "已生成基准：{$n} 个文件");
}

$diff = FileGuard::diffBaseline();
if (!$diff) {
    Response::ok(['no_baseline' => true], '尚未生成基准，请先点击「生成基准」');
}
Audit::log($admin, 'files_integrity', '文件校验',
    '完整性校验：改动 ' . count($diff['modified']) . ' / 缺失 ' . count($diff['missing'])
    . ' / 新增 ' . count($diff['added']),
    [], ['modified' => count($diff['modified']), 'missing' => count($diff['missing']), 'added' => count($diff['added'])]);
Response::ok($diff, '校验完成');
