<?php
/**
 * action: userinfo
 * 获取当前登录用户信息
 * 参数: token, machine_id（强制设备绑定校验，2026-10-03 审计）
 */

$token = Util::str($requestData, 'token', '');
$v = Session::validate($token, Util::str($requestData, 'machine_id', '') ?: null, true);
if (!$v['ok']) {
    Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
}

$userId = (int) $v['session']['user_id'];
$user   = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1002, '账号不存在');
}

$vip    = Auth::checkVip($user);
$expire = (int) $user['vip_expire'];
$remain = $expire === -1 ? -1 : ($expire > 0 ? max(0, $expire - time()) : 0);

$group = Auth::group((int) $user['group_id']);

Response::ok([
    'user'          => Auth::publicInfo($user),
    'group_name'    => $group['name'] ?? '默认用户组',
    'vip'           => $vip,
    'remain'        => $remain,
    'remain_text'   => $remain === -1 ? '永久' : Util::duration($remain),
    'register_time' => Util::date((int) $user['created_at']),
    'last_login'    => Util::date((int) $user['last_login_time']),
    'device'        => [
        'max_devices' => Auth::maxDevices($user),
        'bound_count' => Device::activeCount($userId),
    ],
]);
