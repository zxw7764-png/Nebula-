export function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

export function tag(text, color) {
    return `<span class="tag ${color}">${esc(text)}</span>`;
}

export function statusTag(status, map) {
    const m = map[status] || ['未知', 'gray'];
    return tag(m[0], m[1]);
}

export function loading() {
    return '<div class="loading"><div class="spinner"></div>加载中...</div>';
}

export function empty(icon, text) {
    return `<div class="empty"><div class="icon">${icon}</div>${esc(text)}</div>`;
}

export function fmtSize(bytes) {
    if (!bytes) return '-';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0, n = bytes;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return n.toFixed(i ? 2 : 0) + ' ' + u[i];
}

export function ts2str(ts) {
    if (!ts || ts <= 0) return '-';
    const d = new Date(ts * 1000);
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} `
         + `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

export function str2ts(v) {
    v = (v || '').trim();
    if (!v) return 0;
    const t = Math.floor(new Date(v.replace(/-/g, '/')).getTime() / 1000);
    return isNaN(t) ? 0 : t;
}

export function debounce(fn, wait = 300) {
    let timer = null;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

export function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
    return Promise.resolve();
}

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
