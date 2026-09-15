<?php
/**
 * Nebula Menu · 官网用户接口
 * 路由: /web/api.php?action=xxx
 *
 *   ping      站点信息 / 公告 / 登录状态
 *   register  注册（成功后直接落登录态，引导去激活）
 *   login     登录（方式由后台「系统设置 → 登录方式」单选决定：
 *             用户名+密码 / 用户名+激活码 / 激活码直登，见 lib/LoginMethod.php）
 *   logout    退出登录
 *   me        个人中心资料
 *   activate  使用激活码激活
 *   devices   设备列表（含已解绑记录）
 *   unbind    解绑设备（单台 / 全部，仅限本账号自己的设备）
 *   notice    公告列表
 *   online    在线人数（公开，无需登录）
 *   download  客户端下载地址（公开，无需登录）
 *   plan      价格套餐列表（公开，无需登录）
 *   seller    购买商家列表（公开，无需登录）
 *   shot      客户端截图列表（公开，无需登录）
 *   msg_list  留言板列表（公开，仅返回已审核通过的）
 *   msg_post  发布留言 / 回复（需登录，先审后显示）
 *   msg_like  留言点赞 / 取消点赞（需登录，唯一键防重复）
 *   fb_types  反馈类型选项（公开）
 *   fb_list   我的反馈列表（需登录，仅本人）
 *   fb_post   提交反馈（需登录）
 *   captcha   图形验证码（公开，IP 限流）
 *
 * 统一响应：{ code: 0, msg: 'ok', time: ..., data: {...} }
 *   code != 0 时 msg 为可直接展示给用户的文案。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/inc/portal.php';

// ------------------------------------------------------------------
// 路由
// ------------------------------------------------------------------
$input  = Util::input();
$rawAction = $_GET['action'] ?? ($input['action'] ?? '');
$action = is_scalar($rawAction) ? preg_replace('/[^a-z_]/', '', strtolower((string) $rawAction)) : '';

if ($action === '') {
    Response::error(1001, '缺少 action 参数');
}

// ------------------------------------------------------------------
// 全局限流
// ------------------------------------------------------------------
if (!RateLimit::hit('web:' . Util::ip(), 240, 60)) {
    Logger::log('web_' . $action, 0, '请求过于频繁', ['ip' => Util::ip()]);
    Response::error(5001, '操作过于频繁，请稍后再试');
}

// ------------------------------------------------------------------
// 只读公开接口：维护模式下仍放行（用于展示维护公告、在线人数、
// 下载地址、套餐与截图），且免除 CSRF 校验。
//
// 注意「只读」与「公开」是两件事：
//   · 真正无需登录的：ping / notice / online / download / plan / seller / shot / msg_list / fb_types / captcha
//   · 需要登录但仍是只读的：fb_list（只返回本人反馈）、devices
//     —— GET 读操作不该强依赖 CSRF，否则页面刷新/Safari 隐私模式等
//        会话令牌迟到场景会莫名报「页面校验已失效」。
//        它们自身已按会话鉴权 + 只返回本人数据，不存在 CSRF 可被利用的副作用。
// ------------------------------------------------------------------
$readOnly = ['ping', 'notice', 'online', 'download', 'plan', 'seller', 'shot', 'msg_list', 'fb_types', 'fb_list', 'devices', 'captcha', 'game_top'];

// ------------------------------------------------------------------
// 人机风控（防自动化 / 防逆向）
// ------------------------------------------------------------------
// 前端 assets/guard.js 采集的信号随请求体 _g 提交，这里统一评分：
//   · 判定为自动化（webdriver / 蜜罐被填 / 脚本 UA）→ 拒绝 + 累计风险分
//   · 判定为可疑 → 放行，但写接口限流阈值收紧（见下方 sus 限流）
// 无信号（老前端、禁用 JS 的浏览器、脚本直接 POST）一律放行 ——
// 风控组件自身绝不能把正常用户挡在门外，兜底交给已有的验证码与限流。
// ------------------------------------------------------------------
// 已进入封禁状态：快速拒绝，不再做完整评分（验证码接口除外 —— 它要输出
// PNG，返回 JSON 会让 <img> 直接显示成破图）
if ($action !== 'captcha' && Guard::isBanned()) {
    Response::error(1008, '检测到异常访问，已被临时限制，请稍后再试');
}

$guard = Guard::assess('web:' . $action, $input, ['challenge' => false]);
if ($guard['block']) {
    $banned = Guard::punish('web:' . $action, $guard['score']);
    Logger::log('web_' . $action, 0, '风控拦截：' . implode(',', $guard['reasons']), ['ip' => Util::ip()]);
    Response::error($banned ? 1008 : 1007, '检测到自动化访问，请求已被拒绝');
}
// 可疑请求的写接口额外收紧：240/分 → 30/分（只读接口不额外限制）
if (!in_array($action, $readOnly, true) && Guard::suspicious()) {
    if (!RateLimit::hit('websus:' . Util::ip(), 30, 60)) {
        Response::error(5001, '操作过于频繁，请稍后再试');
    }
}

// ------------------------------------------------------------------
// 维护模式
// ------------------------------------------------------------------
// ------------------------------------------------------------------
// 维护模式：分软件策略覆盖优先（该软件单独维护时只拦该软件官网的写操作）
// ------------------------------------------------------------------
if (Policy::maintainModeFor(web_current_software()) && !in_array($action, $readOnly, true)) {
    Response::error(6002, Policy::maintainMsgFor(web_current_software()));
}

// ------------------------------------------------------------------
// CSRF：除只读接口外必须带正确的令牌
// ------------------------------------------------------------------
if (!in_array($action, $readOnly, true)) {
    $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($input['csrf'] ?? '');
    if (!Util::csrfCheck(is_string($csrf) ? $csrf : null)) {
        Response::error(1006, '页面校验已失效，请刷新页面后重试');
    }
}

// ------------------------------------------------------------------
// 取当前用户的小工具（未登录直接返回 need_login）
// ------------------------------------------------------------------
$requireUser = static function (): array {
    $u = web_user();
    if (!$u) {
        Response::send(1002, '登录状态已失效，请重新登录', ['need_login' => true]);
    }
    if ((int) $u['status'] !== 1) {
        web_logout();
        Response::send(2002, '账号状态异常，请重新登录', ['need_login' => true]);
    }
    return $u;
};

// ------------------------------------------------------------------
// 分发
// ------------------------------------------------------------------
switch ($action) {

    // ---------------------------------------------------------------
    case 'ping':
        $site = web_site_info();
        $u    = web_user();
        Response::ok([
            'site'   => $site,
            'logged' => (bool) $u,
            'name'   => $u ? ($u['nickname'] ?: $u['username']) : '',
        ]);
        break;

    // ---------------------------------------------------------------
    // 公告分层（多软件）：
    //   · 未登录           → 仅「全部软件」通用公告
    //   · 已登录未激活     → 不下发任何公告（激活后才有归属软件）
    //   · 已登录已激活     → 所属软件 + 通用公告
    // ---------------------------------------------------------------
    case 'notice':
        // scope=panel：个人中心「软件公告」——客户端公告（type 2/3/4），按登录用户归属软件下发
        if (($input['scope'] ?? '') === 'panel') {
            $u = web_user();
            if ($u && (int) ($u['software_id'] ?? 0) > 0) {
                Response::ok(['list' => web_client_notices_for((int) $u['software_id']), 'need_activate' => false]);
            }
            Response::ok(['list' => [], 'need_activate' => true]);   // 未登录 / 未激活：引导先激活
        }
        // 默认：首页公告区——官网门户公告（type=1）
        $u = web_user();
        if ($u) {
            if ((int) ($u['software_id'] ?? 0) > 0) {
                Response::ok(['list' => web_notices_for((int) $u['software_id']), 'need_activate' => false]);
            } else {
                Response::ok(['list' => [], 'need_activate' => true]);
            }
        } else {
            Response::ok(['list' => web_notices_for(0), 'need_activate' => false]);
        }
        break;

    // ---------------------------------------------------------------
    // 在线人数：与客户端 /api/online、后台首页「在线会话」同一口径
    // ---------------------------------------------------------------
    case 'online':
        Response::ok(Session::onlineStat());
        break;

    // ---------------------------------------------------------------
    // 客户端下载地址：与客户端 init/version 同源（版本发布表 -> config 兜底）
    // 只认可 http/https，避免后台误填 javascript: 等伪协议被前端直接跳转
    // ---------------------------------------------------------------
    case 'download':
        // 多软件：按当前选中软件解析版本与下载地址（未选择时回落全局版本配置）
        $sw = web_current_software();
        $v  = $sw ? Software::versionInfo($sw, 'stable') : Version::latest('stable');
        $url = trim((string) $v['download_url']);
        $ok  = (bool) preg_match('#^https?://#i', $url);

        $fileName = '';
        if ($ok) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $fileName = basename($path);
            }
        }

        Response::ok([
            'configured'   => $ok,
            'software'     => $sw ? (string) $sw['name'] : '',
            'version'      => (string) $v['version'],
            'download_url' => $ok ? $url : '',
            'file_name'    => $fileName,
            'file_size'    => (int) $v['file_size'],
            'file_hash'    => (string) $v['file_hash'],
            'changelog'    => (string) $v['changelog'],
        ]);
        break;

    // ---------------------------------------------------------------
    case 'register':
        // 多软件：先解析官网当前软件，注册账号即归属该软件
        // （激活码激活时若卡密属于其他软件，Card::activate 会自动纠正绑定）
        $swCtx = web_current_software();

        $username = Util::str($input, 'username', '');
        $password = (string) Util::get($input, 'password', '');
        $confirm  = (string) Util::get($input, 'password2', '');
        $email    = Util::str($input, 'email', '');

        if ($password !== $confirm) {
            Response::error(1001, '两次输入的密码不一致');
        }
        // 图形验证码：防脚本批量注册（留言板/反馈同款机制）
        if (!Captcha::verify((string) Util::get($input, 'captcha', ''))) {
            Response::error(1001, '验证码错误或已过期');
        }
        Captcha::clear();
        // 同一 IP 注册频控：上限后台「安全设置」可调（默认 10 个/小时）
        $regMax = max(1, (int) Setting::get('web_reg_max_hour', 10));
        if (!RateLimit::hit('webreg:' . Util::ip(), $regMax, 3600)) {
            Response::error(5001, '注册过于频繁，请稍后再试');
        }

        $r = Auth::register(['username' => $username, 'password' => $password, 'email' => $email], Util::ip(), $swCtx);

        Logger::log('web_register', $r['ok'] ? 1 : 0, $r['msg'], [
            'username' => $username,
            'user_id'  => (int) ($r['data']['user_id'] ?? 0),
        ]);

        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }

        // 注册即登录，直接送到个人中心去激活
        $uid  = (int) $r['data']['user_id'];
        $user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$uid]);
        web_login($uid);

        Response::ok([
            'profile'        => web_profile($user),
            'just_registered'=> true,
        ], '注册成功，请使用激活码激活账号');
        break;

    // ---------------------------------------------------------------
    case 'login':
        // 登录方式与客户端同源：分软件设置优先，软件未单独配置时
        // 回落后台「系统设置 → 登录方式」的全局单选
        $method = LoginMethod::currentFor(web_current_software());

        if (!Captcha::verify(Util::str($input, 'captcha', ''))) {
            Logger::log('web_login', 0, '验证码错误', ['method' => $method]);
            Response::error(1002, '验证码不正确');
        }
        Captcha::clear();

        if (!RateLimit::hit('weblogin:' . Util::ip(), 10, 60)) {
            Logger::log('web_login', 0, '登录尝试过于频繁', ['method' => $method]);
            Response::error(5001, '登录尝试过于频繁，请稍后再试');
        }

        $r = Auth::loginBy($input, $method);
        if (!$r['ok']) {
            Logger::log('web_login', 0, $r['msg'], [
                'method' => $method,
                'raw'    => ['username' => Util::str($input, 'username', '')],
            ]);
            Response::error($r['code'], $r['msg']);
        }

        $user = $r['user'];
        web_login((int) $user['id']);

        Logger::log('web_login', 1, '官网登录成功', [
            'user_id'  => (int) $user['id'],
            'username' => $user['username'],
            'method'   => $method,
            'created'  => $r['created'],
        ]);

        Response::ok([
            'profile'         => web_profile($user),
            'login_method'    => $method,
            'account_created' => (bool) $r['created'],
        ], $r['created'] ? '账号已开通，登录成功' : '登录成功');
        break;

    // ---------------------------------------------------------------
    case 'logout':
        $uid = web_uid();
        if ($uid > 0) {
            Logger::log('web_logout', 1, '官网退出登录', ['user_id' => $uid]);
        }
        web_logout();
        Response::ok(['logout' => true], '已退出登录');
        break;

    // ---------------------------------------------------------------
    case 'me':
        $user = $requireUser();
        Response::ok(['profile' => web_profile($user)]);
        break;

    // ---------------------------------------------------------------
    case 'activate':
        $user = $requireUser();

        // 新账号行为冷却期：注册后 N 分钟内禁止激活，防批量注册抢活动资源（默认 10 分钟，后台可调）
        $cooldown = max(0, (int) Setting::get('web_act_cooldown_min', 10)) * 60;
        if ($cooldown > 0 && time() - (int) ($user['created_at'] ?? 0) < $cooldown) {
            Response::error(5001, '新注册账号需等待 ' . (int) ($cooldown / 60) . ' 分钟后才能激活卡密');
        }

        $code = Util::str($input, 'code', '');
        if ($code === '') {
            Response::error(1001, '请输入激活码');
        }
        // 激活码尝试频控：防卡密枚举（默认 20 次/分钟，后台可调）
        $actMax = max(1, (int) Setting::get('web_act_max_min', 20));
        if (!RateLimit::hit('webact:' . Util::ip(), $actMax, 60)) {
            Response::error(5001, '操作过于频繁，请稍后再试');
        }

        $r = Card::activate(['code' => $code], $user);

        Logger::log('web_activate', $r['ok'] ? 1 : 0, $r['msg'], [
            'user_id'  => (int) $user['id'],
            'username' => $user['username'],
            'raw'      => ['code' => Util::maskCard($code)],
        ]);

        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        // 发卡订单归属补绑：游客下单 → 激活卡密的账号自动接管订单（个人中心 shop_orders 可见）
        Shop::bindOrderByCard(strtoupper(trim($code)), (int) $user['id']);

        $fresh = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [(int) $user['id']]);

        Response::ok([
            'profile' => web_profile($fresh),
            'detail'  => $r['data']['detail'] ?? '',
        ], $r['msg']);
        break;

    // ---------------------------------------------------------------
    case 'devices':
        $user = $requireUser();
        $uid  = (int) $user['id'];

        $list = array_map(static function (array $d) {
            return [
                'id'          => (int) $d['id'],
                'machine_id'  => $d['machine_id'],
                'device_name' => $d['device_name'] ?: '未知设备',
                'os_info'     => $d['os_info'],
                'ip'          => $d['ip'],
                'status'      => (int) $d['status'],
                'status_text' => (int) $d['status'] === 1 ? '正常' : '已解绑',
                'bind_at'     => Util::date((int) $d['bind_at']),
                'last_seen'   => Util::date((int) $d['last_seen']),
                'online'      => (int) $d['status'] === 1
                    && (time() - (int) $d['last_seen']) < Policy::heartbeatTimeout(),
            ];
        }, Device::listByUser($uid));

        Response::ok([
            'max_devices' => Auth::maxDevices($user),
            'bound_count' => Device::activeCount($uid),
            'devices'     => $list,
        ]);
        break;

    // ---------------------------------------------------------------
    // 解绑设备：支持 id 或 machine_id 二选一；scope=all 时解绑全部
    // 只允许操作归属当前登录账号的设备，防止越权解绑他人机器
    // ---------------------------------------------------------------
    case 'unbind':
        $user = $requireUser();
        $uid  = (int) $user['id'];

        // 每日解绑上限：后台「系统设置 → 每日解绑次数上限」（Policy::unbindPerDay，0 = 不限制）。
        // 与客户端 /api/unbind 使用同一限流键（unbind:{uid}），官网与客户端共享每日额度，
        // 避免两边各解绑一轮绕过上限；后台改成多少，官网提示与限制同步生效。
        $unbindPerDay = Policy::unbindPerDay();
        if ($unbindPerDay > 0 && !RateLimit::hit('unbind:' . $uid, $unbindPerDay, 86400)) {
            Response::error(5001, "今日解绑次数已用完（上限 {$unbindPerDay} 次/天），请明日再试或联系客服处理");
        }

        $scope = Util::str($input, 'scope', '');
        $id    = (int) Util::get($input, 'id', 0);
        $mid   = Util::str($input, 'machine_id', '');
        $tbl   = Database::t('devices');

        if ($scope === 'all') {
            $n = Device::unbindAll($uid, '官网自助解绑（全部）');
            if ($n <= 0) {
                Response::error(4005, '当前没有可解绑的设备');
            }
            Logger::log('web_unbind', 1, '官网解绑全部设备', [
                'user_id'  => $uid,
                'username' => $user['username'],
                'count'    => $n,
            ]);
        } else {
            $row = null;
            if ($id > 0) {
                $row = Database::one("SELECT * FROM {$tbl} WHERE id = ? AND user_id = ?", [$id, $uid]);
            } elseif ($mid !== '') {
                $row = Database::one("SELECT * FROM {$tbl} WHERE machine_id = ? AND user_id = ?", [$mid, $uid]);
            }

            if (!$row) {
                Response::error(4004, '设备不存在或不属于当前账号');
            }
            if ((int) $row['status'] !== 1) {
                Response::error(4005, '该设备已经是解绑状态');
            }

            Device::forceUnbind((int) $row['id'], '官网自助解绑');

            Logger::log('web_unbind', 1, '官网解绑设备', [
                'user_id'  => $uid,
                'username' => $user['username'],
                'raw'      => ['machine_id' => $row['machine_id'], 'device' => $row['device_name']],
            ]);
        }

        // 解绑后设备额度立即释放，回传最新资料与列表
        $fresh = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$uid]);
        $list  = array_map(static function (array $d) {
            return [
                'id'          => (int) $d['id'],
                'machine_id'  => $d['machine_id'],
                'device_name' => $d['device_name'] ?: '未知设备',
                'os_info'     => $d['os_info'],
                'ip'          => $d['ip'],
                'status'      => (int) $d['status'],
                'status_text' => (int) $d['status'] === 1 ? '正常' : '已解绑',
                'bind_at'     => Util::date((int) $d['bind_at']),
                'last_seen'   => Util::date((int) $d['last_seen']),
                'online'      => (int) $d['status'] === 1
                    && (time() - (int) $d['last_seen']) < Policy::heartbeatTimeout(),
            ];
        }, Device::listByUser($uid));

        Response::ok([
            'profile'     => web_profile($fresh),
            'max_devices' => Auth::maxDevices($fresh),
            'bound_count' => Device::activeCount($uid),
            'devices'     => $list,
        ], $scope === 'all' ? '已解绑全部设备' : '设备已解绑');
        break;

    // ---------------------------------------------------------------
    // 价格套餐（公开）：后台可配置，官网首页展示
    // ---------------------------------------------------------------
    case 'shop_orders':
        $user = $requireUser();
        $uid  = (int) $user['id'];
        $orders = Database::all(
            'SELECT order_no, plan_name, amount, qty, status, pay_type, card_code, created_at
             FROM ' . Database::t('shop_orders') . '
             WHERE user_id = ? ORDER BY id DESC LIMIT 30',
            [$uid]
        );
        $cardsTable = Database::t('cards');

        // 待支付易支付单附「继续支付」收银台地址（与发卡网同源的站点地址解析）
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
            $payUrl = '';
            if ($repayBase !== '' && (int) $o['status'] === Shop::ORDER_PENDING && (int) $o['pay_type'] === Shop::PAY_EPAY) {
                $payUrl = Shop::repayUrl((string) $o['order_no'], $repayBase);
            }
            $out[] = [
                'order_no'    => $o['order_no'],
                'plan_name'   => $o['plan_name'],
                'amount_text' => number_format(((int) $o['amount']) / 100, 2, '.', ''),
                'qty'         => (int) $o['qty'],
                'status'      => (int) $o['status'],
                'pay_type'    => (int) $o['pay_type'],
                'pay_url'     => $payUrl,
                'created_at'  => date('Y-m-d H:i', (int) $o['created_at']),
                'codes'       => $codes,
            ];
        }
        Response::ok(['orders' => $out], '', 0);
        break;

    // ---------------------------------------------------------------
    // 用户自助关闭待支付订单（仅待支付状态可关，已发卡/已关闭不可关）
    // ---------------------------------------------------------------
    case 'shop_order_close':
        $user = $requireUser();
        $uid  = (int) $user['id'];
        $orderNo = strtoupper(trim(Util::str($input, 'order_no', '')));
        if ($orderNo === '') {
            Response::error(1001, '缺少订单号');
        }
        $order = Database::one(
            'SELECT id, user_id, status FROM ' . Database::t('shop_orders') . ' WHERE order_no = ? LIMIT 1',
            [$orderNo]
        );
        if (!$order || (int) $order['user_id'] !== $uid) {
            Response::error(1004, '订单不存在');
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
    // 小游戏排行榜（官网/发卡网模板右下角小游戏共用一套表）
    // game_top   公开只读：TOP 20
    // game_score_save  提交分数：游客可玩，页面 CSRF 令牌 + 频控防刷
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
        Response::ok(['list' => array_map(static fn (array $r) => [
            'name'       => (string) $r['name'],
            'score'      => (int) $r['score'],
            'created_at' => (int) $r['created_at'],
        ], $rows)]);
        break;

    case 'game_score_save':
        if (!Setting::bool('web_games_enabled', true)) {
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

    case 'plan':
        Response::ok(['list' => web_plan_list()]);
        break;

    // ---------------------------------------------------------------
    // 购买商家（公开）：后台可配置，官网首页展示
    // ---------------------------------------------------------------
    case 'seller':
        Response::ok(['list' => web_seller_list()]);
        break;

    // ---------------------------------------------------------------
    // 客户端截图（公开）：后台可配置，官网首页展示
    // ---------------------------------------------------------------
    case 'shot':
        Response::ok(['list' => web_screenshot_list()]);
        break;

    // ---------------------------------------------------------------
    // 留言板列表（公开）：仅返回已审核通过的留言
    // 未登录也能看，登录后额外标记哪些是自己发的、哪些自己点过赞
    // ---------------------------------------------------------------
    case 'msg_list':
        $page = max(1, (int) Util::get($input, 'page', 1));
        $uid  = web_uid();
        Response::ok(web_message_list($page, 10, $uid));
        break;

    // ---------------------------------------------------------------
    // 发布留言 / 回复（需登录，先审后显示）
    // ---------------------------------------------------------------
    case 'msg_post':
        $user = $requireUser();
        $uid  = (int) $user['id'];

        // 验证码：放在 RateLimit 之前，答错不消耗发言频次
        if (!Captcha::verify((string) Util::get($input, 'captcha', ''))) {
            Response::error(1001, '验证码不正确或已过期，请重新输入');
        }

        // 单账号每分钟 3 条，防刷屏
        if (!RateLimit::hit('webmsg:' . $uid, 3, 60)) {
            Response::error(5001, '发言过于频繁，请稍后再试');
        }

        $content = trim((string) Util::get($input, 'content', ''));
        $parent  = (int) Util::get($input, 'parent_id', 0);

        // 长度按「字符」算，避免中文被按字节截断
        if ($content === '') {
            Response::error(1001, '留言内容不能为空');
        }
        if (mb_strlen($content, 'UTF-8') > 500) {
            Response::error(1001, '留言内容最多 500 字');
        }

        $replyTo = '';
        if ($parent > 0) {
            // 只能回复已审核通过的留言，防止探测未审核内容
            $p = Database::one(
                'SELECT * FROM ' . Database::t('messages') . ' WHERE id = ? AND status = 1',
                [$parent]
            );
            if (!$p) {
                Response::error(1001, '要回复的留言不存在或未通过审核');
            }
            if ((int) $p['parent_id'] !== 0) {
                Response::error(1001, '只支持两层回复，请回复主楼');
            }
            $replyTo = $p['username'];
        }

        // 留言按当前官网软件归属（总站提交=0）；老库无该列时跳过
        $msgRow = [
            'user_id'    => $uid,
            'username'   => (string) ($user['nickname'] ?: $user['username']),
            'content'    => $content,
            'parent_id'  => $parent,
            'reply_to'   => $replyTo,
            'likes'      => 0,
            'status'     => 0,              // 先审后显示
            'admin_note' => '',
            'ip'         => Util::ip(),
            'created_at' => time(),
        ];
        if (WebInteract::hasSwCol('messages')) {
            $sw = web_current_software();
            $msgRow['software_id'] = $sw ? (int) $sw['id'] : 0;
        }

        $id = Database::insert('messages', $msgRow);

        Logger::log('web_msg_post', 1, '提交留言', [
            'user_id'  => $uid,
            'username' => $user['username'],
            'raw'      => ['message_id' => $id, 'reply' => $parent > 0],
        ]);

        // 明确告知用户「已提交待审核」，避免以为没发出去
        Response::ok(['id' => $id, 'pending' => true], '留言已提交，通过审核后展示');
        break;

    // ---------------------------------------------------------------
    // 留言点赞 / 取消点赞（需登录）
    // 唯一键 (message_id, user_id) 防重复；likes 冗余计数同步增减
    // ---------------------------------------------------------------
    case 'msg_like':
        $user = $requireUser();
        $uid  = (int) $user['id'];
        $mid  = (int) Util::get($input, 'id', 0);

        if ($mid <= 0) {
            Response::error(1001, '缺少留言 ID');
        }
        if (!RateLimit::hit('weblike:' . $uid, 30, 60)) {
            Response::error(5001, '操作过于频繁，请稍后再试');
        }

        $msg = Database::one(
            'SELECT * FROM ' . Database::t('messages') . ' WHERE id = ? AND status = 1',
            [$mid]
        );
        if (!$msg) {
            Response::error(1001, '留言不存在或未通过审核');
        }

        $tblLike = Database::t('message_likes');
        $tblMsg  = Database::t('messages');

        $had = Database::one(
            "SELECT id FROM {$tblLike} WHERE message_id = ? AND user_id = ?",
            [$mid, $uid]
        );

        if ($had) {
            // 取消点赞：先删记录，成功后再减计数，避免并发下计数变负
            Database::exec("DELETE FROM {$tblLike} WHERE id = ?", [(int) $had['id']]);
            Database::exec("UPDATE {$tblMsg} SET likes = GREATEST(likes, 1) - 1 WHERE id = ?", [$mid]);
            $liked = false;
        } else {
            // 点赞：唯一键兜底并发重复插入
            try {
                Database::insert('message_likes', [
                    'message_id' => $mid,
                    'user_id'    => $uid,
                    'created_at' => time(),
                ]);
                Database::exec("UPDATE {$tblMsg} SET likes = likes + 1 WHERE id = ?", [$mid]);
                $liked = true;
            } catch (Throwable $e) {
                // 并发下另一个请求已插入，视为已点赞
                $liked = true;
            }
        }

        $fresh = Database::one("SELECT likes FROM {$tblMsg} WHERE id = ?", [$mid]);

        Response::ok([
            'liked' => $liked,
            'likes' => (int) ($fresh['likes'] ?? 0),
        ], $liked ? '已点赞' : '已取消点赞');
        break;

    // ---------------------------------------------------------------
    // 反馈类型选项（公开）：前后端共用一份，避免两处硬编码漂移
    // ---------------------------------------------------------------
    case 'fb_types':
        $types = [];
        foreach (web_feedback_types() as $k => $v) {
            $types[] = ['value' => $k, 'label' => $v];
        }
        Response::ok(['types' => $types]);
        break;

    // ---------------------------------------------------------------
    // 我的反馈列表（需登录，只返回自己的）
    // ---------------------------------------------------------------
    case 'fb_list':
        $user = $requireUser();
        Response::ok([
            'list'  => web_feedback_list((int) $user['id']),
            'types' => web_feedback_types(),
        ]);
        break;

    // ---------------------------------------------------------------
    // 提交反馈（需登录）
    // ---------------------------------------------------------------
    case 'fb_post':
        $user = $requireUser();
        $uid  = (int) $user['id'];

        // 单账号每小时 5 条
        if (!RateLimit::hit('webfb:' . $uid, 5, 3600)) {
            Response::error(5001, '提交过于频繁，请稍后再试');
        }

        $type    = (int) Util::get($input, 'type', 1);
        $title   = trim((string) Util::get($input, 'title', ''));
        $content = trim((string) Util::get($input, 'content', ''));
        $contact = trim((string) Util::get($input, 'contact', ''));

        if (!array_key_exists($type, web_feedback_types())) {
            $type = 4;
        }
        if ($title === '') {
            Response::error(1001, '请填写反馈标题');
        }
        if (mb_strlen($title, 'UTF-8') > 60) {
            Response::error(1001, '标题最多 60 字');
        }
        if ($content === '') {
            Response::error(1001, '请填写反馈内容');
        }
        if (mb_strlen($content, 'UTF-8') > 2000) {
            Response::error(1001, '反馈内容最多 2000 字');
        }
        if (mb_strlen($contact, 'UTF-8') > 100) {
            Response::error(1001, '联系方式过长');
        }

        // 反馈按当前官网软件归属（总站提交=0）；老库无该列时跳过
        $fbRow = [
            'user_id'     => $uid,
            'username'    => (string) $user['username'],
            'type'        => $type,
            'title'       => $title,
            'content'     => $content,
            'contact'     => $contact,
            'status'      => 0,            // 待处理
            'reply'       => '',
            'reply_admin' => '',
            'replied_at'  => 0,
            'created_at'  => time(),
        ];
        if (WebInteract::hasSwCol('feedbacks')) {
            $sw = web_current_software();
            $fbRow['software_id'] = $sw ? (int) $sw['id'] : 0;
        }

        $id = Database::insert('feedbacks', $fbRow);

        Logger::log('web_feedback', 1, '提交反馈', [
            'user_id'  => $uid,
            'username' => $user['username'],
            'raw'      => ['feedback_id' => $id, 'type' => $type],
        ]);

        Response::ok([
            'id'   => $id,
            'list' => web_feedback_list($uid),
        ], '反馈已提交，我们会尽快处理');
        break;

    // ---------------------------------------------------------------
    // 激活码找回：第一步 查询激活码绑定的用户名（官网登录弹窗用）
    // 前提：后台「激活码找回密码（发卡网）」开关开启；仅支持已绑定的卡
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
    // 激活码找回：第二步 为绑定账号设置新密码（带图形验证码）
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
            Logger::log('web_reclaim', 0, $r['msg'], ['raw' => ['username' => Util::str($input, 'username', '')]]);
            Response::error($r['code'], $r['msg']);
        }
        Logger::log('web_reclaim', 1, '激活码找回密码成功', ['username' => Util::str($input, 'username', '')]);
        Response::ok([], '密码已重置，请使用新密码登录');
        break;

    // ---------------------------------------------------------------
    // 图形验证码：GET 拉图；IP 维度限流（60/分钟），独立于会话
    // 答案存 session，留言/反馈提交时验证；本身是只读，加入 readOnly
    // 免 CSRF 校验，避免页面刷新时第一张图就被拦
    // ---------------------------------------------------------------
    case 'captcha':
        if (!RateLimit::hit('webcaptcha:' . Util::ip(), 60, 60)) {
            // 限流也输出图片（占位图），避免 <img> 拿到 JSON 显示破图
            Captcha::renderBusy();
            break;
        }
        Captcha::render();
        break;

    // ---------------------------------------------------------------
    default:
        Response::error(1001, '未知的接口: ' . $action);
}
