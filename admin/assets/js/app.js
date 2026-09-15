/* ======================================================================
   app.js — 应用入口
   ====================================================================== */

import { S, setToken, setSessionKey, useCookieSession, API_ENTRY } from './core/state.js';
import { api, login } from './core/api.js';
import { toast, confirmBox } from './core/ui.js';
import { go, currentFromHash, renderNav } from './core/router.js';
import { initTheme, toggleTheme, appliedTheme } from './core/theme.js';

// 注册所有页面模块（动态导入，带版本号防缓存）。
// 模块是异步加载的：刷新恢复会话可能比模块加载更快，若不等加载完成就 go()，
// registry 还是空的，内容区会渲染成「页面开发中」（侧边栏却已高亮）—— 见 enterApp。
const PAGES = [
    "dashboard", "stat", "software", "user", "agent", "agent_code",
    "card", "batch", "device", "device_ban", "session", "notice", "version", "client_notice",
    "group", "message", "feedback", "plan", "shop", "shop_setting", "shop_goods",
    "seller", "screenshot", "log", "files", "audit", "setting", "profile", "portal_web", "templates", "games",
];
const pagesReady = Promise.all(
    PAGES.map(p => import(`./pages/${p}.js?v=${window.NB_V || ''}`).catch(() => { /* 单模块失败不阻塞整个后台 */ }))
);

/* ------------------------- 登录 / 登出 ------------------------- */
/** 登录验证码：加载失败自动重试一次 */
function refreshLoginCaptcha() {
    const img = document.getElementById('lgCaptchaImg');
    if (!img) return;
    img.onerror = () => {
        img.onerror = null;
        setTimeout(refreshLoginCaptcha, 1200);
    };
    img.src = `${API_ENTRY}?action=captcha&t=${Date.now()}`;
    const inp = document.getElementById('lgCaptcha');
    if (inp) inp.value = '';
}

/**
 * 服务端要求补动态码（code 2006/2007）时确保输入框可见。
 * 登录页只在「本站有账号开启 2FA」时才渲染该字段（见 home.php 的 $totpInUse），
 * 所以正常情况下它已经在页面上；万一没有（例如刚开启 2FA 而页面还是旧的），
 * 整页刷新一次就能拿到新渲染的字段。
 * @returns {boolean} true=字段可用，false=已触发刷新，本次登录流程应中止
 */
function showTotpField() {
    const wrap = document.getElementById('lgTotpWrap');
    if (!wrap) {
        location.reload();
        return false;
    }
    if (wrap.style.display === 'none') {
        wrap.style.display = '';
    }
    return true;
}

async function doLogin() {
    const u = document.getElementById('lgUser').value.trim();
    const p = document.getElementById('lgPass').value;
    const c = (document.getElementById('lgCaptcha')?.value || '').trim();
    const t = (document.getElementById('lgTotp')?.value || '').trim();
    if (!u || !p) return toast('请输入账号和密码', 'warn');
    if (!c) return toast('请输入图形验证码', 'warn');

    const btn = document.getElementById('lgBtn');
    if (btn) { btn.disabled = true; btn.textContent = '登录中...'; }
    try {
        const res = await login(u, p, c, t);
        // 2006 = 账号已开启二次验证，需要补动态码；2007 = 动态码不对
        if (res.code === 2006 || res.code === 2007) {
            // 页面上没有该字段（本站 2FA 状态刚变化）→ 已触发刷新，中止本次流程
            if (!showTotpField()) return;
            toast(res.msg || '请输入动态验证码', 'warn');
            refreshLoginCaptcha(); // 图形验证码已被本次请求消费，必须换新图
            const tin = document.getElementById('lgTotp');
            if (tin) { tin.focus(); }
            return;
        }
        if (res.code !== 0) {
            toast(res.msg || '登录失败', 'err');
            refreshLoginCaptcha(); // 验证码一次性消费，失败必换新图
            return;
        }
        toast('登录成功');
        enterApp();
    } catch (e) {
        toast('登录请求失败：' + e.message, 'err');
        refreshLoginCaptcha();
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '登 录'; }
    }
}

function doLogout() {
    confirmBox('退出登录', '确定要退出管理后台吗？', async () => {
        try { await api('logout', {}, true); } catch (e) { /* 忽略 */ }
        setToken('');
        setSessionKey('');
        location.reload();
    });
}

async function enterApp() {
    // 等所有页面模块注册完成再进应用，避免 go() 时 registry 为空
    await pagesReady;

    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('app').style.display = 'block';

    const a = S.admin || {};
    document.getElementById('sideAdmin').textContent = a.username || '-';
    document.getElementById('sideRole').textContent = a.role_text || '-';
    document.getElementById('topAvatar').textContent =
        ((a.nickname || a.username || 'A').charAt(0) || 'A').toUpperCase();

    renderNav();
    // 每次进入后台都默认落到「数据概览」，不沿用上次停留的页面
    go('dashboard');
}

/* ------------------------- 启动 ------------------------- */
(async function boot() {
    // 隐藏启动遮罩
    const mask = document.getElementById('bootMask');
    if (mask) mask.classList.add('hide');

    // 登录提交：走 form submit（让浏览器密码管理器识别），同时阻止默认跳转
    const lgForm = document.getElementById('lgForm');
    if (lgForm) {
        lgForm.addEventListener('submit', e => {
            e.preventDefault();
            doLogin();
        });
    } else {
        // 兼容无 form 的旧结构
        ['lgUser', 'lgPass'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
        });
        const lgBtn = document.getElementById('lgBtn');
        if (lgBtn) lgBtn.addEventListener('click', doLogin);
    }

    // 登录页验证码：加载首图 + 点击刷新
    const lgCapImg = document.getElementById('lgCaptchaImg');
    if (lgCapImg) {
        const lp = document.getElementById('loginPage');
        if (lp && lp.style.display !== 'none') refreshLoginCaptcha();
        lgCapImg.addEventListener('click', refreshLoginCaptcha);
    }

    // 退出按钮
    const outBtn = document.getElementById('btnLogout');
    if (outBtn) outBtn.addEventListener('click', doLogout);

    // 主题：应用 + 绑定切换按钮
    // （CSS 加载前的防闪烁部分由 home.php <head> 的内联脚本完成，这里负责运行时行为）
    initTheme();
    const themeBtn = document.getElementById('btnTheme');
    if (themeBtn) {
        const paintThemeBtn = () => {
            const dark = appliedTheme() === 'dark';
            themeBtn.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
            themeBtn.title = dark ? '切换到浅色模式' : '切换到深色模式';
        };
        paintThemeBtn();
        themeBtn.addEventListener('click', () => { toggleTheme(); paintThemeBtn(); });
    }

    // hash 变化（前进/后退）
    window.addEventListener('hashchange', () => {
        if (S.admin) go(currentFromHash());
    });

    // 尝试恢复会话
    // ------------------------------------------------------------------
    // ⚠️ Cookie 模式下 S.token 恒为空字符串（令牌在 HttpOnly Cookie 里，
    //    JS 读不到也不该读），所以这里【不能】用 S.token 判断是否已登录 ——
    //    否则每次刷新都会误判成「未登录」，直接弹回登录页。
    //    正确的判据是「Cookie 模式」或「localStorage 里有 token」。
    //    真正的会话有效性由服务端 profile 接口裁决：
    //      有效 → 返回 0 并填 S.admin；失效 → 返回 1003 被 api() 拦截跳登录。
    // ------------------------------------------------------------------
    const maybeLoggedIn = useCookieSession() || !!S.token;
    if (maybeLoggedIn) {
        try {
            const res = await api('profile', { op: 'get' }, true);
            if (res.code === 0) {
                S.admin = res.data;
                enterApp();
                return;
            }
        } catch (e) { /* 忽略：会话失效时 api() 已处理跳转 */ }
        // 会话确实失效：token 与 session_key 一并清除，避免半截凭证残留
        // （Cookie 模式下还要让服务端清 Cookie，见下方 logout）
        setToken('');
        setSessionKey('');
    }

    // 无有效会话：显示登录页（否则页面会一直空白）
    const lp = document.getElementById('loginPage');
    if (lp) lp.style.display = 'flex';
    refreshLoginCaptcha();

    const userInput = document.getElementById('lgUser');
    if (userInput) userInput.focus();
})();
