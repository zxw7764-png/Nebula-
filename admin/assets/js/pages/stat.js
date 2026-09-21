import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';

register('stat_overview', render);

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('stat_overview', {});
    if (res.code !== 0) return;
    const d = res.data;

    const epRows = d.by_endpoint.map(e => `
        <tr>
            <td><b>${esc(e.endpoint)}</b></td>
            <td>${e.calls}</td>
            <td>${e.fails > 0 ? `<span style="color:#ef4444">${e.fails}</span>` : 0}</td>
            <td>${e.calls > 0 ? (e.fails / e.calls * 100).toFixed(2) + '%' : '-'}</td>
            <td>${e.avg_ms || 0} ms</td>
        </tr>`).join('');

    const ipRows = d.top_ip.map(x => `
        <tr>
            <td class="mono">${esc(x.ip)}</td>
            <td>${x.calls}</td>
            <td>${x.fails}</td>
            <td>${x.calls > 0 ? (x.fails / x.calls * 100).toFixed(1) + '%' : '-'}</td>
        </tr>`).join('');

    const abRows = d.abnormal_ip.map(x => `
        <tr>
            <td class="mono">${esc(x.ip)}</td>
            <td>${x.calls}</td>
            <td style="color:#ef4444">${x.fails}</td>
            <td>${tag(x.fail_rate + '%', x.fail_rate > 50 ? 'red' : 'yellow')}</td>
        </tr>`).join('');

    let dateBars = '';
    if (d.by_date.length) {
        const maxV = Math.max(...d.by_date.map(x => +x.calls), 1);
        dateBars = d.by_date.map(x => `
            <div class="bar-row">
                <span class="name">${esc(x.stat_date)}</span>
                <span class="bar"><i style="width:${(+x.calls / maxV * 100).toFixed(1)}%"></i></span>
                <span class="num">${x.calls}</span>
            </div>`).join('');
    }

    c.innerHTML = `
    <div class="stats">
        <div class="stat c1">
            <div class="label">总调用次数</div>
            <div class="value">${d.summary.calls}</div>
            <div class="extra">${esc(d.range.start)} ~ ${esc(d.range.end)}</div>
        </div>
        <div class="stat c4">
            <div class="label">失败次数</div>
            <div class="value">${d.summary.fails}</div>
            <div class="extra">失败率 ${d.summary.fail_rate}%</div>
        </div>
        <div class="stat c2">
            <div class="label">平均耗时</div>
            <div class="value">${d.summary.avg_ms}</div>
            <div class="extra">毫秒</div>
        </div>
        <div class="stat c3">
            <div class="label">接口数</div>
            <div class="value">${d.by_endpoint.length}</div>
            <div class="extra">活跃来源 IP ${d.top_ip.length}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>每日调用量</h3></div>
        <div class="card-body">${dateBars || empty('<i class="bi bi-bar-chart-line"></i>', '暂无数据')}</div>
    </div>

    <div class="card">
        <div class="card-head"><h3>接口明细</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>接口</th><th>调用次数</th><th>失败</th><th>失败率</th><th>平均耗时</th></tr></thead>
                <tbody>${epRows || '<tr><td colspan="5">' + empty('<i class="bi bi-bar-chart-line"></i>', '暂无数据') + '</td></tr>'}</tbody>
            </table>
        </div>
    </div>

    <div class="row2">
        <div class="card" style="margin:0">
            <div class="card-head"><h3>调用量 TOP IP</h3></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>IP</th><th>调用</th><th>失败</th><th>失败率</th></tr></thead>
                    <tbody>${ipRows || '<tr><td colspan="4">' + empty('<i class="bi bi-bar-chart-line"></i>', '暂无数据') + '</td></tr>'}</tbody>
                </table>
            </div>
        </div>
        <div class="card" style="margin:0">
            <div class="card-head"><h3>异常 IP 告警</h3></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>IP</th><th>调用</th><th>失败</th><th>失败率</th></tr></thead>
                    <tbody>${abRows || '<tr><td colspan="4">' + empty('<i class="bi bi-check-circle"></i>', '未发现异常') + '</td></tr>'}</tbody>
                </table>
            </div>
        </div>
    </div>`;
}
