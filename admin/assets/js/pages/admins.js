import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast } from '../core/ui.js';

register('admins', render);

let catalog = {};
let lastList = [];

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = '<div class="card"><div class="card-body">加载中...</div></div>';

    const res = await api('admin_list');
    if (res.code !== 0) return;
    const d = res.data;
    lastList = d.list || [];
    catalog = d.catalog || {};

    const rows = lastList.map(a => {
        const isSuper = a.role === 1;
        const totpTag = a.totp === 'enabled' ? tag('2FA 已开启', 'green')
            : a.totp === 'pending' ? tag('2FA 待验证', 'yellow')
            : tag('2FA 未开启', 'gray');
        const permText = isSuper
            ? tag('全部权限', 'green')
            : (a.permissions === null
                ? `<span class="sub">角色默认（${esc(a.role_text)}）</span>`
                : `<span class="sub">自定义 ${a.permissions.length} 项</span>`);
        const ops = [];
        ops.push(`<button class="btn ghost sm" data-act="edit" data-id="${a.id}">编辑</button>`);
        if (!isSuper) {
            ops.push(`<button class="btn ghost sm" data-act="toggle" data-id="${a.id}">${a.status === 1 ? '停用' : '启用'}</button>`);
            ops.push(`<button class="btn ghost sm" data-act="rp" data-id="${a.id}">重置密码</button>`);
            if (a.totp !== 'none') {
                ops.push(`<button class="btn ghost sm" data-act="rt" data-id="${a.id}">重置 2FA</button>`);
            }
            ops.push(`<button class="btn danger sm" data-act="del" data-id="${a.id}">删除</button>`);
        }
        return `
        <tr>
            <td>${a.id}</td>
            <td><b>${esc(a.username)}</b></td>
            <td>${esc(a.nickname || '-')}</td>
            <td>${isSuper ? tag('超级管理员', 'green') : tag(a.role_text, 'blue')}</td>
            <td>${totpTag}</td>
            <td>${permText}</td>
            <td>${a.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td class="sub">${esc(a.last_login_text || '从未登录')}${a.last_login_ip ? '<br>' + esc(a.last_login_ip) : ''}</td>
            <td style="white-space:nowrap">${ops.join(' ')}</td>
        </tr>`;
    }).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>管理员账号</h3>
            <div class="acts"><button class="btn success" id="aNew">+ 新增管理员</button></div>
        </div>
        <div class="card-body"><div class="hint">
            仅超级管理员可以管理管理员账号。每个账号的功能权限由超管逐项勾选；
            未勾选「自定义权限」的账号按角色默认矩阵（操作员 / 只读）执行。
            「管理员管理」本身为超管专属权限，无法授权给其他账号。
            二次验证（2FA）由各账号在「个人中心」自行绑定；丢失验证器时可由超管在此重置。
        </div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>ID</th><th>登录名</th><th>显示名</th><th>角色</th><th>二次验证</th>
                    <th>权限来源</th><th>状态</th><th>最后登录</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-shield-lock"></i>', '暂无账号')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('aNew').addEventListener('click', () => adminEdit(null));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = lastList.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'edit') adminEdit(item);
            else if (act === 'toggle') adminToggle(item);
            else if (act === 'rp') adminResetPassword(item);
            else if (act === 'rt') adminResetTotp(item);
            else if (act === 'del') adminDelete(item);
        });
    });
}

/* ── 新增 / 编辑弹窗 ─────────────────────────────────────────────── */
function adminEdit(a) {
    a = a || null;
    const isSuper = a && a.role === 1;
    const isNew = !a;
    const followDefault = a && a.permissions === null;
    const checked = new Set(a && a.permissions ? a.permissions : []);

    const catalogHtml = Object.entries(catalog).map(([group, perms]) => {
        const items = Object.entries(perms).map(([p, name]) => `
            <label class="perm-item">
                <input type="checkbox" data-perm="${esc(p)}" ${checked.has(p) ? 'checked' : ''}>
                <span class="perm-name">${esc(name)}<span class="perm-key">${esc(p)}</span></span>
            </label>`).join('');
        return `
        <div class="perm-group">
            <div class="perm-group-head">
                <b>${esc(group)}</b>
                <label><input type="checkbox" data-perm-group="${esc(group)}"> 全选</label>
            </div>
            <div class="perm-items">${items}</div>
        </div>`;
    }).join('');

    const body = isNew ? `
        <div class="row2">
            <div class="field"><label>登录名 *（3-32 位字母/数字/下划线）</label>
                <input id="amUser" placeholder="例如：operator01"></div>
            <div class="field"><label>初始密码 *</label>
                <input id="amPass" type="password" placeholder="登录后建议在个人中心修改"></div>
        </div>
        <div class="field"><label>显示名（选填）</label><input id="amNick" placeholder="默认同登录名"></div>
        ${permSection()}
        <div class="field"><label>状态</label>
            <select id="amStatus">
                <option value="1" selected>启用</option>
                <option value="0">停用（暂停登录）</option>
            </select></div>
        <div class="hint">2FA 由该账号登录后在「个人中心」自行绑定。</div>
    ` : (isSuper ? `
        <div class="field"><label>显示名</label><input id="amNick" value="${esc(a.nickname || '')}"></div>
        <div class="hint">超级管理员账号仅可修改显示名；权限、密码与 2FA 由本人自助管理。</div>
    ` : `
        <div class="row2">
            <div class="field"><label>显示名</label><input id="amNick" value="${esc(a.nickname || '')}"></div>
            <div class="field"><label>角色</label>
                <select id="amRole">
                    <option value="2" ${a.role === 2 ? 'selected' : ''}>操作员</option>
                    <option value="3" ${a.role === 3 ? 'selected' : ''}>只读</option>
                </select></div>
        </div>
        <div class="field"><label>重置密码（选填，留空不改）</label>
            <input id="amPass" type="password" placeholder="填写后立即生效并踢下线该账号"></div>
        <div class="field"><label>状态</label>
            <select id="amStatus">
                <option value="1" ${a.status === 1 ? 'selected' : ''}>启用</option>
                <option value="0" ${a.status === 0 ? 'selected' : ''}>停用（踢下线并禁止登录）</option>
            </select></div>
        ${permSection()}
        <div class="hint">权限变更保存后立即生效（该账号下次请求即按新权限执行）。</div>
    `);

    function permSection() {
        if (isSuper) return '';
        return `
        <div class="field">
            <label class="chk-inline">
                <input type="checkbox" id="amFollowDefault" ${followDefault ? 'checked' : ''}>
                <span>跟随角色默认权限（不自定义）</span>
            </label>
            <div id="amPermBox" class="perm-tree" ${followDefault ? 'hidden' : ''}>${catalogHtml}</div>
            <div class="hint">勾选 = 允许该账号使用的功能；「管理员管理」为超管专属，不在可选范围内。</div>
        </div>`;
    }

    const m = openModal(isNew ? '新增管理员' : `编辑：${esc(a.username)}`, body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        {
            text: '保存', act: async () => {
                const payload = { op: 'save', id: a ? a.id : 0 };
                payload.nickname = (document.getElementById('amNick').value || '').trim();
                if (isNew) {
                    payload.username = (document.getElementById('amUser').value || '').trim();
                    payload.password = document.getElementById('amPass').value;
                    payload.role = 2;
                    payload.status = parseInt(document.getElementById('amStatus').value, 10);
                    payload.permissions = collectPerms();
                } else if (!isSuper) {
                    payload.role = parseInt(document.getElementById('amRole').value, 10);
                    payload.status = parseInt(document.getElementById('amStatus').value, 10);
                    const pw = document.getElementById('amPass').value;
                    if (pw) payload.password = pw;
                    payload.permissions = collectPerms();
                }
                const res = await api('admin_save', payload);
                if (res.code !== 0) return;
                toast(res.msg || '已保存');
                closeModal();
                render();
            }
        },
    ], 'wide');

    // 「跟随角色默认」开关 + 分组全选
    const fd = document.getElementById('amFollowDefault');
    if (fd) {
        fd.addEventListener('change', () => {
            const box = document.getElementById('amPermBox');
            if (box) box.hidden = fd.checked;
        });
    }
    m.querySelectorAll('[data-perm-group]').forEach(cb => {
        cb.addEventListener('change', () => {
            const group = cb.dataset.permGroup;
            m.querySelectorAll(`[data-perm]`).forEach(p => {
                const g = groupOfPerm(p.dataset.perm);
                if (g === group) p.checked = cb.checked;
            });
        });
    });

    function collectPerms() {
        if (!isSuper) {
            const fd2 = document.getElementById('amFollowDefault');
            if (fd2 && fd2.checked) return null;         // 清除自定义 → 角色默认矩阵
            const arr = [];
            m.querySelectorAll('[data-perm]').forEach(p => {
                if (p.checked) arr.push(p.dataset.perm);
            });
            return arr;
        }
        return undefined;                                 // 超管编辑：不提交该字段
    }
}

function groupOfPerm(perm) {
    for (const [group, perms] of Object.entries(catalog)) {
        if (Object.prototype.hasOwnProperty.call(perms, perm)) return group;
    }
    return '';
}

/* ── 启停 ───────────────────────────────────────────────────────── */
function adminToggle(a) {
    const label = a.status === 1 ? '停用' : '启用';
    confirmBox(`${label}管理员`,
        `确定${label}「${a.username}」？${a.status === 1 ? '停用后立即踢下线且无法登录。' : ''}`,
        async () => {
            const res = await api('admin_save', { op: 'toggle', id: a.id });
            if (res.code !== 0) return;
            toast(res.msg || '已操作');
            render();
        }, a.status === 1);
}

/* ── 重置密码 ───────────────────────────────────────────────────── */
function adminResetPassword(a) {
    const body = `
        <div class="field"><label>「${esc(a.username)}」的新密码 *</label>
            <input id="amNewPass" type="password" placeholder="输入新密码">
            <div class="hint">重置后该账号的所有登录会话立即失效。</div></div>`;
    const m = openModal('重置密码', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        {
            text: '重置', cls: 'danger', act: async () => {
                const np = document.getElementById('amNewPass').value;
                if (!np) return toast('请输入新密码', 'warn');
                const res = await api('admin_save', { op: 'reset_password', id: a.id, new_password: np });
                if (res.code !== 0) return;
                toast(res.msg || '已重置');
                closeModal();
                render();
            }
        },
    ], 'sm');
}

/* ── 重置 2FA ───────────────────────────────────────────────────── */
function adminResetTotp(a) {
    confirmBox('重置二次验证',
        `确定重置「${a.username}」的 2FA？重置后其验证器与剩余恢复码全部失效，可重新绑定。`,
        async () => {
            const res = await api('admin_save', { op: 'reset_totp', id: a.id });
            if (res.code !== 0) return;
            toast(res.msg || '已重置');
            render();
        }, true);
}

/* ── 删除 ───────────────────────────────────────────────────────── */
function adminDelete(a) {
    confirmBox('删除管理员',
        `确定删除「${a.username}」？该操作不可恢复，其登录会话一并清除。`,
        async () => {
            const res = await api('admin_save', { op: 'delete', id: a.id });
            if (res.code !== 0) return;
            toast(res.msg || '已删除');
            render();
        }, true);
}
