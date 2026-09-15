<?php
/**
 * admin action: message_op
 * 留言审核操作：通过 / 驳回 / 删除 / 批量操作
 *
 * 参数：
 *   op      pass | reject | delete | batch
 *   id      单条操作时的留言 ID
 *   ids     批量操作时的 ID 数组（batch 用）
 *   note    驳回理由（reject 用，选填）
 */

$op     = Util::str($input, 'op', 'pass');
$table  = Database::t('messages');
$likeT  = Database::t('message_likes');

/**
 * 删除单条留言，并连带清理它的回复与点赞记录。
 * 删主楼时若留下孤儿回复，前端会显示"回复 xxx"却找不到目标，所以必须级联。
 */
$deleteOne = static function (int $id) use ($table, $likeT): int {
    // 找出该留言及其所有回复
    $ids = [$id];
    $reps = Database::all("SELECT id FROM {$table} WHERE parent_id = ?", [$id]);
    foreach ($reps as $r) { $ids[] = (int) $r['id']; }

    $hold = implode(',', array_fill(0, count($ids), '?'));

    // 先删点赞记录（无外键约束，需手工级联，避免留下孤儿数据）
    Database::exec("DELETE FROM {$likeT} WHERE message_id IN ({$hold})", $ids);

    // 删回复再删主楼
    Database::exec("DELETE FROM {$table} WHERE parent_id = ?", [$id]);
    return (int) Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);
};

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '留言不存在');
    }

    $n = $deleteOne($id);

    Audit::log($admin, 'message_delete', "留言#{$id} {$old['username']}", '删除留言',
        ['content' => mb_substr((string) $old['content'], 0, 100, 'UTF-8'), 'status' => (int) $old['status']],
        []);

    Response::ok(['deleted' => $n], '留言已删除');
}

if ($op === 'batch') {
    $ids = Util::get($input, 'ids', []);
    if (!is_array($ids) || !$ids) {
        Response::error(1001, '请选择要操作的留言');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
    if (!$ids) {
        Response::error(1001, '请选择要操作的留言');
    }
    if (count($ids) > 200) {
        Response::error(1001, '单次最多操作 200 条');
    }

    $do = Util::str($input, 'do', 'pass');   // pass | reject | delete
    $hold = implode(',', array_fill(0, count($ids), '?'));

    if ($do === 'delete') {
        $n = 0;
        foreach ($ids as $id) { $n += $deleteOne($id); }
        Audit::log($admin, 'message_batch_delete', count($ids) . ' 条留言', '批量删除留言',
            ['ids' => $ids], []);
        Response::ok(['affected' => $n], "已删除 {$n} 条留言");
    }

    if ($do === 'reject') {
        $note = mb_substr(trim(Util::str($input, 'note', '')), 0, 200);
        $n = Database::exec(
            "UPDATE {$table} SET status = 2, admin_note = ? WHERE id IN ({$hold})",
            array_merge([$note], $ids)
        );
        Audit::log($admin, 'message_batch_reject', count($ids) . ' 条留言', '批量驳回留言',
            ['ids' => $ids], ['status' => 2]);
        Response::ok(['affected' => $n], "已驳回 {$n} 条留言");
    }

    // 默认通过
    $n = Database::exec(
        "UPDATE {$table} SET status = 1, admin_note = '' WHERE id IN ({$hold})",
        $ids
    );
    Audit::log($admin, 'message_batch_pass', count($ids) . ' 条留言', '批量通过留言',
        ['ids' => $ids], ['status' => 1]);
    Response::ok(['affected' => $n], "已通过 {$n} 条留言");
}

// ---------------------------------------------------------------
// 单条审核
// ---------------------------------------------------------------
$id = Util::int($input, 'id', 0);
if ($id <= 0) {
    Response::error(1001, '缺少 id');
}

$old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$old) {
    Response::error(1004, '留言不存在');
}

if ($op === 'reject') {
    $note = mb_substr(trim(Util::str($input, 'note', '')), 0, 200);
    Database::exec("UPDATE {$table} SET status = 2, admin_note = ? WHERE id = ?", [$note, $id]);
    Audit::log($admin, 'message_reject', "留言#{$id} {$old['username']}", '驳回留言',
        ['status' => (int) $old['status']], ['status' => 2, 'note' => $note]);
    Response::ok(['id' => $id, 'status' => 2], '留言已驳回');
}

if ($op !== 'pass') {
    Response::error(1001, '未知操作: ' . $op);
}

Database::exec("UPDATE {$table} SET status = 1, admin_note = '' WHERE id = ?", [$id]);
Audit::log($admin, 'message_pass', "留言#{$id} {$old['username']}", '通过留言',
    ['status' => (int) $old['status']], ['status' => 1]);
Response::ok(['id' => $id, 'status' => 1], '留言已通过审核');
