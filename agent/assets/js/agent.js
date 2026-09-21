const RT        = window.__NBAG__ || {};
const API_ENTRY = RT.entry || 'api.php';
const TOKEN_KEY = RT.tokenKey || 'nb_agent_token';

const SKEY_KEY  = TOKEN_KEY + '_sk';
const CSRF      = RT.csrf || '';
const HEADERS   = RT.headers || (CSRF ? { 'X-CSRF': CSRF } : {});

const REG_OPEN  = !!RT.register;

const S = {
    token: localStorage.getItem(TOKEN_KEY) || '',
    sessionKey: localStorage.getItem(SKEY_KEY) || '',
    agent: null,
    stats: null,
    page: 'dashboard',
};

function $(id) { return document.getElementById(id); }

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function toast(msg, type = 'ok') {
    const box = $('toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    const icon = { ok: '✓', err: '✕', warn: '!' }[type] || '•';
    el.innerHTML = `<b>${icon}</b><span>${esc(msg)}</span>`;
    box.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        el.style.transition = '.25s';
        setTimeout(() => el.remove(), 250);
    }, 2600);
}

function loading() { return '<div class="loading"><div class="spinner"></div>加载中...</div>'; }
function empty(icon, text) { return `<div class="empty"><div class="icon">${icon}</div>${esc(text)}</div>`; }
function tag(text, color) { return `<span class="tag ${color}">${esc(text)}</span>`; }

function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
    return Promise.resolve();
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function openModal(title, bodyHtml, buttons = [], size = '') {
    const root = $('modalRoot');
    const id = 'm' + Date.now();
    const btns = buttons.map((b, i) =>
        `<button class="btn ${b.cls || ''}" data-btn="${i}">${esc(b.text)}</button>`).join('');
    root.innerHTML = `
    <div class="modal-mask" id="${id}">
        <div class="modal ${size === 'wide' ? 'wide' : ''}">
            <div class="modal-head">
                <h3>${esc(title)}</h3>
                <button class="close" data-close>&times;</button>
            </div>
            <div class="modal-body">${bodyHtml}</div>
            ${btns ? `<div class="modal-foot">${btns}</div>` : ''}
        </div>
    </div>`;
    const mask = $(id);
    mask.addEventListener('click', e => { if (e.target === mask) closeModal(); });
    mask.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeModal));
    buttons.forEach((b, i) => {
        const el = mask.querySelector(`[data-btn="${i}"]`);
        if (el) el.addEventListener('click', b.act);
    });
    return mask;
}

function closeModal() {
    const root = $('modalRoot');
    if (root) root.innerHTML = '';
}

function confirmBox(title, msg, onOk, danger = false) {
    openModal(title, `<p style="color:var(--text-sub)">${esc(msg)}</p>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确定', cls: danger ? 'danger' : '', act: () => { closeModal(); onOk(); } }],
        'sm');
}

function pager(total, page, size) {
    const pages = Math.max(1, Math.ceil(total / size));
    let btns = '';
    const start = Math.max(1, page - 2);
    const end = Math.min(pages, start + 4);
    if (page > 1) btns += `<button data-go="${page - 1}">‹</button>`;
    for (let i = start; i <= end; i++) {
        btns += `<button class="${i === page ? 'on' : ''}" data-go="${i}">${i}</button>`;
    }
    if (page < pages) btns += `<button data-go="${page + 1}">›</button>`;
    return `<div class="pager"><span>共 <b>${total}</b> 条，第 ${page}/${pages} 页</span>
            <div class="pages">${btns}</div></div>`;
}

function bindPager(container, onGo) {
    container.querySelectorAll('[data-go]').forEach(b => {
        b.addEventListener('click', () => onGo(parseInt(b.dataset.go, 10)));
    });
}

function setToken(t) {
    S.token = t || '';
    if (t) localStorage.setItem(TOKEN_KEY, t);
    else localStorage.removeItem(TOKEN_KEY);
}

function setSessionKey(k) {
    S.sessionKey = k || '';
    if (k) localStorage.setItem(SKEY_KEY, k);
    else localStorage.removeItem(SKEY_KEY);
}

function authHeaders() {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, HEADERS);
    if (S.token) headers['X-Token'] = S.token;
    if (S.sessionKey) headers['X-Session-Key'] = S.sessionKey;
    return headers;
}

function withGuard(data) {
    const body = data || {};
    try { if (window.NBGuard) NBGuard.attach(body); } catch (e) { }
    return body;
}

async function api(action, data = {}, silent = false) {
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

    if (res.code === 1002 || res.code === 1003) {
        if (!silent) toast('登录已过期，请重新登录', 'warn');
        setTimeout(() => { setToken(''); setSessionKey(''); location.reload(); }, 900);
        throw res;
    }
    if (res.code !== 0 && !silent) {
        toast(res.msg || '操作失败', 'err');
    }
    return res;
}

async function apiDownload(action, data = {}, fallbackName = 'export.txt') {
    const headers = authHeaders();

    const r = await fetch(`${API_ENTRY}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(withGuard(data)),
        credentials: 'same-origin',
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);

    const cd = r.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
    const name = m ? decodeURIComponent(m[1]) : fallbackName;
    const blob = await r.blob();
    return { blob, name };
}

const MENUS = [
    { id: 'dashboard', name: '概览',      icon: 'bi-speedometer2' },
    { id: 'stats',     name: '数据分析',  icon: 'bi-graph-up' },
    { id: 'generate',  name: '生成卡密',  icon: 'bi-plus-circle' },
    { id: 'cards',     name: '我的卡密',  icon: 'bi-key' },
    { id: 'batches',   name: '我的批次',  icon: 'bi-collection' },
    { id: 'recharge',  name: '充值卡密',  icon: 'bi-arrow-up-circle' },
    { id: 'logs',      name: '操作记录',  icon: 'bi-clock-history' },
    { id: 'account',   name: '账号设置',  icon: 'bi-person-gear' },
];

function renderNav() {
    const nav = $('nav');
    if (!nav) return;
    nav.innerHTML = MENUS.map(m => `
        <a data-page="${m.id}" class="${S.page === m.id ? 'active' : ''}">
            <span class="ic"><i class="bi ${m.icon}"></i></span><span class="tx">${esc(m.name)}</span>
        </a>`).join('');
    nav.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', () => go(a.dataset.page));
    });
}

function go(page) {
    S.page = page;
    renderNav();
    const title = MENUS.find(m => m.id === page);
    const t = $('pageTitle');
    if (t) t.textContent = title ? title.name : '概览';

    const c = $('content');
    if (!c) return;
    const render = {
        dashboard: renderDashboard,
        stats:     renderStats,
        generate:  renderGenerate,
        cards:     renderCards,
        batches:   renderBatches,
        recharge:  renderRecharge,
        logs:      renderLogs,
        account:   renderAccount,
    }[page];
    if (render) render(c);
}

function quotaBar(a) {
    const isQuota = a.charge_mode === 1;
    const isBal   = a.charge_mode === 2;
    const types   = a.types || [];

    const cards = types.map(t => {

        const canMake = Number(t.can_make || 0);
        const main = isBal
            ? (t.price > 0 ? ('可生成 ' + canMake + ' 张') : '未配置单价')
            : t.quota_text;
        const sub = isBal
            ? (t.price > 0 ? ('单价 ¥ ' + esc(t.price_text) + ' / 张') : '请联系管理员配置单价')
            : ('已用 ' + t.quota_used + (t.quota_total === -1 ? '' : ' / 共 ' + t.quota_total));
        return `<div class="type-card ${t.enabled ? '' : 'off'}">
            <div class="t-name"><span>${esc(t.name)}</span>${t.enabled ? '' : '<span class="tag gray">未开放</span>'}</div>
            <div class="t-quota">${t.enabled ? main : '—'}</div>
            <div class="t-sub">${t.enabled ? sub : '请联系管理员开通'}</div>
            ${t.enabled && t.group_name ? `<div class="t-sub">激活后进入：${esc(t.group_name)}</div>` : ''}
        </div>`;
    }).join('');

    const modeHint = isQuota
        ? '配额模式：每种卡类型有独立的可生成张数，生成时按类型扣减'
        : (isBal
            ? '余额模式：按「该卡类型的单价 × 张数」从余额扣款，当前余额 ¥ ' + esc(a.balance_text)
              + '（卡片上的张数 = 当前余额还能生成多少张）'
            : '不限量模式：生成不扣额度，仅记录归属与日志');

    return `<div class="card">
        <div class="card-head"><h3>发货规格（由管理员设置）</h3><div class="hint">${modeHint}</div></div>
        <div class="card-body">
            <div class="type-grid">${cards || empty('⊘', '管理员尚未配置卡密类型')}</div>
            <div class="kv" style="margin-top:16px">
                <span class="k">控量模式</span><span class="v">${esc(a.charge_mode_text)}</span>
                <span class="k">账户余额</span><span class="v">¥ ${esc(a.balance_text)}</span>
                <span class="k">设备上限</span><span class="v">${a.max_devices} 台 / 张</span>
                <span class="k">兜底用户组</span><span class="v">${a.group_name ? esc(a.group_name) : '不换组（默认用户组）'}</span>
                <span class="k">允许作废</span><span class="v">${a.can_void ? '是' : '否'}</span>
                <span class="k">注册激活码</span><span class="v mono">${esc(a.reg_code || '管理员创建')}</span>
            </div>
        </div>
    </div>`;
}

function headerSummary(a) {
    if (a.charge_mode === 1) {
        return '额度 ' + (a.quota_left_total === -1 ? '不限' : a.quota_left_total + ' 张');
    }
    if (a.charge_mode === 2) {
        return '余额 ¥' + a.balance_text;
    }
    return '不限量';
}

function paintHeader(a) {
    if (!a) return;
    const name = a.nickname || a.username;
    const side = $('sideAgent');
    if (side) side.textContent = name;
    const summary = headerSummary(a);
    const sq = $('sideQuota');
    if (sq) sq.textContent = summary;
    const tq = $('topQuota');
    if (tq) tq.textContent = summary;
    const av = $('topAvatar');
    if (av) av.textContent = (name.charAt(0) || 'A').toUpperCase();
}

async function refreshProfile() {
    const res = await api('profile', {}, true);
    if (res.code === 0) {
        S.agent = res.data.agent;
        S.stats = res.data.stats;
        S.agent._stats = res.data.stats;
        paintHeader(S.agent);
    }
    return S.agent;
}

async function renderDashboard(c) {
    c.innerHTML = loading();
    const res = await api('dashboard');
    if (res.code !== 0) return;
    const a = res.data.agent;
    const s = res.data.stats;
    a._stats = s;
    S.agent = a;
    S.stats = s;
    paintHeader(a);

    const logRows = (res.data.logs || []).map(l => `
        <tr>
            <td>${esc(l.action_text)}</td>
            <td>${esc(l.detail || '-')}</td>
            <td>${l.amount !== 0 ? l.amount : '-'}</td>
            <td class="mono" style="font-size:12px">${esc(l.time_text)}</td>
        </tr>`).join('');

    c.innerHTML = `
    ${quotaBar(a)}
    <div class="stats">
        <div class="stat"><div class="label">卡密总数</div><div class="value">${s.total}</div></div>
        <div class="stat c2"><div class="label">未使用</div><div class="value">${s.unused}</div></div>
        <div class="stat"><div class="label">已使用</div><div class="value">${s.used}</div></div>
        <div class="stat c4"><div class="label">已作废</div><div class="value">${s.void}</div></div>
        <div class="stat c3"><div class="label">批次数量</div><div class="value">${s.batches}</div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>最近操作记录</h3>
            <button class="btn ghost sm" id="dRefresh">刷新</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>操作</th><th>详情</th><th>张数</th><th>时间</th></tr></thead>
                <tbody>${logRows || `<tr><td colspan="4">${empty('≡', '暂无记录')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    $('dRefresh').addEventListener('click', () => renderDashboard(c));
}

async function renderStats(c) {
    c.innerHTML = loading();
    const res = await api('stats');
    if (res.code !== 0) return;
    const d = res.data;
    const sum = d.summary || {};
    const peak = d.peak || { date: '', count: 0 };

    const trend = d.trend || [];
    const maxVal = Math.max(1, ...trend.map(t => Math.max(t.generated, t.activated)));
    const bars = trend.map(t => {
        const gh = Math.round(t.generated / maxVal * 100);
        const ah = Math.round(t.activated / maxVal * 100);
        const label = t.date.slice(5);
        return `<div class="sw-tcol" title="${t.date} 生成 ${t.generated} / 激活 ${t.activated}">
            <div class="sw-tbars">
                <div class="sw-bar gen" style="height:${gh}%"></div>
                <div class="sw-bar act" style="height:${ah}%"></div>
            </div>
            <div class="sw-tlabel">${label}</div>
        </div>`;
    }).join('');

    const typeRows = (d.types || []).map(t => `
        <tr>
            <td>${esc(t.name)}</td>
            <td>${t.total}</td>
            <td>${t.used}</td>
            <td>${t.unused}</td>
            <td>${t.voided}</td>
            <td style="min-width:140px">
                <div class="sw-rate">
                    <div class="sw-rate-bar"><i style="width:${Math.min(100, t.used_rate)}%"></i></div>
                    <span>${t.used_rate}%</span>
                </div>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="stats">
        <div class="stat c1"><div class="label">今日生成</div><div class="value">${sum.today ?? 0}</div></div>
        <div class="stat"><div class="label">昨日生成</div><div class="value">${sum.yesterday ?? 0}</div></div>
        <div class="stat c3"><div class="label">本月生成</div><div class="value">${sum.month ?? 0}</div></div>
        <div class="stat c2"><div class="label">激活率</div><div class="value">${sum.activate_rate ?? 0}%</div></div>
        <div class="stat c4"><div class="label">近14天最高产</div><div class="value">${peak.count}</div><div class="extra">${peak.date || '—'}</div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>近 14 天趋势</h3>
            <div class="sw-legend">
                <span><i class="dot gen"></i>生成</span>
                <span><i class="dot act"></i>激活</span>
            </div>
        </div>
        <div class="card-body">
            ${trend.length ? `<div class="sw-trend">${bars}</div>` : empty('≡', '暂无数据')}
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>卡类型占比</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>类型</th><th>总数</th><th>已使用</th><th>未使用</th><th>已作废</th><th>使用率</th></tr></thead>
                <tbody>${typeRows || `<tr><td colspan="6">${empty('⊘', '还没有生成过卡密')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;
}

async function renderGenerate(c) {
    c.innerHTML = loading();
    const a = await refreshProfile();
    if (!a) { c.innerHTML = empty('✕', '资料加载失败'); return; }

    const types  = a.types || [];
    const usable = types.filter(t => t.enabled);
    if (!usable.length) {
        c.innerHTML = `<div class="card"><div class="card-body">
            ${empty('⊘', '管理员尚未开放任何卡密类型，请联系管理员开通')}
        </div></div>`;
        return;
    }

    const typeMap = {};
    types.forEach(t => { typeMap[t.type] = t; });

    const typeOpts = types.map(t =>
        `<option value="${t.type}" ${t.enabled ? '' : 'disabled'}>${esc(t.name)}${t.enabled ? '' : '（未开放）'}</option>`
    ).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>生成卡密</h3></div>
        <div class="card-body">
            <div class="hint" id="gTypeHint"
                 style="background:var(--primary-bg);color:var(--primary-strong);padding:10px 14px;border-radius:9px;margin-bottom:18px"></div>
            <div class="row2">
                <div class="field"><label>生成数量 *</label>
                    <input id="gCount" type="number" value="10" min="1" max="500">
                    <div class="hint">单次最多 500 张</div>
                </div>
                <div class="field"><label>卡密类型 *</label>
                    <select id="gType">${typeOpts}</select>
                    <div class="hint">额度与单价按所选类型分别计算</div>
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label id="gDurLabel">时长（天）*</label>
                    <div style="display:flex;gap:8px" id="gDurWrap">
                        <input id="gDur" type="number" value="30" style="flex:1;min-width:0">
                        <select id="gDurUnit" style="width:86px;flex:none">
                            <option value="31536000">年</option>
                            <option value="2592000">月</option>
                            <option value="604800">星期</option>
                            <option value="86400">天</option>
                            <option value="3600">小时</option>
                            <option value="60">分钟</option>
                        </select>
                    </div>
                    <div class="hint" id="gDurHint">时长卡填时长并选择单位</div>
                </div>
                <div class="field"><label>卡密前缀${a.card_prefix ? '（管理员已固定）' : ''}</label>
                    ${a.card_prefix
                        ? `<input id="gPrefix" value="${esc(a.card_prefix)}" readonly
                                  style="background:var(--hover-wash);cursor:not-allowed">`
                        : `<input id="gPrefix" placeholder="如 VIP（仅字母数字，最多 8 位）">`}
                    <div class="hint">${a.card_prefix
                        ? '该前缀由管理员在代理档案上固定，不可修改'
                        : '留空则生成的卡密不带前缀'}</div>
                </div>
            </div>
            <div class="row2">
                <div class="field"><label>卡密自身有效期（天）</label>
                    <input id="gExpire" type="number" value="0" placeholder="0 = 永久有效">
                    <div class="hint">超过该天数未激活则失效，与卡密时长无关</div>
                </div>
                <div class="field"><label>批次名称</label>
                    <input id="gName" placeholder="如：双十一批次">
                </div>
            </div>
            <div class="field"><label>备注</label><input id="gRemark" placeholder="选填，仅自己可见"></div>

            <div class="kv" style="background:var(--hover-wash);border-radius:9px;padding:12px 16px;margin-bottom:16px">
                <span class="k">设备上限</span><span class="v">${a.max_devices} 台（管理员固定）</span>
                <span class="k">兜底用户组</span><span class="v">${a.group_name ? esc(a.group_name) : '不换组（默认用户组）'}</span>
                <span class="k">卡密前缀</span><span class="v">${a.card_prefix ? esc(a.card_prefix) + '（管理员固定）' : '可自填'}</span>
            </div>

            <button class="btn success" id="gBtn">立即生成</button>
        </div>
    </div>

    <div class="card" id="gResultCard" style="display:none">
        <div class="card-head">
            <h3>生成结果</h3>
            <div style="display:flex;gap:8px">
                <button class="btn ghost sm" id="gCopy">复制预览</button>
                <button class="btn sm" id="gExport">导出全部</button>
            </div>
        </div>
        <div class="card-body" id="gResult"></div>
    </div>`;

    function paintDurationField() {
        const t = $('gType').value;
        const lab = $('gDurLabel'), hintEl = $('gDurHint'), inp = $('gDur'), unit = $('gDurUnit');
        if (t === '1') { lab.textContent = '时长 *'; hintEl.textContent = '时长卡填时长并选择单位，如 30 天 / 12 小时'; if (!inp.value) inp.value = 30; unit.style.display = ''; inp.disabled = false; }
        else if (t === '4') { lab.textContent = '时长（无需填写）'; hintEl.textContent = '永久卡无需填写时长'; inp.value = 0; unit.style.display = 'none'; inp.disabled = true; }
        else if (t === '2') { lab.textContent = '点数 *'; hintEl.textContent = '点数卡填点数，如 100'; if (!inp.value) inp.value = 100; unit.style.display = 'none'; inp.disabled = false; }
        else { lab.textContent = '次数 *'; hintEl.textContent = '次数卡填次数，如 50'; if (!inp.value) inp.value = 50; unit.style.display = 'none'; inp.disabled = false; }
    }

    function paintTypeHint() {
        const el = $('gTypeHint');
        const t  = typeMap[parseInt($('gType').value, 10)] || {};
        const cnt = parseInt($('gCount').value, 10) || 0;
        if (!el) return;

        if (!t.enabled) {
            el.innerHTML = `管理员未开放「${esc(t.name || '该类型')}」，请联系管理员`;
            return;
        }

        let html;
        if (a.charge_mode === 1) {
            html = `「${esc(t.name)}」剩余额度 <b>${esc(t.quota_text)}</b>`
                 + (t.quota_total === -1 ? '' : `（已用 ${t.quota_used} / 共 ${t.quota_total}）`);
            if (t.quota_left >= 0 && cnt > t.quota_left) {
                html += ` <span style="color:var(--danger)">· 本次 ${cnt} 张已超剩余额度</span>`;
            }
        } else if (a.charge_mode === 2) {
            html = t.price > 0
                ? `「${esc(t.name)}」单价 <b>¥ ${esc(t.price_text)} / 张</b>，当前余额 <b>¥ ${esc(a.balance_text)}</b>`
                  + `，约可生成 <b>${Number(t.can_make || 0)}</b> 张`
                : `「${esc(t.name)}」尚未配置单价，请联系管理员`;
            if (t.price > 0 && cnt > 0) {
                const need = (t.price * cnt) / 100;
                if (need > parseFloat(a.balance_text)) {
                    html += ` <span style="color:var(--danger)">· 本次需 ¥ ${need.toFixed(2)}，余额不足</span>`;
                } else {
                    html += ` · 本次需 ¥ ${need.toFixed(2)}`;
                }
            }
        } else {
            html = '当前为不限量模式，生成不扣额度';
        }

        const toGroup = t.group_name || a.group_name;
        html += `<div style="margin-top:4px;font-weight:600">`
             + (toGroup
                 ? `激活后进入用户组「${esc(toGroup)}」`
                 : '激活后不换组（保持用户当前用户组）')
             + `</div>`;

        el.innerHTML = html;
    }

    $('gType').value = String(usable[0].type);
    paintDurationField();
    paintTypeHint();

    $('gType').addEventListener('change', () => { paintDurationField(); paintTypeHint(); });
    $('gCount').addEventListener('input', paintTypeHint);

    $('gBtn').addEventListener('click', doGenerate);

    $('gCopy').addEventListener('click', () => {
        if (!lastGen.codes.length) { return toast('暂无可复制的卡密', 'warn'); }
        copyText(lastGen.codes.join('\n')).then(() => toast('已复制预览 ' + lastGen.codes.length + ' 条'));
    });

    $('gExport').addEventListener('click', async () => {
        if (!lastGen.batchId) { return toast('请先生成卡密', 'warn'); }
        try {
            const { blob, name } = await apiDownload('card_export', {
                format: 'txt', status: '', batch_id: lastGen.batchId, limit: 20000,
            }, 'cards.txt');
            downloadBlob(blob, name);
            toast('导出成功');
        } catch (e) {
            toast('导出失败：' + e.message, 'err');
        }
    });
}

let lastGen = { batchId: 0, count: 0, codes: [] };

async function doGenerate() {
    const btn = $('gBtn');
    const type = parseInt($('gType').value, 10);
    const raw = parseInt($('gDur').value, 10) || 0;
    const unitSec = parseInt($('gDurUnit').value, 10) || 86400;

    const payload = {
        count: parseInt($('gCount').value, 10) || 1,
        type: type,
        duration: raw,
        duration_sec: type === 1 ? raw * unitSec : 0,
        prefix: $('gPrefix').value.trim(),
        expire_days: parseInt($('gExpire').value, 10) || 0,
        name: $('gName').value.trim(),
        remark: $('gRemark').value.trim(),
    };

    if (payload.count < 1) return toast('生成数量不正确', 'warn');

    btn.disabled = true;
    btn.textContent = '生成中...';
    try {
        const res = await api('card_generate', payload);
        if (res.code !== 0) return;

        const d = res.data;
        lastGen = { batchId: d.batch_id, count: d.count, codes: d.codes };

        if (d.agent) { S.agent = d.agent; paintHeader(d.agent); }

        const costText = d.cost && d.cost.quota > 0
            ? `扣减额度 ${d.cost.quota} 张`
            : (d.cost && d.cost.balance > 0 ? `扣款 ¥ ${esc(d.cost.balance_text)}` : '未扣减');

        $('gResultCard').style.display = 'block';
        $('gResult').innerHTML = `
            <div style="background:rgba(52,211,153,.12);color:var(--success);padding:12px 16px;border-radius:9px;margin-bottom:14px">
                成功生成 <b>${d.count}</b> 张卡密（批次 #${d.batch_id}）· ${costText}
            </div>
            <p style="margin-bottom:10px;color:var(--text-sub);font-size:13px">
                以下为前 ${d.preview_count} 条预览，完整卡密请点「导出全部」：
            </p>
            <div class="code-box">${d.codes.map(esc).join('\n')}</div>`;
        toast(res.msg || '生成成功');
    } finally {
        btn.disabled = false;
        btn.textContent = '立即生成';
    }
}

const cardState = { page: 1, size: 20, keyword: '', status: '', batch_id: 0 };

async function renderCards(c) {
    c.innerHTML = loading();
    const a = await refreshProfile();
    if (!a) { c.innerHTML = empty('✕', '资料加载失败'); return; }

    const res = await api('card_list', cardState);
    if (res.code !== 0) return;
    const d = res.data;
    const canVoid = !!d.can_void;

    const rows = d.list.map(x => `
        <tr>
            <td class="col-check"><input type="checkbox" data-row-check="${x.id}"></td>
            <td>${x.id}</td>
            <td class="mono"><b>${esc(x.code)}</b></td>
            <td>${tag(x.type_text, 'blue')}</td>
            <td>${esc(x.duration_text)}</td>
            <td>${x.max_devices}</td>
            <td>${x.batch_id ? '#' + x.batch_id : '-'}</td>
            <td>${tag(x.status_text, x.status === 0 ? 'green' : (x.status === 1 ? 'gray' : 'red'))}</td>
            <td>${x.used_by ? 'UID:' + x.used_by : '-'}</td>
            <td class="mono" style="font-size:12px">${esc(x.used_at_text || '-')}</td>
            <td>${esc(x.expire_text)}</td>
            <td class="mono" style="font-size:12px">${esc(x.created_at_text)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-copy="${esc(x.code)}">复制</button>
                ${canVoid && x.status === 0 ? `<button class="btn danger sm" data-void="${x.id}" data-code="${esc(x.code)}">作废</button>` : ''}
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <div class="toolbar">
                <input id="cKw" placeholder="搜索卡密" value="${esc(cardState.keyword)}">
                <select id="cStatus">
                    <option value="">全部状态</option>
                    <option value="0" ${cardState.status === '0' ? 'selected' : ''}>未使用</option>
                    <option value="1" ${cardState.status === '1' ? 'selected' : ''}>已使用</option>
                    <option value="2" ${cardState.status === '2' ? 'selected' : ''}>已作废</option>
                </select>
                <button class="btn" id="cSearch">搜索</button>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <div id="cBulkBox" class="toolbar" style="display:none">
                    <span id="cSelInfo" style="font-size:13px;color:var(--text-sub);white-space:nowrap">已选 0 项</span>
                    <select id="cBulkOp">
                        <option value="copy">复制卡密</option>
                        <option value="void">批量作废</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="cBulkRun">执行</button>
                    <button class="btn ghost sm" id="cClearSel">取消选择</button>
                </div>
                <button class="btn ghost" id="cExport">导出未使用</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th class="col-check"><input type="checkbox" id="cCheckAll"></th>
                    <th>ID</th><th>卡密</th><th>类型</th><th>时长/点数</th><th>设备</th><th>批次</th>
                    <th>状态</th><th>使用者</th><th>使用时间</th><th>卡密有效期</th><th>生成时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="13">${empty('◆', '还没有生成过卡密')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { cardState.page = p; renderCards(c); });

    $('cKw').addEventListener('keydown', e => { if (e.key === 'Enter') doCardSearch(c); });
    $('cSearch').addEventListener('click', () => doCardSearch(c));
    $('cExport').addEventListener('click', () => exportCards('0'));

    const cardSel = {
        set: new Set(),
        sync() {
            c.querySelectorAll('[data-row-check]').forEach(cb => {
                const id = String(cb.dataset.rowCheck);
                cb.checked = this.set.has(id);
                const tr = cb.closest('tr');
                if (tr) tr.classList.toggle('checked', this.set.has(id));
            });
            const all = $('cCheckAll');
            if (all) {
                all.checked = d.list.length > 0 && this.set.size === d.list.length;
                all.indeterminate = this.set.size > 0 && this.set.size < d.list.length;
            }
            const box = $('cBulkBox');
            const info = $('cSelInfo');
            if (box) box.style.display = this.set.size > 0 ? 'flex' : 'none';
            if (info) info.textContent = '已选 ' + this.set.size + ' 项';
        },
        ids() { return [...this.set].map(x => parseInt(x, 10)).filter(n => !isNaN(n)); },
        clear() { this.set.clear(); this.sync(); },
    };

    c.querySelectorAll('[data-row-check]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = String(cb.dataset.rowCheck);
            if (cb.checked) cardSel.set.add(id); else cardSel.set.delete(id);
            cardSel.sync();
        });
    });

    const checkAll = $('cCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            if (checkAll.checked) {
                d.list.forEach(x => cardSel.set.add(String(x.id)));
            } else {
                cardSel.clear();
            }
            cardSel.sync();
        });
    }

    const clearBtn = $('cClearSel');
    if (clearBtn) clearBtn.addEventListener('click', () => cardSel.clear());

    const bulkRun = $('cBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = $('cBulkOp').value;
        const ids = cardSel.ids();
        if (!ids.length) return toast('请先选择卡密', 'warn');

        if (op === 'copy') {

            const codes = [...c.querySelectorAll('tbody tr')]
                .filter(tr => {
                    const rcb = tr.querySelector('[data-row-check]');
                    return rcb && rcb.checked;
                })
                .map(tr => {
                    const td = tr.querySelectorAll('td')[2];
                    return td ? td.textContent.trim() : '';
                })
                .filter(Boolean);
            if (!codes.length) return toast('未取到卡密', 'warn');
            copyText(codes.join('\n')).then(() => toast(`已复制 ${codes.length} 张卡密`));
            return;
        }

        if (op === 'void') {
            confirmBox('批量作废', `将作废已选的 ${ids.length} 张「未使用」卡密，确定继续？`, async () => {
                const r = await api('card_batch_void', { ids });
                if (r.code === 0) { toast(r.msg); cardSel.clear(); renderCards(c); }
            }, true);
            return;
        }

        if (op === 'delete') {
            confirmBox('批量删除', `确定删除已选的 ${ids.length} 张卡密？\n\n此操作不可恢复，已使用的卡密会自动跳过。`, async () => {
                const r = await api('card_batch_delete', { ids });
                if (r.code === 0) { toast(r.msg); cardSel.clear(); renderCards(c); }
            }, true);
        }
    });

    cardSel.sync();

    c.querySelectorAll('[data-copy]').forEach(b => {
        b.addEventListener('click', () => copyText(b.dataset.copy).then(() => toast('已复制')));
    });
    c.querySelectorAll('[data-void]').forEach(b => {
        const id = parseInt(b.dataset.void, 10);
        b.addEventListener('click', () => {
            confirmBox('作废卡密', '作废后该卡密将无法激活，且不可恢复。确定作废 ' + b.dataset.code + ' ？', async () => {
                const r = await api('card_void', { card_id: id });
                if (r.code === 0) { toast(r.msg); renderCards(c); }
            }, true);
        });
    });
}

function doCardSearch(c) {
    cardState.keyword = $('cKw').value.trim();
    cardState.status  = $('cStatus').value;
    cardState.page    = 1;
    renderCards(c);
}

async function exportCards(status) {
    try {
        const { blob, name } = await apiDownload('card_export', {
            format: 'txt', status: status, limit: 20000,
        }, 'cards.txt');
        downloadBlob(blob, name);
        toast('导出成功');
    } catch (e) {
        toast('导出失败：' + e.message, 'err');
    }
}

async function renderBatches(c) {
    c.innerHTML = loading();
    const res = await api('batch_list');
    if (res.code !== 0) return;
    const list = res.data.list || [];

    const rows = list.map(b => `
        <tr>
            <td>#${b.id}</td>
            <td>${esc(b.name || '-')}</td>
            <td class="mono">${esc(b.prefix || '-')}</td>
            <td>${esc(b.type_text)}</td>
            <td>${b.count}</td>
            <td>${b.used_count}</td>
            <td>${b.left_count}</td>
            <td class="mono" style="font-size:12px">${esc(b.created_at_text)}</td>
            <td><button class="btn ghost sm" data-exp="${b.id}">导出该批次</button></td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>我的批次</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>批次</th><th>名称</th><th>前缀</th><th>类型</th>
                    <th>数量</th><th>已用</th><th>剩余</th><th>生成时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('▣', '还没有批次')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    c.querySelectorAll('[data-exp]').forEach(b => {
        const id = parseInt(b.dataset.exp, 10);
        b.addEventListener('click', async () => {
            try {
                const { blob, name } = await apiDownload('card_export', {
                    format: 'txt', status: '', batch_id: id, limit: 20000,
                }, 'cards.txt');
                downloadBlob(blob, name);
                toast('导出成功');
            } catch (e) {
                toast('导出失败：' + e.message, 'err');
            }
        });
    });
}

async function renderRecharge(c) {
    c.innerHTML = loading();
    const a = await refreshProfile();
    if (!a) { c.innerHTML = empty('✕', '资料加载失败'); return; }
    paintRecharge(c, a);
}

function paintRecharge(c, a) {
    const isBal   = a.charge_mode === 2;
    const isQuota = a.charge_mode === 1;

    const typeCards = (a.types || []).map(t => `
        <div class="type-card ${t.enabled ? '' : 'off'}">
            <div class="t-name"><span>${esc(t.name)}</span>${t.enabled ? '' : '<span class="tag gray">未开放</span>'}</div>
            <div class="t-quota">${t.enabled ? esc(t.quota_text) : '—'}</div>
            <div class="t-sub">${t.enabled
                ? ('已用 ' + t.quota_used + (t.quota_total === -1 ? ' 张（不限量）' : ' / 共 ' + t.quota_total + ' 张'))
                : '请联系管理员开通'}</div>
        </div>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>兑换充值卡密</h3>
            <div class="hint">卡密由主管理员生成，兑换后立即到账</div>
        </div>
        <div class="card-body">
            <div class="field">
                <label>充值卡密 *</label>
                <input id="rcCode" placeholder="输入充值卡密，如 RCG-XXXX-XXXXX" autocomplete="off">
                <div class="hint">一张卡密通常只能兑换一次；若提示无效 / 已兑换，请联系管理员核对。</div>
            </div>
            <button class="btn success" id="rcBtn">立即兑换</button>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>我的账户</h3>
            <div class="hint">兑换前可先核对当前余额 / 各类型额度</div>
        </div>
        <div class="card-body">
            <div class="kv">
                <span class="k">控量模式</span><span class="v">${esc(a.charge_mode_text)}</span>
                <span class="k">账户余额</span><span class="v"><b>¥ ${esc(a.balance_text)}</b></span>
            </div>
            ${isQuota ? `<div class="type-grid" style="margin-top:14px">${typeCards}</div>` : ''}
            <div class="hint" style="margin-top:12px">
                ${isBal
                    ? '当前为<b>余额计费</b>：生成卡密按「该类型单价 × 张数」从余额扣款，余额越多能生成的卡密越多。'
                    : (isQuota
                        ? '当前为<b>张数额度</b>：每种卡类型各自统计可生成张数，额度用完需再充值。'
                        : '当前为<b>不限量</b>模式，生成卡密不扣减额度。')}
            </div>
        </div>
    </div>`;

    $('rcBtn').addEventListener('click', doRedeem);
    $('rcCode').addEventListener('keydown', e => { if (e.key === 'Enter') doRedeem(); });
}

async function doRedeem() {
    const el  = $('rcCode');
    const btn = $('rcBtn');
    const code = (el.value || '').trim();
    if (!code) return toast('请输入充值卡密', 'warn');

    btn.disabled = true;
    btn.textContent = '兑换中...';
    try {
        const res = await api('recharge', { code });
        if (res.code === 0) {
            toast(res.data && res.data.detail ? ('兑换成功：' + res.data.detail) : '兑换成功');
            el.value = '';
            const a = await refreshProfile();
            if (a) paintRecharge($('content'), a);
        }
    } finally {
        btn.disabled = false;
        btn.textContent = '立即兑换';
    }
}

async function renderLogs(c) {
    c.innerHTML = loading();
    const res = await api('dashboard');
    if (res.code !== 0) return;
    const logs = res.data.logs || [];

    const rows = logs.map(l => `
        <tr>
            <td>${esc(l.action_text)}</td>
            <td>${esc(l.detail || '-')}</td>
            <td>${l.amount !== 0 ? l.amount : '-'}</td>
            <td class="mono" style="font-size:12px">${esc(l.time_text)}</td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>最近操作记录</h3>
            <div class="hint">仅显示最近 10 条，完整记录请联系管理员</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>操作</th><th>详情</th><th>张数</th><th>时间</th></tr></thead>
                <tbody>${rows || `<tr><td colspan="4">${empty('≡', '暂无记录')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;
}

async function renderAccount(c) {
    c.innerHTML = loading();
    const a = await refreshProfile();
    if (!a) { c.innerHTML = empty('✕', '资料加载失败'); return; }

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>账号资料</h3></div>
        <div class="card-body">
            <div class="kv">
                <span class="k">代理账号</span><span class="v mono"><b>${esc(a.username)}</b></span>
                <span class="k">名称</span><span class="v">${esc(a.nickname)}</span>
                <span class="k">联系方式</span><span class="v">${esc(a.contact || '-')}</span>
                <span class="k">控量模式</span><span class="v">${esc(a.charge_mode_text)}</span>
                <span class="k">账户余额</span><span class="v">¥ ${esc(a.balance_text)}</span>
                <span class="k">设备上限</span><span class="v">${a.max_devices} 台</span>
                <span class="k">兜底用户组</span><span class="v">${a.group_name ? esc(a.group_name) : '不换组'}</span>
                <span class="k">注册激活码</span><span class="v mono">${esc(a.reg_code || '管理员创建')}</span>
                <span class="k">最后登录</span><span class="v">${esc(a.last_login_text || '-')} ${esc(a.last_login_ip || '')}</span>
                <span class="k">创建时间</span><span class="v">${esc(a.created_at_text || '-')}</span>
            </div>
            <div class="hint" style="margin-top:14px">
                账号资料与发货规格由主管理员维护（激活码注册的规格来自激活码），如需调整请联系管理员。
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>我的发货规格</h3>
            <div class="hint">每种卡类型的额度 / 单价 / 激活用户组由管理员分别设置</div>
        </div>
        <div class="card-body">
            <div class="type-grid">
                ${(a.types || []).map(t => `
                <div class="type-card ${t.enabled ? '' : 'off'}">
                    <div class="t-name"><span>${esc(t.name)}</span>${t.enabled ? '' : '<span class="tag gray">未开放</span>'}</div>
                    <div class="t-quota">${t.enabled
                        ? (a.charge_mode === 2
                            ? (t.price > 0 ? ('可生成 ' + Number(t.can_make || 0) + ' 张') : '未配置单价')
                            : t.quota_text)
                        : '—'}</div>
                    <div class="t-sub">${t.enabled
                        ? (a.charge_mode === 2
                            ? (t.price > 0 ? ('单价 ¥ ' + esc(t.price_text) + ' / 张') : '请联系管理员配置单价')
                            : ('已用 ' + t.quota_used + (t.quota_total === -1 ? '' : ' / 共 ' + t.quota_total)))
                        : '请联系管理员开通'}</div>
                    ${t.enabled ? `<div class="t-sub">激活后进入：${esc(t.group_name || a.group_name || '不换组')}</div>` : ''}
                </div>`).join('')}
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>修改登录密码</h3></div>
        <div class="card-body">
            <div class="field"><label>原密码 *</label><input id="pwOld" type="password" autocomplete="current-password"></div>
            <div class="row2">
                <div class="field"><label>新密码 *</label><input id="pwNew" type="password" autocomplete="new-password" placeholder="至少 8 位"></div>
                <div class="field"><label>确认新密码 *</label><input id="pwNew2" type="password" autocomplete="new-password"></div>
            </div>
            <div class="hint" style="margin-bottom:14px">修改成功后所有登录会话将失效，需要用新密码重新登录。</div>
            <button class="btn" id="pwBtn">修改密码</button>
        </div>
    </div>`;

    $('pwBtn').addEventListener('click', async () => {
        const oldP = $('pwOld').value, n1 = $('pwNew').value, n2 = $('pwNew2').value;
        if (!oldP || !n1) return toast('请填写原密码与新密码', 'warn');
        if (n1.length < 8) return toast('新密码至少 8 位', 'warn');
        if (n1 !== n2) return toast('两次输入的新密码不一致', 'warn');

        const res = await api('password', { old_password: oldP, new_password: n1, new_password2: n2 });
        if (res.code === 0) {
            toast('密码已修改，请重新登录');
            setTimeout(() => { setToken(''); setSessionKey(''); location.reload(); }, 1200);
        }
    });
}

function enterApp() {
    $('loginPage').style.display = 'none';
    const rp = $('regPage');
    if (rp) rp.style.display = 'none';
    $('app').style.display = 'block';
    renderNav();
    go('dashboard');
}

function showLogin() {
    const rp = $('regPage');
    if (rp) rp.style.display = 'none';
    const lp = $('loginPage');
    if (lp) lp.style.display = 'flex';
    refreshAgentCaptcha('lg');
    const u = $('lgUser');
    if (u) u.focus();
}

function showRegister() {
    const lp = $('loginPage');
    if (lp) lp.style.display = 'none';
    const rp = $('regPage');
    if (rp) rp.style.display = 'flex';
    refreshAgentCaptcha('rg');
    const c = $('rgCode');
    if (c) c.focus();
}

function agentCaptchaUrl() {
    return `${API_ENTRY}?action=captcha&ts=${Date.now()}`;
}
function refreshAgentCaptcha(which) {
    const map = { lg: 'lgCaptcha', rg: 'rgCaptcha' };
    const img = $(map[which] + 'Img');
    if (img) {
        img.onerror = () => {
            img.onerror = null;
            setTimeout(() => { img.src = agentCaptchaUrl() + '&r=' + Math.random().toString(36).slice(2); }, 1200);
        };
        img.src = agentCaptchaUrl();
    }
    const inp = $(map[which]);
    if (inp) inp.value = '';
}
function bindAgentCaptcha() {
    ['lgCaptchaImg', 'rgCaptchaImg'].forEach(id => {
        const img = document.getElementById(id);
        if (img) img.onclick = function () { refreshAgentCaptcha(id === 'lgCaptchaImg' ? 'lg' : 'rg'); };
    });
}

async function postLogin(username, password, captcha) {
    const r = await fetch(`${API_ENTRY}?action=login`, {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, HEADERS),
        body: JSON.stringify(withGuard({ username, password, captcha })),
        credentials: 'same-origin',
    });
    return r.json();
}

async function doLogin() {
    const u = $('lgUser').value.trim();
    const p = $('lgPass').value;
    const c = $('lgCaptcha') ? $('lgCaptcha').value.trim() : '';
    if (!u || !p) return toast('请输入账号和密码', 'warn');
    if (!c) return toast('请输入验证码', 'warn');

    const btn = $('lgBtn');
    btn.disabled = true;
    btn.textContent = '登录中...';
    try {
        const res = await postLogin(u, p, c);
        if (res.code !== 0) {
            toast(res.msg || '登录失败', 'err');
            refreshAgentCaptcha('lg');
            return;
        }
        setToken(res.data.token);
        setSessionKey(res.data.session_key || '');
        S.agent = res.data.agent;
        paintHeader(S.agent);
        toast('登录成功');
        enterApp();
    } catch (e) {
        toast('登录请求失败：' + e.message, 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = '登 录';
    }
}

async function doRegister() {
    const payload = {
        code:      $('rgCode').value.trim(),
        username:  $('rgUser').value.trim(),
        nickname:  $('rgNick').value.trim(),
        password:  $('rgPass').value,
        password2: $('rgPass2').value,
        contact:   $('rgContact').value.trim(),
        captcha:   $('rgCaptcha') ? $('rgCaptcha').value.trim() : '',
    };

    if (!payload.code) return toast('请填写代理商激活码', 'warn');
    if (!payload.username) return toast('请填写代理账号', 'warn');
    if (payload.password.length < 8) return toast('密码至少 8 位', 'warn');
    if (payload.password !== payload.password2) return toast('两次输入的密码不一致', 'warn');
    if (!payload.captcha) return toast('请输入验证码', 'warn');

    const btn = $('rgBtn');
    btn.disabled = true;
    btn.textContent = '注册中...';
    try {
        const r = await fetch(`${API_ENTRY}?action=register`, {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, HEADERS),
            body: JSON.stringify(withGuard(payload)),
            credentials: 'same-origin',
        });
        const res = await r.json();
        if (res.code !== 0) {
            toast(res.msg || '注册失败', 'err');
            refreshAgentCaptcha('rg');
            return;
        }

        toast('注册成功，请用新账号登录', 'ok');
        const lu = $('lgUser');
        if (lu) lu.value = payload.username;
        showLogin();
    } catch (e) {
        toast('注册请求失败：' + e.message, 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = '注 册';
    }
}

function doLogout() {
    confirmBox('退出登录', '确定要退出代理商后台吗？', async () => {
        try { await api('logout', {}, true); } catch (e) {
 }
        setToken('');
        setSessionKey('');
        location.reload();
    });
}

function agentStoredTheme() {
    try {
        const t = localStorage.getItem('nb_agent_theme');
        return (t === 'dark' || t === 'light') ? t : '';
    } catch (e) { return ''; }
}

function agentAppliedTheme() {
    try {
        return agentStoredTheme()
            || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    } catch (e) { return 'light'; }
}

function agentApplyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
}

function paintAgentThemeBtn() {
    const btn = $('btnTheme');
    if (!btn) return;
    const dark = agentAppliedTheme() === 'dark';
    btn.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    btn.title = dark ? '切换到浅色模式' : '切换到深色模式';
}

function initAgentTheme() {
    agentApplyTheme(agentAppliedTheme());
    paintAgentThemeBtn();
    const btn = $('btnTheme');
    if (btn) {
        btn.addEventListener('click', () => {
            const next = agentAppliedTheme() === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('nb_agent_theme', next); } catch (e) {
 }
            agentApplyTheme(next);
            paintAgentThemeBtn();
        });
    }

    try {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = e => { if (!agentStoredTheme()) { agentApplyTheme(e.matches ? 'dark' : 'light'); paintAgentThemeBtn(); } };
        mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange);
    } catch (e) {
 }
}

(async function boot() {
    const mask = $('bootMask');
    if (mask) mask.classList.add('hide');

    initAgentTheme();

    const form = $('lgForm');
    if (form) {
        form.addEventListener('submit', e => { e.preventDefault(); doLogin(); });
    }
    bindAgentCaptcha();
    const out = $('btnLogout');
    if (out) out.addEventListener('click', doLogout);

    if (REG_OPEN) {
        const rf = $('rgForm');
        if (rf) rf.addEventListener('submit', e => { e.preventDefault(); doRegister(); });
        const toReg = $('toReg');
        if (toReg) toReg.addEventListener('click', showRegister);
        const toLogin = $('toLogin');
        if (toLogin) toLogin.addEventListener('click', showLogin);
    }

    if (!RT.enabled) return;

    if (S.token) {
        try {
            const res = await api('profile', {}, true);
            if (res.code === 0) {
                S.agent = res.data.agent;
                S.stats = res.data.stats;
                paintHeader(S.agent);
                enterApp();
                return;
            }
        } catch (e) {
 }

        setToken('');
        setSessionKey('');
    }

    if (REG_OPEN && location.hash === '#reg') {
        showRegister();
        return;
    }
    showLogin();
})();
