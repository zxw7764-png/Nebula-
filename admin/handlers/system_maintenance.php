<?php
/**
 * admin action: system_maintenance
 * ------------------------------------------------------------------
 * 系统维护：健康巡检 + 数据备份管理。
 * 备份的自动化由 cron.php 负责（按 backup.interval_hours 节流），
 * 本接口提供「查看状态 / 立即备份 / 删除某份 / 即时巡检」。
 *
 * 权限点：settings.infra（与「系统设置 → 系统」同一档）
 */

$op = Util::str($input, 'op', 'health');

// 统一兜底：任何异常都转成结构化错误返回，前端才能结束 loading 并给出可读提示
// （否则 PHP 致命错误会输出非 JSON，前端 res.json() 抛错 → 页面永远停在「加载中」）
try {

// ---- 即时体检 ----
if ($op === 'health') {
    Response::ok(Health::run());
}

// ---- 备份状态与列表 ----
if ($op === 'backup_list') {
    $last = Backup::lastRunAt();
    $list = [];
    foreach (Backup::listFiles() as $f) {
        // 只回传展示所需字段，绝对路径不出后端
        $list[] = [
            'name'      => $f['name'],
            'bytes'     => $f['bytes'],
            'size_text' => $f['size_text'],
            'time_text' => $f['time_text'],
        ];
    }
    Response::ok([
        'mode'           => Backup::mode(),
        'mode_text'      => Backup::modeText(),
        'enabled'        => Backup::enabled(),
        'interval_hours' => Backup::intervalHours(),
        'keep'           => Backup::keep(),
        'last_text'      => $last > 0 ? Util::date($last) : '',
        'dir'            => 'data/backups',
        'total'          => count($list),
        'list'           => $list,
    ]);
}

// ---- 保存备份设置（不备份 / 自动备份 / 手动备份 + 周期 + 保留份数）----
if ($op === 'backup_save') {
    $mode = Util::str($input, 'mode', 'auto');
    if (!in_array($mode, ['off', 'auto', 'manual'], true)) {
        Response::error(1001, '备份模式不合法');
    }
    $hours = Util::int($input, 'interval_hours', 24);
    $keep  = Util::int($input, 'keep', 7);
    if ($hours < 1 || $hours > 720) {
        Response::error(1001, '自动备份间隔需在 1 ~ 720 小时之间');
    }
    if ($keep < 1 || $keep > 90) {
        Response::error(1001, '备份保留份数需在 1 ~ 90 份之间');
    }

    Setting::set('backup_mode', $mode, '备份模式：off=不备份 / auto=自动备份 / manual=手动备份');
    Setting::set('backup_interval_hours', (string) $hours, '自动备份间隔（小时）');
    Setting::set('backup_keep', (string) $keep, '备份保留份数');

    Audit::log($admin, 'backup_save', "管理员#{$admin['id']} {$admin['username']}",
        "备份设置：mode={$mode} interval={$hours}h keep={$keep}");

    Response::ok([
        'mode'           => Backup::mode(),
        'mode_text'      => Backup::modeText(),
        'enabled'        => Backup::enabled(),
        'interval_hours' => Backup::intervalHours(),
        'keep'           => Backup::keep(),
    ], '备份设置已保存');
}

// ---- 下载某份备份 ----
// 注意：这里必须绕过 Response 的 JSON 封装，直接把 gzip 二进制刷给浏览器，
// 所以任何 echo 都要在 header() 之后（否则 SAPI 会报 headers already sent）。
//
// 备份是整库转储（含全部卡密、密码摘要、通信密钥），属最高敏感产物，
// 因此额外要求二次输入登录密码 —— 与 software_reset_keys / software_delete
// 同一套路（前端 confirmPassword 弹窗把密码放进 confirm_pwd）。
if ($op === 'backup_download') {
    $confirmPwd = $_SERVER['HTTP_X_CONFIRM_PWD'] ?? ($input['confirm_pwd'] ?? '');
    $confirmPwd = is_string($confirmPwd) ? $confirmPwd : '';
    if ($confirmPwd === '') {
        Response::error(1010, '下载数据库备份需要输入当前登录密码确认');
    }
    if (!AdminAuth::verifyPassword((int) $admin['id'], $confirmPwd)) {
        Logger::log('admin_system_maintenance', 0, '备份下载二次密码校验失败',
            ['admin_id' => (int) $admin['id'], 'username' => $admin['username'] ?? '']);
        Response::error(1010, '密码错误，下载已取消');
    }

    $name = Util::str($input, 'name', '');
    $path = Backup::resolve($name);
    if ($path === null) {
        Response::error(1001, '文件不存在或文件名不合法');
    }

    $base = basename($path);
    $size = (int) @filesize($path);

    Audit::log($admin, 'backup_download', "管理员#{$admin['id']} {$admin['username']}",
        '下载备份 ' . $base);

    // 清掉可能存在的输出缓冲，避免前面任何杂散输出污染二进制流
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $base . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    readfile($path);
    exit;
}

// ---- 立即备份 ----
if ($op === 'backup_run') {
    $r = Backup::run();
    Audit::log($admin, 'backup_run', "管理员#{$admin['id']} {$admin['username']}",
        $r['ok'] ? (string) ($r['msg'] ?? '手动备份') : '手动备份失败：' . ($r['msg'] ?? ''));
    if (!$r['ok']) {
        Response::error(1001, (string) ($r['msg'] ?? '备份失败'));
    }
    Response::ok($r, (string) $r['msg']);
}

// ---- 删除某份备份 ----
if ($op === 'backup_delete') {
    $name = Util::str($input, 'name', '');
    $ok   = Backup::remove($name);
    Audit::log($admin, 'backup_delete', "管理员#{$admin['id']} {$admin['username']}",
        ($ok ? '删除备份 ' : '删除备份失败 ') . $name);
    if (!$ok) {
        Response::error(1001, '删除失败：文件名不合法或文件不存在');
    }
    Response::ok(null, '已删除 ' . $name);
}

} catch (Throwable $e) {
    Logger::log('system_maintenance', 0, '[' . $op . '] ' . $e->getMessage());
    Response::error(1001, '维护操作失败：' . $e->getMessage());
}

Response::error(1001, '未知操作');
