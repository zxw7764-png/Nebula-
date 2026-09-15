<?php
/**
 * 管理后台页面入口
 * ------------------------------------------------------------------
 * 与 index.php（API 入口）分离：
 *   /admin/          -> 本文件（页面）
 *   /admin/index.php -> API
 *
 * 由 PHP 输出 HTML 骨架，动态注入运行时参数：
 *   - 接口入口地址
 *   - 会话令牌存储 key（随机化）
 *   - 一次性 CSRF 令牌
 * 前端业务逻辑全部在 assets/js/ 下，按模块拆分，不在此内联。
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 会话 Cookie 引导（P1-09）：与 API 入口共用同一套 Cookie 参数
SessionCookie::init(
    'nb_admin_sid',
    'admin.cookie_session',
    'admin.cookie_name',
    'admin.cookie_secure'
);

// 未安装时引导到安装向导
if (!is_file(NB_ROOT . '/install/install.lock')) {
    header('Location: ../install/install.php');
    exit;
}

// ------------------------------------------------------------------
// 后台入口保护（可选）
// 入口 token 优先读数据库（安装时自动生成），未设置时回落 config.php
// ------------------------------------------------------------------
$entryKey = (string) (Setting::get('admin_entry_key') ?: Config::get('admin.entry_key', ''));
if ($entryKey !== '') {
    $given = isset($_GET['k']) ? (string) $_GET['k'] : '';
    $cookieVal = $_COOKIE['nb_entry'] ?? '';
    $expectCookie = hash('sha256', $entryKey . '|' . Util::ip());

    if (hash_equals($expectCookie, $cookieVal)) {
        // cookie 有效，放行
    } elseif ($given !== '' && hash_equals($entryKey, $given)) {
        setcookie('nb_entry', $expectCookie, [
            'expires'  => time() + 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // 入口密钥缺失或错误：返回仿真 404，不暴露后台存在
        fake_404_exit();
    }
}

// ------------------------------------------------------------------
// 动态生成前端运行参数
// ------------------------------------------------------------------
$tokenKey = 'nb_t_' . substr(hash('sha256', (string) Config::get('crypto.aes_key', 'xr') . '|tk'), 0, 12);
$csrf     = Util::csrfToken();

$runtime = [
    'entry'    => 'index.php',
    'tokenKey' => $tokenKey,
    'csrf'     => $csrf,
    'headers'  => ['X-CSRF' => $csrf],
    // Cookie 会话模式（P1-09）：true 时前端不再把令牌写 localStorage，
    // 完全依赖 HttpOnly Cookie 由浏览器自动携带。
    // 前端仍会保存 session_key（P0-02 的第二因子），它在 Cookie 里没有。
    'cookie'   => SessionCookie::enabled(),
];

// 侧边栏品牌区：站点名称（后台「站点设置」里改的），未设置时回落 Nebula Menu
$siteName = trim((string) (Setting::get('site_name') ?: 'Nebula Menu'));
// 全局 Logo：后台「系统设置 → 站点」设置，官网 / 发卡网 / 管理后台共用
$adminLogo = Shop::logoUrl((string) Setting::get('shop_logo', ''));
if ($adminLogo === '') { $adminLogo = '../logo.png'; }

// ------------------------------------------------------------------
// 登录页是否显示「动态验证码」：
//   本站只要有任一个管理员开启了二次验证 → 显示（开了 2FA 的账号可一步登录完，
//   不用先失败一次等服务端回 2006 才展开输入框）；
//   全站都没开启 → 整个字段不渲染，不给普通站点添乱。
// 只暴露「本站启用了 2FA」这个事实，不暴露是哪个账号，因此不构成账号枚举。
// ------------------------------------------------------------------
$totpInUse = AdminAuth::totpInUse();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>Nebula Menu · 管理后台</title>
<script nonce="<?= NB_CSP_NONCE ?>">
/* 防闪烁（FOUC）：在 CSS 加载前把主题落到 <html data-theme> 上，
   与 assets/js/core/theme.js 的读写规则保持一致（localStorage: nb_theme） */
(function () {
    try {
        var t = localStorage.getItem('nb_theme');
        if (t !== 'dark' && t !== 'light') {
            t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
    } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
})();
</script>
<link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css?v=<?= esc_attr(NB_VERSION) ?>">
<link rel="stylesheet" href="assets/css/main.css?v=<?= esc_attr(NB_VERSION) ?>">
</head>
<body>

<div id="bootMask"><div class="spinner"></div>正在加载管理后台...</div>

<!-- ============ 登录页 ============ -->
<div id="loginPage" style="display:none">
    <form class="login-box" id="lgForm" method="post" action="javascript:void(0)" autocomplete="on">
        <img class="logo" src="<?= esc_attr($GLOBALS['adminLogo']) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
        <h1>Nebula Menu</h1>
        <div class="sub">管理后台 · 请登录后操作</div>
        <div class="field">
            <label for="lgUser">管理员账号</label>
            <input id="lgUser" name="username" placeholder="请输入账号" autocomplete="username" required>
        </div>
        <div class="field">
            <label for="lgPass">密码</label>
            <input id="lgPass" name="password" type="password" placeholder="请输入密码" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="lgCaptcha">图形验证码</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input id="lgCaptcha" name="captcha" maxlength="6" placeholder="请输入图中字符"
                       autocomplete="off" spellcheck="false" required
                       style="flex:1;padding:10px 13px;border:1px solid var(--border);border-radius:10px;background:var(--input-bg);color:var(--text)">
                <img id="lgCaptchaImg" class="captcha-img" alt="验证码" title="点击刷新"
                     style="width:120px;height:38px;border-radius:8px;cursor:pointer;border:1px solid var(--border)">
            </div>
        </div>
        <?php if ($totpInUse): ?>
        <!-- 二次验证：仅当本站有账号开启时才渲染（见文件头 $totpInUse）。
             这样开了 2FA 的账号可以直接填码登录，不必先失败一次等服务端要求补码。 -->
        <div class="field" id="lgTotpWrap">
            <label for="lgTotp">动态验证码</label>
            <input id="lgTotp" name="totp" maxlength="10" inputmode="numeric"
                   placeholder="验证器 6 位码，或一次性恢复码"
                   autocomplete="one-time-code" spellcheck="false"
                   style="width:100%;padding:10px 13px;border:1px solid var(--border);border-radius:10px;background:var(--input-bg);color:var(--text)">
        </div>
        <?php endif; ?>
        <button class="btn block" id="lgBtn" type="submit">登 录</button>
        <div class="hint" style="text-align:center;margin-top:14px;font-size:12px;color:#9ca3af">
            连续登录失败会触发临时锁定
        </div>
    </form>
</div>

<!-- ============ 主应用 ============ -->
<div id="app">
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <img class="logo" src="<?= esc_attr($GLOBALS['adminLogo']) ?>?v=<?= esc_attr(NB_VERSION) ?>" alt="logo">
                <div class="bt">
                    <div class="t1"><?= esc_attr($siteName) ?></div>
                    <div class="t2">后台管理</div>
                </div>
            </div>
            <nav class="nav" id="nav"></nav>
            <div class="foot">
                <div id="sideAdmin">-</div>
                <div style="margin-top:3px" id="sideRole">-</div>
                <div style="margin-top:6px;font-size:11.5px;opacity:.55" id="sideVer">v<?= esc_attr(NB_VERSION) ?></div>
            </div>
        </aside>
        <div class="main">
            <div class="topbar">
                <h2 id="pageTitle">概览</h2>
                <div class="user">
                    <span id="topServer" style="color:#9ca3af;font-size:12px"></span>
                    <div class="avatar" id="topAvatar">A</div>
                    <button class="btn ghost sm" id="btnTheme" title="切换深色 / 浅色模式"><i class="bi bi-circle-half"></i></button>
                    <button class="btn ghost sm" id="btnLogout">退出</button>
                </div>
            </div>
            <div class="subtabs" id="subTabs"></div>
            <div class="content" id="content"></div>
        </div>
    </div>
</div>

<div id="toasts"></div>
<div id="modalRoot"></div>

<script nonce="<?= NB_CSP_NONCE ?>">
window.__NB__ = <?= json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="../assets/guard.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script src="../assets/input-filter.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
<script nonce="<?= NB_CSP_NONCE ?>">window.NB_V = '<?= esc_attr(NB_VERSION) ?>';</script>
<script type="module" src="assets/js/app.js?v=<?= esc_attr(NB_VERSION) ?>"></script>
</body>
</html>
