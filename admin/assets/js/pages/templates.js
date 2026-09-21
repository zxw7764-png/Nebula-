import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc } from '../core/util.js';
import { toast, confirmBox } from '../core/ui.js';

register('templates', render);

let data = null;
let softwares = [];
const pick = { web: '', shop: '', swWeb: '', swShop: '' };
const swOverrides = { web: {}, shop: {} };
const swPainters = {};

const SW_SIDES = [
    {
        kind: 'web',  title: '分软件官网模板',  tplSide: 'web',
        followName: '跟随官网模板',   followSub: '不单独覆盖，使用上面的官网模板设置',
        field: 'web_ui_template',  getAction: 'software_web_get',  saveAction: 'software_web_save',
        hint: '为单个软件官网单独指定模板，访问带该软件 ?app= 标识的官网时生效',
    },
    {
        kind: 'shop', title: '分软件发卡网模板', tplSide: 'shop',
        followName: '跟随发卡网模板', followSub: '不单独覆盖，使用上面的发卡网模板设置',
        field: 'shop_ui_template', getAction: 'shop_sw_get',       saveAction: 'shop_sw_save',
        hint: '为单个软件单独指定发卡网模板，识别到访客软件（?app= / 记忆 / 单软件）时生效',
    },
];

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    let tRes, sRes;
    try {
        [tRes, sRes] = await Promise.all([api('template_list'), api('software_list')]);
    } catch (e) { return; }
    if (tRes.code !== 0) return;
    data = tRes.data;
    softwares = sRes.code === 0 ? (sRes.data.options || []) : [];
    pick.web = data.current.web || '';
    pick.shop = data.current.shop || '';
    pick.swWeb = '';
    pick.swShop = '';
    swOverrides.web = {};
    swOverrides.shop = {};

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>界面模板</h3>
            <button class="btn ghost sm" id="tplReload">刷新模板</button>
        </div>
        <div class="card-body"><div class="tpl-note">
            官网与发卡网模板<b>统一在 web/Template/&lt;模板名&gt;/ 直接识别与加载</b>：
            web.css = 官网样式、shop.css = 发卡样式、game.html + game.js = 自带小游戏（两端通用）——
            建好文件夹刷新本页即出现，前台改完即生效，无需同步。
            各端 assets 模板文件夹（单文件 &lt;id&gt;.css 或文件夹 &lt;id&gt;/&lt;id&gt;.css）的旧式模板继续兼容，
            下划线开头为共享样式。开发规范见 docs/TEMPLATE.md（文档中心）。
            模板自带小游戏会在前台右下角出现 🎮 按钮。模板自带整套配色与装饰背景，
            激活后<b>优先于主题色 / 背景图</b>；「默认深空」沿用后台主题色配置。
        </div></div>
        <div class="card-body" style="padding-top:2px">
            ${section('web', '官网模板', '应用于软件官网（portal），单个软件可在下方「分软件官网模板」单独覆盖')}
            ${section('shop', '发卡网模板', '应用于发卡网（shop），模板自带右下角小游戏与排行榜')}
            ${tplSecSection()}
            ${SW_SIDES.map(sw => swSection(sw)).join('')}
        </div>
    </div>`;

    document.getElementById('tplReload').addEventListener('click', render);
    bindTplSec();

    c.querySelectorAll('[data-tpl-pick]').forEach(el => {
        el.addEventListener('click', () => {
            const side = el.dataset.side;
            pick[side] = el.dataset.tplPick;
            const p = swPainters[side.slice(2)];
            if ((side === 'swweb' || side === 'swshop') && p) {
                p.cards();
                p.state(true);
            } else {
                c.querySelectorAll(`[data-side="${side}"][data-tpl-pick]`).forEach(x =>
                    x.classList.toggle('on', x === el));
            }
        });
    });

    c.querySelectorAll('[data-tpl-save]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const side = btn.dataset.tplSave;
            btn.disabled = true;
            try {
                const r = await api('template_save', { side: side, template: pick[side] });
                if (r.code === 0) { toast(r.msg || '已保存'); render(); }
            } finally {
                btn.disabled = false;
            }
        });
    });

    SW_SIDES.forEach(bindSwSection);
}

function section(side, title, hint) {
    const list = (data.templates && data.templates[side]) || [];
    const cur = pick[side];
    const cards = [
        cardHtml(side, '', '默认深空', '跟随后台主题色（不加载模板装饰）', '#7c5cff', cur),
        ...list.map(t => cardHtml(side, t.id, t.name, tplSub(t), t.color, cur)),
    ].join('');
    return `
    <div style="margin-top:22px">
        <b style="font-size:15px;display:block">${esc(title)}</b>
        <div class="hint" style="margin:5px 0 12px">${esc(hint)}</div>
        <div class="tpl-grid">${cards}</div>
        <button class="btn" data-tpl-save="${side}">保存${esc(title)}</button>
    </div>`;
}

function tplSub(t) {
    return t.sub + (t.dir ? ' · 文件夹' : '') + (t.has_game ? ' · 自带小游戏' : '') + (t.has_interact ? ' · 交互音效' : '');
}

function swSection(sw) {
    if (!softwares.length) {
        return `
        <div style="margin-top:22px">
            <b style="font-size:15px;display:block">${esc(sw.title)}</b>
            <div class="hint" style="margin:5px 0 12px">${esc(sw.hint)}</div>
            <div class="hint">暂无软件。请先到「软件管理」新增软件。</div>
        </div>`;
    }
    const opts = softwares.map(s =>
        `<option value="${s.id}">${esc(s.name)}${s.status === 1 ? '' : '（停用）'}</option>`).join('');
    const list = (data.templates && data.templates[sw.tplSide]) || [];
    const cur = pick['sw' + sw.kind] || '';
    const cards = [
        cardHtml('sw' + sw.kind, '', sw.followName, sw.followSub, '#7c5cff', cur),
        ...list.map(t => cardHtml('sw' + sw.kind, t.id, t.name, tplSub(t), t.color, cur)),
    ].join('');
    return `
    <div style="margin-top:22px">
        <b style="font-size:15px;display:block">${esc(sw.title)}</b>
        <div class="hint" style="margin:5px 0 12px">${esc(sw.hint)}</div>
        <div class="pw-group">
            <div class="row2" style="margin-bottom:12px">
                <div class="field"><label>选择软件</label>
                    <select id="tplSwSel_${sw.kind}">${opts}</select>
                    <div class="hint">切换软件会自动加载该软件已保存的模板覆盖</div>
                </div>
                <div class="field"><label>当前覆盖状态</label>
                    <input id="tplSwState_${sw.kind}" readonly value="加载中…">
                    <div class="hint">模板之外的覆盖字段保存时原样保留，不会被清空</div>
                </div>
            </div>
            <div class="tpl-grid">${cards}</div>
            <button class="btn" id="tplSwSave_${sw.kind}">保存该软件模板</button>
        </div>
    </div>`;
}

function bindSwSection(sw) {
    const sel = document.getElementById('tplSwSel_' + sw.kind);
    if (!sel) return;
    const stateInp = document.getElementById('tplSwState_' + sw.kind);

    const paintCards = () => {
        const cur = pick['sw' + sw.kind] || '';
        document.querySelectorAll(`[data-side="sw${sw.kind}"][data-tpl-pick]`).forEach(x => {
            const on = (x.dataset.tplPick || '') === cur;
            x.classList.toggle('on', on);
            const tagEl = x.querySelector('.tpl-cur');
            if (on && !tagEl) {
                const b = x.querySelector('.tpl-info b');
                if (b) b.insertAdjacentHTML('beforeend', ' <span class="tag green tpl-cur">当前</span>');
            } else if (!on && tagEl) {
                tagEl.remove();
            }
        });
    };

    const paintState = (pending) => {
        const cur = pick['sw' + sw.kind] || '';
        if (pending) {
            stateInp.value = cur === ''
                ? '待保存：将清除覆盖（跟随全局模板）'
                : `待保存：「${templateName(sw.tplSide, cur)}」`;
            return;
        }
        const ov = swOverrides[sw.kind] || {};
        const has = typeof ov[sw.field] === 'string' && ov[sw.field].trim() !== '';
        const n = Object.keys(ov).length;
        stateInp.value = has
            ? `已覆盖为「${templateName(sw.tplSide, ov[sw.field])}」（另有 ${Math.max(n - 1, 0)} 项覆盖保留）`
            : '未覆盖（使用全局模板设置）';
    };
    swPainters[sw.kind] = { cards: paintCards, state: paintState };

    async function loadSw() {
        const id = parseInt(sel.value, 10);
        stateInp.value = '…';
        swOverrides[sw.kind] = {};
        try {
            const res = await api(sw.getAction, { id });
            if (res.code !== 0) { stateInp.value = '读取失败'; return toast(res.msg || '读取失败', 'err'); }
            swOverrides[sw.kind] = res.data.overrides || {};
            pick['sw' + sw.kind] = String(swOverrides[sw.kind][sw.field] || '').trim();
        } catch (e) {
            pick['sw' + sw.kind] = '';
            stateInp.value = '读取失败';
            return;
        }
        paintCards();
        paintState();
    }

    sel.addEventListener('change', loadSw);
    loadSw();

    document.getElementById('tplSwSave_' + sw.kind).addEventListener('click', () => {
        const b = document.getElementById('tplSwSave_' + sw.kind);
        b.disabled = true;
        (async () => {
            try {

                const payload = Object.assign({}, swOverrides[sw.kind], {
                    id: parseInt(sel.value, 10),
                    [sw.field]: pick['sw' + sw.kind],
                });
                const r = await api(sw.saveAction, payload);
                if (r.code === 0) {
                    swOverrides[sw.kind] = (r.data || {}).overrides || {};
                    toast(sw.kind === 'web' ? '该软件官网模板已保存' : '该软件发卡网模板已保存');
                    paintCards();
                    paintState();
                }
            } finally {
                b.disabled = false;
            }
        })();
    });
}

function templateName(tplSide, id) {
    if (!id) return '默认深空';
    const t = ((data.templates && data.templates[tplSide]) || []).find(x => x.id === id);
    return t ? t.name : id;
}

let secTpl = '';
let secSide = 'web';

function secTplList() {
    return ((data.templates && data.templates[secSide]) || []).filter(t => (t.src || '') === 'dev');
}

function secTplOptions() {
    const cur = pick[secSide] || '';
    return secTplList().map(t =>
        `<option value="${esc(t.id)}">${esc(t.name)}（${esc(t.id)}）${cur === t.id ? ' · 当前生效' : ''}</option>`).join('');
}

function tplSecSection() {

    const devOf = side => ((data.templates && data.templates[side]) || []).filter(t => (t.src || '') === 'dev');
    if (!devOf('web').length && !devOf('shop').length) return '';
    return `
    <div style="margin-top:22px">
        <b style="font-size:15px;display:block">布局与自定义区块（官网 / 发卡网）</b>
        <div class="hint" style="margin:5px 0 12px">
            调整官网首页 / 发卡网首页区块的显示顺序与显隐（写入该端模板 css 头注释 Layout: 行），并编辑模板自定义区块
            （官网 sections/*.html、发卡网 shop-sections/*.html）内容——文件直接保存在模板目录，前台改完即生效。
            官网内置区块（hero / features / shots / flow / pricing / sellers / notice / board / faq）
            文案在「官网内容」页配置；发卡网内置区块：notice 公告横幅 / notes 购买须知 / goods 商品区。
        </div>
        <div class="row2" style="margin-bottom:4px">
            <div class="field" style="margin-bottom:0"><label>编辑端</label>
                <select id="tplSecSide">
                    <option value="web">官网</option>
                    <option value="shop">发卡网</option>
                </select>
            </div>
            <div class="field" style="margin-bottom:0"><label>选择模板</label>
                <select id="tplSecTpl"></select>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:14px;margin:2px 0 12px">
            <div class="hint" style="margin:0;flex:1">仅 web/Template 文件夹型模板支持编辑；改完顺序与区块前台即时生效。</div>
            <button class="btn" id="tplSecLoad">管理模板布局与区块</button>
        </div>
        <div id="tplSecBox"></div>
    </div>`;
}

function bindTplSec() {
    const sideSel = document.getElementById('tplSecSide');
    if (!sideSel) return;
    const sel = document.getElementById('tplSecTpl');
    const fill = () => {
        sel.innerHTML = secTplOptions();
        if (secTplList().some(t => t.id === (pick[secSide] || ''))) { sel.value = pick[secSide]; }
        document.getElementById('tplSecBox').innerHTML = '';
    };
    sideSel.value = secSide;
    fill();
    document.getElementById('tplSecLoad').addEventListener('click', loadTplSections);
    sel.addEventListener('change', () => { document.getElementById('tplSecBox').innerHTML = ''; });
    sideSel.addEventListener('change', () => {
        secSide = sideSel.value === 'shop' ? 'shop' : 'web';
        fill();
    });
}

async function loadTplSections() {
    const sel = document.getElementById('tplSecTpl');
    const box = document.getElementById('tplSecBox');
    if (!sel || !box) return;
    secTpl = sel.value;
    box.innerHTML = loading();
    let res;
    try {
        res = await api('tpl_sections_get', { template: secTpl, side: secSide });
    } catch (e) { box.innerHTML = ''; return; }
    if (res.code !== 0) { box.innerHTML = ''; return; }
    const d = res.data;
    const bi = d.builtins || [];
    box.innerHTML = `
    <div class="pw-group" style="margin-top:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px">
            <b>正在编辑（${secSide === 'shop' ? '发卡网' : '官网'}）：${esc(d.template)}${(pick[secSide] || '') === d.template ? ' <span class="tag green">当前生效</span>' : ''}</b>
            <button class="btn ghost sm" id="tplSecClose">关闭编辑</button>
        </div>
        <div class="field">
            <label>区块显示顺序（逗号分隔，可增删排序）</label>
            <input id="tplSecLayout" value="${esc((d.layout || []).join(', '))}">
            <div class="hint">内置区块：${esc(bi.join(' / '))}；自定义区块 id 也可填（先在下方创建）。
                ${d.layout_declared ? '' : '当前模板 css 未声明 Layout，以上为内置默认顺序。'}
                留空保存 = 清除声明恢复默认；省略的区块前台不显示。</div>
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                <button class="btn" id="tplSecLayoutSave">保存区块顺序</button>
                <button class="btn ghost" id="tplSecLayoutReset" title="清除该模板 css 里的布局声明，恢复内置默认顺序与全部区块">恢复默认布局（取消自定义顺序）</button>
            </div>
        </div>
        <div style="margin-top:18px">
            <b style="font-size:14px;display:block">自定义区块内容</b>
            <div class="hint" style="margin:4px 0 10px">模板 ${secSide === 'shop' ? 'shop-sections/' : 'sections/'} 目录下的区块文件；标注「未加入布局」的区块前台不显示，把 id 填进上方顺序并保存即可。删除区块会同时删文件并从布局顺序移除。</div>
            ${d.sections.length ? d.sections.map(s => `
            <div class="field" style="margin-bottom:14px">
                <label>${esc(s.id)}${s.in_layout ? '' : ' <span class="tag" style="margin-left:4px">未加入布局</span>'}</label>
                <textarea id="tplSecC_${esc(s.id)}" rows="6" class="mono" spellcheck="false">${esc(s.content)}</textarea>
                <div style="display:flex;gap:8px;margin-top:6px">
                    <button class="btn sm" data-sec-save="${esc(s.id)}">保存「${esc(s.id)}」</button>
                    <button class="btn sm ghost danger-ghost" data-sec-del="${esc(s.id)}">删除「${esc(s.id)}」</button>
                </div>
            </div>`).join('') : '<div class="hint">该模板还没有自定义区块，可在下方创建。</div>'}
        </div>
        <div style="margin-top:6px;border-top:1px solid var(--border);padding-top:14px">
            <b style="font-size:14px;display:block">新增自定义区块</b>
            <div class="field" style="margin-top:8px"><label>区块 id（小写字母 / 数字 / 下划线，1-32 位）</label>
                <input id="tplSecNewId" placeholder="例如 banner2">
            </div>
            <div class="field"><label>区块 HTML 内容（{{SITE_NAME}} 会替换为站点名；样式写在模板 css 里）</label>
                <textarea id="tplSecNewContent" rows="4" class="mono" spellcheck="false"></textarea>
            </div>
            <button class="btn" id="tplSecNewSave">创建区块</button>
        </div>
    </div>`;

    document.getElementById('tplSecClose').addEventListener('click', () => { box.innerHTML = ''; });

    document.getElementById('tplSecLayoutReset').addEventListener('click', async () => {
        const btn = document.getElementById('tplSecLayoutReset');
        btn.disabled = true;
        try {
            const r = await api('tpl_sections_save', { template: secTpl, side: secSide, mode: 'layout', layout: [] });
            if (r.code === 0) { toast(r.msg || '已恢复默认布局'); loadTplSections(); }
        } finally { btn.disabled = false; }
    });

    document.getElementById('tplSecLayoutSave').addEventListener('click', async () => {
        const btn = document.getElementById('tplSecLayoutSave');
        btn.disabled = true;
        try {
            const raw = document.getElementById('tplSecLayout').value;
            const ids = raw.split(/[,，、;；\s]+/).map(x => x.trim()).filter(Boolean);
            const r = await api('tpl_sections_save', { template: secTpl, side: secSide, mode: 'layout', layout: ids });
            if (r.code === 0) {
                toast(r.msg || '已保存');

                document.getElementById('tplSecLayout').value = ((r.data && r.data.layout) || ids).join(', ');
                loadTplSections();
            }
        } finally { btn.disabled = false; }
    });

    box.querySelectorAll('[data-sec-del]').forEach(b => {
        b.addEventListener('click', () => {
            const id = b.dataset.secDel;
            confirmBox('删除自定义区块', `确定删除「${id}」？区块文件会被删除，并自动从布局顺序中移除，前台立即消失。`, async () => {
                b.disabled = true;
                try {
                    const r = await api('tpl_sections_save', { template: secTpl, side: secSide, mode: 'section', id, delete: 1 });
                    if (r.code === 0) { toast(r.msg || '已删除'); loadTplSections(); }
                } finally { b.disabled = false; }
            }, true);
        });
    });

    box.querySelectorAll('[data-sec-save]').forEach(b => {
        b.addEventListener('click', async () => {
            const id = b.dataset.secSave;
            const ta = document.getElementById('tplSecC_' + id);
            b.disabled = true;
            try {
                const r = await api('tpl_sections_save', { template: secTpl, side: secSide, mode: 'section', id, content: ta ? ta.value : '' });
                if (r.code === 0) { toast(r.msg || '已保存'); }
            } finally { b.disabled = false; }
        });
    });

    document.getElementById('tplSecNewSave').addEventListener('click', async () => {
        const btn = document.getElementById('tplSecNewSave');
        const idEl = document.getElementById('tplSecNewId');
        const cEl = document.getElementById('tplSecNewContent');
        const id = (idEl.value || '').trim();
        if (!/^[a-z0-9_]{1,32}$/.test(id)) { return toast('区块 id 仅限小写字母 / 数字 / 下划线，1-32 位', 'err'); }
        btn.disabled = true;
        try {
            const r = await api('tpl_sections_save', { template: secTpl, side: secSide, mode: 'section', id, content: cEl.value || '' });
            if (r.code === 0) { toast(r.msg || '已创建'); loadTplSections(); }
        } finally { btn.disabled = false; }
    });
}

function cardHtml(side, id, name, sub, color, cur) {
    const active = cur === id;
    return `
    <div class="tpl-card ${active ? 'on' : ''}" data-side="${side}" data-tpl-pick="${esc(id)}"
         title="点击选中，保存后生效">
        <span class="tpl-dot" style="background:${esc(color)}"></span>
        <div class="tpl-info">
            <b>${esc(name)}${active ? ' <span class="tag green tpl-cur">当前</span>' : ''}</b>
            <div class="hint">${esc(sub)}</div>
        </div>
        ${id ? `<code class="mono sub" style="font-size:11px">${esc(id)}</code>` : ''}
    </div>`;
}
