<?php
/**
 * admin action: device_unban
 * 解除设备黑名单（按黑名单记录 ID 批量）
 */

$ids = $input['ban_ids'] ?? [];
if (!is_array($ids)) {
    $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (!$ids) {
    Response::error(1001, '请选择要解除的黑名单记录');
}
if (count($ids) > 500) {
    Response::error(1001, '单次最多操作 500 条记录');
}

$in  = implode(',', $ids);
$rows = Database::all(
    'SELECT id, machine_id, expire_at FROM ' . Database::t('device_bans') . " WHERE id IN ($in)"
);
if (!$rows) {
    Response::error(1001, '未找到对应的黑名单记录');
}

$count = Deleter::unbanDevices(array_map('intval', array_column($rows, 'id')));

Audit::log($admin, 'device_unban', '设备批量(解除拉黑)',
    '解除 ' . $count . ' 台设备的黑名单',
    [], [], ['machine_ids' => array_slice(array_column($rows, 'machine_id'), 0, 200)]);

Response::ok(['count' => $count], "已解除 {$count} 台设备的拉黑");
