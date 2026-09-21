import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

register('group_list', render);

let sel = null;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('group_list');
    if (res.code !== 0) return;
    const list = res.data.list;

    const rows = list.map(g => `
        <tr>
            ${g.id > 1 ? rowCheckBox(g.id) : '<td class="col-check"></td>'}
            <td>${g.id}</td>
            <td><b>${esc(g.name)}</b></td>
            <td>${g.max_devices} 台</td>
            <td>${esc(g.daily_quota_text || (g.daily_quota || '不限'))}</td>
            <td>${g.daily_quota > 0 ? `${g.today_calls} / ${g.daily_quota * g.user_count}` : '—'}</td>
            <td>${g.user_count}</td>
            <td>${esc(g.remark || '-')}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${g.id}">编辑</button>
                ${g.id > 1 ? `<button class="btn danger sm" data-act="del" data-id="${g.id}">删除</button>` : ''}
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>用户组列表</h3>
            <div class="acts">
                <span id="gBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="gBulkOp">
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="gBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="gNew">+ 新增用户组</button>
            </div>
        </div>
        <p class="muted" style="margin:-4px 0 12px;font-size:12.5px;line-height:1.7">
            设备上限为「保底额度」：实际额度取 <b>max(用户自身额度, 所在组额度)</b>，组只能提升、不会降低用户已有额度。<br>
            每日配额按「每个用户」独立计数，限制其当天调用客户端接口（心跳/激活/查询等）的次数；心跳约每 60 秒一次、每天约 1440 次，设置时请把心跳算进去。
        </p>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>组名</th><th>设备上限</th><th>每日配额</th><th>今日用量</th><th>用户数</th><th>备注</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-collection"></i>', '暂无用户组')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('gNew').addEventListener('click', () => groupEdit(null));

    sel = createSelection({
        root: c,
        allIds: list.filter(g => g.id > 1).map(g => g.id),
        onChange: ids => {
            const box = document.getElementById('gBulkBox');
            if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
            c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
        },
    });
    document.getElementById('gBulkRun').addEventListener('click', () => {
        const ids = sel ? sel.ids() : [];
        if (!ids.length) return toast('请先选择用户组', 'warn');
        confirmBox('批量删除用户组',
            `将删除已选的 ${ids.length} 个用户组；组内还有用户的组会自动跳过。确定继续？`,
            async () => {
                const res = await api('group_batch', { op: 'delete', ids });
                if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
            }, true);
    });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = list.find(x => x.id === id);
        b.addEventListener('click', () => {
            if (b.dataset.act === 'edit') groupEdit(item);
            else if (b.dataset.act === 'del') groupDel(id);
        });
    });
}

function groupEdit(g) {
    g = g || {};
    const body = `
    <div class="field"><label>组名 *</label><input id="grName" value="${esc(g.name || '')}"></div>
    <div class="row2">
        <div class="field">
            <label>设备上限</label>
            <input id="grDev" type="number" value="${g.max_devices || 1}" min="1" max="99">
            <small class="muted">保底额度，实际取 max(用户自身, 本组)</small>
        </div>
        <div class="field">
            <label>每日配额</label>
            <input id="grQuota" type="number" value="${g.daily_quota || 0}" min="0" placeholder="0=不限">
            <small class="muted">每用户每天可调用接口次数，0=不限<br>心跳约 60 秒一次（约 1440 次/天）</small>
        </div>
    </div>
    <div class="field"><label>备注</label><input id="grRemark" value="${esc(g.remark || '')}"></div>`;

    openModal(g.id ? '编辑用户组' : '新增用户组', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: g.id || 0,
                name: document.getElementById('grName').value.trim(),
                max_devices: parseInt(document.getElementById('grDev').value, 10) || 1,
                daily_quota: parseInt(document.getElementById('grQuota').value, 10) || 0,
                remark: document.getElementById('grRemark').value.trim(),
            };
            const res = await api('group_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ]);
}

function groupDel(id) {
    confirmBox('删除用户组', '确定删除该用户组？组内用户会回落到默认组。', async () => {
        const res = await api('group_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
