import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';
import { bindImageUpload } from '../core/uploader.js';

register('seller_list', render);

let swSt = '';
let sel = null;
let lastList = [];

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('seller_list', { page: 1, size: 100, sw: swSt });
    if (res.code !== 0) return;
    const d = res.data;
    const softwares = d.softwares || [];
    lastList = d.list || [];

    const swOpts = softwares.map(s =>
        `<option value="${s.id}" ${swSt === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const rows = d.list.map(s => `
        <tr>
            ${rowCheckBox(s.id)}
            <td>${s.id}</td>
            <td style="white-space:nowrap">
                ${s.logo
                    ? `<img src="${esc(s.logo)}" alt="" style="width:34px;height:34px;border-radius:9px;object-fit:cover;vertical-align:middle">`
                    : ''}
                <b style="vertical-align:middle">${esc(s.name)}</b>
            </td>
            <td>${s.software_id === 0
                ? tag('全部软件', 'blue')
                : `<span class="tag gray">${esc(s.software_name)}</span>`}</td>
            <td>${s.badge ? tag(s.badge, 'cyan') : '<span class="sub">-</span>'}</td>
            <td class="sub">${esc((s.desc || '').split('\n').filter(Boolean).length)} 条要点</td>
            <td class="sub">${esc(s.contact || '-')}</td>
            <td>${s.url ? '<a href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer nofollow">链接</a>' : '<span class="sub">-</span>'}</td>
            <td>${s.highlight ? tag('推荐', 'yellow') : tag('普通', 'gray')}</td>
            <td>${s.sort}</td>
            <td>${s.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${s.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${s.id}">${s.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${s.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>购买商家</h3>
            <div class="acts">
                <span id="sBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="sBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="sBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <select id="sSw">
                        <option value="">全部归属</option>
                        ${swOpts}
                    </select>
                    <button class="btn" id="sSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="sNew">+ 新增商家</button>
            </div>
        </div>
        <div class="card-body"><div class="hint">商家按「排序」从大到小展示在官网首页「购买商家」区块；勾选「推荐」会以推荐样式突出显示。Logo 与店铺链接仅支持 http/https 地址。归属选「全部软件」所有官网都显示，选具体软件只在该软件官网显示。</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>商家</th><th>归属软件</th><th>角标</th><th>简介</th>
                    <th>联系方式</th><th>店铺</th><th>样式</th><th>排序</th><th>状态</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="12">${empty('<i class="bi bi-shop"></i>', '暂无商家')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('sSw').addEventListener('change', doSearch);
    document.getElementById('sSearch').addEventListener('click', doSearch);
    document.getElementById('sNew').addEventListener('click', () => sellerEdit(null, softwares));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'edit') sellerEdit(item, softwares);
            else if (act === 'toggle') sellerToggle(id);
            else if (act === 'del') sellerDel(id);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('sBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('sBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('sBulkOp').value;
        doBulk(op);
    });
}

async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择商家', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 个商家？删除后官网首页不再展示。`, async () => {
            let n = 0;
            for (const id of ids) {
                const res = await api('seller_save', { op: 'delete', id });
                if (res.code === 0) n++;
            }
            toast(`已删除 ${n} 个商家`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = lastList.filter(x => ids.includes(x.id) && x.status !== targetStatus);
    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 个商家吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('seller_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 个商家`);
        if (sel) sel.clear();
        render();
    });
}

function doSearch() {
    swSt = document.getElementById('sSw').value;
    render();
}

function sellerEdit(s, softwares) {
    s = s || {};
    const curSw = Number(s.software_id) || 0;
    const swOptions = (softwares || []).map(x =>
        `<option value="${x.id}" ${curSw === Number(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');

    const body = `
    <div class="row2">
        <div class="field"><label>商家名称 *</label><input id="sName" value="${esc(s.name || '')}" placeholder="例如：星辰网络"></div>
        <div class="field"><label>角标（选填）</label><input id="sBadge" value="${esc(s.badge || '')}" placeholder="例如：授权 / 官方合作"></div>
    </div>
    <div class="row2">
        <div class="field"><label>归属软件</label>
            <select id="sSoftware">
                <option value="0" ${curSw === 0 ? 'selected' : ''}>全部软件（通用）</option>
                ${swOptions}
            </select>
            <div class="hint">选「全部软件」所有官网都显示；选具体软件只在该软件官网显示</div>
        </div>
        <div class="field"><label>Logo 图片（选填，链接或上传）</label>
            <div class="logo-row">
                <input id="sLogo" value="${esc(s.logo || '')}" placeholder="https://example.com/logo.png 或上传">
                <button type="button" class="btn ghost sm" id="sLogoUpload">上传图片</button>
            </div>
        </div>
    </div>
    <div class="field">
        <label>商家简介（一行一条，前端会拆成要点列表）</label>
        <textarea id="sDesc" rows="4" placeholder="官方授权经销商&#10;支持担保交易&#10;售后有保障">${esc(s.desc || '')}</textarea>
    </div>
    <div class="row2">
        <div class="field"><label>联系方式（前端弹窗展示）</label><input id="sContact" value="${esc(s.contact || '')}" placeholder="QQ / 微信 / 网址均可"></div>
        <div class="field"><label>店铺链接（选填，http/https）</label><input id="sUrl" value="${esc(s.url || '')}" placeholder="https://example.com/shop"></div>
    </div>
    <div class="row2">
        <div class="field"><label>排序（越大越靠前）</label><input id="sSort" type="number" value="${s.sort || 0}"></div>
        <div class="field"><label>推荐样式</label>
            <select id="sHighlight">
                <option value="0" ${!s.highlight ? 'selected' : ''}>普通</option>
                <option value="1" ${s.highlight ? 'selected' : ''}>推荐</option>
            </select>
        </div>
    </div>
    <div class="field"><label>状态</label>
        <select id="sStatus">
            <option value="1" ${s.status != 0 ? 'selected' : ''}>启用</option>
            <option value="0" ${s.status == 0 ? 'selected' : ''}>停用</option>
        </select>
    </div>`;

    openModal(s.id ? '编辑商家' : '新增商家', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: s.id || 0,
                name: document.getElementById('sName').value.trim(),
                badge: document.getElementById('sBadge').value.trim(),
                logo: document.getElementById('sLogo').value.trim(),
                desc: document.getElementById('sDesc').value,
                contact: document.getElementById('sContact').value.trim(),
                url: document.getElementById('sUrl').value.trim(),
                highlight: parseInt(document.getElementById('sHighlight').value, 10),
                sort: parseInt(document.getElementById('sSort').value, 10) || 0,
                status: parseInt(document.getElementById('sStatus').value, 10),
                software_id: parseInt(document.getElementById('sSoftware').value, 10) || 0,
            };
            if (!payload.name) return toast('请填写商家名称', 'warn');
            const res = await api('seller_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');

    bindImageUpload('sLogoUpload', 'sLogo');
}

async function sellerToggle(id) {
    const res = await api('seller_save', { op: 'toggle', id });
    if (res.code === 0) { toast(res.msg || '已切换'); render(); }
}

function sellerDel(id) {
    confirmBox('删除商家', '确定删除该商家？删除后官网首页不再展示。', async () => {
        const res = await api('seller_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
