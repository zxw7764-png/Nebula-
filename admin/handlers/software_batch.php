<?php
/**
 * admin action: software_batch
 * 软件批量操作：act=enable 批量启用 / disable 批量停用 / delete 批量删除
 * - 删除循环调用 Software::delete（内部含「至少保留一个启用软件」约束与
 *   会话密钥清除），被约束跳过的软件在提示里逐个列明
 * - 权限：settings.business（与单个删除一致）
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_BUSINESS);

$action = Util::str($input, 'act', '');
$idsIn  = Util::get($input, 'ids', []);
if (!is_array($idsIn)) {
    Response::error(1001, 'ids 需为数组');
}
$ids = [];
foreach ($idsIn as $v) {
    $v = (int) $v;
    if ($v > 0) {
        $ids[] = $v;
    }
}
$ids = array_values(array_unique($ids));
if (!$ids) {
    Response::error(1001, '请先勾选要操作的软件');
}
if (!in_array($action, ['enable', 'disable', 'delete'], true)) {
    Response::error(1001, '未知操作类型');
}

// 租户隔离：RBAC 之外的第二道边界 —— 目标软件必须全部在自己范围内
if (Tenant::isTenant($admin)) {
    $scope = Tenant::softwareScope($admin);
    $outsider = array_diff($ids, $scope ?: []);
    if ($outsider) {
        Response::error(4031, '无权操作该软件的数据（软件 #' . implode(', ', array_slice($outsider, 0, 5)) . '）');
    }
}

// ---------------- 批量删除 ----------------
if ($action === 'delete') {
    $ok = 0;
    $skip = [];
    foreach ($ids as $id) {
        $sw = Software::find($id);
        $r  = Software::delete($id);
        if ($r['ok']) {
            $ok++;
        } else {
            $skip[] = ($sw ? $sw['name'] : '#' . $id) . '：' . $r['msg'];
        }
    }
    if (!$ok) {
        Response::error(1001, '没有可删除的软件：' . implode('；', $skip));
    }
    $msg = '已删除 ' . $ok . ' 个软件';
    if ($skip) {
        $msg .= '；未删除：' . implode('；', $skip);
    }
    Audit::log($admin, 'software_batch', implode(',', $ids), '批量删除软件，成功 ' . $ok . ' 个');
    Response::ok(['ok' => $ok], $msg);
}

// ---------------- 批量启用 / 停用 ----------------
$status = $action === 'enable' ? 1 : 0;
$ph = implode(',', array_fill(0, count($ids), '?'));
Database::exec(
    'UPDATE ' . Software::table() . ' SET status = ? WHERE id IN (' . $ph . ')',
    array_merge([$status], $ids)
);
Audit::log($admin, 'software_batch', implode(',', $ids), '批量' . ($status ? '启用' : '停用') . '软件');
Response::ok(null, '已' . ($status ? '启用' : '停用') . ' ' . count($ids) . ' 个软件');
