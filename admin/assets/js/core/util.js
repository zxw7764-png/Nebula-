/* ======================================================================
   core/util.js — 通用工具函数
   ====================================================================== */

/** HTML 转义，防 XSS */
export function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

/** 标签 */
export function tag(text, color) {
    return `<span class="tag ${color}">${esc(text)}</span>`;
}

/** 状态标签 */
export function statusTag(status, map) {
    const m = map[status] || ['未知', 'gray'];
    return tag(m[0], m[1]);
}

/** 加载中 */
export function loading() {
    return '<div class="loading"><div class="spinner"></div>加载中...</div>';
}

/** 空状态 */
export function empty(icon, text) {
    return `<div class="empty"><div class="icon">${icon}</div>${esc(text)}</div>`;
}

/** 文件大小格式化 */
export function fmtSize(bytes) {
    if (!bytes) return '-';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0, n = bytes;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return n.toFixed(i ? 2 : 0) + ' ' + u[i];
}

/** 秒级时间戳 -> YYYY-MM-DD HH:MM:SS */
export function ts2str(ts) {
    if (!ts || ts <= 0) return '-';
    const d = new Date(ts * 1000);
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} `
         + `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

/** 日期字符串 -> 秒级时间戳，失败返回 0 */
export function str2ts(v) {
    v = (v || '').trim();
    if (!v) return 0;
    const t = Math.floor(new Date(v.replace(/-/g, '/')).getTime() / 1000);
    return isNaN(t) ? 0 : t;
}

/** 防抖 */
export function debounce(fn, wait = 300) {
    let timer = null;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

/** 复制到剪贴板 */
export function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }
    // 降级：execCommand
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
    return Promise.resolve();
}

/** 触发文件下载 */
export function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
