import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast } from '../core/ui.js';

const fmtTime = t => t ? new Date(t * 1000).toLocaleString('zh-CN', { hour12: false }) : '-';

function fileOps(rel) {
    return `
        <button class="btn ghost sm" data-view="${esc(rel)}">查看</button>
        <button class="btn danger sm" data-del="${esc(rel)}">删除</button>`;
}

function selBox(rel) {
    return `<input type="checkbox" class="fSel" data-sel="${esc(rel)}" title="选择该文件">`;
}

function getSelected(c) {
    return [...c.querySelectorAll('input.fSel[data-sel]:checked')].map(x => x.dataset.sel);
}

function batchDeleteModal(c, files, onDone) {
    if (!files.length) { toast('请先勾选要删除的文件', 'warn'); return; }
    const listHtml = files.slice(0, 30).map(f =>
        `<div class="mono" style="font-size:12px;padding:3px 0">· ${esc(f)}</div>`).join('')
        + (files.length > 30 ? `<div class="sub">…等共 ${files.length} 个</div>` : '');
    openModal(`批量删除 ${files.length} 个文件`, `
        <div class="hint" style="margin-bottom:8px">以下文件将被永久删除，<b style="color:var(--danger)">此操作不可恢复</b>：</div>
        <div style="max-height:160px;overflow:auto;background:var(--th-bg);border-radius:8px;padding:8px 12px;margin-bottom:12px">${listHtml}</div>
        <div class="field"><label>请输入管理密码确认</label>
            <input id="fdBatchPass" type="password" placeholder="管理密码"></div>`, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '确认批量删除', cls: 'danger', act: async () => {
            const r2 = await api('file_delete', { files, password: document.getElementById('fdBatchPass').value });
            if (r2.code !== 0) return;
            const d = r2.data || {};
            if (d.failed && d.failed.length) {
                toast(`已删除 ${d.deleted.length} 个，${d.failed.length} 个失败（受保护或不可操作）`, 'warn');
            } else {
                toast(r2.msg || `已删除 ${files.length} 个文件`, 'ok');
            }
            closeModal();
            onDone && onDone();
        } },
    ]);
}

function bindFileOps(c, onDone) {
    c.querySelectorAll('button[data-view]').forEach(b => {
        b.addEventListener('click', async () => {
            const res = await api('file_view', { file: b.dataset.view });
            if (res.code !== 0) return;
            const d = res.data;
            openModal('文件内容 · ' + d.file, `
                <div class="hint" style="margin-bottom:8px">
                    大小 ${d.size} 字节 · 修改于 ${fmtTime(d.mtime)}（超过 64KB 截断）
                </div>
                <pre class="code-box" style="max-height:420px;white-space:pre-wrap;word-break:break-all">${esc(d.content)}</pre>`, [
                { text: '关闭', cls: 'ghost', act: closeModal },
            ], 'wide');
        });
    });
    c.querySelectorAll('button[data-del]').forEach(b => {
        b.addEventListener('click', () => {
            const rel = b.dataset.del;
            openModal('删除文件 · ' + rel, `
                <div class="field"><label>确认删除 <b style="color:var(--danger)">${esc(rel)}</b>？此操作不可恢复。</label>
                    <input id="fdPass" type="password" placeholder="请输入管理密码确认"></div>`, [
                { text: '取消', cls: 'ghost', act: closeModal },
                { text: '确认删除', cls: 'danger', act: async () => {
                    const r2 = await api('file_delete', { file: rel, password: document.getElementById('fdPass').value });
                    if (r2.code !== 0) return;
                    toast(r2.msg || '已删除', 'ok');
                    closeModal();
                    onDone && onDone();
                } },
            ]);
        });
    });

    const refreshCount = () => {
        const btn = c.querySelector('button[data-batchdel]');
        if (!btn) return;
        const n = getSelected(c).length;
        btn.innerHTML = `<i class="bi bi-trash3"></i> 删除选中${n ? `（${n}）` : ''}`;
    };
    const bindBatch = () => {
        const btn = c.querySelector('button[data-batchdel]');
        if (btn && !btn.dataset.bound) {
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => batchDeleteModal(c, getSelected(c), onDone));
        }
        c.querySelectorAll('input.fSelAll').forEach(all => {
            all.addEventListener('change', () => {
                c.querySelectorAll(`input.fSel[data-sel]`).forEach(x => { x.checked = all.checked; });
                refreshCount();
            });
        });
        c.querySelectorAll('input.fSel[data-sel]').forEach(x =>
            x.addEventListener('change', refreshCount));
    };
    const ensureBatchBtn = () => {
        const btn = c.querySelector('button[data-batchdel]');
        if (!btn) {
            const tb = c.querySelector('.toolbar');
            if (tb) tb.insertAdjacentHTML('beforeend',
                `<button class="btn danger" data-batchdel><i class="bi bi-trash3"></i> 删除选中</button>`);
        }
        const target = c.querySelector('button[data-batchdel]');
        if (target) target.style.display = c.querySelector('input.fSel[data-sel]') ? '' : 'none';
    };
    ensureBatchBtn();
    bindBatch();
}

function fileTable(list, { withSel = true } = {}) {
    return `
    <div class="table-wrap"><table>
        <thead><tr>
            ${withSel ? `<th style="width:34px"><input type="checkbox" class="fSelAll" title="全选"></th>` : ''}
            <th>文件</th><th>大小</th><th>修改时间</th><th>操作</th>
        </tr></thead>
        <tbody>${list.map(x => `
            <tr>
                ${withSel ? `<td>${selBox(x.file)}</td>` : ''}
                <td class="mono" style="font-size:12px">${esc(x.file)}</td>
                <td>${x.size} B</td>
                <td class="mono" style="font-size:12px">${fmtTime(x.mtime)}</td>
                <td style="white-space:nowrap">${fileOps(x.file)}</td>
            </tr>`).join('') || `<tr><td colspan="5">${empty('bi-file-earmark-x', '无')}</td></tr>`}</tbody>
    </table></div>`;
}

const batchBtn = `
    <button class="btn danger" data-batchdel style="display:none"><i class="bi bi-trash3"></i> 删除选中</button>`;

register('files_integrity', async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const cached = window.__filesIntegrity || null;

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>完整性校验</h3>
            <div class="toolbar">
                <button class="btn" id="fiCheck"><i class="bi bi-shield-check"></i> 完整性校验</button>
                <button class="btn ghost" id="fiBuild"><i class="bi bi-database-add"></i> 生成基准</button>
                ${batchBtn}
                <span class="hint" id="fiMeta">${cached ? `基准：${fmtTime(cached.built_at)} · 共 ${cached.total} 个文件` : '尚未生成基准，首次使用请先「生成基准」'}</span>
            </div>
        </div>
        <div id="fiResult">${cached ? diffHtml(cached) : `<div class="card-body">${empty('<i class="bi bi-shield"></i>', '生成基准后即可校验代码文件是否被改动')}</div>`}</div>
    </div>`;

    function diffHtml(d) {
        const sec = (title, list, color, sub) => `
            <div class="card-body" style="padding-top:12px">
                <h3 style="margin-bottom:10px">${tag(title, color)} <span class="sub">共 ${list.length} 条 · ${sub}</span></h3>
                ${fileTable(list)}
            </div>`;
        return `
            <div class="card-head" style="border-bottom:none;padding-bottom:0">
                <div class="toolbar">
                    ${tag(`改动 ${d.modified.length}`, d.modified.length ? 'red' : 'green')}
                    ${tag(`缺失 ${d.missing.length}`, d.missing.length ? 'yellow' : 'green')}
                    ${tag(`新增 ${d.added.length}`, d.added.length ? 'blue' : 'green')}
                </div>
            </div>
            ${d.modified.length ? sec('被改动的文件（与基准哈希不一致）', d.modified, 'red', '可勾选批量删除') : ''}
            ${d.added.length ? sec('新增的代码文件（基准中不存在）', d.added, 'blue', '可勾选批量删除') : ''}
            ${d.missing.length ? sec('基准中有但已不存在的文件', d.missing, 'yellow', '文件已不在磁盘，无需删除', false) : ''}
            ${(!d.modified.length && !d.added.length && !d.missing.length)
                ? `<div class="card-body">${empty('<i class="bi bi-patch-check"></i>', '全部文件与基准一致，未被改动')}</div>` : ''}`;
    }

    const resBox = document.getElementById('fiResult');
    const metaBox = document.getElementById('fiMeta');

    document.getElementById('fiBuild').addEventListener('click', () => {
        confirmBox('生成完整性基准', '将以当前所有代码文件的哈希建立基准快照。若现有文件已被篡改，请先清理再生成。继续？', async () => {
            const res = await api('files_integrity', { op: 'build' });
            if (res.code !== 0) return;
            toast(res.msg, 'ok');
            window.__filesIntegrity = { built_at: res.data.built_at, total: res.data.count, modified: [], missing: [], added: [] };
            render();
        });
    });
    document.getElementById('fiCheck').addEventListener('click', async () => {
        const res = await api('files_integrity', { op: 'check' });
        if (res.code !== 0) return;
        if (res.data.no_baseline) {
            toast('尚未生成基准，请先点「生成基准」', 'warn');
            return;
        }
        window.__filesIntegrity = res.data;
        resBox.innerHTML = diffHtml(res.data);
        metaBox.textContent = `基准：${fmtTime(res.data.built_at)} · 共 ${res.data.total} 个文件`;
        bindFileOps(c, render);
        toast('校验完成', 'ok');
    });

    bindFileOps(c, render);
});

register('files_scan', async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>挂马扫描</h3>
            <div class="toolbar">
                <button class="btn" id="fsGo"><i class="bi bi-binoculars"></i> 开始扫描</button>
                <button class="btn danger" data-batchdel id="fsBatch" style="display:none"><i class="bi bi-trash3"></i> 删除选中</button>
                <span class="hint">特征规则评分 + 运行时目录可执行检测 + 最近改动文件，约需数秒</span>
            </div>
        </div>
        <div id="fsResult"><div class="card-body">${empty('<i class="bi bi-binoculars"></i>', '点击「开始扫描」检测潜在 Webshell 与挂马')}</div></div>
    </div>`;

    const resBox = document.getElementById('fsResult');
    const batchBtnEl = document.getElementById('fsBatch');

    document.getElementById('fsGo').addEventListener('click', async () => {
        const btn = document.getElementById('fsGo');
        btn.disabled = true;
        btn.textContent = '扫描中...';
        const res = await api('files_scan', {});
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-binoculars"></i> 开始扫描';
        if (res.code !== 0) return;
        const d = res.data;
        resBox.innerHTML = `
            <div class="card-head" style="border-bottom:none;padding-bottom:0">
                <div class="toolbar">
                    ${tag(`可疑文件 ${d.hits.length}`, d.hits.length ? 'red' : 'green')}
                    ${tag(`运行时目录可执行 ${d.uploads_php.length}`, d.uploads_php.length ? 'red' : 'green')}
                    ${tag(`近 7 天改动 ${d.recent.length}`, d.recent.length ? 'yellow' : 'green')}
                </div>
            </div>
            ${d.uploads_php.length ? uploadPhpHtml(d.uploads_php) : ''}
            ${d.hits.length ? hitsHtml(d.hits) : (!d.uploads_php.length ? `<div class="card-body">${empty('<i class="bi bi-patch-check"></i>', '未发现可疑特征文件')}</div>` : '')}
            ${d.recent.length ? recentHtml(d.recent) : ''}`;
        batchBtnEl.style.display = '';
        bindFileOps(c, render);
        toast('扫描完成', 'ok');
    });

    function uploadPhpHtml(list) {
        return `
        <div class="card-body" style="padding-top:12px">
            <h3 style="margin-bottom:10px">${tag('最高危：运行时目录出现 PHP 文件', 'red')} <span class="sub">可直接勾选批量删除</span></h3>
            ${fileTable(list)}
        </div>`;
    }

    function hitsHtml(list) {
        return `
        <div class="card-body" style="padding-top:12px">
            <h3 style="margin-bottom:10px">${tag('可疑特征文件（按危险分排序）', 'red')} <span class="sub">命中规则仅供排查参考，也可能是合法代码，请查看内容确认</span></h3>
            ${list.map(x => `
            <div style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <span style="display:flex;align-items:center;gap:8px">
                        ${selBox(x.file)}
                        <span class="mono" style="font-size:12.5px;font-weight:600">${esc(x.file)}</span>
                    </span>
                    <span>${tag('危险分 ' + x.score, x.score >= 20 ? 'red' : 'yellow')}
                        <span class="sub" style="margin-left:6px">${x.size} B · ${fmtTime(x.mtime)}</span></span>
                </div>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                    ${x.matches.map(m => `<span style="font-size:12px;border:1px solid var(--border);border-radius:20px;padding:2px 10px">${esc(m.rule)}</span>`).join('')}
                </div>
                <div style="margin-top:8px;display:grid;gap:4px">
                    ${x.matches.map(m => `
                        <div style="display:flex;gap:8px;align-items:flex-start">
                            <span class="mono" style="flex:none;font-size:11px;background:var(--th-bg);border-radius:6px;padding:4px 8px;color:var(--sub)">第 ${m.line} 行</span>
                            <code class="mono" style="flex:1;font-size:12px;background:var(--th-bg);border-radius:6px;padding:4px 8px;word-break:break-all;white-space:pre-wrap">${esc(m.snippet)}</code>
                        </div>`).join('')}
                </div>
                <div style="margin-top:8px">${fileOps(x.file)}</div>
            </div>`).join('')}
        </div>`;
    }

    function recentHtml(list) {
        return `
        <div class="card-body" style="padding-top:12px">
            <h3 style="margin-bottom:10px">${tag('最近 7 天被改动的文件', 'yellow')} <span class="sub">非自己操作出现的改动需警惕，可勾选批量删除</span></h3>
            ${fileTable(list)}
        </div>`;
    }
});
