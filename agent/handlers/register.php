<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: register
 * 代理商凭激活码自助注册
 * ------------------------------------------------------------------
 * 激活码由主管理员在后台「代理商激活码」生成，码上已经写死了：
 *   激活后进入的用户组 / 卡密设备上限 / 是否可作废 / 控量模式 / 各卡类型额度与单价
 * 注册成功后这些规格直接落到代理档案，代理商登录后无法自改。
 *
 * 与本文件同时启用的还有：agent_register_enable 开关、独立注册限流。
 */

// 注册限流：同一 IP 10 分钟最多 5 次（防止暴力猜激活码）
if (!RateLimit::hit('agentreg:' . Util::ip(), 5, 600)) {
    Response::error(5001, '注册尝试过于频繁，请 10 分钟后再试');
}
if (!Captcha::verify(Util::str($input, 'captcha', ''))) {
    Response::error(1002, '验证码不正确');
}
Captcha::clear();

// ------------------------------------------------------------------
// 激活码维度：同一张代理激活码反复尝试 + 来源枚举检测
// 代理商激活码带「可用次数」，被猜中即等于被注册走，故与客户端卡密同规格防护。
// ------------------------------------------------------------------
$regCode    = Util::str($input, 'code', '');
$missKey    = RateLimit::missKey('agentreg');
$missLimit  = (int) Config::get('policy.card_miss_limit', 30);
$missWindow = (int) Config::get('policy.card_miss_window', 600);
if ($regCode !== '') {
    if ($missLimit > 0 && $missWindow > 0 && RateLimit::count($missKey, $missWindow) >= $missLimit) {
        Response::error(5001, '尝试次数过多，请稍后再试');
    }
    $cardLimit  = (int) Config::get('policy.card_try_limit', 8);
    $cardWindow = (int) Config::get('policy.card_try_window', 600);
    if ($cardLimit > 0 && !RateLimit::byCard($regCode, 'agentreg', $cardLimit, $cardWindow)) {
        Response::error(5001, '该激活码尝试次数过多，请稍后再试');
    }
}

$r = Agent::register($input);

// 激活码无效 → 计入枚举检测
if (!$r['ok'] && (int) $r['code'] === 2005 && $regCode !== '' && $missLimit > 0 && $missWindow > 0) {
    RateLimit::incr($missKey, $missWindow);
}

if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

// 注册成功后允许前端立刻用同一凭据登录（前端会自行调 login）
Response::ok([
    'id'         => $r['data']['id'],
    'username'   => $r['data']['username'],
    'can_login'  => true,
], $r['msg']);
