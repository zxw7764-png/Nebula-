const ENTRY = (window.__NB__ && window.__NB__.entry) || 'index.php';

const EXTRA_HEADERS = (window.__NB__ && window.__NB__.headers) || {};

const TOKEN_KEY = (window.__NB__ && window.__NB__.tokenKey) || 'nb_token';

const SKEY_KEY = TOKEN_KEY + '_sk';

const USE_COOKIE = !!(window.__NB__ && window.__NB__.cookie);

export const API_ENTRY = ENTRY;

export const S = {

    token: USE_COOKIE ? '' : (localStorage.getItem(TOKEN_KEY) || ''),
    sessionKey: localStorage.getItem(SKEY_KEY) || '',
    admin: null,
    page: 'dashboard',
    cache: {},

    states: {},
};

export function tokenKey() { return TOKEN_KEY; }

export function extraHeaders() { return EXTRA_HEADERS; }

export function useCookieSession() { return USE_COOKIE; }

export function setToken(t) {
    if (USE_COOKIE) {

        S.token = '';
        localStorage.removeItem(TOKEN_KEY);
        return;
    }
    S.token = t || '';
    if (t) localStorage.setItem(TOKEN_KEY, t);
    else localStorage.removeItem(TOKEN_KEY);
}

export function setSessionKey(k) {
    S.sessionKey = k || '';
    if (k) localStorage.setItem(SKEY_KEY, k);
    else localStorage.removeItem(SKEY_KEY);
}

export function pageState(name, defaults) {
    if (!S.states[name]) S.states[name] = Object.assign({}, defaults);
    return S.states[name];
}

export function resetPageState(name, defaults) {
    S.states[name] = Object.assign({}, defaults);
    return S.states[name];
}

export function can(perm) {
    const perms = S.admin && S.admin.permissions;
    if (perms === '*') return true;
    if (!Array.isArray(perms)) return false;
    return perms.indexOf(perm) !== -1;
}
