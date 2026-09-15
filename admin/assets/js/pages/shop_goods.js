/* ======================================================================
   pages/shop_goods.js — 发卡商品（商品与交易 子页）
   ----------------------------------------------------------------------
   只管发卡侧配置：上架状态 / 发卡售价 / 挂卡规格 / 商品陈列（分类、图标、简介）。
   商品名称、价格文案等官网展示信息在「商品与交易 → 价格套餐」维护，
   两边共享同一份套餐数据（nb_plans），按字段分工各改各的。
   保存走独立 action shop_goods_save（服务端归业务档，仅超管）。
   ====================================================================== */

import { api, uploadHeaders } from '../core/api.js';
import { API_ENTRY, extraHeaders } from '../core/state.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, checkAllBox, rowCheckBox, createSelection } from '../core/ui.js';

register('shop_goods', render);

let curCat = '';   // 分类筛选：''=全部 '__none__'=未分类
let sel = null;    // 行选择器（批量删除）
let catCfg = [];   // 通用分类完整行（「名称|图标」；分类候选与就地新建的数据源）
let typeCfg = [];  // 「卡类型」子页配置：[{id,name,on,hint}]（挂卡类型下拉来源）
let swList = [];   // 软件下拉候选：[{id,name,status}]（显示归属软件下拉来源）
let swCatsMap = {}; // 各软件专属分类完整行：{ "<软件ID>": ["名称|图标"...] }（shop_cats_sw_<id>）
let curCatFlt = ''; // 分类列表归属筛选：''=全部，'general'=通用，'N'=软件N 专属
let curCatKw = ''; // 分类列表名称搜索关键字
let curGKw = ''; // 发卡商品列表搜索关键字（命中套餐名/发卡商品名/分类）
let curTab = 'goods'; // 子页签：goods=发卡商品 cats=分类管理 types=卡类型

const TYPE_TEXT = { 1: '时长卡', 2: '点数卡', 3: '次数卡', 4: '永久卡' };
const typeName = id => (typeCfg.find(t => t.id === Number(id)) || {}).name || TYPE_TEXT[id] || '-';
/* 显示归属软件名（列表展示用，未知 id 显示占位） */
const swName = id => {
    const s = swList.find(x => x.id === Number(id));
    return s ? s.name : ('软件#' + id);
};
/* 卡面带单位展示：时长卡秒数 → 年/月/周/天/小时/分钟，点数/次数原值，0/永久卡 → 永久 */
const durText = p => {
    const v = parseInt(p.card_duration, 10) || 0;
    if (Number(p.card_type) === 4 || v <= 0) return '永久';
    if (Number(p.card_type) === 2) return v + ' 点数';
    if (Number(p.card_type) === 3) return v + ' 次';
    const U = [[31536000, '年'], [2592000, '月'], [604800, '星期'], [86400, '天'], [3600, '小时'], [60, '分钟']];
    for (const [f, n] of U) if (v % f === 0) return (v / f) + ' ' + n;
    return v + ' 秒';
};
/* 售价展示：与下单金额同口径 —— 有效售价 = 规格售价(>0) 否则回落到默认售价。
   多规格显示区间（如 ¥10 ~ ¥100），单规格显示该规格售价；全 0 显示免费 */
const priceCell = p => {
    const base = parseFloat(p.shop_price) || 0;
    let cards = (Array.isArray(p.cards) ? p.cards : []).map(c => {
        const v = parseFloat(c.price) || 0;
        return v > 0 ? v : base;
    });
    if (!cards.length) cards = [base];
    const min = Math.min(...cards), max = Math.max(...cards);
    if (max <= 0) return '<span style="color:#16a34a">免费</span>';
    if (min !== max) {
        const fmt = v => (v % 1 === 0 ? v : v.toFixed(2));
        return '&yen;' + fmt(min) + ' ~ &yen;' + fmt(max);
    }
    return '&yen;' + (Number.isInteger(min) ? min : min.toFixed(2));
};
/* 挂卡规格展示：多规格逐条列出（类型 · 时长 · 设备 · 组），单规格同旧单行格式 */
const specText = p => {
    const cards = Array.isArray(p.cards) ? p.cards : [];
    if (cards.length <= 1) {
        return `${typeName(p.card_type)} · 卡面 ${durText(p)} · ${p.card_max_devices} 设备 · 组 ${p.card_group_id}`;
    }
    return cards.map(c =>
        `${typeName(c.card_type)}·${durText(c)}·${c.card_max_devices}设备·组${c.card_group_id}`
    ).join(' ／ ');
};

/* 页面入口：发卡商品 / 分类 / 卡类型 三个子页签 */
async function render() {
    const c = document.getElementById('content');
    c.innerHTML = `
    <div class="tabs" id="gTabs">
        <button type="button" data-t="goods"${curTab === 'goods' ? ' class="on"' : ''}>发卡商品</button>
        <button type="button" data-t="cats"${curTab === 'cats' ? ' class="on"' : ''}>分类</button>
        <button type="button" data-t="types"${curTab === 'types' ? ' class="on"' : ''}>卡类型</button>
    </div>
    <div id="gWrap">${loading()}</div>`;
    document.querySelectorAll('#gTabs button').forEach(b => {
        b.addEventListener('click', () => { if (curTab !== b.dataset.t) { curTab = b.dataset.t; render(); } });
    });
    if (curTab === 'cats') { await renderCats(); return; }
    if (curTab === 'types') { await renderTypes(); return; }
    await renderGoods();
}

/* 卡类型子页：固定 4 类的显示名/启用配置 + 官网永久会员提示文案 */
async function renderTypes() {
    const c = document.getElementById('gWrap');
    c.innerHTML = loading();

    const cfg = await api('setting_get');
    if (cfg.code !== 0) return;
    const s = cfg.data.settings || cfg.data || {};

    const lines = String(s.shop_card_types || '').split('\n');
    const saved = {};
    lines.forEach(l => {
        const p = l.split('|').map(x => x.trim());
        if (p.length >= 3 && TYPE_TEXT[p[0]]) saved[p[0]] = { name: p[1], on: p[2] === '1', hint: p[3] || '' };
    });
    typeCfg = Object.keys(TYPE_TEXT).map(id => ({
        id: Number(id),
        name: saved[id]?.name || TYPE_TEXT[id],
        on: saved[id] ? saved[id].on : true,
        hint: (saved[id] && saved[id].hint) || { 1: '卡面 = 秒数', 2: '卡面 = 点数', 3: '卡面 = 次数', 4: '激活后永久有效' }[id],
    }));

    const rows = typeCfg.map(t => `
        <tr>
            <td>${t.id}</td>
            <td><b>${esc(TYPE_TEXT[t.id])}</b></td>
            <td><input class="tinput ctName" data-id="${t.id}" maxlength="20" value="${esc(t.name)}"></td>
            <td><input class="tinput ctHint" data-id="${t.id}" maxlength="50" value="${esc(t.hint)}"></td>
            <td><input type="checkbox" class="ctOn" data-id="${t.id}" ${t.on ? 'checked' : ''}></td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>卡类型</h3>
            <div class="acts"><button class="btn success" id="ctSave">保存卡类型</button></div></div>
        <div class="card-body"><div class="hint">关闭的类型不会出现在「新增发卡商品」的挂卡类型下拉里；自定义显示名会同步展示在发卡网商品规格和代理发货规格，提示文案会显示在商品弹窗的挂卡类型说明处。系统内部逻辑（发卡/激活）不受名称影响。</div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>ID</th><th>系统类型</th><th>显示名</th><th>提示文案</th><th>启用</th></tr></thead>
            <tbody>${rows}</tbody>
        </table></div>
    </div>
    <div class="card" style="margin-top:14px">
        <div class="card-head"><h3>官网个人中心 · 永久会员提示</h3>
            <div class="acts"><button class="btn success" id="fvSave">保存提示文案</button></div></div>
        <div class="card-body">
            <div class="field"><label>提示文案（第 1 行主文案，其余每行一条要点；留空用默认）</label>
                <textarea id="fvText" rows="5" placeholder="你的账号已是永久会员，会员时长永久有效，无需再次激活。&#10;永久有效，不存在到期时间&#10;账号、设备额度与全部菜单功能不受期限限制&#10;如有疑问请联系客服核对订单">${esc(String(s.web_forever_text || ''))}</textarea>
                <div class="hint">仅对已激活永久卡（无到期时间）的用户展示在官网个人中心会员状态卡</div>
            </div>
        </div>
    </div>`;

    document.getElementById('ctSave').addEventListener('click', async () => {
        const val = typeCfg.map(t => {
            const name = c.querySelector(`.ctName[data-id="${t.id}"]`).value.trim() || TYPE_TEXT[t.id];
            const hint = c.querySelector(`.ctHint[data-id="${t.id}"]`).value.trim();
            const on = c.querySelector(`.ctOn[data-id="${t.id}"]`).checked ? '1' : '0';
            return `${t.id}|${name}|${on}|${hint}`;
        }).join('\n');
        const res = await api('shop_setting_save', { settings: { shop_card_types: val } });
        if (res.code === 0) toast('卡类型已保存');
    });

    document.getElementById('fvSave').addEventListener('click', async () => {
        const val = document.getElementById('fvText').value.replace(/\r\n?/g, '\n');
        const res = await api('shop_setting_save', { settings: { web_forever_text: val } });
        if (res.code === 0) toast('永久会员提示已保存');
    });
}

async function renderCats() {
    const c = document.getElementById('gWrap');
    c.innerHTML = loading();

    const cfg = await api('setting_get');
    if (cfg.code !== 0) return;
    const st = cfg.data.settings || {};

    // 软件列表：分类归属范围下拉候选（通用 + 各软件专属）
    const swRes = await api('software_list');
    if (swRes.code === 0) swList = swRes.data.options || [];

    // 全部分类平铺：通用（shop_cats）+ 各软件专属（shop_cats_sw_<id>），归属行内标注、弹窗内选择
    const allCats = [];
    ['', ...swList.map(s => String(s.id))].forEach(swId => {
        const raw = String(swId === '' ? (st.shop_cats || '') : (st['shop_cats_sw_' + swId] || ''));
        raw.split('\n').map(l => l.trim()).filter(Boolean).forEach(line => {
            const i = line.indexOf('|');
            allCats.push({ name: i === -1 ? line : line.slice(0, i).trim(), icon: i === -1 ? '' : line.slice(i + 1).trim(), swId });
        });
    });

    // 归属筛选指向已删除软件时回落全部
    if (curCatFlt !== '' && curCatFlt !== 'general' && !swList.some(s => String(s.id) === curCatFlt)) curCatFlt = '';
    // 归属筛选 + 名称搜索（全选只作用于搜索结果）
    const kw = curCatKw.trim().toLowerCase();
    const shown = (curCatFlt === '' ? allCats
        : allCats.filter(cc => String(cc.swId) === String(curCatFlt === 'general' ? '' : curCatFlt)))
        .filter(cc => !kw || cc.name.toLowerCase().includes(kw));

    const rows = shown.map((cat, idx) => `
        <tr>
            ${rowCheckBox('cat_' + idx)}
            <td>${idx + 1}</td>
            <td><b>${esc(cat.name)}</b></td>
            <td>${cat.icon
                ? `<img src="${esc(cat.icon)}" alt="" style="width:34px;height:34px;border-radius:8px;object-fit:cover;border:1px solid var(--border)">`
                : '<span class="sub">无图标</span>'}</td>
            <td>${cat.swId === '' ? tag('通用', 'gray') : tag(swName(cat.swId) + ' 专属', 'blue')}</td>
            <td>${allCats.some(o => o !== cat && o.swId === cat.swId && o.name === cat.name)
                ? tag('名称重复', 'yellow')
                : '<span class="sub">-</span>'}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-edit="${idx}">编辑</button>
                <button class="btn danger sm" data-del="${idx}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>商品分类</h3>
            <div class="acts">
                <span id="catBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="catBulkOp">
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="catBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <select id="catFilter">
                        <option value="" ${curCatFlt === '' ? 'selected' : ''}>全部分类</option>
                        <option value="general" ${curCatFlt === 'general' ? 'selected' : ''}>通用分类</option>
                        ${swList.map(s => `<option value="${s.id}" ${curCatFlt === String(s.id) ? 'selected' : ''}>${esc(s.name)} 专属</option>`).join('')}
                    </select>
                    <input id="catKw" placeholder="搜索分类名称" value="${esc(curCatKw)}">
                    <button class="btn" id="catSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="catNew">+ 添加分类</button>
            </div>
        </div>
        <div class="card-body"><div class="hint">通用分类对所有商品可见；软件专属分类只在商品的「显示归属软件」命中该软件时出现在分类候选里（配合「发卡网配置 → 按软件过滤商品」实现分软件商店）。分类的归属范围在添加 / 编辑弹窗中选择，编辑时更换归属会把分类移动到对应范围。分类按此处的顺序与图标展示在发卡商店的分类页签上；未在此配置的分类若被商品使用，仍会自动出现在商店分类页签（无图标、排在后面）。</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>${checkAllBox()}<th>#</th><th>分类名称</th><th>图标</th><th>归属</th><th>检查</th><th>操作</th></tr></thead>
                <tbody>${rows || `<tr><td colspan="7">${empty('<i class="bi bi-tags"></i>', '当前筛选下暂无分类')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('catFilter').addEventListener('change', e => { curCatFlt = e.target.value; render(); });
    const doCatSearch = () => { curCatKw = document.getElementById('catKw').value; render(); };
    document.getElementById('catSearch').addEventListener('click', doCatSearch);
    document.getElementById('catKw').addEventListener('keydown', e => { if (e.key === 'Enter') doCatSearch(); });
    document.getElementById('catNew').addEventListener('click', () => catEdit(allCats, null));

    c.querySelectorAll('button[data-edit]').forEach(b => {
        b.addEventListener('click', () => catEdit(allCats, shown[parseInt(b.dataset.edit, 10)]));
    });
    c.querySelectorAll('button[data-del]').forEach(b => {
        b.addEventListener('click', async () => {
            const cat = shown[parseInt(b.dataset.del, 10)];
            const res = await api('shop_goods_list');
            const used = res.code === 0 && (res.data.list || []).some(p => p.shop_category === cat.name);
            if (used) {
                toast('该分类下仍有发卡商品，请先把相关商品的分类改掉或清空后再删除', 'warn');
                return;
            }
            confirmBox('删除分类', `确定删除${cat.swId === '' ? '通用分类' : '「' + esc(swName(cat.swId)) + '」专属分类'}「${esc(cat.name)}」？删除后前台分类页签将不再显示该分类。`, async () => {
                const next = allCats.filter(o => o !== cat);
                await catsSave(next, cat.swId);
            }, true);
        });
    });

    // 批量选择
    const catSel = createSelection({ root: c, allIds: shown.map((_, i) => 'cat_' + i), onChange: ids => {
        const box = document.getElementById('catBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    // 批量操作：下拉选择 + 执行
    const catBulkRun = document.getElementById('catBulkRun');
    if (catBulkRun) catBulkRun.addEventListener('click', async () => {
        const op = document.getElementById('catBulkOp').value;
        const ids = catSel ? catSel.ids() : [];
        if (!ids.length) return toast('请先选择分类', 'warn');

        if (op === 'delete') {
            // 取出选中的分类对象
            const toDelete = ids.map(id => shown[parseInt(id.replace('cat_', ''), 10)]).filter(Boolean);
            // 检查是否有分类正在被商品使用
            const res = await api('shop_goods_list');
            const usedNames = (res.code === 0 ? (res.data.list || []) : [])
                .map(p => p.shop_category).filter(Boolean);
            const blocked = toDelete.filter(cat => usedNames.includes(cat.name));
            if (blocked.length) {
                toast(`分类「${blocked.map(c => c.name).join('、')}」下仍有发卡商品，请先清理后再删除`, 'warn');
                return;
            }
            confirmBox('批量删除分类', `确定删除选中的 ${toDelete.length} 个分类？删除后前台分类页签将不再显示这些分类。`, async () => {
                const next = allCats.filter(o => !toDelete.includes(o));
                // 需要落库所有涉及的归属范围
                const swIds = [...new Set(toDelete.map(c => c.swId))];
                await catsSave(next, swIds);
                if (catSel) catSel.clear();
            }, true);
        }
    });
}

function catEdit(cats, cat) {
    const isEdit = !!cat;
    cat = cat || { name: '', icon: '', swId: '' };
    const body = `
    <div class="field"><label>分类名称 *</label>
        <input id="cName" maxlength="30" value="${esc(cat.name)}" placeholder="如：GTA5 / Steam 账号">
        <div class="hint">与发卡商品上选择的分类对应</div>
    </div>
    <div class="field"><label>归属范围</label>
        <select id="cSw">
            <option value="" ${String(cat.swId) === '' ? 'selected' : ''}>通用分类（所有软件可见）</option>
            ${swList.map(s => `<option value="${s.id}" ${String(cat.swId) === String(s.id) ? 'selected' : ''}>「${esc(s.name)}」专属</option>`).join('')}
        </select>
        <div class="hint">${isEdit ? '更换归属会把分类移动到对应范围（原范围内移除，新范围内加入）' : '通用分类所有软件可见；软件专属分类仅当商品「显示归属软件」命中时进入候选'}</div>
    </div>
    <div class="field"><label>分类图标（可选，支持上传 jpg / png / gif / webp）</label>
        <input id="cIcon" value="${esc(cat.icon)}" placeholder="上传图片或填入图片链接（https://… 或 /uploads/…）">
        <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
            <button class="btn ghost sm" type="button" id="cIconUp">上传图片</button>
            <input type="file" id="cIconFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
            <img id="cIconPrev" alt="" style="max-height:52px;max-width:140px;border-radius:8px;display:${cat.icon ? 'block' : 'none'}" src="${esc(cat.icon)}">
        </div>
        <div class="hint">jpg / png / gif / webp，5MB 以内；图标显示在商店分类页签按钮上</div>
    </div>`;

    openModal(isEdit ? `编辑分类 · ${cat.name}` : '添加分类', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const name = document.getElementById('cName').value.trim();
            if (!name) return toast('请填写分类名称', 'warn');
            const icon = document.getElementById('cIcon').value.trim();
            const newSwId = document.getElementById('cSw').value;
            const dup = cats.some(o => String(o.swId) === String(newSwId) && o.name === name && (!isEdit || o !== cat));
            if (dup) return toast('该范围内已存在同名分类', 'warn');
            const next = cats.map(o => (isEdit && o === cat) ? { name, icon, swId: newSwId } : o);
            if (!isEdit) next.push({ name, icon, swId: newSwId });
            // 编辑时更换归属 → 新旧两个范围键都要落库；其余只写目标键
            const keys = isEdit && String(cat.swId) !== String(newSwId) ? [cat.swId, newSwId] : [newSwId];
            await catsSave(next, keys);
        }},
    ]);

    // 分类图标上传（与商品图片同一接口，内容级校验）
    const upBtn = document.getElementById('cIconUp');
    const fileInput = document.getElementById('cIconFile');
    upBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) return toast('图片需在 5MB 以内', 'warn');
        const fd = new FormData();
        fd.append('file', file);
        const headers = uploadHeaders();
        upBtn.disabled = true;
        try {
            const r = await fetch(`${API_ENTRY}?action=shop_goods_upload`, {
                method: 'POST', headers, credentials: 'same-origin', body: fd,
            });
            const j = await r.json();
            if (j.code !== 0) { toast(j.msg || '上传失败', 'err'); return; }
            const input = document.getElementById('cIcon');
            const prev = document.getElementById('cIconPrev');
            input.value = j.data.url;
            if (prev) { prev.src = j.data.url; prev.style.display = 'block'; }
            toast('图片已上传', 'ok');
        } catch (e) {
            toast('上传失败，请稍后重试', 'err');
        } finally {
            upBtn.disabled = false;
            fileInput.value = '';
        }
    });
}

/* 保存分类：按范围分组序列化「名称|图标」行；swIds 为需落库的键（''/空=通用 shop_cats，'N'=shop_cats_sw_N） */
async function catsSave(cats, swIds) {
    const settings = {};
    (Array.isArray(swIds) ? swIds : [swIds]).forEach(swId => {
        const key = String(swId) === '' ? 'shop_cats' : 'shop_cats_sw_' + swId;
        settings[key] = cats.filter(cc => String(cc.swId) === String(swId))
            .map(cc => cc.icon ? `${cc.name}|${cc.icon}` : cc.name).join('\n');
    });
    const res = await api('shop_setting_save', { settings });
    if (res.code === 0) { toast('分类已保存'); closeModal(); render(); }
}

async function renderGoods() {
    const c = document.getElementById('gWrap');
    c.innerHTML = loading();

    const res = await api('shop_goods_list');
    if (res.code !== 0) return;
    const list = res.data.list || [];

    // 拉商店外观的分类配置 + 卡类型配置，作为下拉候选
    const cfg = await api('setting_get');
    if (cfg.code === 0) {
        const s = cfg.data.settings || cfg.data || {};
        catCfg = String(s.shop_cats || '')
            .split('\n').map(l => l.trim()).filter(Boolean);
        // 各软件专属分类完整行（商品弹窗按「显示归属软件」联动分类候选；就地新建时保留其他分类的图标）
        swCatsMap = {};
        Object.keys(s).forEach(k => {
            const m = k.match(/^shop_cats_sw_(\d+)$/);
            if (m) swCatsMap[m[1]] = String(s[k] || '').split('\n').map(l => l.trim()).filter(Boolean);
        });
        // 卡类型：解析「ID|显示名|启用」，未配置的类型默认启用 + 默认名
        const saved = {};
        String(s.shop_card_types || '').split('\n').forEach(l => {
            const p = l.split('|').map(x => x.trim());
            if (p.length >= 3 && TYPE_TEXT[p[0]]) saved[p[0]] = { name: p[1] || TYPE_TEXT[p[0]], on: p[2] === '1', hint: p[3] || '' };
        });
        typeCfg = Object.keys(TYPE_TEXT).map(id => ({
            id: Number(id),
            name: saved[id]?.name || TYPE_TEXT[id],
            on: saved[id] ? saved[id].on : true,
            hint: (saved[id] && saved[id].hint) || { 1: '卡面 = 秒数', 2: '卡面 = 点数', 3: '卡面 = 次数', 4: '激活后永久有效' }[id],
        }));
    }

    // 软件列表：商品弹窗「显示归属软件」下拉候选（拉不到不阻塞页面）
    const swRes = await api('software_list');
    if (swRes.code === 0) {
        swList = swRes.data.options || [];
    }

    // 分类筛选 + 关键字搜索（''=全部，'__none__'=未分类；关键字命中套餐名/发卡商品名/分类）
    const kw = curGKw.trim().toLowerCase();
    const hitKw = p => !kw || [p.name, p.shop_name, p.shop_category]
        .some(v => String(v || '').toLowerCase().includes(kw));
    const shown = (curCat === '' ? list
        : curCat === '__none__' ? list.filter(p => !p.shop_category)
        : list.filter(p => p.shop_category === curCat)).filter(hitKw);
    const cats = [...new Set([
        ...catCfg, ...Object.values(swCatsMap).flat(), ...list.map(p => p.shop_category).filter(Boolean),
    ].map(l => String(l).split('|')[0].trim()).filter(Boolean))];

    const rows = shown.map(p => `
        <tr>
            ${rowCheckBox(p.id)}
            <td>${p.id}</td>
            <td><b>${esc(p.shop_name || p.name)}</b>${p.badge ? ' ' + tag(p.badge, 'yellow') : ''}${p.shop_category ? ` <span class="sub">（${esc(p.shop_category)}）</span>` : ''}${p.shop_software_id ? ` <span class="sub">· ${esc(swName(p.shop_software_id))}专属</span>` : ''}${p.deliver_software_id > 1 ? ` <span class="sub">· 卡密归「${esc(swName(p.deliver_software_id))}」</span>` : ''}</td>
            <td>${p.shop_status === 1 ? tag('在售', 'green') : tag('未上架', 'gray')}</td>
            <td>${p.highlight === 1 ? tag('推荐', 'yellow') : '<span class="sub">-</span>'}</td>
            <td><b class="price-text">${priceCell(p)}</b></td>
            <td class="sub">${p.card_source === 1 ? '外部卡密（导入发货）' : specText(p)}</td>
            <td>${p.shop_status === 1
                ? (p.stock > 0 ? tag('库存 ' + p.stock, 'green') : tag('缺货', 'yellow'))
                : tag('-', 'gray')}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-id="${p.id}">配置发卡</button>${p.card_source === 1 ? ` <button class="btn sm" data-imp="${p.id}">导入卡密</button>` : ''}
                <button class="btn ${p.shop_status === 1 ? '' : 'success'} sm" data-tg="${p.id}">${p.shop_status === 1 ? '下架' : '上架'}</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>商品管理</h3>
            <div class="acts">
                <span id="gBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="gBulkOp">
                        <option value="on">批量上架</option>
                        <option value="off">批量下架</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="gBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <select id="gCat">
                        <option value="">全部分类</option>
                        ${cats.map(ct => `<option value="${esc(ct)}" ${curCat === ct ? 'selected' : ''}>${esc(ct)}</option>`).join('')}
                        <option value="__none__" ${curCat === '__none__' ? 'selected' : ''}>未分类</option>
                    </select>
                    <input id="gKw" placeholder="搜索商品名 / 分类" value="${esc(curGKw)}">
                    <button class="btn" id="gSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="gNew">+ 新增发卡商品</button>
            </div>
        </div>
        <div class="card-body"><div class="hint">这里只管发卡侧配置（上架 / 售价 / 挂卡规格 / 分类陈列），与「商品与交易 → 价格套餐」的启停互相独立——官网首页只看价格套餐的启用状态，发卡商店只看这里的上架状态。</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>商品名</th><th>状态</th><th>推荐</th><th>发卡售价</th><th>挂卡规格</th><th>库存</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-box-seam"></i>', '暂无商品，点击「+ 新增发卡商品」创建')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('gNew').addEventListener('click', () => goodsEdit(null));
    document.getElementById('gCat').addEventListener('change', e => { curCat = e.target.value; render(); });
    const doGSearch = () => { curGKw = document.getElementById('gKw').value; render(); };
    document.getElementById('gSearch').addEventListener('click', doGSearch);
    document.getElementById('gKw').addEventListener('keydown', e => { if (e.key === 'Enter') doGSearch(); });

    sel = createSelection({ root: c, allIds: shown.map(x => x.id), onChange: ids => {
        const box = document.getElementById('gBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    // 批量操作：下拉选择 + 执行
    const bulkRun = document.getElementById('gBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('gBulkOp').value;
        if (op === 'on') goodsBulkToggle(1);
        else if (op === 'off') goodsBulkToggle(0);
        else if (op === 'delete') goodsBulkDelete();
    });

    c.querySelectorAll('button[data-imp]').forEach(b => {
        const item = list.find(x => x.id === parseInt(b.dataset.imp, 10));
        if (item) b.addEventListener('click', () => goodsImport(item));
    });

    c.querySelectorAll('button[data-tg]').forEach(b => {
        const item = list.find(x => x.id === parseInt(b.dataset.tg, 10));
        if (item) b.addEventListener('click', async () => {
            const res = await api('shop_goods_toggle', { id: item.id });
            if (res.code === 0) { toast(res.msg); render(); }
        });
    });

    c.querySelectorAll('button[data-id]').forEach(b => {
        const item = list.find(x => x.id === parseInt(b.dataset.id, 10));
        if (item) b.addEventListener('click', () => goodsEdit(item, list));
    });
}

function goodsEdit(p, allList) {
    const isNew = !p;
    if (isNew) {
        p = { name: '', shop_status: 1, shop_price: '0.00', card_source: 0, card_type: 1, card_duration: 0,
              card_max_devices: 1, card_group_id: 0, shop_category: '', shop_icon: '', shop_intro: '', shop_detail: '', shop_name: '',
              highlight: 0, badge: '' };
    }
    // 新增时才显示商品名（编辑改名在「价格套餐」页）
    const nameField = isNew
        ? '<div class="field"><label>商品名 *</label><input id="gName" maxlength="60" placeholder="例如：GTA5 增强版月卡"></div>'
        : `<div class="field"><label>发卡商品名</label><input id="gShopName" maxlength="120" value="${esc(p.shop_name || '')}" placeholder="发卡商店展示的商品名，留空则显示官网套餐名「${esc(p.name)}」；与价格套餐完全独立"></div>`;
    // 分类下拉候选：通用分类 + 当前归属软件的专属分类 + 已用分类（保证当前值在列）。
    // 「显示归属软件」切换时联动重建（bindCategoryOptions）
    const catCands = swId => [...new Set([
        ...catCfg.map(l => l.split('|')[0].trim()),
        ...((swId && swCatsMap[swId]) || []).map(l => l.split('|')[0].trim()),
        ...((allList || []).map(x => x.shop_category).filter(Boolean)),
        p.shop_category || '',
    ].filter(Boolean))];
    const catField = `
        <div class="field"><label>商品分类 *</label>
            <div style="display:flex;gap:8px;align-items:center">
                <select id="gShopCategory" style="flex:1">
                    <option value="" ${!p.shop_category ? 'selected' : ''}>请选择分类</option>
                    ${catCands(Number(p.shop_software_id) || 0).map(ct => `<option value="${esc(ct)}" ${p.shop_category === ct ? 'selected' : ''}>${esc(ct)}</option>`).join('')}
                </select>
                <button class="btn ghost sm" id="gCatNew" type="button">＋ 新建分类</button>
            </div>
            <div id="gCatNewRow" style="display:none;margin-top:8px;gap:8px;align-items:center">
                <input id="gCatNewName" maxlength="30" placeholder="输入新分类名称（如需配图标稍后到「分类」子页编辑）" style="flex:1">
                <button class="btn success sm" id="gCatNewOk" type="button">创建</button>
                <button class="btn ghost sm" id="gCatNewCancel" type="button">取消</button>
            </div>
            <div class="hint">候选 = 通用分类 + 归属软件的专属分类；切换「显示归属软件」会自动刷新分类候选</div>
        </div>`;
    // 显示归属软件下拉（分软件显示商品的归属设置，需后台开启「按软件过滤商品」）
    const swField = `
        <div class="field"><label>显示归属软件</label>
            <select id="gShopSw">
                <option value="0" ${!p.shop_software_id ? 'selected' : ''}>全部软件通用（默认）</option>
                ${swList.map(s => `<option value="${s.id}" ${Number(p.shop_software_id) === s.id ? 'selected' : ''}>仅「${esc(s.name)}」${s.status ? '' : '（已停用）'}</option>`).join('')}
            </select>
            <div class="hint">配合「发卡网配置 → 按软件过滤商品」使用：识别号命中的软件只能看到「通用」+「归属该软件」的商品，未识别的访客只见通用商品；开关关闭时所有商品照常显示。<br>该归属同时决定卡密的发货归属：下单时按它快照进订单，取库存卡与「缺货自动生成 / 每单自动生成」的新卡都归该软件（买家在对应软件里激活）。选「全部软件通用」时按访客识别号归属，识别不到则归默认软件</div>
        </div>`;
    const body = `
    <div class="row2">
        <div class="field"><label>是否上架发卡</label>
            <select id="gShopStatus">
                <option value="0" ${p.shop_status != 1 ? 'selected' : ''}>仅展示（不参与自动发卡）</option>
                <option value="1" ${p.shop_status == 1 ? 'selected' : ''}>上架发卡商店</option>
            </select>
        </div>
        <div class="field"><label>卡密来源</label>
            <select id="gSource">
                <option value="0" ${p.card_source != 1 ? 'selected' : ''}>本系统卡密（按挂卡规格自动取卡）</option>
                <option value="1" ${p.card_source == 1 ? 'selected' : ''}>外部卡密（导入卡密发货，非本验证系统商品）</option>
            </select>
            <div class="hint">外部卡密商品不占用本系统卡库，保存后需在列表页点「导入卡密」补充库存</div>
        </div>
    </div>
    <div id="sysSpecBox">
        <div class="modal-divider" style="margin-top:0">挂卡类型与售价（可添加多个，买家在详情页分别选择购买）</div>
        <div class="hint">每个挂卡类型是一项独立规格，<b>售价直接填在规格上</b>（类型 / 时长 / 售价 / 设备 / 用户组）。至少保留一个；系统卡请确保卡密中心有对应规格库存。时长卡直接选「数值 + 单位」（分钟 / 小时 / 天 / 周 / 月 / 年，月按 30 天、年按 365 天折算）。</div>
        <div id="gCardsBox"></div>
        <button class="btn ghost sm" type="button" id="gAddCard" style="margin-top:8px">+ 添加挂卡类型</button>
        <div class="field" id="gPriceField" style="margin-top:14px;max-width:320px">
            <label id="gPriceLabel">默认售价（元）</label>
            <input id="gShopPrice" value="${esc(p.shop_price != null ? p.shop_price : '0.00')}" placeholder="例如：30.00">
            <div class="hint" id="gPriceHint"></div>
        </div>
        <div class="hint" id="gSpecHint">发货时按「类型 + 卡面 + 设备上限 + 用户组」从官方直发卡库自动取卡；请确保卡密中心的库存规格与这里完全一致，否则商品会显示缺货。</div>
    </div>
    <div class="modal-divider">商品陈列（发卡商店展示）</div>
    <div class="row2">
        ${catField}
        <div class="field"><label>商品图片</label><input id="gShopIcon" value="${esc(p.shop_icon || '')}" placeholder="上传图片或填入图片链接（https://… 或 /uploads/…），商品详情页左侧大图展示">
            <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
                <button class="btn ghost sm" type="button" id="gIconUp">上传图片</button>
                <input type="file" id="gIconFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                <img id="gIconPrev" alt="" style="max-height:52px;max-width:140px;border-radius:8px;display:${p.shop_icon ? 'block' : 'none'}" src="${esc(p.shop_icon || '')}">
            </div>
            <div class="hint">jpg / png / gif / webp，5MB 以内</div>
        </div>
    </div>
    ${swField}
    <div class="field"><label>商品简介</label><input id="gShopIntro" value="${esc(p.shop_intro || '')}" placeholder="列表样式第二行展示的简短说明，留空自动显示规格"></div>
    <div class="field"><label>商品详情（详情页展示，支持 HTML 代码）</label><textarea id="gShopDetail" rows="6" placeholder="商品详情页展示的详细内容；支持 HTML 代码（图片/表格/排版等，图片地址建议 https 直链），纯文本则按换行展示；留空则显示简介与卖点">${esc(p.shop_detail || '')}</textarea></div>
    <div class="row2">
        <div class="field"><label>推荐高亮</label>
            <select id="gHighlight">
                <option value="0" ${p.highlight != 1 ? 'selected' : ''}>普通展示</option>
                <option value="1" ${p.highlight == 1 ? 'selected' : ''}>推荐（商品卡高亮描边，导购更醒目）</option>
            </select>
        </div>
        <div class="field"><label>角标文案</label><input id="gBadge" maxlength="30" value="${esc(p.badge || '')}" placeholder="如：热销 / 新品，留空不显示"></div>
    </div>
    <div class="modal-divider">查单自定义提示</div>
    <div class="hint">买家在发卡网查到「该商品」的「已支付 / 已发卡」订单时，订单页顶部展示此提示。留空则不显示。</div>
    <div class="field"><label>查单自定义提示（该商品的订单）</label><textarea id="gNotice" rows="3" placeholder="如：激活码请尽快使用，过期不补发。留空则不显示">${esc(p.shop_notice || '')}</textarea></div>
`;

    openModal(isNew ? '新增发卡商品' : `配置发卡 · ${p.name}`, nameField + body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const cardSource = parseInt(document.getElementById('gSource').value, 10);
            const cards = collectCards();
            if (!cards.length) return toast('请至少配置一个挂卡类型', 'warn');
            // 保存前逐条自检，明确指出第几条不合法（避免静默丢规格）
            for (let i = 0; i < cards.length; i++) {
                const c = cards[i];
                const no = i + 1;
                const tname = typeName(c.card_type);
                if (c.price !== '' && (isNaN(parseFloat(c.price)) || parseFloat(c.price) < 0)) {
                    return toast(`第 ${no} 个挂卡类型（${tname}）的售价需为不小于 0 的数字（留空或填 0 = 用商品默认售价）`, 'warn');
                }
                if (Number(c.card_type) !== 4 && (!c.card_duration || c.card_duration <= 0)) {
                    return toast(`第 ${no} 个挂卡类型（${tname}）请填写卡面数值`, 'warn');
                }
            }
            const payload = {
                id: p.id || 0,
                shop_status: parseInt(document.getElementById('gShopStatus').value, 10),
                shop_price: document.getElementById('gShopPrice').value.trim(),
                cards: cards,
                card_source: cardSource,
                shop_category: document.getElementById('gShopCategory').value.trim(),
                shop_icon: document.getElementById('gShopIcon').value.trim(),
                shop_intro: document.getElementById('gShopIntro').value.trim(),
                shop_name: document.getElementById('gShopName') ? document.getElementById('gShopName').value.trim() : '',
                shop_detail: document.getElementById('gShopDetail').value.trim(),
                highlight: parseInt(document.getElementById('gHighlight').value, 10),
                badge: document.getElementById('gBadge').value.trim(),
                shop_software_id: parseInt(document.getElementById('gShopSw').value, 10) || 0,
            };
            payload.shop_notice = document.getElementById('gNotice').value.trim();
            if (isNew) {
                payload.name = document.getElementById('gName').value.trim();
                if (!payload.name) return toast('请填写商品名', 'warn');
                if (!payload.shop_category) return toast('请选择商品分类（可点「＋ 新建分类」就地创建）', 'warn');
            }
            const res = await api('shop_goods_save', payload);
            if (res.code === 0) { toast(isNew ? '发卡商品已添加' : '发卡商品配置已保存'); closeModal(); render(); }
            else { toast(res.msg || '保存失败', 'err'); }
        }},
    ], 'wide');

    /* ---------- 多规格挂卡类型 ---------- */
    const gCardsBox = document.getElementById('gCardsBox');
    const typeOptionsHTML = (sel) => typeCfg.filter(t => t.on || t.id === sel)
        .map(t => `<option value="${t.id}" ${t.id === sel ? 'selected' : ''}>${esc(t.name)}（${esc(TYPE_TEXT[t.id])}）</option>`).join('');
    /* 时长单位：秒值 → [单位代号, 显示名]；换算统一按秒入库（与卡密中心规格一致）。
       月/年按 30 天 / 365 天折算，避免月份长度不一导致歧义 */
    const DUR_UNITS = [
        ['60', '分钟'], ['3600', '小时'], ['86400', '天'],
        ['604800', '星期'], ['2592000', '月'], ['31536000', '年'],
    ];
    /* 秒值 → 最合适的「数值 + 单位」（优先大单位且能整除） */
    const secToQtyUnit = (sec) => {
        sec = parseInt(sec, 10) || 0;
        for (let i = DUR_UNITS.length - 1; i >= 0; i--) {
            const f = parseInt(DUR_UNITS[i][0], 10);
            if (sec > 0 && sec % f === 0) return { qty: sec / f, unit: DUR_UNITS[i][0] };
        }
        return { qty: sec, unit: '60' }; // 兜底：非整分钟按分钟（向下不整除也按分钟展示）
    };
    const durUnitOptions = (sel) => DUR_UNITS
        .map(u => `<option value="${u[0]}" ${u[0] === String(sel) ? 'selected' : ''}>${u[1]}</option>`).join('');
    /* 非时长卡的“单位”（固定，不可选，仅作后缀提示）：点数卡=点数，次数卡=次 */
    const FIX_UNIT = { 2: '点数', 3: '次' };
    const cardRowHTML = (c) => {
        const ct = c.card_type || 1;
        const qu = secToQtyUnit(c.card_duration);
        // 时长卡：数值+可选单位；点数/次数卡：数值+固定后缀；永久卡：都不显示
        const unitCell = ct === 1
            ? `<select class="gc-dur-unit">${durUnitOptions(qu.unit)}</select>`
            : `<span class="gc-dur-fix">${FIX_UNIT[ct] || ''}</span>`;
        return `
        <div class="card-spec-row" data-ct="${ct}">
            <div class="csr-main">
                <select class="gc-type">${typeOptionsHTML(ct)}</select>
                <div class="gc-dur-wrap">
                    <input class="gc-dur" type="number" min="0" value="${qu.qty > 0 ? qu.qty : ''}" placeholder="数值">
                    ${unitCell}
                </div>
                <input class="gc-price" type="text" value="${parseFloat(c.price) > 0 ? esc(c.price) : ''}" placeholder="售价(0=默认价)">
                <input class="gc-dev" type="number" min="1" max="255" value="${parseInt(c.card_max_devices, 10) > 1 ? c.card_max_devices : ''}" placeholder="设备">
                <input class="gc-gid" type="number" min="0" value="${parseInt(c.card_group_id, 10) > 0 ? c.card_group_id : ''}" placeholder="组ID">
                <button class="gc-del btn ghost sm" type="button" title="删除">×</button>
            </div>
            <div class="hint gc-hint"></div>
        </div>`;
    };
    /* 售价字段随规格数联动：规格是售价主入口，商品级售价只在规格填 0 时兜底。
       单规格时两者等价（取规格价优先），多规格时商品级退化为兜底价。 */
    const syncPriceField = () => {
        const lab = document.getElementById('gPriceLabel');
        const hint = document.getElementById('gPriceHint');
        if (!lab || !hint) return;
        const n = gCardsBox.querySelectorAll('.card-spec-row').length;
        if (n <= 1) {
            lab.textContent = '商品售价（元）';
            hint.innerHTML = '本商品只有一个规格，此处即买家看到的价格（上方规格售价留 0 时用它）。'
                + '填 0 = 免费商品：0 元订单同样走易支付流程，支付完成后自动发卡。';
        } else {
            lab.textContent = '默认售价（元）· 仅作兜底';
            hint.innerHTML = `本商品有 <b>${n}</b> 个规格，<b>售价以各规格为准</b>（列表页显示价格区间）；`
                + '此价仅在某个规格售价填 0 时作为它的兜底价，填 0 则该规格视为免费。';
        }
    };
    const addCardRow = (c) => {
        const wrap = document.createElement('div');
        wrap.innerHTML = cardRowHTML(c);
        const row = wrap.firstElementChild;
        const ty = row.querySelector('.gc-type');
        const hint = row.querySelector('.gc-hint');
        const durWrap = row.querySelector('.gc-dur-wrap');
        const upd = () => {
            const t = parseInt(ty.value, 10);
            const cfg = typeCfg.find(x => x.id === t);
            hint.textContent = cfg && cfg.hint ? cfg.hint : '';
            row.dataset.ct = t;
            // 永久卡无时长，隐藏数值+单位
            durWrap.style.display = t === 4 ? 'none' : '';
            // 单位控件随类型切换：时长卡=下拉，点数/次数=固定后缀
            const oldInp = row.querySelector('.gc-dur');
            const curVal = oldInp ? oldInp.value : 0;
            const oldUnit = row.querySelector('.gc-dur-unit');
            const curUnit = oldUnit ? oldUnit.value : '60';
            const cell = t === 1
                ? `<select class="gc-dur-unit">${durUnitOptions(curUnit)}</select>`
                : `<span class="gc-dur-fix">${FIX_UNIT[t] || ''}</span>`;
            const holder = row.querySelector('.gc-dur-unit, .gc-dur-fix');
            if (holder) holder.outerHTML = cell;
        };
        ty.addEventListener('change', upd);
        row.querySelector('.gc-del').addEventListener('click', () => {
            if (gCardsBox.querySelectorAll('.card-spec-row').length <= 1) return toast('至少保留一个挂卡类型', 'warn');
            row.remove();
            syncPriceField();
        });
        upd();
        gCardsBox.appendChild(row);
        syncPriceField();
    };
    const initCards = (p.cards && p.cards.length) ? p.cards : [{
        card_type: p.card_type || 1, card_duration: p.card_duration || 0,
        price: p.shop_price || '', card_max_devices: p.card_max_devices || 1, card_group_id: p.card_group_id || 0,
    }];
    gCardsBox.innerHTML = '';
    initCards.forEach(addCardRow);
    document.getElementById('gAddCard').addEventListener('click', () =>
        addCardRow({ card_type: 1, card_duration: 0, price: '', card_max_devices: 1, card_group_id: 0 }));

    /* 从多行规格收集 cards：时长卡「数值 × 单位 → 秒」；点数/次数卡取数值；永久卡固定 0 */
    const collectCards = () => {
        const arr = [];
        gCardsBox.querySelectorAll('.card-spec-row').forEach(row => {
            const ct = parseInt(row.querySelector('.gc-type').value, 10);
            if (!TYPE_TEXT[ct]) return;
            const v = parseInt(row.querySelector('.gc-dur').value, 10) || 0;
            const unitEl = row.querySelector('.gc-dur-unit');
            const f = unitEl ? (parseInt(unitEl.value, 10) || 1) : 1;
            arr.push({
                card_type: ct,
                card_duration: ct === 4 ? 0 : (ct === 1 ? v * f : v),
                price: row.querySelector('.gc-price').value.trim() || '0',
                card_max_devices: parseInt(row.querySelector('.gc-dev').value, 10) || 1,
                card_group_id: parseInt(row.querySelector('.gc-gid').value, 10) || 0,
            });
        });
        return arr;
    };

    const syncSource = () => {
        const ext = document.getElementById('gSource').value === '1';
        document.getElementById('sysSpecBox').style.display = '';
        document.getElementById('gSpecHint').textContent = ext
            ? '外部卡密商品同样需要配置挂卡规格：买家在详情页选择规格下单，发货时从对应规格的导入卡密池取卡。保存后需在列表页点「导入卡密」按规格分别补充库存。'
            : '发货时按「类型 + 卡面 + 设备上限 + 用户组」从官方直发卡库自动取卡；请确保卡密中心的库存规格与这里完全一致，否则商品会显示缺货。';
    };
    document.getElementById('gSource').addEventListener('change', syncSource);
    syncSource();

    /* 显示归属软件 → 分类候选联动：切换归属后重建分类下拉（保留当前值，失效则清空） */
    const bindCategoryOptions = () => {
        const swSel = document.getElementById('gShopSw');
        const catSel = document.getElementById('gShopCategory');
        if (!swSel || !catSel) return;
        const cur = catSel.value;
        const cands = catCands(parseInt(swSel.value, 10) || 0);
        catSel.innerHTML = `<option value="" ${!cands.includes(cur) ? 'selected' : ''}>请选择分类</option>` +
            cands.map(ct => `<option value="${esc(ct)}" ${ct === cur ? 'selected' : ''}>${esc(ct)}</option>`).join('');
    };
    document.getElementById('gShopSw').addEventListener('change', bindCategoryOptions);

    // 分类下拉旁「＋ 新建分类」：在当前弹窗内展开输入行（不另开弹窗，避免替换掉商品弹窗）
    const catNewBtn = document.getElementById('gCatNew');
    if (catNewBtn) {
        const row = document.getElementById('gCatNewRow');
        const nameInp = document.getElementById('gCatNewName');
        catNewBtn.addEventListener('click', () => {
            row.style.display = 'flex';
            nameInp.focus();
        });
        document.getElementById('gCatNewCancel').addEventListener('click', () => {
            row.style.display = 'none';
            nameInp.value = '';
        });
        const doCreate = async () => {
            const name = nameInp.value.trim();
            if (!name) return toast('请填写分类名称', 'warn');
            if (name.includes('|')) return toast('分类名称不能包含 |', 'warn');
            // 就地新建按商品当前归属软件落键：通用商品存 shop_cats，归属软件存 shop_cats_sw_<id>
            const curSwId = parseInt(document.getElementById('gShopSw').value, 10) || 0;
            const baseList = curSwId ? (swCatsMap[curSwId] || (swCatsMap[curSwId] = [])) : catCfg;
            const existAll = curSwId ? [...catCfg, ...baseList] : catCfg;
            if (existAll.some(line => line.split('|')[0].trim() === name)) {
                return toast('该分类已存在', 'warn');
            }
            document.getElementById('gCatNewOk').disabled = true;
            const key = curSwId ? 'shop_cats_sw_' + curSwId : 'shop_cats';
            const res = await api('shop_setting_save', { settings: { [key]: baseList.concat(name).join('\n') } });
            document.getElementById('gCatNewOk').disabled = false;
            if (res.code !== 0) { toast(res.msg || '保存失败', 'err'); return; }
            baseList.push(name);
            const selC = document.getElementById('gShopCategory');
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            selC.appendChild(opt);
            selC.value = name;
            row.style.display = 'none';
            nameInp.value = '';
            toast('分类已创建并选中', 'ok');
        };
        document.getElementById('gCatNewOk').addEventListener('click', doCreate);
        nameInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doCreate(); } });
    }

    // 商品图片上传（multipart，走 X-CSRF 头与 api() 同源凭据）
    const upBtn = document.getElementById('gIconUp');
    const fileInput = document.getElementById('gIconFile');
    if (upBtn && fileInput) {
        upBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', async () => {
            const file = fileInput.files && fileInput.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) return toast('图片需在 5MB 以内', 'warn');
            const fd = new FormData();
            fd.append('file', file);
            const headers = uploadHeaders();
            upBtn.disabled = true;
            try {
                const r = await fetch(`${API_ENTRY}?action=shop_goods_upload`, {
                    method: 'POST', headers, credentials: 'same-origin', body: fd,
                });
                const j = await r.json();
                if (j.code !== 0) { toast(j.msg || '上传失败', 'err'); return; }
                const input = document.getElementById('gShopIcon');
                const prev = document.getElementById('gIconPrev');
                input.value = j.data.url;
                if (prev) { prev.src = j.data.url; prev.style.display = 'block'; }
                toast('图片已上传', 'ok');
            } catch (e) {
                toast('上传失败，请稍后重试', 'err');
            } finally {
                upBtn.disabled = false;
                fileInput.value = '';
            }
        });
    }
}

/* 导入外部卡密（card_source=1 的商品补货，一行一条） */
function goodsImport(p) {
    // 多规格：按挂卡类型分别入池，发货时按订单所选类型取卡
    // 下拉选项优先使用商品已配置的多规格（p.cards），展示完整规格信息（类型 + 卡面 + 售价）
    const cards = Array.isArray(p.cards) ? p.cards : [];
    let typeOpts;
    if (cards.length) {
        typeOpts = cards.map(c => {
            const name = typeName(c.card_type);
            const dur = durText(c);
            const price = parseFloat(c.price) || 0;
            const priceStr = price > 0 ? ' ¥' + (Number.isInteger(price) ? price : price.toFixed(2)) : '';
            return `<option value="${c.card_type}">${esc(name)} · ${esc(dur)}${priceStr}</option>`;
        }).join('');
    } else {
        typeOpts = (typeCfg.length ? typeCfg : Object.keys(TYPE_TEXT).map(id => ({ id: Number(id), name: TYPE_TEXT[id], on: true })))
            .filter(t => t.on)
            .map(t => `<option value="${t.id}">${esc(t.name)}（${esc(TYPE_TEXT[t.id])}）</option>`).join('');
    }
    // 各规格当前库存（外部卡密池按 card_type 统计未售条数）
    const stockHint = cards.length
        ? '当前各规格库存：' + cards.map(c => typeName(c.card_type) + '·' + durText(c) + ' = ' + (p.stockByType && p.stockByType[c.card_type] != null ? p.stockByType[c.card_type] : '?') + ' 条').join('，')
        : '';
    const body = `
    <div class="hint">向「${esc(p.name)}」的外部卡密池导入卡密：一行一条，导入后买家购买即自动发出其中一条；与池中已有内容重复的会自动跳过。</div>
    <div class="field"><label>挂卡类型</label>
        <select id="iType">${typeOpts || '<option value="0">未分类</option>'}</select>
        <div class="hint">${cards.length ? '选项展示完整规格（类型 · 卡面 · 售价），按对应规格入池' : '多规格商品按类型分别入池，买家下单所选规格只会从对应类型的卡密池取卡'}</div>
        ${stockHint ? '<div class="hint">' + esc(stockHint) + '</div>' : ''}
    </div>
    <div class="field"><label>从 TXT 文件导入（可选）</label>
        <input id="iFile" type="file" accept=".txt,text/plain">
        <div class="hint">读取文件内容到下方文本框，每行算一个卡密</div>
    </div>
    <div class="field"><label>卡密内容（单次最多 1000 条）</label>
        <textarea id="iText" rows="10" placeholder="一行一条，例如：&#10;ABCD-EFGH-JKLM&#10;1234-5678-90AB"></textarea>
    </div>`;
    openModal(`导入外部卡密 · ${p.name}`, body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '导入', cls: 'success', act: async () => {
            const res = await api('shop_goods_import', {
                plan_id: p.id,
                contents: document.getElementById('iText').value,
                card_type: parseInt(document.getElementById('iType').value, 10) || 0,
            });
            if (res.code === 0) { toast(res.msg || '导入完成'); closeModal(); render(); }
        }},
    ], 'wide');
    document.getElementById('iFile').addEventListener('change', e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const r = new FileReader();
        r.onload = () => {
            document.getElementById('iText').value = String(r.result || '').replace(/^\uFEFF/, '');
            toast('已读取 ' + f.name + '，每行算一个卡密');
        };
        r.readAsText(f, 'utf-8');
    });
    setTimeout(() => { const t = document.getElementById('iText'); if (t) t.focus(); }, 50);
}

/* 批量上架/下架：status 1=上架 0=下架（未配置售价的商品会被服务端跳过） */
function goodsBulkToggle(status) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择商品', 'warn');
    confirmBox(status === 1 ? '批量上架' : '批量下架',
        `将${status === 1 ? '上架' : '下架'}选中的 ${ids.length} 个发卡商品，确定继续？`,
        async () => {
            const res = await api('shop_goods_toggle', { ids, status });
            if (res.code === 0) { toast(res.msg || '操作完成'); if (sel) sel.clear(); render(); }
        }, status === 0);
}

/* 批量删除发卡商品：有发卡订单记录的会被服务端跳过（对账链路不能断） */
function goodsBulkDelete() {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择商品', 'warn');
    confirmBox('批量删除发卡商品',
        `将删除选中的 ${ids.length} 个发卡商品及其外部卡密池；有发卡订单记录的商品会被跳过。此操作不可恢复，确定继续？`,
        async () => {
            const res = await api('shop_goods_delete', { ids });
            if (res.code === 0) { toast(res.msg || '删除完成'); if (sel) sel.clear(); render(); }
        }, true);
}
