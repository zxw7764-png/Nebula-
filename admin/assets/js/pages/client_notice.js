import { api } from '../core/api.js';
import { register, replayContentAnim } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

const DEFAULTS = { page: 1, size: 50, sw: '', type: '' };
let sel = null;
let lastList = [];
let swList = [];

register('client_notice_list', render);

async function render() {
    const st = pageState('client_notice_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const typeParam = st.type === '' ? '2,3,4' : st.type;
    const res = await api('notice_list', { page: st.page, size: st.size, sw: st.sw, type: typeParam });
    if (res.code !== 0) return;
    const d = res.data;
    swList = d.softwares || [];
    lastList = d.list || [];

    const swOpts = swList.map(s =>
        `<option value="${s.id}" ${String(st.sw) === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
    const TYPE_TEXT2 = { 2: '弹窗公告', 3: '立即公告', 4: '列表公告' };
    const TYPE_CLS2  = { 2: 'red', 3: 'orange', 4: 'blue' };

    const rows = d.list.map(n => `
        <tr>
            ${rowCheckBox(n.id)}
            <td>${n.id}</td>
            <td>${tag(TYPE_TEXT2[n.type] || '公告', TYPE_CLS2[n.type] || 'blue')}</td>
            <td><b>${esc(n.title)}</b></td>
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
            <h3>客户端公告</h3>
            <div class="acts">
                <div class="toolbar">
                    <select id="cnSw">
                        <option value="">全部归属</option>
                        ${swOpts}
                    </select>
                    <select id="cnTypeF">
                        <option value="">全部类型</option>
                        <option value="2" ${st.type === '2' ? 'selected' : ''}>弹窗公告</option>
                        <option value="3" ${st.type === '3' ? 'selected' : ''}>立即公告</option>
                        <option value="4" ${st.type === '4' ? 'selected' : ''}>列表公告</option>
                    </select>
                    <button class="btn" id="cnSearch">搜索</button>
                </div>
                <span id="cnBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="cnBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="cnBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="cnNew" style="margin-left:4px">+ 发布公告</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>类型</th><th>标题</th><th>所属软件</th><th>状态</th><th>排序</th>
                    <th>生效时间</th><th>结束时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-megaphone"></i>', '暂无客户端公告')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('cnSw').addEventListener('change', doSearch);
    document.getElementById('cnTypeF').addEventListener('change', doSearch);
    document.getElementById('cnSearch').addEventListener('click', doSearch);
    document.getElementById('cnNew').addEventListener('click', () => noticeEdit(null));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            if (b.dataset.act === 'edit') noticeEdit(item);
            else if (b.dataset.act === 'del') noticeDel(id);
            else if (b.dataset.act === 'toggle') noticeToggle(item);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('cnBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('cnBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('cnBulkOp').value;
        doBulk(op);
    });
}

function doSearch() {
    replayContentAnim();
    const st = pageState('client_notice_list', DEFAULTS);
    st.sw = document.getElementById('cnSw').value;
    st.type = document.getElementById('cnTypeF').value;
    st.page = 1;
    render();
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
                type: Number(item.type) || 4, sort: item.sort, start_at: item.start_at, end_at: item.end_at,
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

function noticeEdit(n) {
    const st = pageState('client_notice_list', DEFAULTS);
    n = n || {};

    const curSw = n.id ? (Number(n.software_id) || 0) : (st.sw === '' ? 0 : parseInt(st.sw, 10) || 0);
    const curType = (n.type === 2 || n.type === 3 || n.type === 4) ? n.type : 4;
    const swOptions = swList.map(s =>
        `<option value="${s.id}" ${curSw === Number(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const body = `
    <div class="row2">
        <div class="field"><label>公告类型 *</label>
            <select id="cnType">
                <option value="4" ${curType === 4 ? 'selected' : ''}>列表公告（客户端公告栏展示）</option>
                <option value="2" ${curType === 2 ? 'selected' : ''}>弹窗公告（客户端弹出窗口展示）</option>
                <option value="3" ${curType === 3 ? 'selected' : ''}>立即公告（弹出展示，看过即不再显示）</option>
            </select>
            <div class="hint">立即公告：SDK 拉取未读的立即公告逐条弹窗，用户确认后本地记为已读，以后不再显示</div>
        </div>
        <div class="field"><label>所属软件</label>
            <select id="cnSoftware">
                <option value="0" ${curSw === 0 ? 'selected' : ''}>全部软件（通用）</option>
                ${swOptions}
            </select>
            <div class="hint">选「全部软件」对所有软件的客户端下发；选具体软件则只有该软件的客户端可见</div>
        </div>
    </div>
    <div class="field"><label>标题 *</label><input id="cnTitle" value="${esc(n.title || '')}"></div>
    <div class="field"><label>排序（越大越靠前）</label><input id="cnSort" type="number" value="${n.sort || 0}"></div>
    <div class="field"><label>内容</label><textarea id="cnContent" placeholder="支持纯文本">${esc(n.content || '')}</textarea></div>
    <div class="row2">
        <div class="field"><label>生效时间</label>
            <div style="display:flex;gap:6px">
                <input id="cnStart" type="number" min="0" placeholder="留空立即生效" style="flex:1">
                <select id="cnStartUnit" style="width:110px">
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
                <input id="cnEnd" type="number" min="0" placeholder="留空永久有效" style="flex:1">
                <select id="cnEndUnit" style="width:110px">
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
        <select id="cnStatus">
            <option value="1" ${n.status != 0 ? 'selected' : ''}>启用</option>
            <option value="0" ${n.status == 0 ? 'selected' : ''}>停用</option>
        </select>
    </div>`;

    openModal(n.id ? '编辑客户端公告' : '发布客户端公告', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {

            const now = Math.floor(Date.now() / 1000);
            const startV = document.getElementById('cnStart').value.trim();
            const endV   = document.getElementById('cnEnd').value.trim();
            let startAt, endAt;
            if (startV !== '')
                startAt = now + (parseInt(startV, 10) || 0) * (parseInt(document.getElementById('cnStartUnit').value, 10) || 0);
            else
                startAt = n.id ? (n.start_at || 0) : 0;
            if (endV !== '')
                endAt = now + (parseInt(endV, 10) || 0) * (parseInt(document.getElementById('cnEndUnit').value, 10) || 0);
            else
                endAt = n.id ? (n.end_at || 0) : 0;
            const payload = {
                id: n.id || 0,
                title: document.getElementById('cnTitle').value.trim(),
                content: document.getElementById('cnContent').value,
                type: parseInt(document.getElementById('cnType').value, 10),
                sort: parseInt(document.getElementById('cnSort').value, 10) || 0,
                start_at: startAt,
                end_at: endAt,
                status: parseInt(document.getElementById('cnStatus').value, 10),
                software_id: parseInt(document.getElementById('cnSoftware').value, 10) || 0,
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
        type: Number(n.type) || 4, sort: n.sort, start_at: n.start_at, end_at: n.end_at,
        software_id: Number(n.software_id) || 0,
        status: n.status === 1 ? 0 : 1,
    });
    if (res.code === 0) { toast('已切换状态'); render(); }
}
