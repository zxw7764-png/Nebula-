<?php
/**
 * Nebula Menu · 官网首页
 * ------------------------------------------------------------------
 * 流程：首页 -> 注册/登录 -> 个人中心 -> 输入激活码激活
 *
 * 本文件只输出 HTML 骨架，并把运行时参数（接口地址、CSRF 令牌、
 * 站点信息、当前登录用户）注入到 window.__NB_WEB__，
 * 之后的交互全部由 assets/js/site.js 接管。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/inc/portal.php';

// 未安装先引导到安装向导
if (!is_file(NB_ROOT . '/install/install.lock')) {
    header('Location: ../install/install.php');
    exit;
}

$site    = web_site_info();
$user    = web_user();
$profile = $user ? web_profile($user) : null;
$csrf    = Util::csrfToken();

// 全局 Logo：后台「系统设置 → 站点」上传/填写，官网与发卡网共用；留空回退默认 logo.png
$globalLogo = Shop::logoUrl((string) Setting::get('shop_logo', ''));
if ($globalLogo === '') { $globalLogo = '../logo.png'; }

// 服务端首屏渲染：价格套餐 / 截图 / 留言板首屏
// 目的有两个：
//   1. 未跑迁移的老站直接得到空数组，页面结构降级为「暂无内容」而不是白屏；
//   2. 首屏内容随 HTML 一起到达，不必等 JS 请求，SEO 与感知速度都更好。
$plans       = web_plan_list();
$sellers     = web_seller_list();
$shots       = web_screenshot_list();
// 每日解绑次数上限：后台「系统设置 → 每日解绑次数上限」，0 = 不限制（前端不显示次数提示）
$unbindPerDay = Policy::unbindPerDay();
$msgFirst    = web_message_list(1, 10, $user ? (int) $user['id'] : 0);
$fbTypes     = web_feedback_types();
$messageOpen = $site['message_board'] ?? true;
// 首页区块开关与标题：锚点 => 导航文本（来自「导航栏链接」设置，分软件可覆盖）
$sec         = $site['sections'] ?? [];

// 官网主题色：分软件覆盖优先（分站主题色），回落「内容运营 → 官网内容」的全局配置；
// #RRGGBB，留空用默认紫。同时派生 RGB 分量变量，供 CSS 中 rgba(var(--primary-rgb), x) 跟随主题。
$hexToRgb = static function (string $hex): string {
    $hex = ltrim(strtolower(trim($hex)), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '';
    }
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
};
$webTheme = Shop::themeColors(strtolower(trim(webSetting('web_theme', ''))));
$primaryRgb   = $webTheme ? $hexToRgb($webTheme['base']) : '';
$primary2Rgb  = $webTheme ? $hexToRgb($webTheme['light']) : '';

// 官网界面模板：分软件覆盖优先，回落总站；'' = 默认深空 UI（不加 body class）。
// 模板自带整套配色（templates 文件夹），激活时跳过后台主题色内联样式避免打架。
// 可用模板由 UiTemplate 接口扫描模板文件夹自动识别（单文件 / 文件夹两种形态），未知值回落默认。
$uiTpl   = UiTemplate::normalize('web', (string) webSetting('web_ui_template', ''));
$tplGame = $uiTpl !== '' ? UiTemplate::gameRel('web', $uiTpl) : null; // 模板自带小游戏（<id>/game.html）
$tplSfx  = $uiTpl !== '' ? UiTemplate::interactRel('web', $uiTpl) : null; // 模板自带交互音效（<id>/interact.js，hover/click）

// 官网背景图：分软件覆盖优先，回落总站配置。
//   http/https 外链 或 /uploads/... 站内路径（上传产物），自动铺满 + 暗色遮罩。
//   站内路径必须放行：上传功能存的就是 /uploads/web/...，外链图床不稳定时它是可靠兜底。
//   界面模板（farm/mario/ink/space）自带整套装饰背景，激活模板时背景图不生效。
$bgUrl   = trim(webSetting('web_bg_url', ''));
$bgUrlOk = $uiTpl === '' && $bgUrl !== '' && preg_match('#^(https?://|/)#i', $bgUrl);

// 总站白页（严格分软件官网模式）：由后台「系统设置 → 站点 → 总站白页」开关控制。
// 开启后多软件场景下只有 URL 显式携带有效 ?app= 才渲染对应软件官网；
// 不带 ?app= 一律显示极简「选择软件」白页 —— Cookie 记忆不再生效，
// 也不会出现「总站官网 + 下拉框选软件」的形态。
// 关闭时回到旧模式：总站渲染完整官网（全站文案），导航下拉 / ?app= 均可用。
if (Setting::bool('web_total_blank', false) && count($site['softwares']) > 1) {
    $appParam  = isset($_GET['app']) && is_string($_GET['app']) ? trim($_GET['app']) : '';
    $explicit  = $appParam !== '' && (bool) Software::byAppKey($appParam);
    if (!$explicit) {
        // 已激活用户（账号有软件归属）访问根路径：直达自己软件的分站官网
        $ownId = $user ? (int) ($user['software_id'] ?? 0) : 0;
        if ($ownId > 0) {
            foreach ($site['softwares'] as $pSw) {
                if ((int) $pSw['id'] === $ownId) {
                    header('Location: ./?app=' . urlencode($pSw['app_key']));
                    exit;
                }
            }
        }
        // 未登录 / 账号无软件归属：多软件下展示「选择软件」白页
        $pickBase = $webTheme['base'] ?? '#7c5cff';
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>选择软件 - <?= esc_html($site['site_name']) ?></title>
<link rel="icon" href="../favicon.ico">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    min-height: 100vh; display: grid; place-items: center;
    background: #f6f7fb; color: #1c2033;
    font-family: "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
    padding: 24px;
}
.pick { text-align: center; max-width: 760px; width: 100%; }
.pick img.logo { height: 52px; margin-bottom: 26px; }
.pick h1 { font-size: 24px; font-weight: 700; letter-spacing: .5px; }
.pick p.sub { margin-top: 10px; font-size: 14px; color: #7a8098; }
.cards {
    margin-top: 34px; display: grid; gap: 16px;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}
.card {
    display: block; padding: 26px 20px; border-radius: 16px;
    background: #fff; border: 1px solid #e6e8f0;
    text-decoration: none; color: inherit;
    transition: border-color .18s, box-shadow .18s, transform .18s;
}
.card:hover {
    border-color: <?= htmlspecialchars($pickBase) ?>;
    box-shadow: 0 14px 34px <?= htmlspecialchars($pickBase) ?>26;
    transform: translateY(-3px);
}
.card b { display: block; font-size: 17px; }
.card span {
    display: inline-block; margin-top: 10px; font-size: 13px; color: #7a8098;
}
.card:hover span { color: <?= htmlspecialchars($pickBase) ?>; }
.foot { margin-top: 40px; font-size: 12.5px; color: #a2a8bd; }
</style>
</head>
<body>
<main class="pick">
    <img class="logo" src="<?= esc_html($globalLogo) ?>" alt="<?= esc_html($site['site_name']) ?>">
    <h1>选择要进入的软件</h1>
    <p class="sub">每个软件拥有独立的官网、公告与账号体系</p>
    <div class="cards">
        <?php foreach ($site['softwares'] as $pSw): ?>
        <a class="card" href="./?app=<?= esc_attr($pSw['app_key']) ?>">
            <b><?= esc_html($pSw['name']) ?></b>
            <span>进入官网 &rarr;</span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="foot">&copy; <?= date('Y') ?> <?= esc_html($site['site_name']) ?></div>
</main>
</body>
</html>
    <?php
    exit;
    } // if (!$explicit)：无显式 ?app= 时终止，以下不再渲染
} // if (总站白页 && 多软件)

$runtime = [
    'api'          => 'api.php',
    'csrf'         => $csrf,
    'site'         => $site,
    'profile'      => $profile,
    'logged'       => (bool) $user,
    'version'      => NB_VERSION,
    'plans'        => $plans,
    'sellers'      => $sellers,
    'shots'        => $shots,
    'messages'     => $msgFirst,
    'feedbackTypes'=> $fbTypes,
    'messageOpen'  => (bool) $messageOpen,
    'unbindPerDay' => $unbindPerDay,
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= esc_html($site['site_name']) ?><?= $site['software'] ? ' · ' . esc_html($site['software']['name']) : '' ?></title>
<meta name="description" content="<?= esc_attr($site['site_name']) ?> · <?= esc_attr($site['site_sub']) ?>。一键呼出的游戏增强菜单，功能模块自由开关，注册即可使用。">
<link rel="icon" href="<?= esc_attr($site['favicon']) ?>">
<link rel="stylesheet" href="assets/css/site.css?v=<?= esc_attr(NB_VERSION) ?>">
<?php if ($webTheme && $uiTpl === ''): ?>
<style>:root {
    --primary: <?= esc_attr($webTheme['base']) ?>;
    --primary-2: <?= esc_attr($webTheme['light']) ?>;
<?php if ($primaryRgb !== ''): ?>    --primary-rgb: <?= esc_attr($primaryRgb) ?>;
<?php endif; ?>
<?php if ($primary2Rgb !== ''): ?>    --primary-2-rgb: <?= esc_attr($primary2Rgb) ?>;
<?php endif; ?>
}</style>
<?php endif; ?>
<?php if ($uiTpl !== ''): ?>
<!-- 模板样式：统一开发目录 web/Template/<id>/web.css（兼容 assets 旧位置），先共享后模板 -->
<link rel="stylesheet" href="assets/css/templates/_shared.css?v=<?= esc_attr(NB_VERSION) ?>">
<link rel="stylesheet" href="<?= UiTemplate::cssRel('web', $uiTpl) ?>?v=<?= esc_attr(UiTemplate::ver('web', $uiTpl)) ?>">
<?php endif; ?>
</head>
<body<?= $uiTpl !== '' ? ' class="ui-' . esc_attr($uiTpl) . '"' : '' ?><?= $tplGame ? ' data-game-frame="' . esc_attr($tplGame) . '?v=' . esc_attr(UiTemplate::ver('web', $uiTpl)) . '"' : '' ?>>

<!-- 背景装饰 -->
<div class="bg" aria-hidden="true">
    <?php if ($bgUrlOk): ?><span class="photo" style="background-image:url('<?= esc_attr($bgUrl) ?>')"></span><?php endif; ?>
    <span class="blob b1"></span>
    <span class="blob b2"></span>
    <span class="blob b3"></span>
    <span class="grid"></span>
</div>

<?php if ($site['maintain_mode']): ?>
<div class="maintain-bar"><?= esc_html($site['maintain_msg']) ?></div>
<?php endif; ?>

<!-- ===================== 顶部导航 ===================== -->
<header class="nav">
    <a class="brand" href="./">
        <img class="brand-logo" src="<?= esc_attr($site['logo']) ?>" alt="<?= esc_attr($site['site_name']) ?>">
        <span><?= esc_html($site['site_name']) ?></span>
    </a>

    <?php if (count($site['softwares']) > 1 && !Setting::bool('web_total_blank', false)): ?>
    <!-- 总站白页模式关闭时才显示导航软件下拉；开启后官网严格按 ?app= 区分，入口收敛到白页 -->
    <select class="sw-picker" id="swPicker" title="切换到对应软件的页面">
        <?php if (!$site['software']): ?><option value="" selected>选择软件</option><?php endif; ?>
        <?php foreach ($site['softwares'] as $__sw): ?>
        <option value="<?= esc_attr($__sw['app_key']) ?>"<?= ($site['software'] ? $site['software']['app_key'] : '') === $__sw['app_key'] ? ' selected' : '' ?>><?= esc_html($__sw['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="nav-drop">
        <nav class="nav-links" id="navLinks">
            <?php foreach ($site['nav_links'] as $nl): ?>
            <a href="<?= esc_attr($nl['href']) ?>"<?= $nl['href'] === '#feedback' ? ' data-feedback-link="1"' : '' ?>><?= esc_html($nl['text']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="nav-actions" id="navActions">
            <!-- 由 JS 渲染 -->
        </div>
    </div>

    <!-- 移动端汉堡按钮（≤900px 显示）：点击展开抽屉导航 -->
    <button class="nav-burger" id="navBurger" type="button" aria-label="打开菜单"><i></i><i></i><i></i></button>
</header>

<main>
<!-- ===================== 首页视图 ===================== -->
<div id="viewHome">
<?php
// ===== 官网区块布局：可被模板 css 头注释「Layout: 区块id, ...」重排/增删（规范见 docs/TEMPLATE.md 第六节）=====
// Layout 省略的区块不显示；不在内置清单的 id 会加载模板 sections/<id>.html 作为自定义区块（支持 {{SITE_NAME}} 占位）
$nbBuiltins = UiTemplate::BUILTIN_SECTIONS;
$secHtml = [];
foreach ($nbBuiltins as $nbKey) {
    ob_start();
    if ($nbKey === 'hero'): ?>
    <section class="hero">
        <div class="hero-inner">
            <span class="pill"><i class="dot"></i>服务运行中 · 当前在线 <b class="pill-online" id="pillOnline">--</b> 人 · 菜单最新版本 <b id="pillVersion">v<?= esc_html($site['latest_version']) ?></b></span>
            <h1><?= esc_html($site['hero_title']) ?><br><em><?= esc_html($site['hero_em']) ?></em></h1>
            <p class="lead">
                <?= esc_html($site['site_sub']) ?><br>
                <?= esc_html($site['hero_lead2']) ?>
            </p>

            <div class="hero-btns" id="heroBtns">
                <!-- 由 JS 渲染 -->
            </div>

            <div class="hero-stats">
                <?php foreach ($site['hero_stats'] as $st): ?>
                <div class="stat"><b data-stat="<?= esc_attr($st['v']) ?>"><?= esc_html(str_replace(['{online}', '{version}'], ['--', (string) $site['latest_version']], $st['v'])) ?></b><span><?= esc_html($st['l']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php elseif ($nbKey === 'features' && isset($sec['features'])): ?>
    <section id="features" class="section">
        <h2 class="sec-title"><?= esc_html($sec['features']) ?></h2>
        <p class="sec-sub"><?= esc_html($site['features_sub']) ?></p>
        <div class="cards">
            <?php foreach ($site['features'] as $f): ?>
            <article class="card">
                <i class="ico <?= esc_attr($f['cls']) ?>"><?= esc_html($f['icon']) ?></i>
                <h3><?= esc_html($f['title']) ?></h3>
                <p><?= esc_html($f['content']) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php elseif ($nbKey === 'shots' && isset($sec['shots'])): ?>
    <section id="shots" class="section">
        <h2 class="sec-title"><?= esc_html($sec['shots']) ?></h2>
        <p class="sec-sub">菜单界面与功能面板实拍</p>
        <div class="shots" id="shotGrid">
            <?php if (!$shots): ?>
                <div class="empty">暂无截图</div>
            <?php else: ?>
                <?php foreach ($shots as $i => $s): ?>
                <figure class="shot" data-shot="<?= (int) $i ?>">
                    <img src="<?= esc_attr($s['url']) ?>" alt="<?= esc_attr($s['title'] ?: '客户端截图') ?>"
                         loading="lazy" referrerpolicy="no-referrer">
                    <?php if ($s['title'] !== ''): ?>
                    <figcaption><?= esc_html($s['title']) ?></figcaption>
                    <?php endif; ?>
                </figure>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php elseif ($nbKey === 'flow' && isset($sec['flow'])): ?>
    <section id="flow" class="section">
        <h2 class="sec-title"><?= esc_html($sec['flow']) ?></h2>
        <p class="sec-sub"><?= esc_html($site['flow_sub']) ?></p>
        <ol class="steps">
            <?php foreach ($site['flow'] as $s): ?>
            <li>
                <span class="num"><?= esc_html($s['num']) ?></span>
                <h3><?= esc_html($s['title']) ?></h3>
                <p><?= esc_html($s['content']) ?></p>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php elseif ($nbKey === 'pricing' && isset($sec['pricing'])): ?>
    <section id="pricing" class="section">
        <h2 class="sec-title"><?= esc_html($sec['pricing']) ?></h2>
        <p class="sec-sub">按时长自由选择，购买后凭激活码在个人中心一键激活</p>
        <!-- 发卡商店入口已移至顶部导航栏（site.js 按站点 shop 配置渲染） -->
        <div class="plans" id="planGrid">
            <?php if (!$plans): ?>
                <div class="empty">暂无套餐</div>
            <?php else: ?>
                <?php foreach ($plans as $p): ?>
                <article class="plan<?= $p['highlight'] ? ' on' : '' ?>">
                    <?php if ($p['badge'] !== ''): ?>
                    <span class="plan-badge"><?= esc_html($p['badge']) ?></span>
                    <?php endif; ?>
                    <h3><?= esc_html($p['name']) ?></h3>
                    <div class="plan-price">
                        <b><?= esc_html($p['price']) ?></b><i><?= esc_html($p['unit']) ?></i>
                    </div>
                    <?php if ($p['duration'] !== ''): ?>
                    <div class="plan-duration">有效期 <?= esc_html($p['duration']) ?></div>
                    <?php endif; ?>
                    <?php if ($p['points']): ?>
                    <ul class="plan-points">
                        <?php foreach ($p['points'] as $pt): ?>
                        <li><?= esc_html($pt) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <button class="btn<?= $p['highlight'] ? ' primary' : ' ghost' ?> block" type="button"
                            data-buy="<?= esc_attr($p['name']) ?>">立即购买</button>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p class="plans-note">
            <?php if (!empty($site['shop']['enabled'])): ?>
                套餐内容以后台实际配置为准；如需长期使用或批量采购，可前往发卡网查看完整套餐并联系客服。
            <?php else: ?>
                套餐内容以后台实际配置为准；如需长期使用或批量采购，可点击「立即购买」查看联系方式。
            <?php endif; ?>
        </p>
    </section>
    <?php elseif ($nbKey === 'sellers' && isset($sec['sellers'])): ?>
    <section id="sellers" class="section">
        <h2 class="sec-title"><?= esc_html($sec['sellers']) ?></h2>
        <p class="sec-sub">以下为官方合作授权商家，请认准渠道谨慎购买</p>
        <div class="sellers" id="sellerGrid">
            <?php if (!$sellers): ?>
                <div class="empty">暂无合作商家</div>
            <?php else: ?>
                <?php foreach ($sellers as $s): ?>
                <article class="seller<?= $s['highlight'] ? ' on' : '' ?>">
                    <?php if ($s['badge'] !== ''): ?>
                    <span class="seller-badge"><?= esc_html($s['badge']) ?></span>
                    <?php endif; ?>
                    <div class="seller-head">
                        <?php if ($s['logo'] !== ''): ?>
                        <img class="seller-logo" src="<?= esc_attr($s['logo']) ?>" alt="<?= esc_attr($s['name']) ?>"
                             loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                        <span class="seller-logo seller-logo-ph"><?= esc_html(mb_substr($s['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                        <h3><?= esc_html($s['name']) ?></h3>
                    </div>
                    <?php if ($s['points']): ?>
                    <ul class="seller-points">
                        <?php foreach ($s['points'] as $pt): ?>
                        <li><?= esc_html($pt) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <div class="seller-foot">
                        <?php if ($s['contact'] !== ''): ?>
                        <button class="btn ghost sm" type="button" data-seller-contact="<?= (int) $s['id'] ?>">联系商家</button>
                        <?php endif; ?>
                        <?php if ($s['url'] !== ''): ?>
                        <a class="btn sm<?= $s['highlight'] ? ' primary' : ' ghost' ?>"
                           href="<?= esc_attr($s['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">进入店铺</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php elseif ($nbKey === 'notice'): ?>
    <section id="notice" class="section">
        <h2 class="sec-title">最新公告</h2>
        <p class="sec-sub">菜单更新与维护通知</p>
        <div class="notice-list" id="noticeList">
            <div class="empty">正在加载公告...</div>
        </div>
    </section>
    <?php elseif ($nbKey === 'board' && isset($sec['board'])): // 留言板开关同原逻辑（系统设置→开启留言） ?>
    <section id="board" class="section">
        <h2 class="sec-title"><?= esc_html($sec['board']) ?></h2>
        <p class="sec-sub">使用心得、问题交流与购买咨询，审核通过后展示</p>

        <div class="board" id="boardRoot">
            <!-- 发布区：未登录时显示登录引导 -->
            <div class="board-compose" id="boardCompose">
                <div class="compose-ask" id="composeAsk">
                    <span class="muted">登录后即可发布留言、点赞与回复</span>
                    <button class="btn primary xs" type="button" data-board-login>登录 / 注册</button>
                </div>

                <div class="compose-box" id="composeBox" hidden>
                    <textarea id="msgText" maxlength="500" rows="3"
                              placeholder="说点什么吧…（最多 500 字，通过审核后展示）"></textarea>
                    <div class="captcha-line">
                        <input class="captcha-input" id="msgCaptcha" maxlength="6" autocomplete="off"
                               spellcheck="false" placeholder="验证码">
                        <img class="captcha-img" id="msgCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                    </div>
                    <div class="compose-foot">
                        <span class="counter"><b id="msgCount">0</b> / 500</span>
                        <button class="btn primary" type="button" id="msgSubmit">发布留言</button>
                    </div>
                </div>

                <form class="compose-box" id="replyBox" hidden autocomplete="off">
                    <p class="reply-tip">回复 <b id="replyWho">-</b>
                        <button class="link-btn" type="button" id="replyCancel">取消</button>
                    </p>
                    <textarea id="replyText" maxlength="500" rows="2"
                              placeholder="写下你的回复…（最多 500 字）"></textarea>
                    <div class="captcha-line">
                        <input class="captcha-input" id="replyCaptcha" maxlength="6" autocomplete="off"
                               spellcheck="false" placeholder="验证码">
                        <img class="captcha-img" id="replyCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                    </div>
                    <div class="compose-foot">
                        <span class="counter"><b id="replyCount">0</b> / 500</span>
                        <button class="btn primary" type="submit" id="replySubmit">提交回复</button>
                    </div>
                </form>
            </div>

            <!-- 列表：服务端首屏渲染，翻页/点赞/发布后由 JS 重绘 -->
            <div class="board-list" id="msgList">
                <?php if (!$msgFirst['list']): ?>
                    <div class="empty">暂无留言，来发布第一条吧</div>
                <?php else: ?>
                    <?php foreach ($msgFirst['list'] as $m): ?>
                    <article class="msg" data-id="<?= (int) $m['id'] ?>">
                        <i class="msg-avatar"><?= esc_html(mb_substr($m['username'] ?: '?', 0, 1, 'UTF-8')) ?></i>
                        <div class="msg-main">
                            <div class="msg-head">
                                <b class="msg-name"><?= esc_html($m['username']) ?></b>
                                <?php if ($m['mine']): ?><span class="msg-tag">我</span><?php endif; ?>
                                <time><?= esc_html($m['created_at']) ?></time>
                            </div>
                            <p class="msg-text"><?= nl2br(esc_html($m['content'])) ?></p>
                            <div class="msg-acts">
                                <button class="act-like<?= $m['liked'] ? ' on' : '' ?>" type="button"
                                        data-like="<?= (int) $m['id'] ?>">
                                    <i>♥</i><span class="like-n"><?= (int) $m['likes'] ?></span>
                                </button>
                                <button class="act-reply" type="button" data-reply="<?= (int) $m['id'] ?>"
                                        data-name="<?= esc_attr($m['username']) ?>">回复</button>
                            </div>
                            <?php if ($m['replies']): ?>
                            <div class="msg-replies">
                                <?php foreach ($m['replies'] as $r): ?>
                                <div class="reply">
                                    <b><?= esc_html($r['username']) ?></b>
                                    <?php if ($r['reply_to'] !== ''): ?><em>回复 <?= esc_html($r['reply_to']) ?></em><?php endif; ?>
                                    <p><?= nl2br(esc_html($r['content'])) ?></p>
                                    <time><?= esc_html($r['created_at']) ?></time>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 分页（由 JS 重绘） -->
            <div class="board-pager" id="msgPager">
                <?php if (($msgFirst['pages'] ?? 1) > 1): ?>
                <button class="btn ghost xs" type="button" data-msg-page="<?= (int) $msgFirst['page'] - 1 ?>"
                        <?= $msgFirst['page'] <= 1 ? 'disabled' : '' ?>>上一页</button>
                <span class="pager-info">第 <?= (int) $msgFirst['page'] ?> / <?= (int) $msgFirst['pages'] ?> 页 · 共 <?= (int) $msgFirst['total'] ?> 条</span>
                <button class="btn ghost xs" type="button" data-msg-page="<?= (int) $msgFirst['page'] + 1 ?>"
                        <?= $msgFirst['page'] >= $msgFirst['pages'] ? 'disabled' : '' ?>>下一页</button>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php elseif ($nbKey === 'faq' && isset($sec['faq'])): ?>
    <section id="faq" class="section">
        <h2 class="sec-title"><?= esc_html($sec['faq']) ?></h2>
        <p class="sec-sub"><?= esc_html($site['faq_sub']) ?></p>
        <div class="faq">
            <?php foreach ($site['faq'] as $f): ?>
            <details>
                <summary><?= esc_html($f['q']) ?></summary>
                <p><?= esc_html($f['a']) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif;
    $secHtml[$nbKey] = ob_get_clean();
}
// 按 Layout 声明输出（未声明 = 内置默认顺序）；非内置 id = 模板自定义区块 sections/<id>.html
$nbOrder = UiTemplate::layout('web', $uiTpl) ?: $nbBuiltins;
foreach ($nbOrder as $nbId) {
    if (isset($secHtml[$nbId])) {
        echo $secHtml[$nbId];
    } elseif ($uiTpl !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $nbId)) {
        $nbCustom = UiTemplate::devDir() . '/' . $uiTpl . '/sections/' . $nbId . '.html';
        if (is_file($nbCustom)) {
            echo str_replace('{{SITE_NAME}}', esc_html((string) ($site['site_name'] ?? '')), (string) file_get_contents($nbCustom));
        }
    }
}
?>
</div>

<!-- ===================== 个人中心视图 ===================== -->
<div id="viewPanel" hidden>
    <div class="panel-wrap">

        <div class="panel-head">
            <div class="greet">
                <div class="avatar" id="pAvatar">N</div>
                <div>
                    <h2>你好，<span id="pName">-</span></h2>
                    <p class="muted" id="pUserLine">-</p>
                </div>
            </div>
            <div class="panel-head-btns">
                <button class="btn primary" id="btnDownload" type="button">下载客户端</button>
                <button class="btn ghost" id="btnRefresh">刷新</button>
                <button class="btn ghost danger-ghost" id="btnLogout">退出登录</button>
            </div>
        </div>

        <!-- 未激活提醒 -->
        <div class="alert warn" id="alertNotActive" hidden>
            <b>账号尚未激活</b>
            <span>请在下方输入购买的激活码完成激活，激活后即可登录软件使用。</span>
        </div>

        <div class="panel-grid">

            <!-- 左：账号资料 -->
            <section class="panel-card">
                <h3 class="card-title">账号资料</h3>
                <div class="vip-badge" id="pVipBadge">未激活</div>

                <ul class="kv">
                    <li><span>账号</span><b id="kUsername">-</b></li>
                    <li><span>用户组</span><b id="kGroup">-</b></li>
                    <li><span>会员状态</span><b id="kVip">-</b></li>
                    <li><span>到期时间</span><b id="kExpire">-</b></li>
                    <li><span>剩余时长</span><b id="kRemain">-</b></li>
                    <li><span>剩余点数</span><b id="kPoints">-</b></li>
                    <li><span>设备额度</span><b id="kDevices">-</b></li>
                    <li><span>注册时间</span><b id="kRegTime">-</b></li>
                    <li><span>上次登录</span><b id="kLastLogin">-</b></li>
                </ul>
            </section>

            <!-- 右：激活 + 设备 -->
            <div class="panel-right">

                <section class="panel-card activate-card" id="actCard">
                    <h3 class="card-title" id="actTitle">激活卡密</h3>

                    <!-- 未激活 / 试用中 / 普通会员：显示激活表单 -->
                    <div id="actNormal">
                        <p class="muted">输入购买到的激活码，点击激活即可延长会员时长或增加点数。</p>

                        <form id="actForm" class="act-form" autocomplete="off">
                            <input id="actCode" name="code" placeholder="XXXX-XXXX-XXXX-XXXX"
                                   maxlength="64" spellcheck="false"
                                   style="text-transform:uppercase" required>
                            <button class="btn primary" type="submit" id="actBtn">立即激活</button>
                        </form>

                        <div class="act-result" id="actResult" hidden></div>

                        <ul class="act-tips">
                            <li>激活码不区分大小写</li>
                            <li>同一激活码仅能使用一次</li>
                            <li>时长卡会在当前有效期上叠加</li>
                        </ul>
                    </div>

                    <!-- 永久会员：不显示激活表单，改为状态卡（由 JS 切换；文案后台可配） -->
                    <?php $nbForever = webForeverNotice(); ?>
                    <div id="actForever" hidden>
                        <span class="forever-badge"><i>◈</i> 永久会员</span>
                        <p class="muted"><?= esc_html($nbForever['main']) ?></p>
                        <ul class="act-tips">
                            <?php foreach ($nbForever['tips'] as $nbTip): ?>
                            <li><?= esc_html($nbTip) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>

                <section class="panel-card">
                    <h3 class="card-title">
                        已绑定设备
                        <span class="card-title-extra" id="devCount">-</span>
                        <button class="btn xs danger-ghost" id="btnUnbindAll" type="button" hidden>全部解绑</button>
                    </h3>
                    <div class="table-wrap">
                        <table class="tbl">
                            <thead>
                            <tr>
                                <th>设备名</th>
                                <th>机器码</th>
                                <th>状态</th>
                                <th>最近在线</th>
                                <th class="col-act">操作</th>
                            </tr>
                            </thead>
                            <tbody id="devBody">
                            <tr><td colspan="5" class="empty">正在加载...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hint-line">
                        设备在客户端登录时自动绑定；换电脑后可在上方直接<b>自助解绑</b>，
                        解绑后该设备立即释放额度，旧电脑将无法再登录。
                        <?php if ($unbindPerDay > 0): ?>
                        <b class="warn-text">每天最多可自助解绑 <?= (int) $unbindPerDay ?> 次</b>（含「全部解绑」），请谨慎操作。
                        <?php endif; ?>
                    </p>
                </section>
            </div>
        </div>

        <!-- ============ 发卡订单（发卡网购买记录） ============ -->
        <section class="panel-card">
            <h3 class="card-title">
                发卡订单
                <span class="card-title-extra" id="shopOrderCount">-</span>
            </h3>
            <p class="muted">在发卡商店（/shop/）购买的商品订单与卡密会显示在这里，登录后发卡网下单免填凭证。</p>
            <div class="table-wrap">
                <table class="tbl">
                    <thead>
                    <tr>
                        <th>商品</th>
                        <th>金额</th>
                        <th>数量</th>
                        <th>状态</th>
                        <th>卡密（激活状态）</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody id="shopOrderBody">
                    <tr><td colspan="7" class="empty">正在加载...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel-card">
            <h3 class="card-title">软件公告</h3>
            <div class="notice-list compact" id="noticeListPanel">
                <div class="empty">正在加载公告...</div>
            </div>
        </section>

        <!-- ============ 个人反馈中心 ============ -->
        <section class="panel-card" id="fbCard">
            <h3 class="card-title">
                我的反馈
                <span class="card-title-extra" id="fbCount">-</span>
                <button class="btn xs primary" id="fbNewBtn" type="button">提交反馈</button>
            </h3>

            <p class="muted fb-intro">
                功能建议、问题反馈、卡密或订单疑问都可以在这里提交，处理结果与客服回复会显示在下方。
            </p>

            <!-- 提交表单 -->
            <div class="fb-form-wrap" id="fbFormWrap" hidden>
                <form class="fb-form" id="fbForm" autocomplete="off">
                    <div class="row2">
                        <div class="field">
                            <label for="fbType">反馈类型</label>
                            <select id="fbType">
                                <?php foreach ($fbTypes as $k => $v): ?>
                                <option value="<?= (int) $k ?>"><?= esc_html($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="fbContact">联系方式（选填）</label>
                            <input id="fbContact" maxlength="100" placeholder="QQ / 微信 / 邮箱，便于客服回访">
                        </div>
                    </div>
                    <div class="field">
                        <label for="fbTitle">标题</label>
                        <input id="fbTitle" maxlength="60" placeholder="一句话概括，最多 60 字" required>
                    </div>
                    <div class="field">
                        <label for="fbContent">详细描述</label>
                        <textarea id="fbContent" rows="4" maxlength="2000"
                                  placeholder="请尽量描述清楚问题出现的场景、时间或订单信息，最多 2000 字"></textarea>
                    </div>
                    <div class="field">
                        <label for="fbCaptcha">验证码</label>
                        <div class="captcha-line">
                            <input class="captcha-input" id="fbCaptcha" maxlength="6" autocomplete="off"
                                   spellcheck="false" placeholder="请输入右侧验证码">
                            <img class="captcha-img" id="fbCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                        </div>
                    </div>
                    <div class="fb-form-btns">
                        <button class="btn primary" type="submit" id="fbSubmit">提交反馈</button>
                        <button class="btn ghost" type="button" id="fbCancel">取消</button>
                    </div>
                </form>
            </div>

            <!-- 列表 -->
            <div class="fb-list" id="fbList">
                <div class="empty">正在加载...</div>
            </div>
        </section>
    </div>
</div>
</main>

<!-- ===================== 页脚 ===================== -->
<footer class="foot">
    <div class="foot-inner">
        <div>
            <div class="foot-brand">
                <img class="foot-logo" src="<?= esc_attr($site['logo']) ?>" alt="<?= esc_attr($site['site_name']) ?>">
                <b><?= esc_html($site['site_name']) ?></b>
            </div>
            <p class="muted"><?= esc_html($site['foot_slogan']) ?></p>
        </div>
        <div class="foot-links">
            <?php foreach ($site['foot_links'] as $fl): ?>
            <a href="<?= esc_attr($fl['href']) ?>"><?= esc_html($fl['text']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="copyright"><?= esc_html($site['copyright'] !== '' ? $site['copyright'] : '© ' . date('Y') . ' ' . $site['site_name'] . '. All rights reserved.') ?></div>
    </div>
</footer>

<!-- ===================== 登录 / 注册弹窗 ===================== -->
<div class="modal" id="authModal" hidden>
    <div class="modal-card">
        <button class="modal-close" id="authClose" type="button" aria-label="关闭">×</button>

        <div class="tabs" id="authTabs">
            <button class="tab active" data-tab="login" type="button">登录</button>
            <button class="tab" data-tab="register" type="button" id="tabRegister"<?= $site['login']['method'] === 'code' ? ' hidden' : '' ?>>注册</button>
        </div>

        <!-- 登录：字段由后台「登录方式」决定（见 lib/LoginMethod.php），服务端渲染避免闪烁 -->
        <?php $lm = $site['login']; ?>
        <form class="auth-form" id="formLogin" autocomplete="on">
            <?php if ($lm['need_username']): ?>
            <div class="field" id="lgUserField">
                <label for="lgUser">账号</label>
                <input id="lgUser" name="username" autocomplete="username"
                       placeholder="<?= $lm['method'] === 'username_code' ? '用户名（首次填写即开通账号）' : '请输入用户名' ?>">
            </div>
            <?php endif; ?>
            <?php if ($lm['need_password']): ?>
            <div class="field" id="lgPassField">
                <label for="lgPass">密码</label>
                <input id="lgPass" name="password" type="password" autocomplete="current-password" placeholder="请输入密码">
            </div>
            <?php endif; ?>
            <?php if ($lm['need_code']): ?>
            <div class="field" id="lgCodeField">
                <label for="lgCode">激活码</label>
                <input id="lgCode" name="code" autocomplete="off" spellcheck="false"
                       placeholder="XXXX-XXXX-XXXX-XXXX" style="text-transform:uppercase">
            </div>
            <?php endif; ?>
            <div class="field">
                <label for="lgCaptcha">验证码</label>
                <div class="captcha-line">
                    <input class="captcha-input" id="lgCaptcha" maxlength="6" autocomplete="off"
                           placeholder="输入右侧字符" spellcheck="false" required>
                    <img class="captcha-img" id="lgCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                </div>
            </div>
            <button class="btn primary block" type="submit" id="lgBtn">登 录</button>
            <p class="form-note">
                <?php if ($lm['method'] === 'code'): ?>
                    请输入购买到的激活码登录，首次登录将自动开通账号
                    <?php if (Setting::bool('register_enable', true) === false) : ?>
                        <br>当前未开放注册
                    <?php endif; ?>
                <?php elseif ($lm['method'] === 'username_code'): ?>
                    首次使用：填写想要的用户名 + 激活码即可开通账号
                <?php else: ?>
                    还没有账号？<a href="javascript:void(0)" data-switch="register">立即注册</a>
                    <?php if (Setting::bool('login_reclaim_enable')): ?>
                        · <a href="javascript:void(0)" id="lgReclaimLink">忘记密码？用激活码找回</a>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </form>

        <!-- 激活码找回：仅密码登录方式且后台开关开启时出现 -->
        <?php if ($lm['need_password'] && Setting::bool('login_reclaim_enable')): ?>
        <form class="auth-form" id="formReclaim" autocomplete="off" hidden>
            <div class="field">
                <label for="rcCode">激活码</label>
                <input id="rcCode" autocomplete="off" spellcheck="false" placeholder="输入购买时获得的激活码" style="text-transform:uppercase">
            </div>
            <div class="field">
                <label for="rcUser">用户名</label>
                <div class="captcha-line">
                    <input class="captcha-input" id="rcUser" maxlength="32" autocomplete="username" placeholder="点「查询用户名」自动填入" spellcheck="false">
                    <button class="btn sm" type="button" id="rcLookup" style="flex-shrink:0">查询用户名</button>
                </div>
            </div>
            <div class="field">
                <label for="rcPass">新密码</label>
                <input id="rcPass" type="password" autocomplete="new-password" placeholder="6-64 位">
            </div>
            <div class="field">
                <label for="rcPass2">确认新密码</label>
                <input id="rcPass2" type="password" autocomplete="new-password" placeholder="再次输入新密码">
            </div>
            <div class="field">
                <label for="rcCaptcha">验证码</label>
                <div class="captcha-line">
                    <input class="captcha-input" id="rcCaptcha" maxlength="6" autocomplete="off" placeholder="输入右侧字符" spellcheck="false">
                    <img class="captcha-img" id="rcCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                </div>
            </div>
            <button class="btn primary block" type="submit" id="rcBtn">重置密码</button>
            <p class="form-note"><a href="javascript:void(0)" data-switch="login">返回登录</a></p>
        </form>
        <?php endif; ?>

        <!-- 注册 -->
        <form class="auth-form" id="formRegister" autocomplete="on" hidden>
            <div class="field">
                <label for="rgUser">用户名</label>
                <input id="rgUser" name="username" autocomplete="username" placeholder="3-32 位字母、数字、下划线或中文" required>
            </div>
            <div class="field">
                <label for="rgMail">邮箱（选填）</label>
                <input id="rgMail" name="email" type="email" autocomplete="email" placeholder="用于找回密码">
            </div>
            <div class="field">
                <label for="rgPass">密码</label>
                <input id="rgPass" name="password" type="password" autocomplete="new-password" placeholder="6-64 位" required>
            </div>
            <div class="field">
                <label for="rgPass2">确认密码</label>
                <input id="rgPass2" name="password2" type="password" autocomplete="new-password" placeholder="再次输入密码" required>
            </div>
            <div class="field">
                <label for="rgCaptcha">验证码</label>
                <div class="captcha-line">
                    <input class="captcha-input" id="rgCaptcha" maxlength="6" autocomplete="off"
                           placeholder="输入右侧字符" spellcheck="false" required>
                    <img class="captcha-img" id="rgCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                </div>
            </div>
            <button class="btn primary block" type="submit" id="rgBtn">注 册</button>
            <p class="form-note">
                已有账号？<a href="javascript:void(0)" data-switch="login">返回登录</a>
            </p>
        </form>

        <p class="modal-foot-note" id="modalNotice" hidden></p>
    </div>
</div>

<!-- ===================== 购买咨询弹窗 ===================== -->
<div class="modal" id="contactModal" hidden>
    <div class="modal-card sm">
        <button class="modal-close" id="contactClose" type="button" aria-label="关闭">×</button>
        <h3 class="modal-title">购买咨询</h3>
        <p class="modal-desc" id="contactTip">-</p>
        <div class="contact-body" id="contactBody"></div>
        <p class="modal-foot-note">购买后会收到激活码，在个人中心「激活卡密」处输入即可生效。</p>
    </div>
</div>

<!-- ===================== 通用确认弹窗 ===================== -->
<div class="modal" id="confirmModal" hidden>
    <div class="modal-card sm">
        <h3 class="modal-title" id="confirmTitle">确认操作</h3>
        <p class="modal-desc" id="confirmDesc">确定要执行此操作吗？</p>
        <div class="confirm-actions">
            <button class="btn ghost block" type="button" id="confirmCancel">取消</button>
            <button class="btn primary block" type="button" id="confirmOk">确定</button>
        </div>
    </div>
</div>

<!-- ===================== 截图灯箱 ===================== -->
<div class="lightbox" id="lightbox" hidden>
    <button class="lightbox-close" id="lightboxClose" type="button" aria-label="关闭">×</button>
    <img id="lightboxImg" src="" alt="">
    <p class="lightbox-cap" id="lightboxCap"></p>
</div>

<div id="toasts"></div>
<div id="loadingMask" hidden><div class="spinner"></div></div>

<script nonce="<?= NB_CSP_NONCE ?>">
window.__NB_WEB__ = <?= json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php if ($uiTpl !== ''): // 界面模板右下角小游戏（含跨用户排行榜；后台「内容运营」可开关） ?>
<?php
// 游戏时长按游戏可配：game_durations JSON {模板id:秒}（后台逐游戏设置），game_duration 为全局默认兜底
$nbDurations = [];
$nbDurRaw = json_decode((string) Setting::get('game_durations', ''), true);
if (is_array($nbDurRaw)) {
    foreach ($nbDurRaw as $nk => $nv) {
        $nk = (string) $nk; $nv = (int) $nv;
        if (preg_match('/^[a-z0-9_]{1,32}$/', $nk) && $nv >= 10 && $nv <= 300) { $nbDurations[$nk] = $nv; }
    }
}
?>
<script nonce="<?= NB_CSP_NONCE ?>">
window.__NB_GAMES__ = {
    enabled: <?= Setting::bool('web_games_enabled', true) ? 'true' : 'false' ?>,
    api: 'api.php',
    name: <?= json_encode($user ? (string) $user['username'] : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrf: <?= json_encode($csrf, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    cfg: {
        game: <?= json_encode($uiTpl) ?>,
        duration: <?= max(10, min(300, (int) Setting::get('game_duration', 30))) ?>,
        durations: <?= json_encode($nbDurations ?: new stdClass()) ?>,
        topN: <?= max(3, min(20, (int) Setting::get('game_top_n', 10))) ?>
    }
};
</script>
<script src="assets/js/<?= $tplGame ? 'portal-game-frame.js' : 'portal-games.js' ?>?v=<?= esc_attr(NB_VERSION) ?>"></script>
<?php endif; ?>
<script src="../assets/guard.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="../assets/input-filter.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="assets/js/site.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<?php if ($tplSfx): // 模板自带交互音效（hover / click 等），模板作者自行实现 ?>
<script src="<?= esc_attr($tplSfx) ?>?v=<?= esc_attr(UiTemplate::ver('web', $uiTpl)) ?>"></script>
<?php endif; ?>
</body>
</html>
