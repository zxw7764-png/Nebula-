<?php
/**
 * admin action: user_delete
 * 删除用户（连带清理设备、会话、卡密归属）
 */

$userId = Util::int($input, 'user_id', 0);
if ($userId <= 0) {
    Response::error(1001, '缺少 user_id');
}
if ((int) $admin['role'] !== 1) {
    Response::error(1004, '仅超级管理员可删除用户');
}

$user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1001, '用户不存在');
}

// 二次密码确认（敏感操作）
if (Config::get('admin.require_password_confirm', true)) {
    $pass = Util::str($input, 'password', '');
    if ($pass === '') {
        Response::error(1005, '请输入管理密码以确认删除');
    }
    $me = Database::one('SELECT password FROM ' . Database::t('admins') . ' WHERE id = ?', [(int) $admin['id']]);
    if (!$me || !Util::verifyPassword($pass, $me['password'])) {
        Response::error(1005, '管理密码错误');
    }
}

Database::begin();
try {
    // 解绑设备
    Database::exec('UPDATE ' . Database::t('devices') . ' SET status = 0, unbind_at = ? WHERE user_id = ?', [time(), $userId]);
    // 结束会话
    Database::exec('UPDATE ' . Database::t('sessions') . ' SET status = 0 WHERE user_id = ?', [$userId]);
    // 卡密归还（置为未使用，保留卡密本身）
    Database::exec(
        'UPDATE ' . Database::t('cards') . ' SET status = 0, used_by = 0, used_at = 0, used_ip = NULL WHERE used_by = ?',
        [$userId]
    );
    // 删除用户
    Database::exec('DELETE FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
    Database::commit();

    Audit::log($admin, 'user_delete', "用户#{$userId} {$user['username']}",
        "删除用户 {$user['username']}",
        [
            'username'    => $user['username'],
            'nickname'    => (string) ($user['nickname'] ?? ''),
            'email'       => (string) ($user['email'] ?? ''),
            'vip_expire'  => (int) $user['vip_expire'],
            'points'      => (int) $user['points'],
        ],
        []);

    Response::ok(null, '用户已删除');
} catch (Throwable $e) {
    Database::rollback();
    Response::error(9999, '删除失败：' . $e->getMessage());
}
