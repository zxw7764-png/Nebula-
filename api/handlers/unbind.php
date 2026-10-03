<?php
/**
 * action: unbind
 * 解绑当前设备或指定设备
 * 参数: token, machine_id(可选，不传则解绑当前令牌对应设备)
 *       password(可选，解绑需二次验证密码)
 */

$token     = Util::str($requestData, 'token', '');
$machineId = Util::str($requestData, 'machine_id', '');
$password  = (string) Util::get($requestData, 'password', '');
$unbindAll = (bool) Util::get($requestData, 'all', false);

$v = Session::validate($token, $machineId !== '' ? $machineId : null, true);
if (!$v['ok']) {
    Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
}

$userId = (int) $v['session']['user_id'];
$user   = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1002, '账号不存在');
}

// 解绑需要密码二次确认（安全考虑）：
//  · 解绑全部设备（all=1）必须提供密码 —— 令牌泄露也不至于被一键清空设备
//  · 解绑单台设备密码可选（令牌本就代表当前会话）
//  · 提供了密码就必须正确，空密码不再绕过校验（2026-10-03 审计修复）
if ($unbindAll && $password === '') {
    Response::error(1001, '解绑全部设备需要输入密码二次确认');
}
if ($password !== '' && !Util::verifyPassword($password, (string) $user['password'])) {
    Logger::log('unbind', 0, '密码校验失败', ['user_id' => $userId]);
    Response::error(2001, '密码错误');
}

// 解绑限制：单账号每日上限（后台「系统设置 → 安全设置」可调，0 = 不限制）
$unbindPerDay = Policy::unbindPerDay();
if ($unbindPerDay > 0 && !RateLimit::hit('unbind:' . $userId, $unbindPerDay, 86400)) {
    Logger::log('unbind', 0, '今日解绑次数已用完', ['user_id' => $userId]);
    Response::error(5001, "今日解绑次数已用完（上限 {$unbindPerDay} 次/天），请明日再试或联系管理员");
}

if ($unbindAll) {
    $n = Device::unbindAll($userId, '用户主动全部解绑');
    Session::kickUser($userId);
    Logger::log('unbind', 1, "全部解绑 {$n} 台设备", ['user_id' => $userId]);
    Response::ok(['count' => $n], "已解绑 {$n} 台设备，请重新登录");
}

// 默认解绑当前设备
$target = $machineId ?: (string) ($v['session']['machine_id'] ?? '');
if ($target === '') {
    Response::error(4003, '缺少机器码');
}

$ok = Device::unbind($userId, $target, '用户主动解绑');

Logger::log('unbind', $ok ? 1 : 0, $ok ? '设备解绑成功' : '设备未找到', [
    'user_id'    => $userId,
    'machine_id' => $target,
]);

if (!$ok) {
    Response::error(4002, '未找到该设备的绑定记录');
}

// 解绑当前设备后强制下线
if ($target === ($v['session']['machine_id'] ?? null)) {
    Session::destroy($token);
}

Response::ok([
    'machine_id'  => $target,
    'bound_count' => Device::activeCount($userId),
    'devices'     => Device::listByUser($userId),
], '设备解绑成功');
