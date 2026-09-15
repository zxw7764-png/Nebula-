/* ======================================================================
   pages/session.js — 在线会话（多选批量踢出）
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import {
    confirmBox, toast, pager, bindPager,
    createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 30, online: '1' };

let sel = null;

register('session_list', render);

async function render() {
    const st = pageState('session_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('session_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    const rows = d.list.map(s => `
        <tr>
            ${rowCheckBox(s.id)}
            <td>${s.id}</td>
            <td>${esc(s.username)}</td>
            <td class="mono" style="font-size:12px">${esc((s.machine_id || '-').slice(0, 24))}</td>
            <td class="mono">${esc(s.ip || '-')}</td>
            <td>${esc(s.client_ver || '-')}</td>
            <td>${s.online ? tag('在线', 'green') : tag('离线', 'gray')}</td>
            <td class="mono" style="font-size:12px">${esc(s.login_at)}</td>
            <td class="mono" style="font-size:12px">${esc(s.last_active)}</td>
            <td>${s.online ? `<button class="btn danger sm" data-act="kick" data-id="${s.id}">踢出</button>` : ''}</td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>在线会话 <span style="color:#10b981">${d.online_count}</span> 个</h3>
            <div class="toolbar">
                <button class="btn sm danger" data-bulk="kick" id="sBulkKick" hidden>批量踢出</button>
                <select id="sOnline">
                    <option value="1" ${st.online === '1' ? 'selected' : ''}>仅在线</option>
                    <option value="0" ${st.online === '0' ? 'selected' : ''}>全部</option>
                </select>
                <button class="btn ghost" id="sRefresh">刷新</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>用户</th><th>机器码</th><th>IP</th><th>版本</th>
                    <th>状态</th><th>登录时间</th><th>最后活跃</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-activity"></i>', '暂无会话')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('sOnline').addEventListener('change', e => {
        st.online = e.target.value;
        st.page = 1;
        render();
    });
    document.getElementById('sRefresh').addEventListener('click', render);

    c.querySelectorAll('[data-act]').forEach(b => {
        b.addEventListener('click', () => sessKick([parseInt(b.dataset.id, 10)]));
    });

    // 只有在线会话可被选中（离线会话无法踢出）
    sel = createSelection({
        root: c,
        allIds: d.list.filter(s => s.online).map(s => s.id),
        onChange: ids => {
            document.getElementById('sBulkKick').hidden = !(ids.length > 0);
            document.getElementById('sRefresh').hidden = ids.length > 0;
        },
    });
    // 离线会话的复选框禁用
    c.querySelectorAll('tbody tr').forEach(tr => {
        const cb = tr.querySelector('[data-row-check]');
        if (!cb) return;
        const online = !!tr.querySelector('[data-act="kick"]');
        if (!online) {
            cb.disabled = true;
            cb.title = '离线会话无需踢出';
        }
    });
    c.querySelectorAll('[data-bulk]').forEach(b => {
        b.addEventListener('click', () => {
            const ids = sel ? sel.ids() : [];
            if (!ids.length) return toast('请先选择会话', 'warn');
            sessKick(ids);
        });
    });
}

function sessKick(ids) {
    confirmBox('踢出会话', `确定踢出已选的 ${ids.length} 个会话？用户将被强制下线。`, async () => {
        const res = await api('session_kick', { op: ids.length > 1 ? 'batch' : 'single', session_id: ids[0], session_ids: ids });
        if (res.code === 0) { toast(res.msg || '已踢出'); if (sel) sel.clear(); render(); }
    }, true);
}
