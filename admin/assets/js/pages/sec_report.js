import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag } from '../core/util.js';

register('sec_report', render);

let cache = null;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    await load(c, false);
}

async function load(c, force) {
    const res = await api('sec_report', { run: force ? 1 : 0 });
    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }
    cache = res.data;
    const r = cache.report;
    const hist = cache.history || [];

    const findingRows = (r.findings || []).map(f => `
        <tr>
            <td style="width:80px">${f.level === 'warn' ? tag('警告', 'danger') : tag('提示', 'purple')}</td>
            <td style="width:220px"><b>${esc(f.name)}</b></td>
            <td class="mono" style="font-size:12px;white-space:pre-wrap">${esc(
                (f.items || []).slice(0, 8).map(it =>
                    Object.entries(it).filter(([k]) => k !== 'level').map(([k, v]) => k + '=' + v).join('  ')
                ).join('\n') || '（无明细）')
            }</td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3><i class="bi bi-heart-pulse"></i> 安全巡检报告</h3>
            <div class="toolbar">
                ${tag(r.summary, r.level === 'warn' ? 'danger' : 'ok')}
                <span style="color:#6b7280;font-size:12px">巡检时间 ${esc(r.date)}</span>
                <button class="btn sm" id="secRefresh"><i class="bi bi-arrow-clockwise"></i> 立即巡检</button>
            </div>
        </div>
        <div style="padding:12px 16px;color:#6b7280;font-size:13px;line-height:1.9">
            每日由计划任务自动巡检一次（有异常时写入日志并保留报告文件），本页面打开时也会实时巡检一次。
            覆盖四类风险：同 IP 暴力破解嫌疑、同账号撞库嫌疑、密钥重置操作追踪、代理商卡密生成突增。
        </div>
        ${r.findings && r.findings.length ? `
        <div class="table-wrap">
            <table>
                <thead><tr><th>级别</th><th>异常类型</th><th>明细（近 24 小时，最多 8 条）</th></tr></thead>
                <tbody>${findingRows}</tbody>
            </table>
        </div>` : `
        <div style="padding:16px;color:#22c55e;font-size:14px">
            <i class="bi bi-check-circle"></i> 近 24 小时各巡检项均无异常。
        </div>`}
    </div>

    ${hist.length ? `
    <div class="card" style="margin-top:16px">
        <div class="card-head"><h3>历史报告（最近 ${hist.length} 个文件）</h3></div>
        ${hist.map(h => `
        <details style="padding:10px 16px;border-bottom:1px solid rgba(148,163,184,.12)">
            <summary style="cursor:pointer;font-size:13px"><b>${esc(h.file)}</b> <span style="color:#6b7280">${esc(h.time)}</span></summary>
            <pre class="mono" style="font-size:12px;background:#0b0f19;color:#9ca3af;padding:12px;border-radius:8px;overflow:auto;white-space:pre-wrap;margin-top:8px">${esc(h.text)}</pre>
        </details>`).join('')}
    </div>` : ''}
    `;

    const btn = document.getElementById('secRefresh');
    if (btn) btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> 巡检中…';
        c.innerHTML = loading();
        await load(c, true);
    });
}
