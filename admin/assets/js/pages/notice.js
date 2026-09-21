import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

const DEFAULTS = { page: 1, size: 50, sw: '' };
let sel = null;
let lastList = [];

register('notice_list', render);

async function render() {
    const st = pageState('notice_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('notice_list', { page: st.page, size: st.size, sw: st.sw, type: 1 });
    if (res.code !== 0) return;
    const d = res.data;
    const softwares = d.softwares || [];
    lastList = d.list || [];

    const swOpts = softwares.map(s =>
        `<option value="${s.id}" ${String(st.sw) === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const rows = d.list.map(n => `
        <tr>
            ${rowCheckBox(n.id)}
            <td>${n.id}</td>
            <td><b>${esc(n.title)}</b></td>
            <td>${tag(n.type_text, n.type === 4 ? 'blue' : 'gray')}</td>
            <td>${n.software_id === 0
                ? tag('全部软件', 'blue')
                : `<span class="tag gray">${esc(n.software_name)}</span>`}</td>
            <td>${n.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td>${n.sort}</td>
            <td class="mono" style="font-size:12px">${esc(n.start_text)}</td>
            <td class="mono" style="font-size:12px">${esc(n.end_text)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${n.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${n.id}" data-status="${n.status}">${n.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${n.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>官网公告</h3>
            <div class="acts">
                <span id="nBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="nBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="nBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <select id="nSw">
                        <option value="">全部归属</option>
                        ${swOpts}
                    </select>
                    <button class="btn" id="nSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="nNew">+ 发布公告</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>标题</th><th>类型</th><th>所属软件</th><th>状态</th><th>排序</th>
                    <th>生效时间</th><th>结束时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-megaphone"></i>', '暂无公告')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('nSw').addEventListener('change', doSearch);
    document.getElementById('nSearch').addEventListener('click', doSearch);
    document.getElementById('nNew').addEventListener('click', () => noticeEdit(null, softwares));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            if (b.dataset.act === 'edit') noticeEdit(item, softwares);
            else if (b.dataset.act === 'del') noticeDel(id);
            else if (b.dataset.act === 'toggle') noticeToggle(item);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('nBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('nBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('nBulkOp').value;
        doBulk(op);
    });
}

async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择公告', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 条公告？此操作不可恢复。`, async () => {
            let n = 0;
            for (const id of ids) {
                const res = await api('notice_save', { op: 'delete', id });
                if (res.code === 0) n++;
            }
            toast(`已删除 ${n} 条公告`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = lastList.filter(x => ids.includes(x.id) && x.status !== targetStatus);

    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 条公告吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('notice_save', {
                id: item.id, title: item.title, content: item.content,
                type: item.type, sort: item.sort, start_at: item.start_at, end_at: item.end_at,
                software_id: Number(item.software_id) || 0,
                status: targetStatus,
            });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 条公告`);
        if (sel) sel.clear();
        render();
    });
}

function doSearch() {
    const st = pageState('notice_list', DEFAULTS);
    st.sw = document.getElementById('nSw').value;
    st.page = 1;
    render();
}

function noticeEdit(n, softwares) {
    n = n || {};
    const curSw = Number(n.software_id) || 0;
    const swOptions = (softwares || []).map(s =>
        `<option value="${s.id}" ${curSw === Number(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const body = `
    <div class="field"><label>标题 *</label><input id="nTitle" value="${esc(n.title || '')}"></div>
    <div class="row2">
        <div class="field"><label>所属软件</label>
            <select id="nSoftware">
                <option value="0" ${curSw === 0 ? 'selected' : ''}>全部软件（通用）</option>
                ${swOptions}
            </select>
            <div class="hint">选「全部软件」对所有软件下发；选具体软件则只有该软件的客户端可见</div>
        </div>
        <div class="field"><label>显示位置</label>
            <select id="nType">
                <option value="1" ${n.type == 1 ? 'selected' : ''}>官网门户公告</option>
            </select>
            <div class="hint">公告显示在官网首页公告区</div>
        </div>
    </div>
    <div class="field"><label>排序（越大越靠前）</label><input id="nSort" type="number" value="${n.sort || 0}"></div>
    <div class="field"><label>内容</label><textarea id="nContent" placeholder="支持纯文本">${esc(n.content || '')}</textarea></div>
    <div class="row2">
        <div class="field"><label>生效时间</label>
            <div style="display:flex;gap:6px">
                <input id="nStart" type="number" min="0" placeholder="留空立即生效" style="flex:1">
                <select id="nStartUnit" style="width:110px">
                    <option value="60">分钟后</option>
                    <option value="3600">小时后</option>
                    <option value="86400" selected>天后</option>
                    <option value="604800">星期后</option>
                    <option value="2592000">月后（30天）</option>
                    <option value="31536000">年后（365天）</option>
                </select>
            </div>
            <div class="hint">${n.id
                ? '填写则覆盖原生效时间（' + esc(n.start_at > 0 ? n.start_text : '立即生效') + '），留空保持不变'
                : '填写数字并选单位，例如 7 + 天后 = 7 天后生效；留空立即生效'}</div>
        </div>
        <div class="field"><label>结束时间</label>
            <div style="display:flex;gap:6px">
                <input id="nEnd" type="number" min="0" placeholder="留空永久有效" style="flex:1">
                <select id="nEndUnit" style="width:110px">
                    <option value="60">分钟后</option>
                    <option value="3600">小时后</option>
                    <option value="86400" selected>天后</option>
                    <option value="604800">星期后</option>
                    <option value="2592000">月后（30天）</option>
                    <option value="31536000">年后（365天）</option>
                </select>
            </div>
            <div class="hint">${n.id
                ? '填写则覆盖原结束时间（' + esc(n.end_at > 0 ? n.end_text : '永久有效') + '），留空保持不变'
                : '填写数字并选单位，例如 30 + 分钟后 = 30 分钟后结束；留空永久有效'}</div>
        </div>
    </div>
    <div class="field"><label>状态</label>
        <select id="nStatus">
            <option value="1" ${n.status != 0 ? 'selected' : ''}>启用</option>
            <option value="0" ${n.status == 0 ? 'selected' : ''}>停用</option>
        </select>
    </div>`;

    openModal(n.id ? '编辑公告' : '发布公告', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {

            const now = Math.floor(Date.now() / 1000);
            const startV = document.getElementById('nStart').value.trim();
            const endV   = document.getElementById('nEnd').value.trim();
            let startAt, endAt;
            if (startV !== '')
                startAt = now + (parseInt(startV, 10) || 0) * (parseInt(document.getElementById('nStartUnit').value, 10) || 0);
            else
                startAt = n.id ? (n.start_at || 0) : 0;
            if (endV !== '')
                endAt = now + (parseInt(endV, 10) || 0) * (parseInt(document.getElementById('nEndUnit').value, 10) || 0);
            else
                endAt = n.id ? (n.end_at || 0) : 0;
            const payload = {
                id: n.id || 0,
                title: document.getElementById('nTitle').value.trim(),
                content: document.getElementById('nContent').value,
                type: parseInt(document.getElementById('nType').value, 10),
                sort: parseInt(document.getElementById('nSort').value, 10) || 0,
                start_at: startAt,
                end_at: endAt,
                status: parseInt(document.getElementById('nStatus').value, 10),
                software_id: parseInt(document.getElementById('nSoftware').value, 10) || 0,
            };
            const res = await api('notice_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');
}

function noticeDel(id) {
    confirmBox('删除公告', '确定删除该公告？', async () => {
        const res = await api('notice_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}

async function noticeToggle(n) {
    const res = await api('notice_save', {
        id: n.id, title: n.title, content: n.content,
        type: n.type, sort: n.sort, start_at: n.start_at, end_at: n.end_at,
        software_id: Number(n.software_id) || 0,
        status: n.status === 1 ? 0 : 1,
    });
    if (res.code === 0) { toast('已切换状态'); render(); }
}
