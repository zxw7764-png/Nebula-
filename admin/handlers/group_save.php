<?php
/**
 * admin action: group_save
 */

$op = Util::str($input, 'op', 'save');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 1) {
        Response::error(1001, '默认用户组不可删除');
    }
    $used = (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE group_id = ?', [$id]);
    if ($used > 0) {
        Response::error(1001, "该组下还有 {$used} 个用户，无法删除");
    }
    Database::exec('DELETE FROM ' . Database::t('groups') . ' WHERE id = ?', [$id]);
    Audit::log($admin, 'group_delete', "用户组#{$id}", '删除用户组');
    Response::ok(null, '用户组已删除');
}

$id        = Util::int($input, 'id', 0);
$name      = mb_substr(Util::str($input, 'name', ''), 0, 64);
$maxDev    = max(1, min(99, Util::int($input, 'max_devices', 1)));
$quota     = max(0, Util::int($input, 'daily_quota', 0));
$remark    = mb_substr(Util::str($input, 'remark', ''), 0, 250);

if ($name === '') {
    Response::error(1001, '组名不能为空');
}

$data = [
    'name'        => $name,
    'max_devices' => $maxDev,
    'daily_quota' => $quota,
    'remark'      => $remark ?: null,
];

if ($id > 0) {
    Database::update('groups', $data, 'id = :id', ['id' => $id]);
    Audit::log($admin, 'group_save', "用户组#{$id} {$name}", '编辑用户组');
    Response::ok(['id' => $id], '用户组已更新');
}

$data['created_at'] = time();
$newId = Database::insert('groups', $data);
Audit::log($admin, 'group_save', "用户组#{$newId} {$name}", '新增用户组');
Response::ok(['id' => $newId], '用户组已创建');
