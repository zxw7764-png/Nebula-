<?php
/**
 * admin action: card_detail
 * 卡密详情：基本信息 + 激活时绑定的设备 + 使用记录
 */

$cardId = Util::int($input, 'card_id', 0);
if ($cardId <= 0) {
    Response::error(1001, '缺少 card_id');
}

$card = Database::one(
    'SELECT c.*, b.name AS batch_name, a.username AS create_admin_name,'
    . ' ag.username AS agent_name, ag.nickname AS agent_nickname'
    . ' FROM ' . Database::t('cards') . ' c'
    . ' LEFT JOIN ' . Database::t('card_batches') . ' b ON b.id = c.batch_id'
    . ' LEFT JOIN ' . Database::t('admins') . ' a ON a.id = c.create_admin'
    . ' LEFT JOIN ' . Database::t('agents') . ' ag ON ag.id = c.agent_id'
    . ' WHERE c.id = ?',
    [$cardId]
);
if (!$card) {
    Response::error(1001, '卡密不存在');
}

// 使用者信息
$usedUsername = '';
if ((int) $card['used_by'] > 0) {
    $usedUsername = (string) (Database::value(
        'SELECT username FROM ' . Database::t('users') . ' WHERE id = ?',
        [(int) $card['used_by']]
    ) ?: '');
}

// 类型文本
$typeMap = [1 => '时长卡', 2 => '点数卡', 3 => '次数卡', 4 => '永久卡'];
$typeText = $typeMap[(int) $card['type']] ?? '未知';

// 时长文本
if ((int) $card['type'] === 1) {
    $durationText = Util::duration((int) $card['duration']);
} elseif ((int) $card['type'] === 2) {
    $durationText = (int) $card['duration'] . ' 点';
} elseif ((int) $card['type'] === 3) {
    $durationText = (int) $card['duration'] . ' 次';
} else {
    $durationText = '永久';
}

$expireText = (int) $card['expire_at'] > 0 ? Util::date((int) $card['expire_at']) : '永久有效';

// 激活后分配的用户组
$groupId   = isset($card['group_id']) ? (int) $card['group_id'] : 0;
$groupName = '';
if ($groupId > 0) {
    $groupName = (string) (Database::value(
        'SELECT name FROM ' . Database::t('groups') . ' WHERE id = ?',
        [$groupId]
    ) ?: ('用户组#' . $groupId));
}

// 激活时绑定的设备（使用者当前绑定的设备，以及卡密日志里记录过的）
$devices = [];
if ((int) $card['used_by'] > 0) {
    $devices = Database::all(
        'SELECT id, machine_id, device_name, ip, bind_at, last_seen'
        . ' FROM ' . Database::t('devices')
        . ' WHERE user_id = ? ORDER BY bind_at DESC LIMIT 20',
        [(int) $card['used_by']]
    );
    foreach ($devices as &$dv) {
        $dv['bound_at_text'] = Util::date((int) $dv['bind_at']);
        $dv['last_seen_text'] = Util::date((int) $dv['last_seen']);
    }
    unset($dv);
}

// 卡密使用日志
$logs = Database::all(
    'SELECT action, detail, ip, created_at FROM ' . Database::t('card_logs')
    . ' WHERE card_id = ? ORDER BY id DESC LIMIT 50',
    [$cardId]
);
foreach ($logs as &$lg) {
    $lg['time_text'] = Util::date((int) $lg['created_at']);
}
unset($lg);

Response::ok([
    'card' => [
        'id'              => (int) $card['id'],
        'code'            => $card['code'],
        'batch_id'        => (int) $card['batch_id'],
        'batch_name'      => $card['batch_name'] ?? '',
        'type'            => (int) $card['type'],
        'type_text'       => $typeText,
        'duration'        => (int) $card['duration'],
        'duration_text'   => $durationText,
        'max_devices'     => (int) $card['max_devices'],
        'group_id'        => $groupId,
        'group_name'      => $groupName,
        'status'          => (int) $card['status'],
        'used_by'         => (int) $card['used_by'],
        'used_username'   => $usedUsername,
        'used_at_text'    => Util::date((int) $card['used_at']),
        'used_ip'         => $card['used_ip'] ?? '',
        'expire_at'       => (int) $card['expire_at'],
        'expire_text'     => $expireText,
        'create_admin_name' => $card['create_admin_name'] ?? '',
        'agent_id'         => (int) ($card['agent_id'] ?? 0),
        'agent_name'       => $card['agent_nickname'] !== null && $card['agent_nickname'] !== ''
                                ? $card['agent_nickname'] : ($card['agent_name'] ?? ''),
        'created_at_text' => Util::date((int) $card['created_at']),
        'remark'          => $card['remark'] ?? '',
    ],
    'devices' => $devices,
    'logs'    => $logs,
]);
