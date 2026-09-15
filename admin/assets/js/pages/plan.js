/* ======================================================================
   pages/plan.js — 官网价格套餐管理
   套餐直接展示在官网首页「价格套餐」区块。
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

register('plan_list', render);

let sel = null;
let lastList = [];   // 最近一次渲染的数据列表（供批量操作取完整对象）

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('plan_list', { page: 1, size: 100 });
    if (res.code !== 0) return;
    const d = res.data;
    const swName = {};
    (d.softwares || []).forEach(s => { swName[s.id] = s.name; });
    const swText = id => id > 0 ? (swName[id] || `软件#${id}`) : '全部软件';
    lastList = d.list || [];

    const rows = d.list.map(p => `
        <tr>
            ${rowCheckBox(p.id)}
            <td>${p.id}</td>
            <td><b>${esc(p.name)}</b>${p.badge ? ' ' + tag(p.badge, 'red') : ''}${p.shop_category ? `<div class="sub">分类：${esc(p.shop_category)}</div>` : ''}</td>
            <td>${p.web_software_id > 0 ? tag(swText(p.web_software_id), 'blue') : tag('全部软件', 'gray')}</td>
            <td><b class="price-text">${esc(p.price)}</b> <span class="sub">${esc(p.unit)}</span></td>
            <td>${esc(p.duration || '-')}</td>
            <td class="sub">${esc((p.desc || '').split('\n').filter(Boolean).length)} 条要点</td>
            <td>${p.highlight ? tag('高亮', 'yellow') : tag('普通', 'gray')}</td>
            <td>${p.sort}</td>
            <td>${p.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${p.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${p.id}">${p.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${p.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>价格套餐</h3>
            <div class="acts">
                <span id="pBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="pBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="pBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="pNew">+ 新增套餐</button>
            </div>
        </div>
        <div class="card-body"><div class="hint">套餐按「排序」从大到小展示在官网首页；「所属软件」决定该套餐出现在哪个软件的官网（全部软件 = 通用）。勾选「高亮」会以推荐款样式突出显示。这里只管官网展示；发卡上架、售价、挂卡规格与商品陈列请在「商品与交易 → 商品管理」里配置。</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>套餐名</th><th>所属软件</th><th>价格</th><th>时长</th>
                    <th>说明</th><th>样式</th><th>排序</th><th>状态</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="11">${empty('<i class="bi bi-layers"></i>', '暂无套餐')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('pNew').addEventListener('click', () => planEdit(null, d.softwares || []));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'edit') planEdit(item, d.softwares || []);
            else if (act === 'toggle') planToggle(id);
            else if (act === 'del') planDel(id);
        });
    });

    // 批量选择
    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('pBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    // 批量操作：下拉选择 + 执行
    const bulkRun = document.getElementById('pBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('pBulkOp').value;
        doBulk(op);
    });
}

/* ------------------------- 批量操作 ------------------------- */
async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择套餐', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 个套餐？删除后官网首页不再展示。`, async () => {
            let n = 0;
            for (const id of ids) {
                const res = await api('plan_save', { op: 'delete', id });
                if (res.code === 0) n++;
            }
            toast(`已删除 ${n} 个套餐`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = lastList.filter(x => ids.includes(x.id) && x.status !== targetStatus);
    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 个套餐吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('plan_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 个套餐`);
        if (sel) sel.clear();
        render();
    });
}

function planEdit(p, softwares) {
    p = p || {};
    const swOpts = (softwares || []).map(s =>
        `<option value="${s.id}" ${(p.web_software_id || 0) === s.id ? 'selected' : ''}>${esc(s.name)}${s.status === 1 ? '' : '（停用）'}</option>`).join('');
    const body = `
    <div class="row2">
        <div class="field"><label>套餐名 *</label><input id="pName" value="${esc(p.name || '')}" placeholder="例如：月卡"></div>
        <div class="field"><label>角标（选填）</label><input id="pBadge" value="${esc(p.badge || '')}" placeholder="例如：热销 / 推荐"></div>
    </div>
    <div class="field"><label>所属软件（官网展示）</label>
        <select id="pWebSw">
            <option value="0" ${!p.web_software_id ? 'selected' : ''}>全部软件（通用，多软件官网都显示）</option>
            ${swOpts}
        </select>
        <div class="hint">选择某软件后，该套餐仅出现在该软件的官网「价格套餐」区；总站与其它软件官网不显示</div>
    </div>
    <div class="row2">
        <div class="field"><label>价格</label><input id="pPrice" value="${esc(p.price || '')}" placeholder="例如：30（也可填「面议」）"></div>
        <div class="field"><label>价格单位</label><input id="pUnit" value="${esc(p.unit || '元')}" placeholder="元"></div>
    </div>
    <div class="field"><label>时长文案</label><input id="pDuration" value="${esc(p.duration || '')}" placeholder="例如：30 天 / 永久"></div>
    <div class="field">
        <label>套餐说明（一行一条，前端会拆成要点列表）</label>
        <textarea id="pDesc" rows="5" placeholder="全部功能解锁&#10;持续更新维护&#10;专属售后支持">${esc(p.desc || '')}</textarea>
    </div>
    <div class="row2">
        <div class="field"><label>排序（越大越靠前）</label><input id="pSort" type="number" value="${p.sort || 0}"></div>
        <div class="field"><label>高亮展示</label>
            <select id="pHighlight">
                <option value="0" ${!p.highlight ? 'selected' : ''}>普通</option>
                <option value="1" ${p.highlight ? 'selected' : ''}>高亮（推荐款）</option>
            </select>
        </div>
    </div>
    <div class="field"><label>状态</label>
        <select id="pStatus">
            <option value="1" ${p.status != 0 ? 'selected' : ''}>启用</option>
            <option value="0" ${p.status == 0 ? 'selected' : ''}>停用</option>
        </select>
    </div>
    <div class="hint">此处维护官网首页价格套餐的展示信息；发卡上架、售价与挂卡规格请在「商品与交易 → 商品管理」中配置。</div>`;

    openModal(p.id ? '编辑套餐' : '新增套餐', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: p.id || 0,
                name: document.getElementById('pName').value.trim(),
                web_software_id: parseInt(document.getElementById('pWebSw').value, 10) || 0,
                price: document.getElementById('pPrice').value.trim(),
                unit: document.getElementById('pUnit').value.trim(),
                duration: document.getElementById('pDuration').value.trim(),
                desc: document.getElementById('pDesc').value,
                badge: document.getElementById('pBadge').value.trim(),
                highlight: parseInt(document.getElementById('pHighlight').value, 10),
                sort: parseInt(document.getElementById('pSort').value, 10) || 0,
                status: parseInt(document.getElementById('pStatus').value, 10),
            };
            if (!payload.name) return toast('请填写套餐名', 'warn');
            const res = await api('plan_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');
}

async function planToggle(id) {
    const res = await api('plan_save', { op: 'toggle', id });
    if (res.code === 0) { toast(res.msg || '已切换'); render(); }
}

function planDel(id) {
    confirmBox('删除套餐', '确定删除该套餐？删除后官网首页不再展示。', async () => {
        const res = await api('plan_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
