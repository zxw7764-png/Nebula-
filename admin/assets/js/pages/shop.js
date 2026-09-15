/* ======================================================================
   pages/shop.js — 发卡订单管理（官网 /shop/ 的订单）
   统计 + 筛选列表 + 单条操作（确认发卡/手动补发/关闭）
   + 批量操作（确认发卡 / 批量关闭 / 批量删除）
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast,
         createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

register('shop_order_list', render);

let curStatus = '';
let curKw = '';
let sel = null;

const STATUS_TAG = {
    0: ['待支付', 'yellow'],
    1: ['已发卡', 'green'],
    2: ['已关闭', 'gray'],
    3: ['人工处理', 'red'],
};

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const params = { page: 1, size: 50 };
    if (curStatus !== '') params.status = curStatus;
    if (curKw !== '') params.kw = curKw;

    const res = await api('shop_order_list', params);
    if (res.code !== 0) return;
    const d = res.data;
    const st = d.stats || {};

    const rows = d.list.map(o => {
        const stTag = STATUS_TAG[o.status] || ['未知', 'gray'];
        return `
        <tr>
            ${rowCheckBox(o.id)}
            <td><code class="mono">${esc(o.order_no)}</code></td>
            <td><b>${esc(o.plan_name)}</b><div class="sub">${esc(o.spec_text)}</div></td>
            <td>${tag(o.software_name || '未知', 'gray')}</td>
            <td><b class="price-text">&yen;${esc(o.amount_text)}</b></td>
            <td>${o.pay_type === 1
                    ? tag(esc(o.pay_type_text || '易支付'), 'blue')
                    : tag('人工', 'yellow')}</td>
            <td>${tag(stTag[0], stTag[1])}</td>
            <td>${o.card_code
                    ? `<code class="mono">${esc(o.card_code)}</code>`
                    : `<span class="sub">${esc(o.remark || '-')}</span>`}</td>
            <td class="sub">${esc(o.contact || '-')}</td>
            <td class="sub">${esc(o.created_at)}</td>
            <td style="white-space:nowrap">
                ${o.status === 0 || o.status === 3 ? `
                    <button class="btn success sm" data-act="deliver" data-id="${o.id}">确认发卡</button>
                    <button class="btn ghost sm" data-act="manual" data-id="${o.id}">补发</button>
                    <button class="btn danger sm" data-act="close" data-id="${o.id}">关闭</button>` : ''}
                ${o.status === 1 ? `<span class="sub">${esc(o.delivered_at)} 发卡</span>` : ''}
                ${o.status === 2 ? `<span class="sub">已关闭</span>` : ''}
            </td>
        </tr>`;
    }).join('');

    c.innerHTML = `
    <div class="stats">
        <div class="stat"><div class="label">今日订单</div><div class="value">${st.today_count || 0}</div></div>
        <div class="stat"><div class="label">今日收款</div><div class="value">&yen;${Number((st.today_paid || 0) / 100).toFixed(2)}</div></div>
        <div class="stat"><div class="label">待支付</div><div class="value">${st.pending || 0}</div></div>
        <div class="stat"><div class="label">人工待处理</div><div class="value">${st.manual || 0}</div></div>
    </div>
    <div class="card">
            <div class="card-head">
                <h3>订单管理</h3>
                <div class="acts">
                    <span id="shopBulkBox" hidden class="bulk-inline" data-count="0">
                        <select id="shopBulkOp">
                            <option value="deliver">批量发卡</option>
                            <option value="close">批量关闭</option>
                            <option value="delete">批量删除</option>
                        </select>
                        <button class="btn" id="shopBulkRun">执行</button>
                    </span>
                    <div class="toolbar">
                        <select id="shopStatus">
                            <option value="">全部状态</option>
                            <option value="0" ${curStatus === '0' ? 'selected' : ''}>待支付</option>
                            <option value="1" ${curStatus === '1' ? 'selected' : ''}>已发卡</option>
                            <option value="2" ${curStatus === '2' ? 'selected' : ''}>已关闭</option>
                            <option value="3" ${curStatus === '3' ? 'selected' : ''}>人工待处理</option>
                        </select>
                        <input id="shopKw" placeholder="订单号 / 联系方式 / 流水号" value="${esc(curKw)}">
                        <button class="btn sm" id="shopSearch">查询</button>
                    </div>
                </div>
            </div>
        <div class="card-body"><div class="hint">
            「确认发卡」表示收款已核实，系统从官方直发卡库自动取一张匹配规格的卡交付；
            库存规格不齐时可用「补发」手工指定卡密。自动发卡的支付回调会直接发卡，无需人工操作。
        </div></div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>订单号</th><th>商品</th><th>所属软件</th><th>金额</th><th>支付</th><th>状态</th>
                    <th>卡密 / 备注</th><th>联系方式</th><th>下单时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="11">${empty('<i class="bi bi-cart-x"></i>', '暂无订单')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    // 批量选择
    sel = createSelection({
        root: c,
        allIds: d.list.map(x => x.id),
        onChange: (ids) => {
            const box = document.getElementById('shopBulkBox');
            if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
            c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
        },
    });

    // 筛选
    document.getElementById('shopSearch').addEventListener('click', () => {
        curStatus = document.getElementById('shopStatus').value;
        curKw = document.getElementById('shopKw').value.trim();
        render();
    });
    document.getElementById('shopKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') document.getElementById('shopSearch').click();
    });

    // 单条操作分发
    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const act = b.dataset.act;
        b.addEventListener('click', () => {
            if (act === 'deliver') orderDeliver(id);
            else if (act === 'manual') orderManual(id);
            else if (act === 'close') orderClose(id);
        });
    });

    // 批量操作：下拉选择 + 执行
    const bulkRun = document.getElementById('shopBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('shopBulkOp').value;
        doBulk(op);
    });
}

/** 确认发卡（自动取卡） */
function orderDeliver(id) {
    confirmBox('确认发卡', '确认已收到该订单的款项？确认后系统将自动取卡发货，此操作不可撤销。', async () => {
        const res = await api('shop_order_op', { op: 'deliver', id });
        if (res.code === 0) { toast('发卡成功'); render(); }
    }, true);
}

/** 手动补发：指定一张官方直发未使用卡密 */
function orderManual(id) {
    openModal('手动补发卡密', `
        <div class="field">
            <label>卡密 *</label>
            <input id="shopManualCode" placeholder="输入一张官方直发的未使用卡密" style="text-transform:uppercase">
            <div class="hint">将校验：卡存在且未使用、归属官方（非代理商）、未过期，且规格与订单商品完全一致（类型 / 卡面 / 设备上限 / 用户组）。</div>
        </div>
    `, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '补发', cls: 'success', act: async () => {
            const code = document.getElementById('shopManualCode').value.trim();
            if (!code) return toast('请输入卡密', 'warn');
            const res = await api('shop_order_op', { op: 'deliver_manual', id, code });
            if (res.code === 0) { toast('补发成功'); closeModal(); render(); }
        }},
    ]);
}

/** 关闭订单 */
function orderClose(id) {
    confirmBox('关闭订单', '确定关闭该订单？关闭后买家支付回调将被拒绝，请确认款项未到账或已退款。', async () => {
        const res = await api('shop_order_op', { op: 'close', id });
        if (res.code === 0) { toast('订单已关闭'); render(); }
    }, true);
}

/* ------------------------- 批量操作 ------------------------- */
function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择订单', 'warn');

    if (op === 'deliver') {
        confirmBox('批量确认发卡',
            `将对已选 ${ids.length} 单中「人工待处理」的订单确认收款并自动取卡发货；` +
            '待支付（款项未核实）、已发卡、已关闭的订单会自动跳过。继续？',
            async () => {
                const res = await api('shop_order_op', { op: 'batch_deliver', ids });
                if (res.code === 0) {
                    toast(res.msg, res.data && res.data.fail && res.data.fail.length ? 'warn' : 'ok');
                    if (sel) sel.clear();
                    render();
                    // 有失败单时逐条列出原因，便于排查
                    if (res.data && res.data.fail && res.data.fail.length) {
                        console.warn('批量发卡失败明细:', res.data.fail);
                    }
                }
            }, true);
        return;
    }

    if (op === 'close') {
        confirmBox('批量关闭',
            `将关闭已选 ${ids.length} 单中「待支付 / 人工待处理」的订单，其余状态跳过。` +
            '关闭后这些订单的支付回调将被拒绝，请确认款项未到账或已退款。继续？',
            async () => {
                const res = await api('shop_order_op', { op: 'batch_close', ids });
                if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
            }, true);
        return;
    }

    if (op === 'delete') {
        confirmBox('批量删除',
            `将永久删除已选的 ${ids.length} 条订单记录，删除后不可恢复！` +
            '已发卡订单发出的卡密仍可正常激活，但交易记录（含卡密绑定关系）无法找回，' +
            '删除前会写入审计日志备查。确定继续？',
            async () => {
                const res = await api('shop_order_op', { op: 'batch_delete', ids });
                if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
            }, true);
        return;
    }
}
