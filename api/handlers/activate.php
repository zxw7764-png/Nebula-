<?php
/**
 * action: activate
 * 激活卡密
 * 参数: token(可选), machine_id, code
 *      未登录状态下可凭 username + code 激活（自动注册流程可另接 register）
 */

$token     = Util::str($requestData, 'token', '');
$machineId = Util::str($requestData, 'machine_id', '');
$code      = Util::str($requestData, 'code', '');

if ($code === '') {
    Response::error(1001, '请输入激活码');
}

// 激活限流：单 IP 每分钟 20 次
if (!RateLimit::hit('activate:' . Util::ip(), 20, 60)) {
    Response::error(5001, '操作过于频繁，请稍后再试');
}

// ------------------------------------------------------------------
// 卡密枚举检测（按来源 IP）
// 单卡限流挡不住「每次换一个卡号」的随机枚举，这里按来源累计
// 「卡密不存在」的次数，超过阈值即在该窗口内直接拒绝。
// 计数在下方确认卡密不存在后自增（见 missKey）。
// ------------------------------------------------------------------
$missKey    = RateLimit::missKey('activate');
$missLimit  = (int) Config::get('policy.card_miss_limit', 30);
$missWindow = (int) Config::get('policy.card_miss_window', 600);
if ($missLimit > 0 && $missWindow > 0 && RateLimit::count($missKey, $missWindow) >= $missLimit) {
    Logger::log('activate', 0, '卡密枚举尝试过多，来源已临时封禁', [
        'raw' => ['code' => Util::maskCard($code)],
    ]);
    Response::error(5001, '尝试次数过多，请稍后再试');
}

// ------------------------------------------------------------------
// 单卡维度限流：同一张卡密被反复尝试（含多账号轮番尝试同一卡）
// ------------------------------------------------------------------
$cardLimit  = (int) Config::get('policy.card_try_limit', 8);
$cardWindow = (int) Config::get('policy.card_try_window', 600);
if ($cardLimit > 0 && !RateLimit::byCard($code, 'activate', $cardLimit, $cardWindow)) {
    Logger::log('activate', 0, '同一卡密尝试过于频繁', [
        'raw' => ['code' => Util::maskCard($code)],
    ]);
    Response::error(5001, '该激活码尝试次数过多，请稍后再试');
}

// 获取用户
$user = null;
if ($token !== '') {
    $v = Session::validate($token, $machineId ?: null, true);
    if (!$v['ok']) {
        Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
    }
    $user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [(int) $v['session']['user_id']]);
} else {
    // 未登录时用用户名 + 密码激活（可选流程）
    $username = Util::str($requestData, 'username', '');
    $password = (string) Util::get($requestData, 'password', '');
    if ($username === '' || $password === '') {
        Response::error(1002, '请先登录或提供账号密码');
    }
    $r = Auth::login($username, $password);
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }
    $user = $r['user'];
}

if (!$user) {
    Response::error(1002, '账号不存在');
}
if ((int) $user['status'] !== 1) {
    Response::error(2002, '账号已被封禁，无法激活');
}

$r = Card::activate($requestData, $user, Software::currentId());

// 卡密不存在 → 计入枚举检测（只累计失败命中，不累计正常业务）
if (!$r['ok'] && (int) $r['code'] === 3001 && $missLimit > 0 && $missWindow > 0) {
    RateLimit::incr($missKey, $missWindow);
}

Logger::log('activate', $r['ok'] ? 1 : 0, $r['msg'], [
    'user_id'    => $user['id'],
    'username'   => $user['username'],
    'machine_id' => $machineId,
    'raw'        => ['code' => Util::maskCard($code)],
]);

if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Response::ok($r['data'], $r['msg']);
