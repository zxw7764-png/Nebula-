/* ======================================================================
   pages/screenshot.js — 官网客户端截图管理
   截图展示在官网首页「界面预览」区块。
   注意：这里是「填图片地址」，不做文件上传（避免引入上传目录与权限问题）。
   图片可放在自己的图床 / OSS / 服务器静态目录，把直链填进来即可。
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';
import { bindImageUpload } from '../core/uploader.js';

register('screenshot_list', render);

let swSt = ''; // 软件筛选：''=全部归属，'0'=全部软件通用，N=具体软件
let sel = null;
let lastList = [];   // 最近一次渲染的数据列表（供批量操作取完整对象）

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('screenshot_list', { page: 1, size: 100, sw: swSt });
    if (res.code !== 0) return;
    const d = res.data;
    const softwares = d.softwares || [];
    lastList = d.list || [];

    const swOpts = softwares.map(s =>
        `<option value="${s.id}" ${swSt === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const rows = d.list.map(s => `
        <tr>
            ${rowCheckBox(s.id)}
            <td>${s.id}</td>
            <td>
                <div class="shot-thumb">
                    ${s.url_valid
                        ? `<img src="${esc(s.url)}" alt="${esc(s.title)}" loading="lazy" referrerpolicy="no-referrer">`
                        : `<span class="thumb-bad">地址无效</span>`}
                </div>
            </td>
            <td><b>${esc(s.title || '-')}</b></td>
            <td>${s.software_id === 0
                ? tag('全部软件', 'blue')
                : `<span class="tag gray">${esc(s.software_name)}</span>`}</td>
            <td class="mono sub" style="max-width:280px;word-break:break-all">${esc(s.url)}</td>
            <td>${s.sort}</td>
            <td>${s.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td class="mono" style="font-size:12px">${esc(s.created_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${s.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${s.id}">${s.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${s.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>客户端截图</h3>
            <div class="acts">
                <span id="sBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="sBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="sBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <select id="sSw">
                        <option value="">全部归属</option>
                        ${swOpts}
                    </select>
                    <button class="btn" id="sSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="sNew">+ 添加截图</button>
            </div>
        </div>
        <div class="card-body"><div class="hint">
            填写图片直链（必须以 <b>http://</b> 或 <b>https://</b> 开头），官网首页会以卡片形式展示。
            建议使用横图，宽度 1200px 以上效果最佳。归属选「全部软件」所有官网都显示，选具体软件只在该软件官网显示。
        </div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>预览</th><th>标题</th><th>归属软件</th><th>图片地址</th>
                    <th>排序</th><th>状态</th><th>添加时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-image"></i>', '暂无截图')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('sSw').addEventListener('change', doSearch);
    document.getElementById('sSearch').addEventListener('click', doSearch);
    document.getElementById('sNew').addEventListener('click', () => shotEdit(null, softwares));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'edit') shotEdit(item, softwares);
            else if (act === 'toggle') shotToggle(id);
            else if (act === 'del') shotDel(id);
        });
    });

    // 批量选择
    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('sBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    // 批量操作：下拉选择 + 执行
    const bulkRun = document.getElementById('sBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('sBulkOp').value;
        doBulk(op);
    });
}

/* ------------------------- 批量操作 ------------------------- */
async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择截图', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 张截图？删除后官网首页不再展示。`, async () => {
            let n = 0;
            for (const id of ids) {
                const res = await api('screenshot_save', { op: 'delete', id });
                if (res.code === 0) n++;
            }
            toast(`已删除 ${n} 张截图`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = lastList.filter(x => ids.includes(x.id) && x.status !== targetStatus);
    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 张截图吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('screenshot_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 张截图`);
        if (sel) sel.clear();
        render();
    });
}

function doSearch() {
    swSt = document.getElementById('sSw').value;
    render();
}

function shotEdit(s, softwares) {
    s = s || {};
    const curSw = Number(s.software_id) || 0;
    const swOptions = (softwares || []).map(x =>
        `<option value="${x.id}" ${curSw === Number(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');

    const body = `
    <div class="field"><label>图片地址 *（链接或上传）</label>
        <div class="logo-row">
            <input id="sUrl" value="${esc(s.url || '')}" placeholder="https://example.com/shot.png 或上传">
            <button type="button" class="btn ghost sm" id="sUrlUpload">上传图片</button>
        </div>
    </div>
    <div class="field" id="sPreviewWrap" ${s.url_valid ? '' : 'hidden'}>
        <label>预览</label>
        <div class="shot-preview"><img id="sPreview" src="${esc(s.url || '')}" alt="预览" referrerpolicy="no-referrer"></div>
    </div>
    <div class="row2">
        <div class="field"><label>标题（选填）</label><input id="sTitle" value="${esc(s.title || '')}" placeholder="例如：主界面"></div>
        <div class="field"><label>排序（越大越靠前）</label><input id="sSort" type="number" value="${s.sort || 0}"></div>
    </div>
    <div class="row2">
        <div class="field"><label>归属软件</label>
            <select id="sSoftware">
                <option value="0" ${curSw === 0 ? 'selected' : ''}>全部软件（通用）</option>
                ${swOptions}
            </select>
            <div class="hint">选「全部软件」所有官网都显示；选具体软件只在该软件官网显示</div>
        </div>
        <div class="field"><label>状态</label>
            <select id="sStatus">
                <option value="1" ${s.status != 0 ? 'selected' : ''}>启用</option>
                <option value="0" ${s.status == 0 ? 'selected' : ''}>停用</option>
            </select>
        </div>
    </div>
    <div class="field"><div class="hint">提示：填完地址后下方会自动尝试加载预览，若显示裂图请检查直链是否可公开访问。</div></div>`;

    openModal(s.id ? '编辑截图' : '添加截图', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: s.id || 0,
                url: document.getElementById('sUrl').value.trim(),
                title: document.getElementById('sTitle').value.trim(),
                sort: parseInt(document.getElementById('sSort').value, 10) || 0,
                status: parseInt(document.getElementById('sStatus').value, 10),
                software_id: parseInt(document.getElementById('sSoftware').value, 10) || 0,
            };
            if (!/^https?:\/\//i.test(payload.url) && !payload.url.startsWith('/')) {
                return toast('图片地址需为 http(s) 开头或上传后的站内路径', 'warn');
            }
            const res = await api('screenshot_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');

    bindImageUpload('sUrlUpload', 'sUrl', {
        onDone: () => {
            const evt = document.getElementById('sUrl');
            if (evt) evt.dispatchEvent(new Event('input'));
        },
    });

    // 地址变化时实时预览
    const urlEl = document.getElementById('sUrl');
    const wrap = document.getElementById('sPreviewWrap');
    const img = document.getElementById('sPreview');
    let timer = null;
    urlEl.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const v = urlEl.value.trim();
            if (/^https?:\/\//i.test(v)) {
                img.src = v;
                wrap.hidden = false;
            } else {
                wrap.hidden = true;
            }
        }, 600);
    });
    img.addEventListener('error', () => {
        img.style.opacity = '.35';
    });
    img.addEventListener('load', () => {
        img.style.opacity = '1';
    });
}

async function shotToggle(id) {
    const res = await api('screenshot_save', { op: 'toggle', id });
    if (res.code === 0) { toast(res.msg || '已切换'); render(); }
}

function shotDel(id) {
    confirmBox('删除截图', '确定删除该截图？删除后官网首页不再展示。', async () => {
        const res = await api('screenshot_save', { op: 'delete', id });
        if (res.code === 0) { toast('已删除'); render(); }
    }, true);
}
