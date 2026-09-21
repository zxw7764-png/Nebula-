<?php
/**
 * Nebula Menu · 发卡网接口
 * 路由: /shop/api.php?action=xxx
 *
 *   info    商品与支付配置（公开，无需登录）
 *   order   创建订单（auto: 返回易支付跳转地址 / manual: 返回收款码与订单号）
 *   query   买家凭订单号查询状态与卡密（公开；订单号随机不可枚举，另配限流）
 *
 * 复用官网引导 portal.php：同一会话 Cookie（NBWEBSID）与 CSRF 令牌体系。
 * 统一响应：{ code: 0, msg: 'ok', data: {...} }
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/../web/inc/portal.php';

$input  = Util::input();
$rawAction = $_GET['action'] ?? ($input['action'] ?? '');
$action = is_scalar($rawAction) ? preg_replace('/[^a-z_]/', '', strtolower((string) $rawAction)) : '';

if ($action === '') {
    Response::error(1001, '缺少 action 参数');
}

// 全局限流（与官网 api 同款口径）
if (!RateLimit::hit('shop:' . Util::ip(), 240, 60)) {
    Response::error(5001, '操作过于频繁，请稍后再试');
}

// 只读接口：免 CSRF
$readOnly = ['info', 'query', 'account', 'captcha', 'game_top'];

// ------------------------------------------------------------------
// 人机风控（防自动化 / 防逆向）
// 详见 web/api.php 同名段落：前端 guard.js 上报 _g 信号，这里评分处置。
// 发卡网是「下单 → 出卡」的直接变现链路，是刷单与爬价的重灾区，
// 因此除拒绝自动化外，可疑请求还会把下单限流从 30/时 收紧到 5/时。
// ------------------------------------------------------------------
// 已进入封禁状态：快速拒绝（验证码接口除外，理由同 web/api.php）
if ($action !== 'captcha' && Guard::isBanned()) {
    Response::error(1008, '检测到异常访问，已被临时限制，请稍后再试');
}

$guard = Guard::assess('shop:' . $action, $input, ['challenge' => false]);
if ($guard['block']) {
    $banned = Guard::punish('shop:' . $action, $guard['score']);
    Logger::log('shop_' . $action, 0, '风控拦截：' . implode(',', $guard['reasons']), ['ip' => Util::ip()]);
    Response::error($banned ? 1008 : 1007, '检测到自动化访问，请求已被拒绝');
}

if (!in_array($action, $readOnly, true)) {
    $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($input['csrf'] ?? '');
    if (!Util::csrfCheck(is_string($csrf) ? $csrf : null)) {
        Response::error(1006, '页面校验已失效，请刷新页面后重试');
    }
}

switch ($action) {

    // ---------------------------------------------------------------
    // 商品与支付配置（公开）
    // ---------------------------------------------------------------
    case 'info':
        Response::ok([
            'enabled'  => Shop::enabled() && Shop::mode() === 'built',
            'pay_mode' => Shop::payMode(),
            'manual'   => Shop::manualInfo(),
            'plans'    => Shop::shopPlans(),
        ]);
        break;

    // ---------------------------------------------------------------
    // 创建订单
    // auto 模式：返回 pay_url，前端直接跳转易支付收银台
    // manual 模式：返回收款码 / 联系方式，买家转账后等管理员确认
    // ---------------------------------------------------------------
    case 'order':
        if (!Shop::enabled() || Shop::mode() !== 'built') {
            Response::error(4001, '发卡网未开启');
        }

        // 下单频控：单 IP 每小时 30 单，防刷单占库存；
        // 人机风控判定为可疑时收紧到 5 单（自动化脚本通常成批下单）
        if (!RateLimit::hit('shoporder:' . Util::ip(), Guard::suspicious() ? 5 : 30, 3600)) {
            Response::error(5001, '下单过于频繁，请稍后再试');
        }

        $planId   = (int) Util::get($input, 'plan_id', 0);
        $contact  = Util::str($input, 'contact', '');
        $queryPwd = Util::str($input, 'query_pwd', '');
        $channel  = Util::str($input, 'channel', 'alipay');
        $qty      = (int) Util::get($input, 'qty', 1);
        $specIdx  = Util::get($input, 'spec_index', -1);
        $specIdx  = is_numeric($specIdx) ? (int) $specIdx : -1;
        $openid   = Util::str($input, 'openid', '');

        // 支付完成后的回跳/回调地址：优先用后台「发卡网配置 → 站点地址」，
        // 防止 Host 头伪造篡改 return_url / notify_url；未配置时按当前请求生成
        $configured = trim((string) Setting::get('shop_site_url', ''));
        if ($configured !== '' && preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', rtrim($configured, '/'))) {
            $base = rtrim($configured, '/');
        } else {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === '' || !preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
                Response::error(5000, '无法确定站点地址，请联系管理员');
            }
            $base = $scheme . '://' . $host;
        }

        $me = ShopAuth::user();
        $r = Shop::createOrder($planId, $contact, $queryPwd, $channel, $base, $base, Util::ip(), $qty,
            $me ? (int) $me['id'] : 0, $specIdx, trim($openid));

        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Response::ok($r['data'], $r['data']['pay_type'] === Shop::PAY_EPAY ? '订单已创建，正在跳转支付' : '订单已创建，请按页面提示完成支付');
        break;

    // ---------------------------------------------------------------
    // 登录 / 注册 / 退出（与验证系统共用 nb_users）
    // ---------------------------------------------------------------
    case 'auth':
        $op = Util::str($input, 'op', '');
        // 维护模式下禁止登录 / 注册（退出不受影响）
        if (Setting::bool('maintain_mode') && ($op === 'login' || $op === 'register')) {
            Response::error(6002, Setting::get('maintain_msg', '服务器维护中，请稍后再试'));
        }
        if ($op === 'logout') {
            ShopAuth::logout();
            Response::ok([], '已退出登录');
        }
        if (!RateLimit::hit('shopauth:' . Util::ip(), 10, 60)) {
            Response::error(5001, '尝试过于频繁，请稍后再试');
        }
        // 登录 / 注册均需图形验证码（防爆破与批量注册）
        if ($op === 'login' || $op === 'register') {
            if (!Captcha::verify(Util::str($input, 'captcha', ''))) {
                Response::error(1002, '验证码不正确');
            }
            Captcha::clear();
        }
        if ($op === 'login') {
            // 卡密类登录方式（用户名+激活码 / 纯激活码）：复用 Auth::loginBy 的
            // 「已绑定直接登录 / 未绑定自动建号并激活」全套逻辑，激活即到账
            if (LoginMethod::isCard()) {
                $r = Auth::loginBy($input);
                if (!$r['ok']) {
                    Response::error($r['code'] ?: 1001, $r['msg']);
                }
                ShopAuth::adoptUid((int) $r['user']['id']);
                Response::ok(
                    ['user' => ShopAuth::user(), 'created' => !empty($r['created'])],
                    !empty($r['created']) ? '登录成功，账号已开通并自动激活' : ($r['msg'] ?: '登录成功')
                );
            }
            $r = ShopAuth::login(Util::str($input, 'username', ''), Util::str($input, 'password', ''));
            if (!$r['ok']) { Response::error(1001, $r['msg']); }
            Response::ok(['user' => ShopAuth::user()], $r['msg']);
        }
        if ($op === 'register') {
            // 卡密类登录方式下没有「注册」概念：输入（未绑定的）激活码即开通账号
            if (LoginMethod::isCard()) {
                Response::error(1001, '当前登录方式无需注册，输入激活码即可开通账号');
            }
            $r = ShopAuth::register(
                Util::str($input, 'username', ''),
                Util::str($input, 'password', ''),
                Util::str($input, 'email', '')
            );
            if (!$r['ok']) { Response::error(1001, $r['msg']); }
            ShopAuth::login(Util::str($input, 'username', ''), Util::str($input, 'password', ''));
            Response::ok(['user' => ShopAuth::user()], $r['msg']);
        }
        Response::error(1001, '未知操作');
        break;

    // ---------------------------------------------------------------
    // 图形验证码（激活码找回密码用，与官网同款 session 机制）
    // ---------------------------------------------------------------
    case 'captcha':
        if (!RateLimit::hit('shopcaptcha:' . Util::ip(), 60, 60)) {
            Captcha::renderBusy();
            break;
        }
        Captcha::render();
        break;

    // ---------------------------------------------------------------
    // 激活码找回：第一步 查询激活码绑定的用户名
    // 前提：后台「激活码找回密码」开关开启；仅支持已使用（已绑定账号）的卡
    // ---------------------------------------------------------------
    case 'reclaim_lookup':
        if (!Setting::bool('login_reclaim_enable')) {
            Response::error(4003, '该功能未开启');
        }
        if (!RateLimit::hit('reclaim:' . Util::ip(), 5, 3600)) {
            Response::error(5001, '尝试过于频繁，请 1 小时后再试');
        }
        $r = ShopAuth::reclaimLookup(Util::str($input, 'code', ''));
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Response::ok(['username' => $r['username']], '已找到绑定账号');
        break;

    // ---------------------------------------------------------------
    // 激活码找回：第二步 为绑定账号设置新密码
    // 必须带图形验证码；用户名必须与激活码绑定账号一致
    // ---------------------------------------------------------------
    case 'reclaim_save':
        if (!Setting::bool('login_reclaim_enable')) {
            Response::error(4003, '该功能未开启');
        }
        if (!RateLimit::hit('reclaim:' . Util::ip(), 5, 3600)) {
            Response::error(5001, '尝试过于频繁，请 1 小时后再试');
        }
        if (!Captcha::verify(Util::str($input, 'captcha', ''))) {
            Response::error(1002, '验证码不正确');
        }
        Captcha::clear();
        $r = ShopAuth::reclaimSave(
            Util::str($input, 'code', ''),
            Util::str($input, 'username', ''),
            Util::str($input, 'password', ''),
            Util::str($input, 'password2', '')
        );
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Response::ok([], '新密码已设置，请使用用户名和新密码登录');
        break;

    // ---------------------------------------------------------------
    // 个人中心：我的订单（含卡密激活状态）+ 已激活卡密
    // ---------------------------------------------------------------
    case 'account':
        $me = ShopAuth::user();
        if (!$me) {
            Response::error(4010, '请先登录');
        }
        $uid = (int) $me['id'];
        $orders = Database::all(
            'SELECT order_no, plan_id, software_id, plan_name, amount, qty, pay_type, status, card_code, created_at
             FROM ' . Database::t('shop_orders') . '
             WHERE user_id = ? ORDER BY id DESC LIMIT 30',
            [$uid]
        );
        $cardsTable = Database::t('cards');

        // 待支付易支付单附「继续支付」收银台地址（与订单查询同源的站点地址解析）
        $repayBase = '';
        $configured = trim((string) Setting::get('shop_site_url', ''));
        if ($configured !== '' && preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', rtrim($configured, '/'))) {
            $repayBase = rtrim($configured, '/');
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            if ($host !== '' && preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
                $repayBase = $scheme . '://' . $host;
            }
        }

        $out = [];
        foreach ($orders as $o) {
            $codes = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $o['card_code']) as $c) {
                $c = trim($c);
                if ($c === '') { continue; }
                $card = Database::one(
                    "SELECT status FROM {$cardsTable} WHERE code = ? LIMIT 1",
                    [$c]
                );
                $codes[] = ['code' => $c, 'activated' => $card && (int) $card['status'] === 1];
            }
            // 待支付 + 易支付自动单才有「继续支付」地址
            $notice = '';
            $st = (int) $o['status'];
            if ($st === Shop::ORDER_DELIVERED || $st === Shop::ORDER_MANUAL) {
                $notice = Shop::planNotice((int) $o['plan_id'], (int) ($o['software_id'] ?? 0));
            }
            $out[] = [
                'order_no'   => $o['order_no'],
                'plan_name'  => $o['plan_name'],
                'amount'     => number_format(((int) $o['amount']) / 100, 2, '.', ''),
                'qty'        => (int) $o['qty'],
                'status'     => (int) $o['status'],
                'status_text'=> Shop::orderStatusText((int) $o['status']),
                'created_at' => date('Y-m-d H:i', (int) $o['created_at']),
                'pay_url'    => ($repayBase !== '' && (int) $o['status'] === Shop::ORDER_PENDING && (int) $o['pay_type'] === Shop::PAY_EPAY)
                    ? Shop::repayUrl((string) $o['order_no'], $repayBase)
                    : '',
                'codes'      => $codes,
                'notice'     => $notice,
            ];
        }
        $activated = Database::all(
            "SELECT code, type, duration, status, used_at
             FROM {$cardsTable} WHERE used_by = ? AND status = 1
             ORDER BY used_at DESC LIMIT 50",
            [$uid]
        );
        $activated = array_map(function (array $c) {
            return [
                'code'     => (string) $c['code'],
                'type'     => (int) $c['type'],
                'duration' => (int) $c['duration'],
                'used_at'  => date('Y-m-d H:i', (int) $c['used_at']),
            ];
        }, $activated);
        Response::ok(['user' => $me, 'orders' => $out, 'activated' => $activated]);
        break;

    // ---------------------------------------------------------------
    // 激活卡密：与官网 activate 同口径（登录用户、冷却期、频控一致）
    // ---------------------------------------------------------------
    case 'activate':
        $me = ShopAuth::user();
        if (!$me) {
            Response::error(4010, '请先登录后激活');
        }
        // Card::activate 需要完整用户行（vip_expire / points / max_devices / group_id 等）
        $me = Database::one(
            'SELECT * FROM ' . Database::t('users') . ' WHERE id = ?',
            [(int) $me['id']]
        );
        if (!$me || (int) $me['status'] !== 1) {
            Response::error(4010, '登录状态已失效，请重新登录');
        }

        // 新账号行为冷却期：与官网一致，防批量注册抢活动资源
        $cooldown = max(0, (int) Setting::get('web_act_cooldown_min', 10)) * 60;
        if ($cooldown > 0 && time() - (int) ($me['created_at'] ?? 0) < $cooldown) {
            Response::error(5001, '新注册账号需等待 ' . (int) ($cooldown / 60) . ' 分钟后才能激活卡密');
        }

        $code = strtoupper(trim(Util::str($input, 'code', '')));
        if ($code === '') {
            Response::error(1001, '请选择要激活的卡密');
        }
        // 激活码尝试频控：防卡密枚举（与官网同档）
        $actMax = max(1, (int) Setting::get('web_act_max_min', 20));
        if (!RateLimit::hit('shopact:' . Util::ip(), $actMax, 60)) {
            Response::error(5001, '操作过于频繁，请稍后再试');
        }

        $r = Card::activate(['code' => $code], $me);
        Logger::log('shop_activate', $r['ok'] ? 1 : 0, $r['msg'], [
            'user_id' => (int) $me['id'],
            'raw'     => ['code' => Util::maskCard($code)],
        ]);
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        // 发卡订单归属补绑：游客下单 → 激活时开通的账号自动接管订单（个人中心可见）
        Shop::bindOrderByCard($code, (int) $me['id']);
        Response::ok([], $r['msg']);
        break;

    // ---------------------------------------------------------------
    // 订单查询：凭订单号（随机 19 位，不可枚举）
    // 已发卡订单返回卡密——订单号即取货凭证，提醒买家妥善保存
    // ---------------------------------------------------------------
    case 'query':
        // 查询频控 + 枚举防护
        if (!RateLimit::hit('shopquery:' . Util::ip(), 20, 60)) {
            Response::error(5001, '查询过于频繁，请稍后再试');
        }

        $orderNo  = strtoupper(trim(Util::str($input, 'order_no', '')));
        $qContact = trim(Util::str($input, 'contact', ''));
        $qPwd     = Util::str($input, 'query_pwd', '');

        // 待支付自动单附「继续支付」收银台地址（与下单同源的站点地址解析）
        $repayBase = '';
        $configured = trim((string) Setting::get('shop_site_url', ''));
        if ($configured !== '' && preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', rtrim($configured, '/'))) {
            $repayBase = rtrim($configured, '/');
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            if ($host !== '' && preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
                $repayBase = $scheme . '://' . $host;
            }
        }
        $attachRepay = static function (array &$row) use ($repayBase): void {
            $row['pay_url'] = ($repayBase !== '' && (int) $row['status'] === 0)
                ? Shop::repayUrl((string) $row['order_no'], $repayBase)
                : '';
        };

        // 凭证查询：手机号/邮箱/自定义内容 + 查询密码（后台 shop_contact_mode 决定形态）
        if ($orderNo === '' && $qContact !== '') {
            $list = Shop::queryByContact($qContact, $qPwd);
            if (!$list) {
                // 凭证+密码不匹配也计数，防枚举撞库
                RateLimit::incr('shopmiss:' . Util::ip(), 600);
                if (RateLimit::count('shopmiss:' . Util::ip(), 600) >= 40) {
                    Logger::log('shop_query', 0, '凭证查询疑似撞库，临时封禁查询', ['ip' => Util::ip()]);
                    Response::error(5001, '查询过于频繁，请稍后再试');
                }
                Response::error(4004, '未找到匹配的订单，请核对凭证与查询密码');
            }
            foreach ($list as &$row) { $attachRepay($row); }
            unset($row);
            Response::ok(['mode' => 'contact', 'list' => $list]);
        }

        $o = Shop::queryByOrderNo($orderNo);
        if (!$o) {
            // 「订单不存在」计数：同 IP 短时间内大量 miss 视为在扫订单号
            if (RateLimit::count('shopmiss:' . Util::ip(), 600) >= 40) {
                Logger::log('shop_query', 0, '订单号枚举嫌疑，临时封禁查询', ['ip' => Util::ip()]);
                Response::error(5001, '查询过于频繁，请稍后再试');
            }
            RateLimit::incr('shopmiss:' . Util::ip(), 600);
            Response::error(4004, '订单不存在，请核对订单号');
        }

        $attachRepay($o);
        Response::ok($o);
        break;

    // ---------------------------------------------------------------
    // 用户自助关闭待支付订单
    // 登录用户可关自己名下的单；未登录游客凭订单号关闭（订单号即凭证，
    // 19 位随机不可枚举，与订单查询同级风险），仅限 user_id=0 的游客单
    // ---------------------------------------------------------------
    case 'order_close':
        if (!RateLimit::hit('shopclose:' . Util::ip(), 10, 60)) {
            Response::error(5001, '操作过于频繁，请稍后再试');
        }
        $orderNo = strtoupper(trim(Util::str($input, 'order_no', '')));
        if ($orderNo === '') {
            Response::error(1001, '缺少订单号');
        }
        $order = Database::one(
            'SELECT id, user_id, status FROM ' . Database::t('shop_orders') . ' WHERE order_no = ? LIMIT 1',
            [$orderNo]
        );
        if (!$order) {
            Response::error(4004, '订单不存在');
        }
        $me  = ShopAuth::user();
        $uid = $me ? (int) $me['id'] : 0;
        if ($uid > 0) {
            if ((int) $order['user_id'] !== $uid) {
                Response::error(4004, '订单不存在');
            }
        } elseif ((int) $order['user_id'] !== 0) {
            Response::error(4010, '该订单已关联账号，请登录后操作');
        }
        if ((int) $order['status'] !== Shop::ORDER_PENDING) {
            Response::error(4005, '订单当前状态不允许关闭');
        }
        $n = Database::update(
            'shop_orders',
            ['status' => Shop::ORDER_CLOSED, 'remark' => '用户自助关闭'],
            'id = :id AND status = :s0',
            ['id' => (int) $order['id'], 's0' => Shop::ORDER_PENDING]
        );
        if ($n !== 1) {
            Response::error(4005, '订单状态已变更，请刷新后重试');
        }
        Logger::log('shop_close', $uid, '用户自助关闭订单 ' . $orderNo);
        Response::ok(['order_no' => $orderNo], '订单已关闭');
        break;

    // ---------------------------------------------------------------
    // 小游戏排行榜（与官网共用一套 nb_game_scores 表）
    // ---------------------------------------------------------------
    case 'game_top':
        // game_top 是 GET 请求，参数在查询串里（Util::input 只解析请求体，不读 $_GET）
        // 白名单 = 内置游戏 + 各端模板 id（模板自带小游戏按文件夹名分榜，UiTemplate 已限 id 字符集）
        $g = strtolower(trim((string) ($_GET['game'] ?? Util::str($input, 'game', ''))));
        if (!in_array($g, array_merge(['farm', 'mario', 'ink', 'space'], UiTemplate::ids('web'), UiTemplate::ids('shop')), true)) {
            Response::error(1001, '未知的游戏');
        }
        $topN = max(20, min(50, (int) Setting::get('game_top_n', 10))); // 后台「榜单显示条数」，接口至少返回 20 供浮层取用
        $rows = Database::all(
            'SELECT name, score, created_at FROM ' . Database::t('game_scores') . '
             WHERE game = ? ORDER BY score DESC, id ASC LIMIT ' . $topN,
            [$g]
        );
        Response::ok(['list' => array_map(function (array $r) {
            return [
                'name'       => (string) $r['name'],
                'score'      => (int) $r['score'],
                'created_at' => (int) $r['created_at'],
            ];
        }, $rows)]);
        break;

    case 'game_score_save':
        if (!Setting::bool('shop_games_enabled', true)) {
            Response::error(6002, '小游戏已关闭');
        }
        if (!RateLimit::hit('gamescore:' . Util::ip(), max(1, min(60, (int) Setting::get('game_rate_limit', 10))), 60)) {
            Response::error(5001, '提交过于频繁，请稍后再试');
        }
        $g = strtolower(trim(Util::str($input, 'game', '')));
        if (!in_array($g, array_merge(['farm', 'mario', 'ink', 'space'], UiTemplate::ids('web'), UiTemplate::ids('shop')), true)) {
            Response::error(1001, '未知的游戏');
        }
        $name  = trim(Util::str($input, 'name', ''));
        $score = (int) ($input['score'] ?? 0);
        if ($name === '') {
            $name = ['farm' => '匿名农夫', 'mario' => '匿名水管工', 'ink' => '匿名散仙', 'space' => '匿名领航员'][$g] ?? '匿名玩家';
        }
        $name = mb_substr($name, 0, 16, 'UTF-8');
        if ($score <= 0 || $score > 999999) {
            Response::error(1001, '分数无效');
        }
        Database::insert('game_scores', [
            'game'       => $g,
            'name'       => $name,
            'score'      => $score,
            'ip'         => Util::ip(),
            'created_at' => time(),
        ]);
        Response::ok([], '成绩已上榜');
        break;

    default:
        Response::error(1001, '未知的接口: ' . $action);
}
