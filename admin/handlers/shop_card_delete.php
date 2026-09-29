<?php
/**
 * admin action: shop_card_delete
 * 批量删除外部卡密（外部卡密池 nb_shop_cards，卡密中心多选/单条）
 * ------------------------------------------------------------------
 *   · 只删外部卡密池记录，发卡商品库存实时减少（extStock 按未售条数计算）
 *   · scope=unused 仅删未售；scope=all 含已售——已售卡密内容在订单
 *     card_code 有快照，删除不影响买家查看订单与对账
 *
 * 权限：card.void（与系统卡作废/删除同档），需管理密码确认。
 */

$ids = $input['ids'] ?? [];
if (!is_array($ids)) {
    $ids = explode(',', (string) $ids);
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
if (!$ids) {
    Response::error(1001, '请选择要删除的卡密');
}
if (count($ids) > 2000) {
    Response::error(1001, '单次最多删除 2000 条');
}

// 租户隔离：先查出这些卡密所属的 software_id，再做范围校验
$rows = Database::all(
    "SELECT c.id, p.software_id FROM " . Database::t('shop_cards') . " c"
    . " JOIN " . Database::t('shop_plans') . " p ON p.id = c.plan_id"
    . " WHERE c.id IN (" . implode(',', $ids) . ")"
);
$planMap = [];
foreach ($rows as $r) {
    $planMap[(int) $r['id']] = (int) $r['software_id'];
}
foreach ($ids as $oid) {
    if (isset($planMap[$oid])) {
        Tenant::requireTouch($admin, $planMap[$oid]);
    }
}

Deleter::confirmPassword($admin, $input, '删除外部卡密');

$scope = Util::str($input, 'scope', 'all');
if (!in_array($scope, ['all', 'unused'], true)) {
    $scope = 'all';
}

$tbl = Database::t('shop_cards');
$in  = implode(',', $ids);

// 先取删除范围内的记录（按 scope 过滤）用于审计与计数
$rows = Database::all(
    "SELECT id, plan_id, content, status FROM {$tbl} WHERE id IN ({$in})"
    . ($scope === 'unused' ? ' AND status = 0' : '')
);

$delIds = array_map('intval', array_column($rows, 'id'));
if (!$delIds) {
    Response::ok(
        ['count' => 0, 'skipped' => count($ids)],
        $scope === 'unused' ? '所选卡密均已售出，没有可删除的未售卡密' : '没有可删除的卡密'
    );
}
Database::exec("DELETE FROM {$tbl} WHERE id IN (" . implode(',', $delIds) . ")");

$deleted = count($delIds);
$skipped = count($ids) - $deleted;
$sold    = count(array_filter($rows, fn ($r) => (int) $r['status'] === 1));

Audit::log($admin, 'shop_card_delete', '外部卡密(删除)',
    "删除 {$deleted} 条外部卡密"
    . ($sold > 0 ? "（含已售 {$sold} 条，订单快照不受影响）" : '')
    . '，示例：' . implode(', ', array_slice(array_column($rows, 'content'), 0, 10)),
    [], [], ['ids' => $delIds, 'scope' => $scope, 'deleted' => $deleted]);

Response::ok(
    ['count' => $deleted, 'skipped' => $skipped],
    "已删除 {$deleted} 条外部卡密" . ($skipped > 0 ? "，跳过 {$skipped} 条" : '')
);
