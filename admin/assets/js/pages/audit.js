import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState, resetPageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, pager, bindPager } from '../core/ui.js';

const DEFAULTS = { page: 1, size: 30, keyword: '', action: '', admin_id: 0, date_from: '', date_to: '' };

register('audit_list', render);

async function render() {
    const st = pageState('audit_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('audit_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    const rows = d.list.map(a => `
        <tr>
            <td>${a.id}</td>
            <td class="mono" style="font-size:12px">${esc(a.created_at)}</td>
            <td><b>${esc(a.admin_name || '-')}</b></td>
            <td>${tag(a.action_text || a.action, 'purple')}</td>
            <td>${esc(a.target_text || '-')}</td>
            <td style="color:#6b7280">${esc(a.summary || '')}</td>
            <td class="mono">${esc(a.ip || '')}</td>
            <td>${a.has_diff ? `<button class="btn ghost sm" data-act="diff" data-id="${a.id}">变更明细</button>` : '-'}</td>
        </tr>`).join('');

    const opts = (d.actions || []).map(a =>
        `<option value="${esc(a.value)}" ${st.action === a.value ? 'selected' : ''}>${esc(a.text)}</option>`).join('');

    const adminOpts = (d.admins || []).map(a =>
        `<option value="${a.id}" ${st.admin_id == a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>审计日志</h3>
            <div class="toolbar">
                <input id="aKw" placeholder="搜索操作人/目标/说明/IP" value="${esc(st.keyword)}">
                <select id="aAction">
                    <option value="">全部操作</option>
                    ${opts}
                </select>
                <select id="aAdmin">
                    <option value="0">全部操作人</option>
                    ${adminOpts}
                </select>
                <input id="aFrom" type="date" value="${esc(st.date_from)}" style="min-width:140px">
                <input id="aTo" type="date" value="${esc(st.date_to)}" style="min-width:140px">
                <button class="btn" id="aSearch">搜索</button>
                <button class="btn ghost" id="aReset">重置</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>ID</th><th>时间</th><th>操作人</th><th>操作</th><th>目标</th>
                    <th>摘要</th><th>IP</th><th>明细</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="8">${empty('<i class="bi bi-journal-check"></i>', '暂无审计记录')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('aKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('aSearch').addEventListener('click', doSearch);
    document.getElementById('aReset').addEventListener('click', () => {
        resetPageState('audit_list', DEFAULTS);
        render();
    });

    c.querySelectorAll('[data-act="diff"]').forEach(b => {
        b.addEventListener('click', () => showDiff(parseInt(b.dataset.id, 10)));
    });
}

function doSearch() {
    const st = pageState('audit_list', DEFAULTS);
    st.keyword = document.getElementById('aKw').value.trim();
    st.action = document.getElementById('aAction').value;
    st.admin_id = parseInt(document.getElementById('aAdmin').value, 10) || 0;
    st.date_from = document.getElementById('aFrom').value;
    st.date_to = document.getElementById('aTo').value;
    st.page = 1;
    render();
}

async function showDiff(id) {
    openModal('变更明细', loading(), [], 'wide');
    const res = await api('audit_detail', { audit_id: id });
    if (res.code !== 0) { closeModal(); return; }
    const d = res.data, a = d.audit;

    const changes = d.changes || [];
    const rows = changes.map(ch => `
        <tr>
            <td><b>${esc(ch.label || ch.field)}</b><div style="font-size:11.5px;color:#9ca3af">${esc(ch.field)}</div></td>
            <td class="diff-old">${esc(ch.old === null || ch.old === undefined || ch.old === '' ? '（空）' : ch.old)}</td>
            <td class="diff-new">${esc(ch.new === null || ch.new === undefined || ch.new === '' ? '（空）' : ch.new)}</td>
        </tr>`).join('');

    openModal(`变更明细 · ${a.action_text || a.action}`, `
    <div class="kv" style="margin-bottom:18px">
        <span class="k">操作人</span><span class="v">${esc(a.admin_name || '-')}（#${a.admin_id}）</span>
        <span class="k">操作</span><span class="v">${esc(a.action_text || a.action)}</span>
        <span class="k">目标</span><span class="v">${esc(a.target_text || '-')}</span>
        <span class="k">时间</span><span class="v">${esc(a.created_at)}</span>
        <span class="k">IP</span><span class="v mono">${esc(a.ip || '-')}</span>
        <span class="k">摘要</span><span class="v">${esc(a.summary || '-')}</span>
    </div>
    ${changes.length ? `
    <h4 style="margin-bottom:10px;font-size:14px">字段变更（共 ${changes.length} 项）</h4>
    <div class="table-wrap"><table class="diff-table">
        <thead><tr><th>字段</th><th>修改前</th><th>修改后</th></tr></thead>
        <tbody>${rows}</tbody>
    </table></div>` : '<p style="color:#6b7280">本次操作无字段级变更记录。</p>'}`,
    [{ text: '关闭', cls: '', act: closeModal }], 'wide');
}
