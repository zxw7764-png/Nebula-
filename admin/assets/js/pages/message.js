import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import {
    openModal, closeModal, confirmBox, toast, pager, bindPager,
    createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

register('message_list', render);

const STATUS = { 0: ['待审核', 'yellow'], 1: ['已通过', 'green'], 2: ['已驳回', 'gray'] };

const DEFAULTS = { page: 1, size: 20, status: '', kw: '', sw: '' };
let st = { ...DEFAULTS };
let sel = null;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('message_list', st);
    if (res.code !== 0) return;
    const d = res.data;
    const softwares = d.softwares || [];

    const swOpts = softwares.map(s =>
        `<option value="${s.id}" ${st.sw === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const rows = d.list.map(m => `
        <tr>
            ${rowCheckBox(m.id)}
            <td>${m.id}</td>
            <td>
                <b>${esc(m.username || '-')}</b>
                ${m.is_reply ? `<div class="sub-line">${esc(m.parent_text)}</div>` : ''}
            </td>
            <td>${m.software_id === 0
                ? tag('总站/通用', 'blue')
                : `<span class="tag gray">${esc(m.software_name)}</span>`}</td>
            <td class="cell-content">${esc(m.content)}</td>
            <td>${m.likes}</td>
            <td>${tag(STATUS[m.status] ? STATUS[m.status][0] : '?', STATUS[m.status] ? STATUS[m.status][1] : 'gray')}</td>
            <td class="mono" style="font-size:12px">${esc(m.created_at)}</td>
            <td style="white-space:nowrap">
                ${m.status !== 1 ? `<button class="btn success sm" data-act="pass" data-id="${m.id}">通过</button>` : ''}
                ${m.status !== 2 ? `<button class="btn warn sm" data-act="reject" data-id="${m.id}">驳回</button>` : ''}
                <button class="btn danger sm" data-act="del" data-id="${m.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>留言板
                ${d.pending > 0 ? `<span class="tag red" style="margin-left:8px">${d.pending} 条待审</span>` : ''}
            </h3>
            <div class="toolbar">
                <span id="mBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="mBulkOp">
                        <option value="pass">批量通过</option>
                        <option value="reject">批量驳回</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="mBulkRun">执行</button>
                </span>
                <select id="mSw">
                    <option value="">全部归属</option>
                    ${swOpts}
                </select>
                <select id="mStatus">
                    <option value="">全部状态</option>
                    <option value="0" ${st.status === '0' ? 'selected' : ''}>待审核</option>
                    <option value="1" ${st.status === '1' ? 'selected' : ''}>已通过</option>
                    <option value="2" ${st.status === '2' ? 'selected' : ''}>已驳回</option>
                </select>
                <input id="mKw" placeholder="搜索内容或昵称" value="${esc(st.kw)}">
                <button class="btn bulk-hide" id="mSearch">搜索</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>用户</th><th>归属软件</th><th>内容</th><th>点赞</th>
                    <th>状态</th><th>时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-chat-dots"></i>', '暂无留言')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });

    const doSearch = () => {
        st = { ...st, kw: document.getElementById('mKw').value.trim(), status: document.getElementById('mStatus').value, sw: document.getElementById('mSw').value, page: 1 };
        render();
    };
    document.getElementById('mSearch').addEventListener('click', doSearch);
    document.getElementById('mStatus').addEventListener('change', doSearch);
    document.getElementById('mSw').addEventListener('change', doSearch);
    document.getElementById('mKw').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'pass') opOne('pass', id);
            else if (act === 'reject') rejectOne(id, item);
            else if (act === 'del') delOne(id);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('mBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('mBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('mBulkOp').value;
        doBulk(op);
    });
}

function doBulk(act) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择留言', 'warn');

    const label = { pass: '通过', reject: '驳回', delete: '删除' }[act] || act;
    const extra = act === 'delete'
        ? '\n删除会连带清掉这些留言的回复与点赞记录，且不可恢复。'
        : '';

    confirmBox(`批量${label}`, `确定要${label}选中的 ${ids.length} 条留言吗？${extra}`, async () => {
        const res = await api('message_op', { op: 'batch', do: act, ids });
        if (res.code === 0) {
            toast(res.msg || '操作完成');
            if (sel) sel.clear();
            render();
        }
    }, act === 'delete');
}

async function opOne(op, id) {
    const res = await api('message_op', { op, id });
    if (res.code === 0) { toast(res.msg || '操作完成'); render(); }
}

function rejectOne(id, item) {
    const body = `
    <div class="field"><label>留言内容</label>
        <div class="code-box">${esc(item ? item.content : '')}</div>
    </div>
    <div class="field">
        <label>驳回理由（选填，仅管理员可见）</label>
        <textarea id="mNote" placeholder="例如：包含广告内容"></textarea>
    </div>`;

    openModal('驳回留言', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '确认驳回', cls: 'warn', act: async () => {
            const res = await api('message_op', {
                op: 'reject', id, note: document.getElementById('mNote').value.trim(),
            });
            if (res.code === 0) { toast('已驳回'); closeModal(); render(); }
        }},
    ]);
}

function delOne(id) {
    confirmBox('删除留言', '确定删除该留言？\n若它是主楼，其下回复与点赞记录会一并删除。', async () => {
        const res = await api('message_op', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
