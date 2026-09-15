<?php
/**
 * 代理商后台 · 统一接口入口
 * 路由: /agent/api.php?action=xxx
 *
 * 认证方式: 请求头 X-Token 或 body.token（与主管理后台完全独立的一套会话表）
 *
 * 可用 action：
 *   login          登录
 *   register       凭主管理员生成的激活码自助注册
 *   logout         退出
 *   profile        我的资料 + 各类型额度/余额 + 发货统计
 *   dashboard      概览（与 profile 同源，供首页使用）
 *   stats          数据分析（生成/激活趋势、卡类型占比、概况）
 *   card_generate  生成卡密（按该卡类型的额度/单价扣减）
 *   card_list      我生成的卡密
 *   card_export    导出我的卡密
 *   card_void      作废我名下未使用的卡密
 *   batch_list     我的批次
 *   password       修改登录密码
 *
 * 安全说明：
 *   1. 代理商后台默认关闭（后台「系统设置 → 代理商设置」），关闭时任何接口都拒绝
 *   2. 可选的入口密钥（Setting: agent_entry_key），未携带时返回仿真 404
 *   3. 所有写操作要求 CSRF（与页面同源会话）；注册另加独立限流
 *   4. 卡密的设备上限、激活用户组、各类型额度单价由主管理员固定
 *      （后台创建或激活码预设），代理提交的同名字段一律忽略
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 会话引导（与页面入口共用同一套 Cookie 名，CSRF 校验依赖它）
define('NB_AGENT_ENTRY', true);
require_once __DIR__ . '/inc/session.php';
nb_agent_session_start();

// ------------------------------------------------------------------
// 先读请求体再决定编码方式（必须在任何 Response::error 之前）
// ------------------------------------------------------------------
$input = Util::input();
Response::setEncrypt(false);

// ------------------------------------------------------------------
// 入口密钥（可选）
// ------------------------------------------------------------------
$entryKey = (string) Setting::get('agent_entry_key', '');
if ($entryKey !== '') {
    $expect = hash('sha256', $entryKey . '|' . Util::ip());
    $cookie = $_COOKIE['nb_agent_entry'] ?? '';
    if (!hash_equals($expect, $cookie)) {
        fake_404_exit();
    }
}

// ------------------------------------------------------------------
// 代理商功能总开关
// ------------------------------------------------------------------
if (!Agent::enabled()) {
    Response::error(6002, '代理商后台暂未开放');
}

// ------------------------------------------------------------------
// 路由
// ------------------------------------------------------------------
$action = $_GET['action'] ?? ($input['action'] ?? '');
$action = preg_replace('/[^a-z_]/', '', strtolower((string) $action));
if ($action === '') {
    Response::error(1001, '缺少 action 参数');
}

// 图形验证码：GET 输出图片，登录/注册前拉取；IP 限流
if ($action === 'captcha' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!RateLimit::hit('agentcaptcha:' . Util::ip(), 60, 60)) {
        Captcha::renderBusy();
        exit;
    }
    Captcha::render();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    Response::error(1001, '请使用 POST 请求');
}

// ------------------------------------------------------------------
// 限流
// ------------------------------------------------------------------
if (!RateLimit::hit('agent:' . Util::ip(), 240, 60)) {
    Response::error(5001, '操作过于频繁，请稍后再试');
}

// ------------------------------------------------------------------
// 人机风控（防自动化 / 防逆向）
// 代理端持有卡密生成额度，被脚本接管会直接放大成「批量出货」，
// 因此这里比官网更严：判定为自动化直接拒绝，可疑则写接口收紧限流。
// 说明详见 web/api.php 同名段落。
// ------------------------------------------------------------------
if (Guard::isBanned()) {
    Response::error(1008, '检测到异常访问，已被临时限制，请稍后再试');
}

$guard = Guard::assess('agent:' . $action, $input, ['challenge' => false]);
if ($guard['block']) {
    $banned = Guard::punish('agent:' . $action, $guard['score']);
    Logger::log('agent_' . $action, 0, '风控拦截：' . implode(',', $guard['reasons']), ['ip' => Util::ip()]);
    Response::error($banned ? 1008 : 1007, '检测到自动化访问，请求已被拒绝');
}

// ------------------------------------------------------------------
// 公开接口
// ------------------------------------------------------------------
$publicActions = ['login', 'ping', 'register'];
$token = $_SERVER['HTTP_X_TOKEN'] ?? ($input['token'] ?? '');
$token = is_string($token) ? trim($token) : '';

if (!in_array($action, $publicActions, true) && Guard::suspicious()) {
    if (!RateLimit::hit('agentsus:' . Util::ip(), 30, 60)) {
        Response::error(5001, '操作过于频繁，请稍后再试');
    }
}

$agent = null;
if (!in_array($action, $publicActions, true)) {
    if ($token === '') {
        Response::error(1002, '请先登录');
    }
    // 会话密钥（P0-02）：新会话必须同时提供 token 与 session_key
    $sk = $_SERVER['HTTP_X_SESSION_KEY'] ?? ($input['session_key'] ?? '');
    $agent = Agent::check($token, is_string($sk) && $sk !== '' ? $sk : null);
    if (!$agent) {
        Response::error(1003, '登录已过期，请重新登录');
    }
}

// ------------------------------------------------------------------
// CSRF（只读接口豁免）
// ------------------------------------------------------------------
$csrfExempt = [
    'login', 'ping', 'register',
    'dashboard', 'profile', 'card_list', 'batch_list', 'stats',
];
if (!in_array($action, $csrfExempt, true)) {
    $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($input['csrf'] ?? '');
    if (!Util::csrfCheck(is_string($csrf) ? $csrf : null)) {
        Response::error(1006, '请求校验失败（CSRF），请刷新页面后重试');
    }
}

// ------------------------------------------------------------------
// 分发
// ------------------------------------------------------------------
try {
    $handler = __DIR__ . '/handlers/' . $action . '.php';
    if (!is_file($handler)) {
        Response::error(1001, '未知的接口: ' . $action);
    }
    require $handler;
} catch (Throwable $e) {
    Logger::log('agent_' . $action, 0, $e->getMessage());
    if (Config::get('debug')) {
        Response::error(9999, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    Response::error(9999, '服务器内部错误');
}
