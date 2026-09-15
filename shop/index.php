<?php
/**
 * Nebula Menu · 发卡商店（/shop/）
 * ------------------------------------------------------------------
 * 独立的发卡前台页面，复用官网 portal.php 引导与设计语言：
 *   · shop_mode=built     内置发卡：展示上架套餐，下单走易支付自动发卡
 *                         或人工确认（由后台 shop_pay_mode 决定）
 *   · shop_mode=external  外部发卡站：直接 302 跳转 shop_external_url，
 *                         本页面不做任何展示（跳不过去就是没配链接）
 *   · shop_enable=0       显示「发卡未开启」占位页
 *
 * 支付完成回跳：/shop/?o=订单号 → 页面自动查询并展示卡密。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/../web/inc/portal.php';

if (!is_file(NB_ROOT . '/install/install.lock')) {
    header('Location: ../install/install.php');
    exit;
}

// ------------------------------------------------------------------
// 外部发卡站模式：点击进来直接跳，不渲染任何页面
// ------------------------------------------------------------------
if (Shop::enabled() && Shop::mode() === 'external') {
    $ext = Shop::externalUrl();
    if ($ext !== '') {
        header('Location: ' . $ext);
        exit;
    }
    // 开了外链模式但没填链接：落到「未开启」占位页
    $shopOpen = false;
} else {
    $shopOpen = Shop::enabled() && Shop::mode() === 'built';
}

$site     = web_site_info();
$plans    = $shopOpen ? Shop::shopPlans() : [];
// 商品分类（按套餐填写的 shop_category 汇总，保持出现顺序）
$cats     = [];
foreach ($plans as $_p) {
    if ($_p['category'] !== '' && !in_array($_p['category'], $cats, true)) {
        $cats[] = $_p['category'];
    }
}
$payMode  = Shop::payMode();
// 自动支付判定与 createOrder 同口径：易支付配置齐全 或 当前驱动（码支付/V免签）配置齐全
$payReady = $payMode === 'auto' && (Shop::epayReady() || Pay::ready(Pay::active()));
$genmode  = Shop::cardGenMode();
// 下单/查询凭证形态（后台发卡网配置）：phone 手机号 / email 邮箱 / custom 自定义内容
$cMode   = Shop::contactMode();
$cLabels = Shop::contactModeLabel($cMode);
// auto 模式但易支付没配齐：实际走人工（与 Shop::createOrder 的降级口径一致）
$effectiveManual = !$shopOpen || !$payReady;
$manual   = Shop::manualInfo();
$style    = Shop::styleConfig();
$theme    = $style['theme'] !== '' ? Shop::themeColors($style['theme']) : [];
// 发卡网界面模板：'' = 默认深空 UI（不加 body class）。
// 模板自带整套配色（templates 文件夹），激活时跳过后台主题色内联样式避免打架。
// 可用模板由 UiTemplate 接口扫描模板文件夹自动识别（单文件 / 文件夹两种形态），未知值回落默认。
// 分软件覆盖：识别到访客软件（?app= → Cookie → 单软件）且该软件设了模板时优先用之（后台「模板管理 → 分软件发卡网模板」）。
$shopSwOv = [];
$shopSwId = Shop::visitorSoftwareId();
if ($shopSwId > 0) {
    $raw = trim((string) Setting::get('shop_sw_' . $shopSwId, ''));
    if ($raw !== '' && is_array($decoded = json_decode($raw, true))) {
        $shopSwOv = $decoded;
    }
}
$uiTpl   = UiTemplate::normalize('shop', (string) ($shopSwOv['shop_ui_template'] ?? Setting::get('shop_ui_template', '')));
$tplGame = $uiTpl !== '' ? UiTemplate::gameRel('shop', $uiTpl) : null; // 模板自带小游戏（<id>/game.html）
$tplSfx  = $uiTpl !== '' ? UiTemplate::interactRel('shop', $uiTpl) : null; // 模板自带交互音效（<id>/interact.js）
$csrf     = Util::csrfToken();
$me       = ShopAuth::user();
// 登录方式规格：发卡网登录/注册弹窗按后台「登录方式」渲染对应字段
$loginSpec = LoginMethod::spec();
$lmIsCard  = LoginMethod::isCard();
// 激活码找回（从卡密登录切回密码登录后，自动建号账号无密码）
$reclaimOn = !$lmIsCard && Setting::bool('login_reclaim_enable');

// 全站背景图（后台商店外观配置）：http(s) 外链或站内 /uploads/ 路径
$shopBg = trim((string) Setting::get('shop_bg_url', ''));
$shopBgOk = $shopBg !== '' && preg_match('#^(https?://|/)#i', $shopBg);

// 商店标题：后台自定义优先，缺省「站点名 · 发卡商店」
$shopTitle = $style['title'] !== '' ? $style['title'] : $site['site_name'] . ' · 发卡商店';

// 浏览器标签页标题：后台「发卡网配置」自定义（留空用商店标题）
$tabTitle = $style['tab_title'] !== '' ? $style['tab_title'] : $shopTitle;
// 离开页面（切走标签）时的闪动提醒文案（留空用默认；填 none 关闭该功能）
$tabAlert = $style['tab_alert'] !== '' ? $style['tab_alert'] : '快回来 ~ 还有卡密等你带走！';
if (strtolower(trim($tabAlert)) === 'none') {
    $tabAlert = '';
}

// 支付完成回跳的订单号（auto 模式从收银台回来带 ?o=）
$backOrderNo = strtoupper(trim((string) ($_GET['o'] ?? '')));
if (!preg_match('/^S[0-9]{12}[0-9A-F]{10}$/', $backOrderNo)) {
    $backOrderNo = '';
}

$runtime = [
    'api'       => 'api.php',
    'csrf'      => $csrf,
    'site_name' => $site['site_name'],
    'open'      => $shopOpen,
    'manual'    => $effectiveManual,   // 前端按这个决定下单表单形态
    'qrcode'    => $effectiveManual ? $manual['qrcode'] : '',
    'contact'   => $effectiveManual ? $manual['contact'] : '',
    'plans'     => $plans,
    'back_no'   => $backOrderNo,
    'cmode'     => $cMode,
    'clabel'    => $cLabels['label'],
    'cph'       => $cLabels['placeholder'],
    'dstyle'    => $style['detail'],
    'tabIcon'   => $style['tab_icon'],
    'genmode'   => Shop::cardGenMode(),
    'channels'  => array_values(array_filter(Shop::channels(), static function ($c) {
        // V免签仅支持支付宝/微信，剔除 QQ 钱包渠道
        return Pay::active() !== 'vmq' || $c['value'] !== 'qqpay';
    })),
    'login'     => $loginSpec,
    'user'      => $me,
];
// 弹窗公告内联渲染预处理：统一在默认弹窗壳的内容框里展示（不套整页 iframe）。
// · <style> 包进 <template>（浏览器不渲染不生效），由 shop.js 取出做作用域化后注入，样式只作用于弹窗内容框
// · 剥掉整份 HTML 文档的壳（doctype/head/html/body/meta/title/link），贴整份文档也正常
$shopPopupInline = function (string $html): string {
    $html = preg_replace_callback('/<style\b[^>]*>([\s\S]*?)<\/style>/i', static function ($m) {
        return '<template class="pop-style-tpl">' . $m[1] . '</template>';
    }, $html);
    return (string) preg_replace([
        '/<!DOCTYPE[^>]*>/i',
        '/<head[\s\S]*?<\/head>/i',
        '/<\/?(html|body)\b[^>]*>/i',
        '/<(meta|title|link)\b[^>]*>/i',
    ], '', $html);
};

// 官网软件上下文：官网 ?app=xxx 会写 Cookie nb_web_app（path=/），商店与官网同域可读。
// 返回官网的链接带上 ?app=，确保回到用户所属软件的分站官网；
// 官网侧 byAppKey 校验失败会自动回落总站，透传未知名安全。
$nbWebApp = isset($_COOKIE['nb_web_app']) && is_string($_COOKIE['nb_web_app'])
    ? preg_replace('/[^A-Za-z0-9_-]/', '', $_COOKIE['nb_web_app'])
    : '';
$webBack = '../web/' . ($nbWebApp !== '' ? '?app=' . urlencode($nbWebApp) : '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= esc_html($tabTitle) ?></title>
<meta name="description" content="<?= esc_attr($shopTitle) ?> 官方自动发卡，支付成功即时到卡。">
<link rel="icon" href="../favicon.ico">
<link rel="stylesheet" href="assets/shop.css?v=<?= esc_attr(NB_VERSION) ?>">
<?php if ($theme && $uiTpl === ''): ?>
<style>
/* 后台自定义主题色（覆盖 shop.css 的默认紫） */
:root { --primary: <?= esc_attr($theme['base']) ?>; --primary-2: <?= esc_attr($theme['light']) ?>; }
.btn.primary { background: linear-gradient(135deg, <?= esc_attr($theme['base']) ?>, <?= esc_attr($theme['light']) ?>); }
.goods-card.on { border-color: <?= esc_attr($theme['base']) ?>66; box-shadow: 0 18px 44px <?= esc_attr($theme['base']) ?>30; }
</style>
<?php endif; ?>
<?php if ($uiTpl !== ''): ?>
<!-- 模板样式：templates 文件夹（一个模板一个文件），先加载共享再加载当前模板 -->
<link rel="stylesheet" href="assets/templates/_shared.css?v=<?= esc_attr(NB_VERSION) ?>">
<link rel="stylesheet" href="<?= UiTemplate::cssRel('shop', $uiTpl) ?>?v=<?= esc_attr(UiTemplate::ver('shop', $uiTpl)) ?>">
<?php endif; ?>
<?php if ($tabAlert !== ''): ?>
<script nonce="<?= NB_CSP_NONCE ?>">
/* 浏览器标签页离开提醒：切走时标题闪动 + 图标变主题色圆点，回来后恢复 */
(function () {
    var base = document.title;
    var alertText = <?= json_encode($tabAlert, JSON_UNESCAPED_UNICODE) ?>;
    var dotColor = <?= json_encode($theme ? $theme['base'] : '#7c5cff') ?>;
    var iconMode = <?= json_encode($style['tab_icon'] ?: 'dot') ?>;
    var iconHref = document.querySelector('link[rel="icon"]');
    var iconSrc = iconHref ? iconHref.href : '';
    var flash, shown = false;
    function makeDot() {
        var c = document.createElement('canvas');
        c.width = c.height = 64;
        var g = c.getContext('2d');
        g.beginPath(); g.arc(32, 32, 28, 0, Math.PI * 2); g.fillStyle = dotColor; g.fill();
        if (iconMode === 'heart') {
            g.font = 'bold 36px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
            g.fillStyle = '#fff'; g.fillText('♥', 32, 34);
        }
        return c.toDataURL('image/png');
    }
    var dotIcon = null;
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            shown = true;
            if (iconMode !== 'keep') {
                if (!dotIcon) dotIcon = makeDot();
                if (iconHref) iconHref.href = dotIcon;
            }
            var i = 0;
            clearInterval(flash);
            flash = setInterval(function () {
                document.title = (i++ % 2 === 0) ? '🔔 ' + alertText : base;
            }, 1200);
        } else if (shown) {
            shown = false;
            clearInterval(flash);
            document.title = base;
            if (iconHref) iconHref.href = iconSrc;
        }
    });
})();
</script>
<?php endif; ?>
</head>
<body<?= $uiTpl !== '' ? ' class="ui-' . esc_attr($uiTpl) . '"' : '' ?><?= $tplGame ? ' data-game-frame="' . esc_attr($tplGame) . '?v=' . esc_attr(UiTemplate::ver('shop', $uiTpl)) . '"' : '' ?>>

<!-- 背景装饰（与官网同款极光底） -->
<div class="bg" aria-hidden="true">
    <?php if ($shopBgOk): ?><span class="photo" style="background-image:url('<?= esc_attr($shopBg) ?>')"></span><?php endif; ?>
    <span class="blob b1"></span>
    <span class="blob b2"></span>
    <span class="blob b3"></span>
    <span class="grid"></span>
</div>

<!-- 顶栏 -->
<header class="nav">
    <a class="brand" href="../web/">
        <img class="brand-logo" src="<?= $style['logo'] !== '' ? esc_attr($style['logo']) : '../logo.png' ?>" alt="<?= esc_html($site['site_name']) ?>">
        <span><?= esc_html($shopTitle) ?></span>
    </a>
    <div class="nav-actions">
        <?php if ($shopOpen): ?>
        <a class="btn ghost sm" href="#" id="navQuery">订单查询</a>
        <?php endif; ?>
        <?php if ($me): ?>
        <a class="btn ghost sm" href="#" id="navAccount">我的订单</a>
        <a class="btn ghost sm" href="#" id="navLogout" title="<?= esc_attr($me['username']) ?>"><?= esc_html($me['nickname']) ?> · 退出</a>
        <?php else: ?>
        <a class="btn ghost sm" href="#" id="navLogin">登录 / 注册</a>
        <?php endif; ?>
        <a class="btn ghost sm" href="<?= $webBack ?>">返回官网</a>
    </div>
</header>

<main class="wrap">

<?php if (!$shopOpen): ?>
    <!-- 发卡未开启占位 -->
    <section class="section">
        <div class="closed-card">
            <span class="closed-ico">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 4h16l1.5 5.2a3 3 0 0 1-5.9.8 3 3 0 0 1-5.7 0 3 3 0 0 1-5.9-.8L4 4Z"/>
                    <path d="M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9"/>
                    <path d="M9 20v-5h6v5"/>
                </svg>
            </span>
            <h1>发卡商店暂未开放</h1>
            <p class="muted">店主还没有开启自动发卡，请从官网「价格套餐」联系客服购买。</p>
            <a class="btn primary" href="<?= $webBack ?>#pricing">前往官网</a>
        </div>
    </section>

<?php else: ?>

    <div id="viewShop">
    <?php $shopSec = []; ob_start(); // ===== 区块 notice：公告 / 横幅 / 弹窗公告（后台自定义） ===== ?>
    <?php if ($style['notice'] !== ''): ?>
    <?php if (stripos($style['notice'], '<style') !== false || stripos($style['notice'], '<script') !== false): ?>
    <!-- 富文本公告（含 style/script）：iframe 沙箱隔离，样式不外泄到全站 -->
    <iframe class="notice-frame" sandbox="allow-scripts allow-popups allow-forms"
            srcdoc="<?= esc_attr($style['notice']) ?>"></iframe>
    <?php else: ?>
    <!-- 简单公告（纯文本/行内标签）：直接渲染 -->
    <div class="notice-bar"><?= $style['notice'] ?></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($style['banner'] !== ''): ?>
    <div class="shop-banner" id="shopBanner">
        <?php if (preg_match('/\.(mp4|webm|mov|m4v|ogv)(\?|#|$)/i', $style['banner'])): ?>
        <video src="<?= esc_attr($style['banner']) ?>" autoplay muted loop playsinline></video>
        <?php else: ?>
        <img src="<?= esc_attr($style['banner']) ?>" alt="<?= esc_attr($shopTitle) ?>" referrerpolicy="no-referrer">
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($style['popup'] !== ''): ?>
    <!-- 弹窗公告：统一在弹窗内容框里渲染（HTML/CSS 生效，样式由 shop.js 限定作用域不外泄） -->
    <div class="pop-notice" id="popNotice" hidden>
        <div class="pop-card">
            <button class="modal-close" data-close type="button" aria-label="关闭">&times;</button>
            <h3 class="modal-title">商店公告</h3>
            <div class="pop-body"><?= $shopPopupInline($style['popup']) ?></div>
            <div class="pop-actions">
                <button class="btn ghost" data-close type="button">关闭</button>
                <button class="btn primary" data-forever type="button">不再提示</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php $shopSec['notice'] = ob_get_clean(); ob_start(); // ===== 区块 notes：购买须知（后台可配置：每行一条，格式「标题|内容」，留空隐藏板块） ===== ?>
    <?php $shopNotes = array_values(array_filter(array_map('trim', explode("\n", $style['notes'])), 'strlen')); ?>
    <?php if ($shopNotes): ?>
    <section class="section">
        <h2 class="sec-title">购买须知</h2>
        <div class="notes">
            <?php foreach ($shopNotes as $i => $line): ?>
            <?php $parts = array_map('trim', preg_split('/[|｜]/u', $line, 2)); ?>
            <div class="note"><b><?= (count($parts) > 1 ? esc_html($parts[0]) : ((string) ($i + 1) . '. ' . esc_html($parts[0]))) ?></b><span><?= count($parts) > 1 ? esc_html($parts[1]) : '' ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php $shopSec['notes'] = ob_get_clean(); ob_start(); // ===== 区块 goods：商品区 ===== ?>
    <section class="section">
        <h1 class="sec-title">选购套餐</h1>
        <p class="sec-sub">在线支付成功即时自动发货 · 卡密当场展示，登录后可站内一键激活</p>

        <?php $sidebar = $style['layout'] === 'sidebar'; $pick = $style['layout'] === 'pick'; ?>
        <?php if ($sidebar): ?><div class="side-wrap"><?php endif; ?>
        <?php
        // 分类页签数据：后台配置（shop_cats，带图标）优先，未配置的既有分类按出现顺序补在后面
        $catTabs = [];
        foreach ($style['cats'] as $c) { $catTabs[$c['name']] = $c['icon']; }
        foreach ($cats as $cat) { if (!array_key_exists($cat, $catTabs)) { $catTabs[$cat] = ''; } }
        ?>
        <?php if (($cats || $style['cats']) && !$pick): ?>
        <div class="cat-tabs<?= $sidebar ? ' vertical' : '' ?>" id="catTabs">
            <button type="button" class="on" data-cat="">全部</button>
            <?php foreach ($catTabs as $cat => $icon): ?>
            <button type="button" data-cat="<?= esc_attr($cat) ?>"><?php if ($icon !== ''): ?><img class="cicon" src="<?= esc_attr($icon) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?><?= esc_html($cat) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($pick && $plans): ?>
        <!-- 选择式布局：分类 + 商品按钮挑选，点商品在下方展开详情卡 -->
        <div class="pick-box" id="pickBox">
            <div class="pick-label">请选择商品分类</div>
            <div class="pick-row" id="pickCats">
                <?php if ($catTabs): ?><button type="button" class="on" data-cat="">请选择分类</button><?php endif; ?>
                <?php foreach (array_keys($catTabs) as $cat): ?>
                <button type="button" data-cat="<?= esc_attr($cat) ?>"><?= esc_html($cat) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="pick-panel" id="pickPanel">
                <?php if ($catTabs): ?><div class="pick-hint" id="pickHint">请选择商品分类</div><?php endif; ?>
                <div id="pickGoodsArea"<?= $catTabs ? ' hidden' : '' ?>>
                    <div class="pick-label">请选择商品</div>
                    <div class="pick-row" id="pickGoods">
                        <?php foreach ($plans as $p): ?>
                        <button type="button" data-pick="<?= (int) $p['id'] ?>" data-pcat="<?= esc_attr($p['category']) ?>"><?= esc_html($p['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="pick-detail" id="pickDetail" hidden></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$plans): ?>
            <div class="empty">暂无上架商品，请联系客服购买</div>
        <?php else: ?>
        <div class="goods-main">
        <div class="goods ly-<?= esc_attr($style['layout']) ?>" id="goodsGrid"<?= $pick ? ' hidden' : '' ?>>
            <?php foreach ($plans as $p): ?>
            <article class="goods-card<?= $p['highlight'] ? ' on' : '' ?>" data-cat="<?= esc_attr($p['category']) ?>">
                <?php if ($p['badge'] !== ''): ?>
                <span class="badge"><?= esc_html($p['badge']) ?></span>
                <?php endif; ?>
                <?php if ($p['icon'] !== ''): ?>
                <img class="gicon" src="<?= esc_attr($p['icon']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                <?php endif; ?>
                <h3><?php if ($p['icon'] !== '' && !in_array($style['layout'], ['rows', 'sidebar', 'pick'], true)): ?><img class="ticon" src="<?= esc_attr($p['icon']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?><?= esc_html($p['name']) ?></h3>
                <?php $ptext = (string) ($p['price_text'] ?? (string) $p['shop_price']); ?>
                <div class="price"><b><?= $ptext === '免费' ? '免费' : '&yen;' . esc_html($ptext) ?></b></div>
                <div class="intro"><?= esc_html($p['intro'] !== '' ? $p['intro'] : ($p['is_ext'] ? '付款后自动发货' : $p['spec_text'])) ?></div>
                <div class="spec"><?= esc_html($p['spec_text']) ?></div>
                <?php if ($p['duration'] !== ''): ?>
                <div class="sub"><?= esc_html($p['duration']) ?></div>
                <?php endif; ?>
                <?php if ($p['points']): ?>
                <ul class="points">
                    <?php foreach ($p['points'] as $pt): ?>
                    <li><?= esc_html($pt) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="stock-line">
                    <?php if ($p['stock'] > 0): ?>
                        <span class="stock ok">有货 <b><?= (int) $p['stock'] ?></b> 张</span>
                    <?php elseif ($p['is_ext']): ?>
                        <span class="stock out">暂时缺货</span>
                    <?php elseif ($genmode !== 'stock'): ?>
                        <span class="stock ok">可自动生成</span>
                    <?php else: ?>
                        <span class="stock out">暂时缺货</span>
                    <?php endif; ?>
                </div>
                <button class="btn primary block"
                        type="button" data-goods="<?= (int) $p['id'] ?>">查看详情</button>
            </article>
            <?php endforeach; ?>
        </div>
        </div><!-- /goods-main -->
        <?php endif; ?>
        <?php if ($sidebar): ?></div><?php endif; ?>
    </section>
    <?php $shopSec['goods'] = ob_get_clean();
    // 按模板 shop.css 头注释 Layout: 声明输出（未声明 = 内置顺序 notice→notes→goods）；
    // 非内置 id = 模板自定义区块 web/Template/<模板>/shop-sections/<id>.html（支持 {{SITE_NAME}} 占位）
    $shopOrder = UiTemplate::layout('shop', $uiTpl) ?: UiTemplate::BUILTIN_SHOP_SECTIONS;
    foreach ($shopOrder as $shopSid) {
        if (isset($shopSec[$shopSid])) {
            echo $shopSec[$shopSid];
        } elseif ($uiTpl !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $shopSid)) {
            $shopCustom = UiTemplate::devDir() . '/' . $uiTpl . '/shop-sections/' . $shopSid . '.html';
            if (is_file($shopCustom)) {
                echo str_replace('{{SITE_NAME}}', esc_html((string) ($site['site_name'] ?? '')), (string) file_get_contents($shopCustom));
            }
        }
    }
    ?>
    </div><!-- /viewShop -->

    <div id="viewDetail" hidden>
    <!-- ===================== 商品整页详情（后台 shop_detail_style=page 时启用） ===================== -->
    <section class="section">
        <button class="btn ghost sm" id="detailBack" type="button">&larr; 返回商店</button>
        <div class="detail-page" id="detailPage"></div>
    </section>
    </div><!-- /viewDetail -->

    <div id="viewQuery" hidden>
    <!-- ===================== 订单查询（导航栏切换，独立视图） ===================== -->
    <section class="section" id="query">
        <h2 class="sec-title">订单查询</h2>
        <p class="sec-sub">下单后请保存订单号；支付完成回到本页会自动展示卡密</p>
        <div class="query-card">
            <div class="qswitch" id="querySwitch">
                <button type="button" class="on" data-qt="no">按订单号</button>
                <button type="button" data-qt="contact">按<?= esc_html($cLabels['label']) ?>+密码</button>
            </div>
            <form class="query-form" id="queryForm" autocomplete="off">
                <input id="queryNo" maxlength="25" spellcheck="false"
                       placeholder="输入订单号，如 S260911123456A1B2C3">
                <input id="queryContact" maxlength="100" spellcheck="false" hidden
                       placeholder="下单时填写的<?= esc_html($cLabels['label']) ?>">
                <input id="queryPwd" type="password" maxlength="32" hidden
                       placeholder="下单时设置的查询密码">
                <button class="btn primary" type="submit" id="queryBtn">查询订单</button>
            </form>
            <div class="query-result" id="queryResult" hidden></div>
        </div>
    </section>
    </div><!-- /viewQuery -->

    <div id="viewAccount" hidden>
    <!-- ===================== 我的订单（登录后查看关联订单与激活状态） ===================== -->
    <section class="section">
        <h2 class="sec-title">我的订单</h2>
        <p class="sec-sub">账号关联的发卡订单与卡密激活状态</p>
        <div class="query-card" id="accountBox"><div class="empty">加载中…</div></div>
    </section>
    </div><!-- /viewAccount -->

<?php endif; ?>
</main>

<footer class="foot">
    <?php if ($style['footer'] !== ''): ?>
    <span><?= esc_html($style['footer']) ?></span>
    <?php else: ?>
    <span>&copy; <?= date('Y') ?> <?= esc_html($site['site_name']) ?></span>
    <?php endif; ?>
    <a href="<?= $webBack ?>">官网首页</a>
</footer>

<!-- ===================== 下单弹窗（弹窗形态；内容 JS 按 dstyle 填充） ===================== -->
    <!-- ===================== 登录 / 注册弹窗 ===================== -->
    <div class="modal" id="authModal" hidden>
        <div class="modal-card auth-card">
            <button class="modal-close" id="authClose" type="button" aria-label="关闭">&times;</button>
            <h3 class="modal-title" id="authTitle"><?= $lmIsCard ? '登录 / 开通' : '登录' ?></h3>
            <?php if (!$lmIsCard): ?>
            <div class="qswitch">
                <button type="button" class="on" data-at="login">登录</button>
                <button type="button" data-at="register">注册</button>
            </div>
            <?php endif; ?>
            <form id="authForm" autocomplete="off">
                <?php if ($loginSpec['need_username']): ?>
                <div class="field" id="authUserRow"><label>用户名</label>
                    <input id="authUser" maxlength="32" spellcheck="false"
                           placeholder="<?= $lmIsCard ? '填写想要的用户名（首次输入即开通账号）' : '3-32 位字母、数字或下划线' ?>"></div>
                <?php endif; ?>
                <?php if ($loginSpec['need_password']): ?>
                <div class="field" id="authPwdRow"><label>密码</label>
                    <input id="authPwd" type="password" maxlength="64" placeholder="6-64 位"></div>
                <?php endif; ?>
                <?php if ($loginSpec['need_code']): ?>
                <div class="field" id="authCodeRow"><label>激活码</label>
                    <input id="authCode" maxlength="64" spellcheck="false" style="text-transform:uppercase"
                           placeholder="XXXX-XXXX-XXXX-XXXX"></div>
                <?php endif; ?>
                <div class="field" id="authEmailRow" hidden><label>邮箱（可选）</label>
                    <input id="authEmail" maxlength="128" placeholder="用于找回密码与接收通知"></div>
                <div class="field"><label>验证码</label>
                    <div class="captcha-line">
                        <input class="captcha-input" id="authCaptcha" maxlength="6" autocomplete="off"
                               spellcheck="false" placeholder="输入右侧字符">
                        <img class="captcha-img" id="authCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                    </div>
                </div>
                <button class="btn primary block" type="submit" id="authBtn"><?= $lmIsCard ? '登录 / 开通' : '登录' ?></button>
                <?php if ($reclaimOn): ?>
                <p class="auth-tip"><a href="javascript:void(0)" id="reclaimLink">忘记密码？用激活码找回用户名 / 设置新密码</a></p>
                <?php endif; ?>
                <p class="auth-tip"><?php if ($loginSpec['method'] === 'code'): ?>输入激活码即可登录或自动开通账号并激活<?php elseif ($lmIsCard): ?>用户名 + 激活码：首次输入即自动开通账号并激活<?php else: ?>与官网客户端共用同一账号，激活过的卡密两边互通<?php endif; ?></p>
            </form>
            <?php if ($reclaimOn): ?>
            <form id="reclaimForm" autocomplete="off" hidden>
                <div class="field"><label>激活码（绑定哪个账号就找回哪个）</label>
                    <input id="rcCode" maxlength="64" spellcheck="false" style="text-transform:uppercase"
                           placeholder="XXXX-XXXX-XXXX-XXXX"></div>
                <div class="field"><label>用户名</label>
                    <div class="logo-row">
                        <input id="rcUser" maxlength="32" spellcheck="false" placeholder="可点右侧按钮自动填入">
                        <button class="btn ghost sm" type="button" id="rcLookup">查询用户名</button>
                    </div>
                </div>
                <div class="row2">
                    <div class="field"><label>新密码</label>
                        <input id="rcPass" type="password" maxlength="64" placeholder="6-64 位"></div>
                    <div class="field"><label>确认新密码</label>
                        <input id="rcPass2" type="password" maxlength="64" placeholder="再次输入新密码"></div>
                </div>
                <div class="field"><label>验证码</label>
                    <div class="captcha-line">
                        <input class="captcha-input" id="rcCaptcha" maxlength="6" autocomplete="off"
                               spellcheck="false" placeholder="输入右侧字符">
                        <img class="captcha-img" id="rcCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
                    </div>
                </div>
                <button class="btn primary block" type="submit" id="rcBtn">设置新密码</button>
                <p class="auth-tip"><a href="javascript:void(0)" id="reclaimBack">返回登录</a></p>
            </form>
            <?php endif; ?>
        </div>
    </div>

<div class="modal" id="orderModal" hidden>
    <div class="modal-card" id="modalHost"></div>
</div>

<div id="toasts"></div>

<script nonce="<?= NB_CSP_NONCE ?>">
window.__NB_SHOP__ = <?= json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php if ($uiTpl !== ''): // 界面模板右下角小游戏（含跨用户排行榜；后台「发卡网配置」可开关） ?>
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
    enabled: <?= Setting::bool('shop_games_enabled', true) ? 'true' : 'false' ?>,
    api: 'api.php',
    name: <?= json_encode($me ? (string) $me['username'] : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrf: <?= json_encode($csrf, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    cfg: {
        game: <?= json_encode($uiTpl) ?>,
        duration: <?= max(10, min(300, (int) Setting::get('game_duration', 30))) ?>,
        durations: <?= json_encode($nbDurations ?: new stdClass()) ?>,
        topN: <?= max(3, min(20, (int) Setting::get('game_top_n', 10))) ?>
    }
};
</script>
<script src="../web/assets/js/<?= $tplGame ? 'portal-game-frame.js' : 'portal-games.js' ?>?v=<?= esc_attr(NB_VERSION) ?>"></script>
<?php endif; ?>
<?php if ($tplSfx): // 模板自带交互音效（悬停/点击/选分类/选商品），模板作者自行实现 ?>
<script src="<?= esc_attr($tplSfx) ?>?v=<?= esc_attr(UiTemplate::ver('shop', $uiTpl)) ?>"></script>
<?php endif; ?>
<script src="../assets/guard.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="../assets/input-filter.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="assets/shop.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
</body>
</html>
