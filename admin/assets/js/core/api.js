import { API_ENTRY, S, extraHeaders, setToken, setSessionKey } from './state.js';
import { toast } from './ui.js';

const AUTH_FAIL_CODES = [1002, 1003];

function authHeaders() {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, extraHeaders());
    if (S.token) headers['X-Token'] = S.token;
    if (S.sessionKey) headers['X-Session-Key'] = S.sessionKey;
    return headers;
}

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

export async function apiDownload(action, data = {}, fallbackName = 'export.txt') {
    const headers = authHeaders();

    const r = await fetch(`${API_ENTRY}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(withGuard(data)),
        credentials: 'same-origin',
    });

    const ctype = r.headers.get('Content-Type') || '';
    if (ctype.indexOf('application/json') !== -1) {
        const text = await r.text();
        let j = null;
        try { j = JSON.parse(text); } catch (e) {
 }
        throw new Error((j && j.msg) || ('下载失败（HTTP ' + r.status + '）'));
    }
    if (!r.ok) throw new Error('HTTP ' + r.status);

    const cd = r.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
    const name = m ? decodeURIComponent(m[1]) : fallbackName;
    const blob = await r.blob();
    return { blob, name };
}

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

        setSessionKey(res.data.session_key || '');
        S.admin = res.data.admin;
    }
    return res;
}
