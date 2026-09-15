/* ======================================================================
   pages/feedback.js — 官网用户反馈管理
   反馈是私密的（仅本人与客服可见），这里是客服回复与流转的入口。
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import {
    openModal, closeModal, confirmBox, toast, pager, bindPager,
    createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

register('feedback_list', render);

const STATUS = { 0: ['待处理', 'red'], 1: ['处理中', 'yellow'], 2: ['已回复', 'green'], 3: ['已关闭', 'gray'] };

const DEFAULTS = { page: 1, size: 20, status: '', type: '', kw: '', sw: '' };
let st = { ...DEFAULTS };
let sel = null;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('feedback_list', st);
    if (res.code !== 0) return;
    const d = res.data;
    const cnt = d.counts || {};
    const softwares = d.softwares || [];

    const swOpts = softwares.map(s =>
        `<option value="${s.id}" ${st.sw === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const tabs = [
        { id: '',  name: '全部',   n: cnt.all || 0 },
        { id: '0', name: '待处理', n: cnt.pending || 0 },
        { id: '1', name: '处理中', n: cnt.doing || 0 },
        { id: '2', name: '已回复', n: cnt.replied || 0 },
        { id: '3', name: '已关闭', n: cnt.closed || 0 },
    ];

    const rows = d.list.map(f => `
        <tr>
            ${rowCheckBox(f.id)}
            <td>${f.id}</td>
            <td><b>${esc(f.username || '-')}</b></td>
            <td>${tag(f.type_text, 'blue')}</td>
            <td class="cell-content">${esc(f.title)}</td>
            <td>${f.software_id === 0
                ? tag('总站/通用', 'blue')
                : `<span class="tag gray">${esc(f.software_name)}</span>`}</td>
            <td>${tag(STATUS[f.status] ? STATUS[f.status][0] : '?', STATUS[f.status] ? STATUS[f.status][1] : 'gray')}</td>
            <td class="mono" style="font-size:12px">${esc(f.created_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="view" data-id="${f.id}">查看</button>
                <button class="btn success sm" data-act="reply" data-id="${f.id}">回复</button>
                ${f.status !== 3 ? `<button class="btn ghost sm" data-act="close" data-id="${f.id}">关闭</button>` : ''}
                <button class="btn danger sm" data-act="del" data-id="${f.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>用户反馈</h3>
            <div class="toolbar">
                <span id="fbBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="fbBulkOp">
                        <option value="close">批量关闭</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="fbBulkRun">执行</button>
                </span>
                <select id="fbSw">
                    <option value="">全部归属</option>
                    ${swOpts}
                </select>
                <select id="fbType">
                    <option value="">全部类型</option>
                    ${Object.entries(d.types || {}).map(([k, v]) =>
                        `<option value="${k}" ${st.type === String(k) ? 'selected' : ''}>${esc(v)}</option>`).join('')}
                </select>
                <input id="fbKw" placeholder="搜索标题、内容或用户名" value="${esc(st.kw)}">
                <button class="btn bulk-hide" id="fbSearch">搜索</button>
            </div>
        </div>

        <div class="tabs">
            ${tabs.map(t => `
                <button class="${st.status === t.id ? 'on' : ''}" data-tab="${t.id}">${esc(t.name)} (${t.n})</button>
            `).join('')}
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>用户</th><th>类型</th><th>标题</th><th>归属软件</th>
                    <th>状态</th><th>提交时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-chat-square-text"></i>', '暂无反馈')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });

    const doFilter = () => {
        st = { ...st, kw: document.getElementById('fbKw').value.trim(), type: document.getElementById('fbType').value, sw: document.getElementById('fbSw').value, page: 1 };
        render();
    };
    document.getElementById('fbSearch').addEventListener('click', doFilter);
    document.getElementById('fbType').addEventListener('change', doFilter);
    document.getElementById('fbSw').addEventListener('change', doFilter);
    document.getElementById('fbKw').addEventListener('keydown', e => { if (e.key === 'Enter') doFilter(); });

    c.querySelectorAll('[data-tab]').forEach(b => {
        b.addEventListener('click', () => {
            if (st.status === b.dataset.tab) return;
            st = { ...st, status: b.dataset.tab, page: 1 };
            render();
        });
    });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'view') fbView(item);
            else if (act === 'reply') fbReply(item);
            else if (act === 'close') fbClose(id);
            else if (act === 'del') fbDel(id);
        });
    });

    // 批量选择
    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('fbBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    // 批量操作：下拉选择 + 执行
    const bulkRun = document.getElementById('fbBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('fbBulkOp').value;
        doBulk(op);
    });
}

/* ------------------------- 批量操作 ------------------------- */
function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择反馈', 'warn');

    if (op === 'close') {
        confirmBox('批量关闭', `确定关闭选中的 ${ids.length} 条反馈吗？`, async () => {
            const res = await api('feedback_reply', { op: 'batch_close', ids });
            if (res.code === 0) {
                toast(res.msg || '已关闭');
                if (sel) sel.clear();
                render();
            }
        });
        return;
    }

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 条反馈？此操作不可恢复。`, async () => {
            let n = 0;
            for (const id of ids) {
                const res = await api('feedback_reply', { op: 'delete', id });
                if (res.code === 0) n++;
            }
            toast(`已删除 ${n} 条反馈`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }
}

/* ------------------------- 查看详情 ------------------------- */
function fbView(f) {
    if (!f) return;
    const s = STATUS[f.status] || ['?', 'gray'];
    const body = `
    <div class="kv">
        <div class="k">用户</div><div class="v">${esc(f.username || '-')}</div>
        <div class="k">类型</div><div class="v">${esc(f.type_text)}</div>
        <div class="k">状态</div><div class="v">${esc(s[0])}</div>
        <div class="k">提交时间</div><div class="v">${esc(f.created_at)}</div>
        ${f.contact ? `<div class="k">联系方式</div><div class="v">${esc(f.contact)}</div>` : ''}
    </div>
    <div class="field"><label>标题</label>
        <div class="code-box">${esc(f.title)}</div>
    </div>
    <div class="field"><label>内容</label>
        <div class="code-box pre">${esc(f.content)}</div>
    </div>
    ${f.reply ? `
    <div class="field"><label>客服回复（${esc(f.reply_admin || '-')} · ${esc(f.replied_at)}）</label>
        <div class="code-box pre reply-box">${esc(f.reply)}</div>
    </div>` : ''}`;

    openModal('反馈详情 #' + f.id, body, [
        { text: '关闭', cls: 'ghost', act: closeModal },
        { text: '回复', cls: 'success', act: () => { closeModal(); fbReply(f); } },
    ], 'wide');
}

/* ------------------------- 回复 ------------------------- */
function fbReply(f) {
    if (!f) return;
    const body = `
    <div class="field"><label>用户提问</label>
        <div class="code-box pre">${esc(f.title)}

${esc(f.content)}</div>
    </div>
    <div class="field">
        <label>回复内容 *</label>
        <textarea id="fbReplyText" rows="6" placeholder="回复会展示在用户的个人中心，请填写清晰的解决方案">${esc(f.reply || '')}</textarea>
    </div>
    <div class="field"><div class="hint">回复后反馈状态自动变为「已回复」，用户可在个人中心查看。</div></div>`;

    openModal('回复反馈 #' + f.id, body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '发送回复', cls: 'success', act: async () => {
            const text = document.getElementById('fbReplyText').value.trim();
            if (!text) return toast('请填写回复内容', 'warn');
            const res = await api('feedback_reply', { op: 'reply', id: f.id, reply: text });
            if (res.code === 0) { toast('回复已发送'); closeModal(); render(); }
        }},
    ], 'wide');
}

async function fbClose(id) {
    const res = await api('feedback_reply', { op: 'close', id });
    if (res.code === 0) { toast('已关闭'); render(); }
}

function fbDel(id) {
    confirmBox('删除反馈', '确定删除该反馈？此操作不可恢复。', async () => {
        const res = await api('feedback_reply', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
