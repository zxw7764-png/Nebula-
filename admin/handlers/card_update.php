<?php
/**
 * admin action: card_update
 * 编辑卡密（仅未使用的卡密可改）
 */

$cardId = Util::int($input, 'card_id', 0);
if ($cardId <= 0) {
    Response::error(1001, '缺少 card_id');
}

$card = Database::one('SELECT * FROM ' . Database::t('cards') . ' WHERE id = ?', [$cardId]);
if (!$card) {
    Response::error(1001, '卡密不存在');
}
if ((int) $card['status'] !== 0) {
    Response::error(1001, '只能编辑未使用的卡密');
}
// 租户隔离：只能编辑归属软件在自己范围内的卡密
Tenant::touchRow($admin, 'cards', $card);

$old = [
    'duration'    => (int) $card['duration'],
    'max_devices' => (int) $card['max_devices'],
    'group_id'    => isset($card['group_id']) ? (int) $card['group_id'] : 0,
    'expire_at'   => (int) $card['expire_at'],
    'remark'      => (string) ($card['remark'] ?? ''),
];

$data = [];

if (isset($input['duration'])) {
    $duration = Util::int($input, 'duration', 0);
    if ($duration < 0) {
        Response::error(1001, '时长/点数不能为负');
    }
    $data['duration'] = $duration;
}

if (isset($input['max_devices'])) {
    $maxDev = Util::int($input, 'max_devices', 1);
    if ($maxDev < 1 || $maxDev > 99) {
        Response::error(1001, '设备数需在 1-99 之间');
    }
    $data['max_devices'] = $maxDev;
}

if (isset($input['group_id'])) {
    $gid = Util::int($input, 'group_id', 0);
    if ($gid > 0 && !Database::value('SELECT id FROM ' . Database::t('groups') . ' WHERE id = ?', [$gid])) {
        Response::error(1001, '激活后分配的用户组不存在');
    }
    $data['group_id'] = $gid;
}

if (isset($input['expire_at'])) {
    $expire = Util::int($input, 'expire_at', 0);
    if ($expire < -1) {
        Response::error(1001, '有效期不合法');
    }
    // -1 表示永久（存 0）
    $data['expire_at'] = $expire === -1 ? 0 : max(0, $expire);
}

if (isset($input['remark'])) {
    $data['remark'] = mb_substr(Util::str($input, 'remark', ''), 0, 250) ?: null;
}

if (!$data) {
    Response::error(1001, '没有需要更新的字段');
}

Database::update('cards', $data, 'id = :id', ['id' => $cardId]);

// 重新读取用于审计对比
$newRow = Database::one('SELECT * FROM ' . Database::t('cards') . ' WHERE id = ?', [$cardId]);
$new = [
    'duration'    => (int) $newRow['duration'],
    'max_devices' => (int) $newRow['max_devices'],
    'expire_at'   => (int) $newRow['expire_at'],
    'remark'      => (string) ($newRow['remark'] ?? ''),
];

Audit::log($admin, 'card_update', "卡密#{$cardId} {$card['code']}",
    '编辑卡密属性', $old, $new);

Response::ok(null, '保存成功');
