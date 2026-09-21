import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag } from '../core/util.js';
import {
    confirmBox, toast,
    pager, bindPager, createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20, keyword: '' };

let sel = null;

register('device_ban_list', render);

async function render() {
    const st = pageState('device_ban_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('device_ban_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    const rows = d.list.map(x => `
        <tr style="${x.active ? '' : 'opacity:.55'}">
            ${rowCheckBox(x.id)}
            <td>${x.id}</td>
            <td class="mono" style="font-size:12px">${esc(x.machine_id)}</td>
            <td>${esc(x.reason || '-')}</td>
            <td>${esc(x.admin_name)}</td>
            <td class="mono" style="font-size:12px">${esc(x.created_at)}</td>
            <td>${x.permanent ? tag('永久', 'red') : esc(x.expire_at)}</td>
            <td>${x.active ? tag('生效中', 'red') : tag('已过期', 'gray')}</td>
            <td><button class="btn sm ghost" data-act="unban" data-id="${x.id}">解除</button></td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>设备黑名单</h3>
            <div class="toolbar">
                <input id="bKw" placeholder="搜索机器码/原因" value="${esc(st.keyword)}">
                <button class="btn" id="bSearch">搜索</button>
            </div>
            <div class="acts">
                <button class="btn sm danger" data-bulk="unban" id="bBulkUnban" hidden>批量解除拉黑</button>
                <span style="font-size:12px;color:#9ca3af">被拉黑的机器码无法登录/绑定任何账号</span>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>机器码</th><th>拉黑原因</th><th>操作人</th>
                    <th>拉黑时间</th><th>到期</th><th>状态</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-slash-circle"></i>', '黑名单为空')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('bKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('bSearch').addEventListener('click', doSearch);

    c.querySelectorAll('[data-act]').forEach(b => {
        b.addEventListener('click', () => {
            if (b.dataset.act === 'unban') devUnban([parseInt(b.dataset.id, 10)]);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        document.getElementById('bBulkUnban').hidden = !(ids.length > 0);
    }});
    c.querySelectorAll('[data-bulk]').forEach(b => {
        b.addEventListener('click', () => {
            const ids = sel ? sel.ids() : [];
            if (!ids.length) return toast('请先选择记录', 'warn');
            if (b.dataset.bulk === 'unban') devUnban(ids);
        });
    });
}

function doSearch() {
    const st = pageState('device_ban_list', DEFAULTS);
    st.keyword = document.getElementById('bKw').value.trim();
    st.page = 1;
    render();
}

function devUnban(ids) {
    confirmBox('解除拉黑',
        `将解除已选 ${ids.length} 条黑名单记录，对应机器码恢复登录/绑定资格。确定继续？`,
        async () => {
            const res = await api('device_unban', { ban_ids: ids });
            if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
        }, true);
}
