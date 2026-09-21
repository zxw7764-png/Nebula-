<?php
/**
 * Nebula Menu · 官网公共引导
 * ------------------------------------------------------------------
 * 官网（对外门户）与客户端接口 /api/index.php 的差异：
 *   1. 认证方式是浏览器会话 Cookie，而不是 token + 机器码；
 *   2. 全程明文 JSON，不需要 AES/HMAC 信封（同域部署，密钥不下发到浏览器）；
 *   3. 只做"账号 -> 激活"这一件事，不做设备绑定，避免占用客户端的设备配额。
 *
 * 被 index.php（页面）与 api.php（接口）共同 require。
 */

// 禁止直接通过 URL 访问本文件（只允许入口文件先定义常量再 require）
if (!defined('NB_WEB_ENTRY')) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}

require_once __DIR__ . '/../../lib/bootstrap.php';

// 官网接口一律明文响应。默认加密是给客户端二进制用的，
// 浏览器端拿不到密钥（也不该拿到），因此必须关掉信封。
Response::setEncrypt(false);

// ------------------------------------------------------------------
// 会话：独立 Cookie 名，与后台管理会话互不干扰
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('NBWEBSID');
    @session_start();
}

/** 会话中记录的用户 ID，0 表示未登录 */
function web_uid(): int
{
    return (int) ($_SESSION['nb_web_uid'] ?? 0);
}

/** 当前登录用户（原始行），未登录或账号异常返回 null */
function web_user(): ?array
{
    $uid = web_uid();
    if ($uid <= 0) {
        return null;
    }
    $u = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$uid]);
    if (!$u) {
        web_logout();
        return null;
    }
    return $u;
}

/** 写入登录态（同时轮换 Session ID，防会话固定攻击） */
function web_login(int $uid): void
{
    @session_regenerate_id(true);
    $_SESSION['nb_web_uid']   = $uid;
    $_SESSION['nb_web_time']  = time();
    $_SESSION['nb_web_ip']    = Util::ip();
    $_SESSION['nb_web_agent'] = substr(Util::ua(), 0, 120);
}

/** 退出登录 */
function web_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

// ------------------------------------------------------------------
// 业务辅助
// ------------------------------------------------------------------

/**
 * 当前官网上下文命中的软件（多软件区分的核心）
 * 解析顺序：URL ?app=<app_key>（写 Cookie 记忆）→ Cookie → 仅一个启用软件时直接用它
 * 多软件且未选择时返回 null：公告只显示「全部软件」通用，前端展示软件选择器。
 * 命中后同步 Software::setCurrent()，官网注册的账号即归属该软件。
 */
function web_current_software(): ?array
{
    static $done = false, $cached = null;
    if ($done) {
        return $cached;
    }
    $done = true;

    $adopt = static function (array $sw) use (&$cached): array {
        $cached = $sw;
        Software::setCurrent($sw);
        return $cached;
    };

    // 1. URL 参数 ?app=，命中后写 Cookie 记忆（会话级）
    $app = isset($_GET['app']) && is_string($_GET['app']) ? trim($_GET['app']) : '';
    if ($app !== '') {
        $sw = Software::byAppKey($app);
        if ($sw) {
            @setcookie('nb_web_app', (string) $sw['app_key'], [
                'expires'  => 0,
                'path'     => '/',
                'samesite' => 'Lax',
                'httponly' => true,
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            return $adopt($sw);
        }
    }

    // 2. Cookie 记忆
    $app = (string) ($_COOKIE['nb_web_app'] ?? '');
    if ($app !== '') {
        $sw = Software::byAppKey($app);
        if ($sw) {
            return $adopt($sw);
        }
    }

    // 3. 恰好只有一个启用软件：直接当作它的官网
    $all = Software::all(true);
    if (count($all) === 1) {
        return $adopt($all[0]);
    }

    // 4. 多软件未选择
    return null;
}

/**
 * 当前软件的官网文案覆盖（软件分站内容独立设置的核心）
 * 后台「软件管理 → 官网内容」按软件保存 JSON（Setting key: web_sw_<id>），
 * key 与全局 Setting 一致（如 web_hero_title）；值为空字符串视为未覆盖。
 */
function web_sw_overrides(): array
{
    static $ov = null;
    if ($ov !== null) {
        return $ov;
    }
    $ov = [];
    $sw = web_current_software();
    if ($sw) {
        $raw = trim((string) Setting::get('web_sw_' . (int) $sw['id'], ''));
        if ($raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $ov = $data;
            }
        }
    }
    return $ov;
}

/**
 * 官网文案取值：当前软件覆盖优先，其次全局 Setting，最后默认值。
 * 所有分站可定制的字段都应经过本函数而不是直接 Setting::get()。
 */
function webSetting(string $key, string $default = ''): string
{
    $ov  = web_sw_overrides();
    $val = $ov[$key] ?? null;
    if (is_string($val) && trim($val) !== '') {
        return $val;
    }
    return (string) Setting::get($key, $default);
}

/** 官网布尔开关取值：当前软件覆盖优先（'0' 也是有效覆盖），回落全局 Setting */
function webSettingBool(string $key, bool $default): bool
{
    $ov  = web_sw_overrides();
    $val = $ov[$key] ?? null;
    if (is_string($val) && trim($val) !== '') {
        return in_array(strtolower(trim($val)), ['1', 'on', 'true', 'yes'], true);
    }
    return Setting::bool($key, $default);
}

/**
 * 官网门户公告列表（type=1，公开展示区）
 *   · softwareId>0（已登录已激活）→ 该软件公告 + 通用公告
 *   · softwareId=0（未登录）     → 全部已发布官网公告（门户区对访客公开）
 */function web_notices_for(int $softwareId, int $limit = 6): array
{
    $now = time();
    if ($softwareId > 0) {
        $rows = Database::all(
            'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
             WHERE status = 1 AND type = 1
               AND (software_id = 0 OR software_id = ?)
               AND (start_at = 0 OR start_at <= ?)
               AND (end_at = 0 OR end_at >= ?)
             ORDER BY sort DESC, id DESC LIMIT ' . $limit,
            [$softwareId, $now, $now]
        );
    } else {
        // 未登录：官网门户公告区对访客公开，展示全部已发布官网公告（type=1）
        $rows = Database::all(
            'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
             WHERE status = 1 AND type = 1
               AND (start_at = 0 OR start_at <= ?)
               AND (end_at = 0 OR end_at >= ?)
             ORDER BY sort DESC, id DESC LIMIT ' . $limit,
            [$now, $now]
        );
    }
    foreach ($rows as &$n) {
        $n['created_at_text'] = Util::date((int) $n['created_at']);
        $n['type_text']       = [1 => '公告'][(int) $n['type']] ?? '公告';
    }
    unset($n);
    return $rows;
}

/** 客户端公告列表（type 2/3/4，softwareId>0 = 该软件+通用；0 = 仅通用）—— 个人中心「软件公告」数据源 */
function web_client_notices_for(int $softwareId, int $limit = 10): array
{
    $now = time();
    $swWhere = $softwareId > 0 ? '(software_id = 0 OR software_id = ?)' : 'software_id = 0';
    $params  = $softwareId > 0 ? [$softwareId, $now, $now] : [$now, $now];
    $rows = Database::all(
        'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
         WHERE status = 1 AND type IN (2, 3, 4)
           AND ' . $swWhere . '
           AND (start_at = 0 OR start_at <= ?)
           AND (end_at = 0 OR end_at >= ?)
         ORDER BY sort DESC, id DESC LIMIT ' . $limit,
        $params
    );
    foreach ($rows as &$n) {
        $n['created_at_text'] = Util::date((int) $n['created_at']);
        $n['type_text']       = [2 => '弹窗公告', 3 => '立即公告', 4 => '列表公告'][(int) $n['type']] ?? '公告';
    }
    unset($n);
    return $rows;
}

/** 会员剩余时长的可读文案（比 Util::duration 更贴合"未激活/已过期"语义） */
function web_remain_text(array $user): string
{
    $expire = (int) $user['vip_expire'];
    $points = (int) $user['points'];

    if ($expire === -1) {
        return '永久会员';
    }
    if ($expire === 0 && $points <= 0) {
        return '未激活';
    }
    if ($expire === 0 && $points > 0) {
        return '点数账户（剩余 ' . $points . ' 点）';
    }
    $left = max(0, $expire - time());
    if ($left <= 0) {
        return '已过期';
    }
    return '剩余 ' . Util::duration($left);
}

/** 组装个人中心所需的资料 */
function web_profile(array $user): array
{
    $vip    = Auth::checkVip($user);
    $expire = (int) $user['vip_expire'];
    $remain = $expire === -1 ? -1 : ($expire > 0 ? max(0, $expire - time()) : 0);
    $group  = Auth::group((int) $user['group_id']);
    $uid    = (int) $user['id'];

    return [
        'user'          => Auth::publicInfo($user),
        'email'         => (string) ($user['email'] ?? ''),
        'group_name'    => $group['name'] ?? '默认用户组',
        'vip'           => $vip,
        'activated'     => (bool) $vip['valid'],
        'vip_status'    => $vip['valid'] ? ($vip['msg'] === '试用中' ? '试用中' : '已激活') : '未激活',
        'remain'        => $remain,
        'remain_text'   => web_remain_text($user),
        'expire_text'   => $expire === -1 ? '永久' : ($expire > 0 ? Util::date($expire) : '—'),
        'register_time' => Util::date((int) $user['created_at']),
        'last_login'    => Util::date((int) $user['last_login_time']),
        'device'        => [
            'max_devices' => Auth::maxDevices($user),
            'bound_count' => Device::activeCount($uid),
        ],
    ];
}

/** 首页功能卡片：每行「图标|标题|内容」，空配置回内置默认四张 */
function webFeatureCards(): array
{
    $raw = trim(webSetting('web_features', ''));
    if ($raw === '') {
        $raw = "🎮|功能模块|自瞄、透视、载具、武器、世界等模块化功能，菜单内自由开关，随手调节强度。\n"
             . "⌨️|一键呼出|默认按 Ins 键呼出 / 隐藏菜单，热键可在菜单设置里自定义，随开随用不打断操作。\n"
             . "🎨|界面自定义|菜单配色、透明度与布局均可自由调节，支持拖动缩放，打造最顺手的操作界面。\n"
             . "🔄|持续更新|功能随游戏版本同步更新，维护与更新第一时间通过公告推送，长期稳定可用。";
    }
    $out = [];
    $i = 0;
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = array_map('trim', explode('|', $line, 3));
        if (count($p) < 3) continue;
        $out[] = ['icon' => $p[0], 'title' => $p[1], 'content' => $p[2], 'cls' => 'i' . (++$i % 4 ?: 4)];
    }
    return $out;
}

/** 通用行解析：每行按 | 拆成 $parts 段，字段不足的行丢弃 */
function webLines(string $raw, int $parts): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = array_map('trim', explode('|', $line));
        if (count($p) < $parts) continue;
        $out[] = $p;
    }
    return $out;
}

/** 个人中心「永久会员」状态卡文案：第 1 行为主文案，其余为要点；空配置回内置默认 */
function webForeverNotice(): array
{
    $lines = webLines((string) Setting::get('web_forever_text', ''), 1);
    if (!$lines) {
        return [
            'main' => '你的账号已是永久会员，会员时长永久有效，无需再次激活。',
            'tips' => [
                '永久有效，不存在到期时间',
                '账号、设备额度与全部菜单功能不受期限限制',
                '如有疑问请联系客服核对订单',
            ],
        ];
    }
    $texts = array_column($lines, 0);
    return ['main' => (string) array_shift($texts), 'tips' => $texts];
}

/** 首页「使用流程」步骤：每行「标题|内容」，编号自动 01/02/03；空配置回内置默认三步 */
function webFlowSteps(): array
{
    $raw = trim(webSetting('web_flow', ''));
    if ($raw === '') {
        $raw = "注册账号|使用用户名和密码注册，注册成功自动登录并进入个人中心。\n"
             . "兑换激活码|在个人中心输入购买到的激活码，点击激活，会员时长立即到账。\n"
             . "呼出菜单|运行 Nebula Menu，登录后按 Ins 键即可呼出菜单，开始调节各项功能。";
    }
    $out = [];
    foreach (webLines($raw, 2) as $i => $p) {
        $out[] = ['num' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'title' => $p[0], 'content' => $p[1]];
    }
    return $out;
}

/** 首页「常见问题」：每行「问题|答案」，空配置回内置默认五条 */
function webFaqList(): array
{
    $raw = trim(webSetting('web_faq', ''));
    if ($raw === '') {
        $raw = "菜单怎么呼出和隐藏？|默认按 Ins 键呼出或隐藏菜单。呼出后进入\"设置\"页即可把热键改成自己习惯的按键，保存后即时生效。\n"
             . "菜单呼不出来怎么办？|请以管理员身份运行 Nebula Menu；若游戏处于全屏独占模式，建议改为无边框窗口后再试。仍不显示请查看最新公告。\n"
             . "激活码提示\"已被使用\"怎么办？|每个激活码仅能激活一个账号，请确认是否已在其他账号使用，或联系客服核对订单。\n"
             . "换电脑后提示设备数量已达上限？|登录后进入个人中心，在「已绑定设备」里点旧设备右侧的解绑按钮即可释放额度，随后在新电脑登录就会自动绑定。也可点「全部解绑」一次性清空。\n"
             . "会员到期后菜单还能用吗？|到期后菜单将无法呼出。账号、设备与剩余时长均会保留，重新激活后立即恢复使用。";
    }
    $out = [];
    foreach (webLines($raw, 2) as $p) {
        $out[] = ['q' => $p[0], 'a' => $p[1]];
    }
    return $out;
}

/** 首屏数据条：每行「数值|标签」，支持 {online}/{version} 占位；空配置回内置默认四项 */
function webHeroStats(): array
{
    $raw = trim(webSetting('web_hero_stats', ''));
    if ($raw === '') {
        $raw = "{online}|当前在线\n<50ms|呼出延迟\nIns|默认热键\nv{version}|最新版本";
    }
    $out = [];
    foreach (webLines($raw, 2) as $p) {
        $out[] = ['v' => $p[0], 'l' => $p[1]];
    }
    return array_slice($out, 0, 6);
}

/**
 * 官网 Logo：分站覆盖 web_logo → 全局 shop_logo（系统设置→站点上传）→ 默认 ../logo.png。
 * 只放行 http/https 直链，挡掉 javascript: 等伪协议。
 */
function web_logo_url(): string
{
    $u = trim(webSetting('web_logo', ''));
    if ($u !== '' && preg_match('#^https?://#i', $u)) {
        return $u;
    }
    $g = Shop::logoUrl((string) Setting::get('shop_logo', ''));
    return $g !== '' ? $g : '../logo.png';
}

/** 官网 Favicon：分站覆盖 web_favicon → 沿用官网 Logo（含分站 Logo 覆盖） */
function web_favicon_url(): string
{
    $u = trim(webSetting('web_favicon', ''));
    if ($u !== '' && preg_match('#^https?://#i', $u)) {
        return $u;
    }
    return web_logo_url();
}

/** 链接列表（导航栏 / 页脚）：每行「文本|锚点」，空配置回内置默认 */
function webLinkList(string $key, string $defaults): array
{
    $raw = trim(webSetting($key, ''));
    if ($raw === '') {
        $raw = $defaults;
    }
    $out = [];
    foreach (webLines($raw, 2) as $p) {
        $href = $p[1];
        if ($href !== '' && !preg_match('~^(https?://|/|#|\.\./)~', $href)) {
            $href = '#' . ltrim($href, '#');
        }
        $out[] = ['text' => $p[0], 'href' => $href];
    }
    return $out;
}

/** 站点公开信息（首页展示用；公告 / 版本 / 下载按当前选中软件区分） */
function web_site_info(): array
{
    $now = time();
    $sw  = web_current_software();
    $notices = web_notices_for($sw ? (int) $sw['id'] : 0);

    // 版本信息与「下载」接口同源：选中软件时按该软件解析（发布表记录优先，
    // 回落软件自身配置）；未选中（多软件待选择）时回落全局版本配置。
    $ver = $sw ? Software::versionInfo($sw, 'stable') : Version::latest('stable');

    // 发卡网入口：开启时官网购买引导跳发卡商店（内置 /shop/ 或外部发卡站链接）。
    // external 模式但没配链接视为未开启，与 /shop/ 页面自身的降级口径一致。
    // 分软件覆盖：该软件单独配置了商店地址（web_shop_url）时优先使用。
    $shopUrl = '';
    if (Shop::enabled()) {
        if (Shop::mode() === 'external') {
            $shopUrl = Shop::externalUrl();
        } else {
            $shopUrl = '../shop/';
        }
    }
    $swShopUrl = trim(webSetting('web_shop_url', ''));
    if ($swShopUrl !== '' && preg_match('#^(https?://|/)#i', $swShopUrl)) {
        $shopUrl = $swShopUrl;
    }

    // 导航栏双重职责：既是顶部导航，也是首页区块的「开关 + 标题」来源 ——
    // 导航里出现的锚点（features/shots/flow/pricing/sellers/faq）才渲染对应区块，
    // 区块大标题直接用导航文本（后台改导航名，首页标题同步变）。
    // 例外：留言板完全由「系统设置→站点→开启留言」单独控制，名称固定「留言板」——
    //   关 = 整块隐藏且导航同步移除留言板入口；
    //   开 = 区块始终显示（标题/导航入口文本均固定），导航行仅控制是否在导航显示。
    $messageOn = webSettingBool('web_message_board', true);
    $navList = webLinkList('web_nav_links', "菜单功能|features\n效果展示|shots\n使用流程|flow\n价格套餐|pricing\n购买商家|sellers\n留言板|board\n常见问题|faq\n我要反馈|feedback");
    if (!$messageOn) {
        $navList = array_values(array_filter(
            $navList,
            function ($nl) { return (string) $nl['href'] !== '#board'; }
        ));
    } else {
        foreach ($navList as &$nl) {
            if ((string) $nl['href'] === '#board') {
                $nl['text'] = '留言板'; // 名称固定，不跟随导航配置
            }
        }
        unset($nl);
    }
    $secMap  = [];
    foreach ($navList as $nl) {
        if (preg_match('~^#([A-Za-z0-9_-]+)$~', (string) $nl['href'], $m)) {
            $secMap[$m[1]] = (string) $nl['text'];
        }
    }
    if ($messageOn) {
        $secMap['board'] = '留言板';
    }

    return [
        // 以下站点文案均支持「软件分站覆盖」：后台软件管理里按软件单独设置，
        // 未覆盖的字段回落全局配置（系统设置 → 站点）
        'site_name'       => webSetting('site_name', 'Nebula Menu'),
        'site_sub'        => webSetting('site_sub', '次世代游戏增强菜单'),
        // 首页文案（后台「系统设置 → 站点」可改，留空用默认）
        'hero_title'      => webSetting('web_hero_title', '极致流畅的'),
        'hero_em'         => webSetting('web_hero_em', '游戏增强菜单'),
        'hero_lead2'      => webSetting('web_hero_lead2', '默认 Ins 键一键呼出 · 功能模块自由开关 · 随开随用不打断操作。'),
        // 首屏数据条：每行「数值|标签」，{online}/{version} 为动态占位
        'hero_stats'      => webHeroStats(),
        // Logo / Favicon：分站可单独覆盖（分站 Logo → 全局 Logo → 默认；Favicon 留空沿用 Logo）
        'logo'            => web_logo_url(),
        'favicon'         => web_favicon_url(),
        'features_title'  => webSetting('web_features_title', '菜单功能'),
        'features_sub'    => webSetting('web_features_sub', '模块化设计，想要的功能在菜单里一键开关'),
        // 功能卡片：每行「图标|标题|内容」，未配置时用内置默认
        'features'        => webFeatureCards(),
        // 使用流程 / 常见问题（后台「系统设置 → 站点」可改）
        'flow_title'      => webSetting('web_flow_title', '使用流程'),
        'flow_sub'        => webSetting('web_flow_sub', '三步开启菜单，全程不超过一分钟'),
        'flow'            => webFlowSteps(),
        'faq_title'       => webSetting('web_faq_title', '常见问题'),
        'faq_sub'         => webSetting('web_faq_sub', '还有其他疑问？联系客服处理'),
        'faq'             => webFaqList(),
        // 导航栏 / 页脚链接与文案：每行「文本|锚点」
        'nav_links'       => $navList,
        // 首页区块开关与标题（锚点 => 导航文本），由导航栏链接派生
        'sections'        => $secMap,
        'foot_slogan'     => webSetting('web_foot_slogan', '专业游戏增强菜单 · 稳定持续更新'),
        'foot_links'      => webLinkList('web_foot_links', "菜单功能|features\n价格套餐|pricing\n留言板|board\n常见问题|faq"),
        'copyright'       => webSetting('web_copyright', ''),
        'register_enable' => Setting::bool('register_enable', true),
        // 登录方式规格（method / need_username / need_password / need_code / fields）
        // 前端据此决定登录表单渲染哪些输入框；分软件设置优先
        'login'           => LoginMethod::specFor($sw),
        // 维护模式：分软件策略覆盖优先（该软件单独设为维护时官网显示维护提示条）
        'maintain_mode'   => Policy::maintainModeFor($sw),
        'maintain_msg'    => Policy::maintainMsgFor($sw),
        // 留言板开关：默认开启（未配置过开关的老站行为不变）
        'message_board'   => Setting::bool('web_message_board', true),
        // 客服联系方式（购买咨询弹窗用）：分软件覆盖优先，未填则前端提示联系客服
        'contact'         => webSetting('contact', ''),
        // 发卡商店入口（enabled=false 时官网购买仍走客服咨询弹窗）
        'shop'            => ['enabled' => $shopUrl !== '', 'url' => $shopUrl],
        'server_time'     => $now,
        // 多软件：当前选中软件（null = 多软件未选择）与可选软件列表
        'software'        => $sw ? [
            'id'      => (int) $sw['id'],
            'name'    => (string) $sw['name'],
            'app_key' => (string) $sw['app_key'],
        ] : null,
        'softwares'       => array_map(function ($s) {
            return [
                'id'      => (int) $s['id'],
                'name'    => (string) $s['name'],
                'app_key' => (string) $s['app_key'],
            ];
        }, Software::all(true)),
        'latest_version'  => $ver['version'],
        'update_url'      => $ver['download_url'],
        'version_source'  => $ver['source'],
        'notices'         => $notices,
    ];
}

// ------------------------------------------------------------------
// 官网互动功能：留言板 / 反馈 / 套餐 / 截图
// 领域逻辑统一放在 lib/WebInteract.php —— 后台 handler 也要用同一套
// 读取与文案逻辑，而 portal.php 只在官网入口被加载，后台拿不到。
// 这里保留薄封装，让官网侧调用点读起来更直白。
// ------------------------------------------------------------------

/** 表是否已建（老站未跑迁移时降级，避免官网整页报错） */
function web_table_ready(string $suffix): bool
{
    return WebInteract::tableReady($suffix);
}

function web_message_status_text(int $status): string
{
    return WebInteract::messageStatusText($status);
}

function web_feedback_status_text(int $status): string
{
    return WebInteract::feedbackStatusText($status);
}

/** 反馈类型选项（前后端共用一份，避免两处硬编码漂移） */
function web_feedback_types(): array
{
    return WebInteract::feedbackTypes();
}

function web_message_list(int $page = 1, int $perPage = 10, int $uid = 0): array
{
    // 留言板按当前软件过滤（通用 + 该软件）；总站/未选择软件时不区分
    $sw = web_current_software();
    return WebInteract::messageList($page, $perPage, $uid, $sw ? (int) $sw['id'] : 0);
}

function web_plan_list(): array
{
    // 官网套餐按当前软件过滤：命中软件时显示「全部软件通用 + 该软件专属」；
    // 未选择软件（多软件待选 / 总站）只显示通用套餐
    $sw  = web_current_software();
    return WebInteract::planList($sw ? (int) $sw['id'] : 0);
}

function web_screenshot_list(): array
{
    // 截图按当前软件过滤（0=全部软件通用）
    $sw = web_current_software();
    return WebInteract::screenshotList($sw ? (int) $sw['id'] : 0);
}

function web_seller_list(): array
{
    // 商家按当前软件过滤（0=全部软件通用）
    $sw = web_current_software();
    return WebInteract::sellerList($sw ? (int) $sw['id'] : 0);
}

function web_feedback_list(int $uid): array
{
    // 反馈列表按当前软件过滤（通用 + 该软件）
    $sw = web_current_software();
    return WebInteract::feedbackList($uid, $sw ? (int) $sw['id'] : 0);
}
