<?php
/**
 * admin action: feedback_reply
 * 回复 / 关闭 / 删除用户反馈，以及批量关闭
 *
 * 参数：
 *   op      reply | close | reopen | delete | batch_close
 *   id      反馈 ID
 *   reply   回复内容（reply 用）
 *   ids     批量关闭时的 ID 数组
 */

$op    = Util::str($input, 'op', 'reply');
$table = Database::t('feedbacks');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '反馈不存在');
    }

    Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);

    Audit::log($admin, 'feedback_delete', "反馈#{$id} {$old['username']}", '删除反馈',
        ['title' => $old['title'], 'type' => (int) $old['type']], []);

    Response::ok(['id' => $id], '反馈已删除');
}

if ($op === 'batch_close') {
    $ids = Util::get($input, 'ids', []);
    if (!is_array($ids) || !$ids) {
        Response::error(1001, '请选择要关闭的反馈');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
    if (!$ids) {
        Response::error(1001, '请选择要关闭的反馈');
    }
    if (count($ids) > 200) {
        Response::error(1001, '单次最多操作 200 条');
    }

    $hold = implode(',', array_fill(0, count($ids), '?'));
    $n    = Database::exec("UPDATE {$table} SET status = 3 WHERE id IN ({$hold})", $ids);

    Audit::log($admin, 'feedback_batch_close', count($ids) . ' 条反馈', '批量关闭反馈',
        ['ids' => $ids], ['status' => 3]);

    Response::ok(['affected' => $n], "已关闭 {$n} 条反馈");
}

$id = Util::int($input, 'id', 0);
if ($id <= 0) {
    Response::error(1001, '缺少 id');
}

$old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$old) {
    Response::error(1004, '反馈不存在');
}

if ($op === 'close') {
    Database::exec("UPDATE {$table} SET status = 3 WHERE id = ?", [$id]);
    Audit::log($admin, 'feedback_close', "反馈#{$id} {$old['username']}", '关闭反馈',
        ['status' => (int) $old['status']], ['status' => 3]);
    Response::ok(['id' => $id, 'status' => 3], '反馈已关闭');
}

if ($op === 'reopen') {
    Database::exec("UPDATE {$table} SET status = 0 WHERE id = ?", [$id]);
    Audit::log($admin, 'feedback_reopen', "反馈#{$id} {$old['username']}", '重开反馈',
        ['status' => (int) $old['status']], ['status' => 0]);
    Response::ok(['id' => $id, 'status' => 0], '反馈已重新打开');
}

if ($op !== 'reply') {
    Response::error(1001, '未知操作: ' . $op);
}

// ---------------------------------------------------------------
// 回复
// ---------------------------------------------------------------
$reply = trim((string) Util::get($input, 'reply', ''));
if ($reply === '') {
    Response::error(1001, '请填写回复内容');
}
if (mb_strlen($reply, 'UTF-8') > 2000) {
    Response::error(1001, '回复内容最多 2000 字');
}

$now  = time();
$name = (string) ($admin['nickname'] ?: $admin['username']);

Database::exec(
    "UPDATE {$table} SET status = 2, reply = ?, reply_admin = ?, replied_at = ? WHERE id = ?",
    [$reply, $name, $now, $id]
);

Audit::log($admin, 'feedback_reply', "反馈#{$id} {$old['username']}", '回复反馈',
    ['status' => (int) $old['status'], 'reply' => (string) $old['reply']],
    ['status' => 2, 'reply' => $reply]);

Response::ok([
    'id'          => $id,
    'status'      => 2,
    'reply'       => $reply,
    'reply_admin' => $name,
    'replied_at'  => Util::date($now),
], '回复已发送');
