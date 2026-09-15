<?php
/**
 * 代理商后台 · 页面入口
 * ------------------------------------------------------------------
 * /agent/          -> 本文件（页面）
 * /agent/api.php   -> 接口
 *
 * 与主管理后台完全独立：独立会话 Cookie、独立账号表（nb_agents）、
 * 独立会话表（nb_agent_sessions），代理商看不到任何后台数据。
 *
 * 可选入口密钥（后台「系统设置 → 代理商设置」）：
 *   填写后必须先访问 /agent/?k=密钥 才能打开本页，否则返回仿真 404。
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 会话引导：与 api.php 共用同一套 Cookie 名，否则 CSRF 令牌不落在同一个会话里
define('NB_AGENT_ENTRY', true);
require_once __DIR__ . '/inc/session.php';

// 未安装时引导到安装向导
if (!is_file(NB_ROOT . '/install/install.lock')) {
    header('Location: ../install/install.php');
    exit;
}

// ------------------------------------------------------------------
// 功能总开关：关闭时给出明确的提示页（而不是 404，便于排查）
// ------------------------------------------------------------------
$agentEnabled = Agent::enabled();

// ------------------------------------------------------------------
// 入口密钥校验
// ------------------------------------------------------------------
if ($agentEnabled) {
    $entryKey = (string) Setting::get('agent_entry_key', '');
    if ($entryKey !== '') {
        $expect    = hash('sha256', $entryKey . '|' . Util::ip());
        $cookieVal = $_COOKIE['nb_agent_entry'] ?? '';
        $given     = isset($_GET['k']) ? (string) $_GET['k'] : '';

        if (hash_equals($expect, $cookieVal)) {
            // 已通过
        } elseif ($given !== '' && hash_equals($entryKey, $given)) {
            setcookie('nb_agent_entry', $expect, [
                'expires'  => time() + 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            fake_404_exit();
        }
    }

    // CSRF 令牌依赖会话，与主后台分开命名，互不干扰
    nb_agent_session_start();
}

$csrf     = $agentEnabled ? Util::csrfToken() : '';
$tokenKey = 'nb_at_' . substr(hash('sha256', (string) Config::get('crypto.aes_key', 'nb') . '|ag'), 0, 12);
$regOpen  = $agentEnabled && Setting::bool('agent_register_enable', true);
// 全局 Logo / 站点名（与主后台同源）
$agentLogo = Shop::logoUrl((string) Setting::get('shop_logo', ''));
if ($agentLogo === '') { $agentLogo = '../logo.png'; }
$agentSiteName = (string) Setting::get('site_name', 'Nebula');

$runtime = [
    'entry'    => 'api.php',
    'tokenKey' => $tokenKey,
    'csrf'     => $csrf,
    'headers'  => ['X-CSRF' => $csrf],
    'enabled'  => $agentEnabled,
    // 是否开放自助注册（后台「系统设置 → 代理商设置」控制）
    'register' => $regOpen,
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>Nebula Menu · 代理商后台</title>
<script nonce="<?= NB_CSP_NONCE ?>">
/* 防闪烁（FOUC）：与主后台同款，CSS 加载前把主题落到 <html data-theme> */
try {
    var t = localStorage.getItem('nb_agent_theme');
    if (t !== 'dark' && t !== 'light') {
        t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', t);
} catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
</script>
<link rel="stylesheet" href="assets/css/main.css?v=<?= esc_attr(NB_VERSION) ?>">
<link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css?v=<?= esc_attr(NB_VERSION) ?>">
</head>
<body>

<div id="bootMask"><div class="spinner"></div>正在加载代理商后台...</div>

<?php if (!$agentEnabled): ?>
<!-- ============ 未开放 ============ -->
<div id="closedPage" style="display:flex">
    <div class="login-box">
        <img class="logo" src="<?= esc_attr($agentLogo) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
        <h1>代理商后台未开放</h1>
        <div class="sub">管理员已在「系统设置 → 代理商设置」中关闭代理商功能，<br>如为代理商请联系管理员开通。</div>
    </div>
</div>
<?php else: ?>

<!-- ============ 登录页 ============ -->
<div id="loginPage" style="display:none">
    <form class="login-box" id="lgForm" method="post" action="javascript:void(0)" autocomplete="on">
        <img class="logo" src="<?= esc_attr($agentLogo) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
        <h1>代理商后台</h1>
        <div class="sub"><?= esc_html($agentSiteName) ?> 分销中心 · 请登录后生成卡密</div>
        <div class="field">
            <label for="lgUser">代理账号</label>
            <input id="lgUser" name="username" placeholder="请输入代理账号" autocomplete="username" required>
        </div>
        <div class="field">
            <label for="lgPass">密码</label>
            <input id="lgPass" name="password" type="password" placeholder="请输入密码" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="lgCaptcha">验证码</label>
            <div class="captcha-line">
                <input class="captcha-input" id="lgCaptcha" maxlength="6" autocomplete="off" placeholder="输入右侧字符" spellcheck="false" required>
                <img class="captcha-img" id="lgCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
            </div>
        </div>
        <button class="btn block" id="lgBtn" type="submit">登 录</button>
        <div class="hint" style="text-align:center;margin-top:14px;font-size:12px;color:#9ca3af">
            连续失败会临时锁定账号
        </div>
        <?php if ($regOpen): ?>
        <div style="text-align:center;margin-top:10px;font-size:13px">
            <a href="javascript:;" id="toReg" style="color:#4f46e5;text-decoration:none">
                还没有代理账号？凭激活码注册 →
            </a>
        </div>
        <?php else: ?>
        <div class="hint" style="text-align:center;margin-top:10px;font-size:12px;color:#9ca3af">
            当前未开放自助注册，账号请联系管理员开通
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($regOpen): ?>
<!-- ============ 注册页（凭主管理员生成的激活码） ============ -->
<div id="regPage" style="display:none">
    <form class="login-box" id="rgForm" method="post" action="javascript:void(0)" autocomplete="on">
        <img class="logo" src="<?= esc_attr($agentLogo) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
        <h1>代理商注册</h1>
        <div class="sub">请填写主管理员发放的专属激活码，发货规格由激活码决定</div>
        <div class="field">
            <label for="rgCode">代理商激活码 *</label>
            <input id="rgCode" placeholder="如 AGT-XXXX-XXXX" autocomplete="off" required>
        </div>
        <div class="row2">
            <div class="field">
                <label for="rgUser">代理账号 *</label>
                <input id="rgUser" placeholder="3-32 位字母数字" autocomplete="username" required>
            </div>
            <div class="field">
                <label for="rgNick">代理名称</label>
                <input id="rgNick" placeholder="如：老王工作室">
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <label for="rgPass">密码 *</label>
                <input id="rgPass" type="password" placeholder="至少 8 位" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="rgPass2">确认密码 *</label>
                <input id="rgPass2" type="password" placeholder="再输一次" autocomplete="new-password" required>
            </div>
        </div>
        <div class="field">
            <label for="rgContact">联系方式</label>
            <input id="rgContact" placeholder="QQ / 微信 / 邮箱（便于管理员联系你）">
        </div>
        <div class="field">
            <label for="rgCaptcha">验证码</label>
            <div class="captcha-line">
                <input class="captcha-input" id="rgCaptcha" maxlength="6" autocomplete="off" placeholder="输入右侧字符" spellcheck="false" required>
                <img class="captcha-img" id="rgCaptchaImg" alt="验证码，点击换一张" title="点击换一张">
            </div>
        </div>
        <button class="btn block success" id="rgBtn" type="submit">注 册</button>
        <div class="hint" style="margin-top:14px;font-size:12px;color:#9ca3af">
            注册后的卡密规格（激活用户组 / 设备上限 / 各类型额度与单价）由激活码写定，
            注册后不可自行修改，如需调整请联系管理员。
        </div>
        <div style="text-align:center;margin-top:10px;font-size:13px">
            <a href="javascript:;" id="toLogin" style="color:#4f46e5;text-decoration:none">已有账号？返回登录</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============ 主应用 ============ -->
<div id="app">
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <img class="logo" src="<?= esc_attr($agentLogo) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
                <div class="bt">
                    <div class="t1"><?= esc_html($agentSiteName) ?></div>
                    <div class="t2">代理商中心</div>
                </div>
            </div>
            <nav class="nav" id="nav"></nav>
            <div class="foot">
                <div id="sideAgent">-</div>
                <div style="margin-top:3px" id="sideQuota">-</div>
                <div style="margin-top:6px;font-size:11.5px;opacity:.55">v<?= esc_attr(NB_VERSION) ?></div>
            </div>
        </aside>
        <div class="main">
            <div class="topbar">
                <h2 id="pageTitle">概览</h2>
                <div class="user">
                    <span id="topQuota" style="color:#9ca3af;font-size:12px"></span>
                    <div class="avatar" id="topAvatar">A</div>
                    <button class="btn ghost sm" id="btnTheme" title="切换深色 / 浅色模式"><i class="bi bi-circle-half"></i></button>
                    <button class="btn ghost sm" id="btnLogout">退出</button>
                </div>
            </div>
            <div class="content" id="content"></div>
        </div>
    </div>
</div>

<?php endif; ?>

<div id="toasts"></div>
<div id="modalRoot"></div>

<script nonce="<?= NB_CSP_NONCE ?>">
window.__NBAG__ = <?= json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php if ($agentEnabled): ?>
<script src="../assets/guard.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="../assets/input-filter.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script type="module" src="assets/js/agent.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<?php endif; ?>
</body>
</html>
