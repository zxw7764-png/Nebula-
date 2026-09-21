const KEY = 'nb_theme';

export function storedTheme() {
    try {
        const t = localStorage.getItem(KEY);
        return (t === 'dark' || t === 'light') ? t : '';
    } catch (e) { return ''; }
}

export function systemTheme() {
    try {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e) { return 'light'; }
}

export function appliedTheme() {
    return storedTheme() || systemTheme();
}

export function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
}

export function initTheme() {
    applyTheme(appliedTheme());
    try {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = e => { if (!storedTheme()) applyTheme(e.matches ? 'dark' : 'light'); };
        mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange);
    } catch (e) {
 }
}

export function toggleTheme() {
    const next = appliedTheme() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) {
 }
    applyTheme(next);
    return next;
}
