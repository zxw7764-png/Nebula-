/* ======================================================================
   core/state.js — 全局状态
   ====================================================================== */

/**
 * 接口入口地址。
 * 由 index.html 通过 window.__NB__ 注入（服务端生成，可配置、可混淆），
 * 前端源码里不出现真实路径，降低被直接爬接口的风险。
 */
const ENTRY = (window.__NB__ && window.__NB__.entry) || 'index.php';

/** 额外请求头（服务端可注入一次性令牌） */
const EXTRA_HEADERS = (window.__NB__ && window.__NB__.headers) || {};

/** 会话令牌存储 key（可由服务端随机化，避免固定 key 被脚本窃取） */
const TOKEN_KEY = (window.__NB__ && window.__NB__.tokenKey) || 'nb_token';

/** 会话密钥存储 key（P0-02：与令牌分离，随登录一次性下发） */
const SKEY_KEY = TOKEN_KEY + '_sk';

/**
 * Cookie 会话模式（P1-09）。
 * 为 true 时令牌存在 HttpOnly Cookie 里，JS 根本读不到，
 * 因此本地不保存 token —— 这是防 XSS 窃取的核心。
 * 为 false 时退回旧行为（localStorage + X-Token 头）。
 */
const USE_COOKIE = !!(window.__NB__ && window.__NB__.cookie);

export const API_ENTRY = ENTRY;

export const S = {
    // Cookie 模式下 token 恒为空字符串：浏览器会自动带上 Cookie，
    // 前端不需要（也无法）参与令牌传递。
    token: USE_COOKIE ? '' : (localStorage.getItem(TOKEN_KEY) || ''),
    sessionKey: localStorage.getItem(SKEY_KEY) || '',
    admin: null,
    page: 'dashboard',
    cache: {},
    /** 页面级状态容器：各页面模块把自己的筛选/分页状态注册进来 */
    states: {},
};

export function tokenKey() { return TOKEN_KEY; }

export function extraHeaders() { return EXTRA_HEADERS; }

/** 是否启用 Cookie 会话模式 */
export function useCookieSession() { return USE_COOKIE; }

export function setToken(t) {
    if (USE_COOKIE) {
        // Cookie 模式下令牌由服务端通过 Set-Cookie 管理，前端不落盘。
        // 这里仍然更新内存值，以便「登录响应里带回 token」的旧流程不报错。
        S.token = '';
        localStorage.removeItem(TOKEN_KEY);
        return;
    }
    S.token = t || '';
    if (t) localStorage.setItem(TOKEN_KEY, t);
    else localStorage.removeItem(TOKEN_KEY);
}

/**
 * 设置会话密钥（P0-02）。
 * 与 token 分开存储，请求时分别置于 X-Token / X-Session-Key 头。
 * 服务端只存它的摘要，因此该值一旦丢失只能重新登录。
 *
 * Cookie 模式下这一层尤其关键：Cookie 会被浏览器自动携带，
 * 若攻击者能诱导请求（CSRF），仍需要这个 JS 持有的密钥才能通过校验。
 */
export function setSessionKey(k) {
    S.sessionKey = k || '';
    if (k) localStorage.setItem(SKEY_KEY, k);
    else localStorage.removeItem(SKEY_KEY);
}

/** 注册/获取页面状态（带默认值） */
export function pageState(name, defaults) {
    if (!S.states[name]) S.states[name] = Object.assign({}, defaults);
    return S.states[name];
}

export function resetPageState(name, defaults) {
    S.states[name] = Object.assign({}, defaults);
    return S.states[name];
}

/* ------------------------- 权限 ------------------------- */
/**
 * 当前管理员是否具备某权限点。
 * 服务端在登录响应里下发 permissions：
 *   - '*'            超管，全部放行
 *   - ['user.read']  操作员/只读，按权限点判断
 *   - null/缺失      未登录（视为无权限）
 *
 * ⚠️ 前端判断只用于隐藏入口、避免用户白点；真正的拦截在服务端
 *    AdminPermission::requireAction()。不要用前端判断做安全控制。
 */
export function can(perm) {
    const perms = S.admin && S.admin.permissions;
    if (perms === '*') return true;
    if (!Array.isArray(perms)) return false;
    return perms.indexOf(perm) !== -1;
}
