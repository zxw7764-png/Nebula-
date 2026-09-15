<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: login
 * 代理商登录
 */

$username = Util::str($input, 'username', '');
$password = (string) Util::get($input, 'password', '');

if ($username === '' || $password === '') {
    Response::error(1001, '请输入账号和密码');
}
if (!RateLimit::hit('agentlogin:' . Util::ip(), 10, 60)) {
    Response::error(5001, '登录尝试过于频繁，请稍后再试');
}
if (!Captcha::verify(Util::str($input, 'captcha', ''))) {
    Logger::log('agent_login', 0, '登录失败：验证码不正确', ['username' => $username]);
    Response::error(1002, '验证码不正确');
}
Captcha::clear();

$r = Agent::login($username, $password);
if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Response::ok($r['data'], '登录成功');
