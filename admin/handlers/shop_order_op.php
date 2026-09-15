<?php
/**
 * admin action: shop_order_op
 * 发卡订单操作：确认并发卡 / 手动补发 / 关闭订单 / 批量发卡 / 批量关闭 / 批量删除
 *
 * 参数：
 *   op    deliver | deliver_manual | close
 *         | batch_deliver | batch_close | batch_delete
 *   id    订单 ID（单条操作）
 *   ids   订单 ID 数组（批量操作，兼容逗号分隔字符串）
 *   code  deliver_manual 时要补发的官方直发卡密
 *
 * 权限：shop_order_op 在 ACTION_PERM 登记为 card.generate
 *（把库存卡交付出去/补发/删除交易记录，与印卡同级别敏感，操作员不可做）。
 */

$op = Util::str($input, 'op', '');
$id = Util::int($input, 'id', 0);

/** 批量 op 集合：这些 op 不走单条订单校验 */
$batchOps = ['batch_deliver', 'batch_close', 'batch_delete'];

/** ids 参数归一化：数组或逗号分隔字符串 → 正整数列表 */
$parseIds = function () use ($input): array {
    $ids = $input['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = explode(',', (string) $ids);
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
};

$order = null;
if ($id > 0) {
    $order = Database::one('SELECT * FROM ' . Database::t('shop_orders') . ' WHERE id = ?', [$id]);
    if (!$order) {
        Response::error(1004, '订单不存在');
    }
}
$orderNo = (string) ($order['order_no'] ?? '');

switch ($op) {

    // ---------------------------------------------------------------
    // 确认收款并自动取卡发卡（人工单确认 / 缺货单补发通用）
    // ---------------------------------------------------------------
    case 'deliver':
        $r = Shop::adminDeliver($id);
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Audit::log($admin, 'shop_deliver', "订单 {$orderNo}", '确认发卡（自动取卡）',
            ['status' => (int) $order['status']],
            ['status' => Shop::ORDER_DELIVERED, 'card_code' => $r['data']['card_code'] ?? '']);
        Response::ok(['id' => $id, 'card_code' => $r['data']['card_code'] ?? ''], '发卡成功');
        break;

    // ---------------------------------------------------------------
    // 手动补发：管理员指定一张官方直发未使用卡密绑定到订单
    // （库存规格不齐时的兜底；Shop 内会校验卡可用性与规格一致）
    // ---------------------------------------------------------------
    case 'deliver_manual':
        $code = Util::str($input, 'code', '');
        if ($code === '') {
            Response::error(1001, '请输入要补发的卡密');
        }
        $r = Shop::adminDeliver($id, $code);
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Audit::log($admin, 'shop_deliver_manual', "订单 {$orderNo}", '人工补发卡密',
            ['status' => (int) $order['status']],
            ['status' => Shop::ORDER_DELIVERED, 'card_code' => $r['data']['card_code'] ?? '']);
        Response::ok(['id' => $id, 'card_code' => $r['data']['card_code'] ?? ''], '补发成功');
        break;

    // ---------------------------------------------------------------
    // 关闭订单（仅待支付 / 人工待处理可关）
    // ---------------------------------------------------------------
    case 'close':
        $remark = Util::str($input, 'remark', '');
        $r = Shop::adminClose($id, $remark);
        if (!$r['ok']) {
            Response::error($r['code'], $r['msg']);
        }
        Audit::log($admin, 'shop_close', "订单 {$orderNo}", '关闭发卡订单',
            ['status' => (int) $order['status']], ['status' => Shop::ORDER_CLOSED]);
        Response::ok(['id' => $id], '订单已关闭');
        break;

    // ---------------------------------------------------------------
    // 批量确认发卡：仅对「人工待处理」订单逐单自动取卡发货。
    // 待支付（款项未核实）/ 已发卡 / 已关闭 一律跳过，
    // 逐单独立事务 —— 单单失败不影响其他订单。
    // ---------------------------------------------------------------
    case 'batch_deliver':
        $ids = $parseIds();
        if (!$ids) {
            Response::error(1001, '请选择订单');
        }
        if (count($ids) > 500) {
            Response::error(1001, '单次最多批量发卡 500 单');
        }
        $ok   = 0;
        $fail = [];
        foreach ($ids as $oid) {
            $r = Shop::adminDeliver($oid);
            if ($r['ok']) {
                $ok++;
            } else {
                $fail[] = 'ID' . $oid . '：' . $r['msg'];
            }
        }
        Audit::log($admin, 'shop_batch_deliver', '发卡订单批量(确认发卡)',
            "批量确认发卡 {$ok}/" . count($ids) . ' 单',
            [], [], ['ids' => $ids, 'ok' => $ok, 'fail' => array_slice($fail, 0, 20)]);
        Response::ok(
            ['count' => $ok, 'total' => count($ids), 'fail' => $fail],
            "已发卡 {$ok} 单" . ($fail ? '，' . count($fail) . ' 单失败' : '')
        );
        break;

    // ---------------------------------------------------------------
    // 批量关闭：一条原子 UPDATE，仅待支付 / 人工待处理会被关闭
    // ---------------------------------------------------------------
    case 'batch_close':
        $ids = $parseIds();
        if (!$ids) {
            Response::error(1001, '请选择订单');
        }
        if (count($ids) > 2000) {
            Response::error(1001, '单次最多操作 2000 单');
        }
        $in = implode(',', $ids);
        $n  = Database::exec(
            'UPDATE ' . Database::t('shop_orders')
            . ' SET status = ?, remark = "管理员批量关闭"'
            . " WHERE id IN ({$in}) AND status IN (?, ?)",
            [Shop::ORDER_CLOSED, Shop::ORDER_PENDING, Shop::ORDER_MANUAL]
        );
        Audit::log($admin, 'shop_batch_close', '发卡订单批量(关闭)',
            "批量关闭 {$n}/" . count($ids) . ' 单',
            [], [], ['ids' => $ids, 'affected' => $n]);
        $skip = count($ids) - $n;
        Response::ok(['count' => $n, 'total' => count($ids)],
            "已关闭 {$n} 单" . ($skip > 0 ? "，{$skip} 单状态不允许关闭（已发卡/已关闭）" : ''));
        break;

    // ---------------------------------------------------------------
    // 批量删除：物理删除订单记录（不可恢复）。
    // 已发出的卡密状态不受影响（status=3 的卡仍可正常激活），
    // 删除前先取订单快照写入审计，保住对账线索。
    // ---------------------------------------------------------------
    case 'batch_delete':
        $ids = $parseIds();
        if (!$ids) {
            Response::error(1001, '请选择订单');
        }
        if (count($ids) > 2000) {
            Response::error(1001, '单次最多操作 2000 单');
        }
        $in   = implode(',', $ids);
        $snap = Database::all(
            'SELECT order_no, plan_name, amount, status, card_code, trade_no'
            . ' FROM ' . Database::t('shop_orders') . " WHERE id IN ({$in})"
        );
        $n = Database::exec(
            'DELETE FROM ' . Database::t('shop_orders') . " WHERE id IN ({$in})",
            []
        );
        Audit::log($admin, 'shop_batch_delete', '发卡订单批量(删除)',
            "批量删除 {$n}/" . count($ids) . ' 单',
            [], [], ['ids' => $ids, 'affected' => $n, 'snapshot' => array_slice($snap, 0, 50)]);
        Response::ok(['count' => $n, 'total' => count($ids)], "已删除 {$n} 单");
        break;

    default:
        Response::error(1001, '未知操作: ' . $op);
}
