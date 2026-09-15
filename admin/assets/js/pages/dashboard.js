/* ======================================================================
   pages/dashboard.js — 数据概览
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';

register('dashboard', render);

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('dashboard');
    if (res.code !== 0) return;
    const d = res.data;
    const s = d.stat;

    let trendHtml = '';
    if (d.trend && d.trend.length) {
        const maxV = Math.max(...d.trend.map(t => t.api), 1);
        trendHtml = d.trend.map(t => `
            <div class="bar-row">
                <span class="name">${esc(t.date.slice(5))}</span>
                <span class="bar"><i style="width:${(t.api / maxV * 100).toFixed(1)}%"></i></span>
                <span class="num">${t.api}</span>
            </div>`).join('');
    }

    const logHtml = (d.recent_logs || []).map(l => `
        <tr>
            <td class="mono">${esc(l.time_text)}</td>
            <td>${esc(l.username || '-')}</td>
            <td>${esc(l.action)}</td>
            <td>${l.result == 1 ? tag('成功', 'green') : tag('失败', 'red')}</td>
            <td style="color:#6b7280">${esc(l.message || '')}</td>
            <td class="mono">${esc(l.ip || '')}</td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="stats">
        <div class="stat c1">
            <div class="label">用户总数</div>
            <div class="value">${s.user_total}</div>
            <div class="extra">今日新增 ${s.user_today}</div>
        </div>
        <div class="stat c2">
            <div class="label">在线会话</div>
            <div class="value">${s.online_count}</div>
            <div class="extra">设备 ${s.device_total} 台</div>
        </div>
        <div class="stat c3">
            <div class="label">未使用卡密</div>
            <div class="value">${s.card_unused}</div>
            <div class="extra">已用 ${s.card_used} / 总 ${s.card_total}</div>
        </div>
        <div class="stat c4">
            <div class="label">今日接口调用</div>
            <div class="value">${s.api_today}</div>
            <div class="extra">失败 ${s.api_fail_today} 次</div>
        </div>
    </div>

    <div class="card" style="margin-top:18px">
        <div class="card-head"><h3>近 7 日调用量</h3></div>
        <div class="card-body">${trendHtml || empty('<i class="bi bi-bar-chart-line"></i>', '暂无数据')}</div>
    </div>

    <div class="card" style="margin-top:18px">
        <div class="card-head"><h3>最近日志</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>结果</th><th>说明</th><th>IP</th></tr></thead>
                <tbody>${logHtml || '<tr><td colspan="6">' + empty('<i class="bi bi-journal-text"></i>', '暂无日志') + '</td></tr>'}</tbody>
            </table>
        </div>
    </div>`;
}
