<?php
/**
 * admin action: files_scan
 * 挂马 / Webshell 扫描：
 *   · 特征规则匹配（eval/命令执行/请求变量动态调用/混淆长串等，评分制）
 *   · 运行时目录（uploads 等）出现可执行文件 → 直接最高危
 *   · 最近 7 天被改动的受管文件
 *
 * 权限：settings.business（仅超管）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_BUSINESS);

$res = FileGuard::scan();
Audit::log($admin, 'files_scan', '挂马扫描',
    '扫描完成：可疑 ' . count($res['hits']) . ' / 运行时目录可执行 ' . count($res['uploads_php']),
    [], ['hits' => count($res['hits']), 'uploads_php' => count($res['uploads_php'])]);
Response::ok($res, '扫描完成');
