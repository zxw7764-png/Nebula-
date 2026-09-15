<?php
/**
 * admin action: logout
 */

$token = SessionCookie::fromRequest($input);
if ($token) {
    AdminAuth::logout((string) $token);
    Logger::log('admin_logout', 1, '管理员退出', ['admin_id' => (int) $admin['id'], 'username' => $admin['username']]);
}

// 清除会话 Cookie（P1-09）：否则浏览器会继续带着已失效的令牌
SessionCookie::clear();

Response::ok(null, '已退出');
