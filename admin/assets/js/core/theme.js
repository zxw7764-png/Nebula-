/* ======================================================================
   core/theme.js — 深色 / 浅色主题切换
   ----------------------------------------------------------------------
   · 用户显式选择存 localStorage（key: nb_theme），优先级最高
   · 未显式选择时跟随系统 prefers-color-scheme，且系统切换时实时跟随
   · 实际生效靠 <html data-theme="dark"> 上的 CSS 变量覆盖（main.css）
   · 防闪烁：home.php <head> 里有同逻辑的内联早期脚本，先于 CSS 生效
   ====================================================================== */

const KEY = 'nb_theme';

/** 读取用户显式存储的偏好：'dark' | 'light' | ''（未选择） */
export function storedTheme() {
    try {
        const t = localStorage.getItem(KEY);
        return (t === 'dark' || t === 'light') ? t : '';
    } catch (e) { return ''; }
}

/** 当前系统偏好 */
export function systemTheme() {
    try {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e) { return 'light'; }
}

/** 实际生效的主题 */
export function appliedTheme() {
    return storedTheme() || systemTheme();
}

/** 把主题落到 <html data-theme="..."> 上（CSS 变量随此切换） */
export function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
}

/** 启动时调用：应用主题 + 监听系统变化（仅在用户未显式选择时跟随） */
export function initTheme() {
    applyTheme(appliedTheme());
    try {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = e => { if (!storedTheme()) applyTheme(e.matches ? 'dark' : 'light'); };
        mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange);
    } catch (e) { /* 老浏览器忽略 */ }
}

/** 切换主题并持久化，返回切换后的主题（'dark' | 'light'） */
export function toggleTheme() {
    const next = appliedTheme() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) { /* 隐私模式等忽略 */ }
    applyTheme(next);
    return next;
}
