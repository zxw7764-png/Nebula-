/* ======================================================================
   pages/version.js — 版本管理
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

register('version_list', render);

let swCache = null;
let sel = null;
let swFilter = '';   // 软件归属筛选：'' = 全部软件，数字 = 指定软件 id

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('version_list', { page: 1, size: 50, software_id: swFilter === '' ? 0 : parseInt(swFilter, 10) });
    if (res.code !== 0) return;
    const d = res.data;
    if (!swCache) {
        api('software_list', {}, true).then(r => {
            if (r.code === 0 && r.data.options && !swCache) {
                swCache = r.data.options;
                if (swFilter === '') render();   // 拿到软件列表后补全筛选下拉
            }
        });
    }

    const swOpts = (swCache || []).map(x =>
        `<option value="${x.id}" ${swFilter !== '' && parseInt(swFilter, 10) === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('');

    const rows = d.list.map(v => `
        <tr>
            ${rowCheckBox(v.id)}
            <td>${v.id}</td>
            <td>${tag(esc(v.software_name || '软件#' + v.software_id), 'purple')}</td>
            <td><b>${esc(v.version)}</b></td>
            <td>${tag(v.channel, v.channel === 'beta' ? 'yellow' : 'blue')}</td>
            <td>${v.force_update ? tag('强制', 'red') : tag('可选', 'gray')}</td>
            <td>${v.status === 1 ? tag('已发布', 'green') : tag('已下架', 'gray')}</td>
            <td>${esc(v.file_size_text)}</td>
            <td class="mono" style="font-size:12px">${esc(v.created_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${v.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${v.id}">${v.status === 1 ? '下架' : '发布'}</button>
                <button class="btn danger sm" data-act="del" data-id="${v.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>版本列表</h3>
            <div class="acts">
                <div class="toolbar" style="padding:0;border:0;background:transparent">
                    <select id="vSwFilter">
                        <option value="">全部软件</option>
                        ${swOpts}
                    </select>
                </div>
                <span id="vBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="vBulkOp">
                        <option value="publish">批量发布</option>
                        <option value="unpublish">批量下架</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="vBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="vNew">+ 发布版本</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>软件</th><th>版本号</th><th>渠道</th><th>更新策略</th><th>状态</th>
                    <th>包大小</th><th>发布时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-arrow-up-circle"></i>', '暂无版本')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>说明</h3></div>
        <div class="card-body" style="font-size:13px;color:var(--text-sub);line-height:1.9">
            · 客户端调用 <code>version</code> 接口时返回<b>该软件</b>同渠道下 ID 最大的已发布版本<br>
            · 客户端版本低于「软件管理」里该软件的最低版本时，会被强制要求更新<br>
            · 「强制更新」开关会让该软件所有低版本客户端必须升级后才能使用<br>
            · 文件哈希用于客户端校验安装包完整性（SHA256）
        </div>
    </div>`;

    document.getElementById('vNew').addEventListener('click', () => versionEdit(null));

    // 软件归属筛选
    document.getElementById('vSwFilter').addEventListener('change', e => {
        swFilter = e.target.value;
        render();
    });

    // 批量选择：发布 / 下架 / 删除（下拉 + 执行）
    sel = createSelection({ root: c, allIds: d.list.map(v => v.id), onChange: ids => {
        const box = document.getElementById('vBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});
    document.getElementById('vBulkRun').addEventListener('click', () => {
        const ids = sel ? sel.ids() : [];
        if (!ids.length) return toast('请先选择版本', 'warn');
        const op = document.getElementById('vBulkOp').value;
        if (op === 'publish' || op === 'unpublish') {
            const label = op === 'publish' ? '发布' : '下架';
            confirmBox(`批量${label}`, `将对已选的 ${ids.length} 个版本执行「${label}」，确定继续？`,
                async () => {
                    const res = await api('version_batch', { op, ids });
                    if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
                });
        } else {
            confirmBox('批量删除版本', `将永久删除已选的 ${ids.length} 个版本记录，确定继续？`,
                async () => {
                    const res = await api('version_batch', { op: 'delete', ids });
                    if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
                }, true);
        }
    });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            if (b.dataset.act === 'edit') versionEdit(item);
            else if (b.dataset.act === 'del') versionDel(id);
            else if (b.dataset.act === 'toggle') versionToggle(item);
        });
    });
}

function versionEdit(v) {
    v = v || {};
    let swOptions = '';
    try {
        // 同步拉软件选项（software_list 为只读接口，缓存到模块级）
        if (!swCache) {
            api('software_list', {}, true).then(r => {
                if (r.code === 0) swCache = r.data.options || [];
            });
        }
        swOptions = (swCache || []).map(x =>
            `<option value="${x.id}" ${v.software_id === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
    } catch (e) { /* 忽略 */ }
    const body = `
    <div class="row2">
        <div class="field"><label>所属软件 *</label><select id="vSw">${swOptions || '<option value="1">默认软件</option>'}</select></div>
        <div class="field"><label>版本号 *</label><input id="vVer" value="${esc(v.version || '')}" placeholder="如 1.2.0"></div>
        <div class="field"><label>渠道</label>
            <select id="vChannel">
                <option value="stable" ${v.channel !== 'beta' ? 'selected' : ''}>stable（正式）</option>
                <option value="beta" ${v.channel === 'beta' ? 'selected' : ''}>beta（测试）</option>
            </select>
        </div>
    </div>
    <div class="field"><label>下载地址</label><input id="vUrl" value="${esc(v.download_url || '')}" placeholder="https://..."></div>
    <div class="row2">
        <div class="field"><label>文件哈希 (SHA256)</label><input id="vHash" value="${esc(v.file_hash || '')}"></div>
        <div class="field"><label>文件大小 (字节)</label><input id="vSize" type="number" value="${v.file_size || 0}"></div>
    </div>
    <div class="field"><label>更新说明</label><textarea id="vLog">${esc(v.changelog || '')}</textarea></div>
    <div class="row2">
        <div class="field"><label>更新策略</label>
            <select id="vForce">
                <option value="0" ${!v.force_update ? 'selected' : ''}>可选更新</option>
                <option value="1" ${v.force_update ? 'selected' : ''}>强制更新</option>
            </select>
        </div>
        <div class="field"><label>状态</label>
            <select id="vStatus">
                <option value="1" ${v.status != 0 ? 'selected' : ''}>发布</option>
                <option value="0" ${v.status == 0 ? 'selected' : ''}>下架</option>
            </select>
        </div>
    </div>`;

    openModal(v.id ? '编辑版本' : '发布版本', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: v.id || 0,
                software_id: parseInt(document.getElementById('vSw').value, 10) || 1,
                version: document.getElementById('vVer').value.trim(),
                channel: document.getElementById('vChannel').value,
                download_url: document.getElementById('vUrl').value.trim(),
                file_hash: document.getElementById('vHash').value.trim(),
                file_size: parseInt(document.getElementById('vSize').value, 10) || 0,
                changelog: document.getElementById('vLog').value,
                force_update: parseInt(document.getElementById('vForce').value, 10),
                status: parseInt(document.getElementById('vStatus').value, 10),
            };
            const res = await api('version_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');
}

function versionDel(id) {
    confirmBox('删除版本', '确定删除该版本记录？', async () => {
        const res = await api('version_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}

async function versionToggle(v) {
    const res = await api('version_save', {
        id: v.id, software_id: v.software_id, version: v.version, channel: v.channel,
        download_url: v.download_url, file_hash: v.file_hash, file_size: v.file_size,
        changelog: v.changelog, force_update: v.force_update,
        status: v.status === 1 ? 0 : 1,
    });
    if (res.code === 0) { toast('已切换状态'); render(); }
}
