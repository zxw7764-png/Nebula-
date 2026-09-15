/* ======================================================================
   core/api.js — 请求封装
   ====================================================================== */

import { API_ENTRY, S, extraHeaders, setToken, setSessionKey } from './state.js';
import { toast } from './ui.js';

/** 业务码：登录态失效 */
const AUTH_FAIL_CODES = [1002, 1003];

/**
 * 组装认证请求头。
 * token 与 session_key 分离（P0-02 / P1-09）：
 *   · Cookie 模式：token 在 HttpOnly Cookie 里，由浏览器自动携带，
 *     这里只能（也只应）带 session_key。
 *   · 兼容模式：token 走 X-Token 头。
 * 服务端要求两者都具备，单有其一不足以冒用会话。
 */
function authHeaders() {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, extraHeaders());
    if (S.token) headers['X-Token'] = S.token;
    if (S.sessionKey) headers['X-Session-Key'] = S.sessionKey;
    return headers;
}

/**
 * multipart 上传请求头：带认证头但不设 Content-Type，
 * 让浏览器自动生成 multipart boundary。
 */
export function uploadHeaders() {
    const headers = Object.assign({}, extraHeaders());
    if (S.token) headers['X-Token'] = S.token;
    if (S.sessionKey) headers['X-Session-Key'] = S.sessionKey;
    return headers;
}

function withGuard(data) {
    const body = data || {};
    try { if (window.NBGuard) NBGuard.attach(body); } catch (e) { }
    return body;
}

/**
 * 发起接口请求
 * @param {string} action 动作名
 * @param {object} data   业务参数
 * @param {boolean} silent 静默模式（不弹错误提示）
 */
export async function api(action, data = {}, silent = false) {
    const headers = authHeaders();

    let res;
    try {
        const r = await fetch(`${API_ENTRY}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers,
            body: JSON.stringify(withGuard(data)),
            credentials: 'same-origin',
        });
        const text = await r.text();
        try {
            res = JSON.parse(text);
        } catch (e) {
            if (!silent) toast('服务端返回异常（HTTP ' + r.status + '）', 'err');
            throw new Error('bad json');
        }
    } catch (e) {
        if (!silent && e.message !== 'bad json') toast('网络请求失败：' + e.message, 'err');
        throw e;
    }

    if (AUTH_FAIL_CODES.includes(res.code)) {
        setToken('');
        setSessionKey('');
        // 仅在已进入后台时才提示并刷新回登录页；
        // 登录页 boot 阶段的失效探测必须静默，否则会形成「reload → 探测 → reload」死循环
        const appEl = document.getElementById('app');
        const inApp = appEl && appEl.style.display === 'block';
        if (inApp) {
            if (!silent) toast('登录已过期，请重新登录', 'warn');
            setTimeout(() => { location.reload(); }, 900);
        }
        throw res;
    }
    if (res.code !== 0 && !silent) {
        toast(res.msg || '操作失败', 'err');
    }
    return res;
}

/**
 * 下载型接口（导出文件）
 * 走 POST + blob，文件名从 Content-Disposition 解析
 */
export async function apiDownload(action, data = {}, fallbackName = 'export.txt') {
    const headers = authHeaders();

    const r = await fetch(`${API_ENTRY}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(withGuard(data)),
        credentials: 'same-origin',
    });
    // 出错时服务端返回的是 JSON 而不是文件流：
    // 必须解析出来抛错，否则调用方会把错误信息当成文件保存（得到一个内容是
    // {"code":1001,"msg":"..."} 的 .txt/.gz）。
    const ctype = r.headers.get('Content-Type') || '';
    if (ctype.indexOf('application/json') !== -1) {
        const text = await r.text();
        let j = null;
        try { j = JSON.parse(text); } catch (e) { /* 非 JSON 就当普通错误 */ }
        throw new Error((j && j.msg) || ('下载失败（HTTP ' + r.status + '）'));
    }
    if (!r.ok) throw new Error('HTTP ' + r.status);

    const cd = r.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
    const name = m ? decodeURIComponent(m[1]) : fallbackName;
    const blob = await r.blob();
    return { blob, name };
}

/** 登录（独立于 api()，因为此时还没有 token）
 *  totp：账号开启二次验证时必填的动态码；未开启时传空串即可
 */
export async function login(username, password, captcha, totp) {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, extraHeaders());
    const r = await fetch(`${API_ENTRY}?action=login`, {
        method: 'POST',
        headers,
        body: JSON.stringify(withGuard({ username, password, captcha: captcha || '', totp: totp || '' })),
        credentials: 'same-origin',
    });
    const res = await r.json();
    if (res.code === 0 && res.data && res.data.token) {
        setToken(res.data.token);
        // 会话密钥仅此一次下发，必须保存下来用于后续请求
        setSessionKey(res.data.session_key || '');
        S.admin = res.data.admin;
    }
    return res;
}
