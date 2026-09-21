import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState, resetPageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { pager, bindPager } from '../core/ui.js';

const DEFAULTS = { page: 1, size: 30, keyword: '', action_filter: '', result: '' };

register('log_list', render);

async function render() {
    const st = pageState('log_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('log_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    const rows = d.list.map(l => `
        <tr>
            <td>${l.id}</td>
            <td class="mono" style="font-size:12px">${esc(l.created_at)}</td>
            <td>${esc(l.username || '-')}</td>
            <td>${tag(l.action, 'blue')}</td>
            <td>${l.result == 1 ? tag('成功', 'green') : tag('失败', 'red')}</td>
            <td style="color:#6b7280">${esc(l.message || '')}</td>
            <td class="mono">${esc(l.ip || '')}</td>
            <td class="mono" style="font-size:11.5px">${esc((l.machine_id || '-').slice(0, 16))}</td>
        </tr>`).join('');

    const opts = (d.actions || []).map(a =>
        `<option value="${esc(a)}" ${st.action_filter === a ? 'selected' : ''}>${esc(a)}</option>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>操作日志</h3>
            <div class="toolbar">
                <input id="lKw" placeholder="搜索用户名/说明/IP/机器码" value="${esc(st.keyword)}">
                <select id="lAction">
                    <option value="">全部动作</option>
                    ${opts}
                </select>
                <select id="lResult">
                    <option value="">全部结果</option>
                    <option value="1" ${st.result === '1' ? 'selected' : ''}>成功</option>
                    <option value="0" ${st.result === '0' ? 'selected' : ''}>失败</option>
                </select>
                <button class="btn" id="lSearch">搜索</button>
                <button class="btn ghost" id="lReset">重置</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>ID</th><th>时间</th><th>用户</th><th>动作</th><th>结果</th>
                    <th>说明</th><th>IP</th><th>机器码</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="8">${empty('<i class="bi bi-journal-text"></i>', '暂无日志')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('lKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('lSearch').addEventListener('click', doSearch);
    document.getElementById('lReset').addEventListener('click', () => {
        resetPageState('log_list', DEFAULTS);
        render();
    });
}

function doSearch() {
    const st = pageState('log_list', DEFAULTS);
    st.keyword = document.getElementById('lKw').value.trim();
    st.action_filter = document.getElementById('lAction').value;
    st.result = document.getElementById('lResult').value;
    st.page = 1;
    render();
}
