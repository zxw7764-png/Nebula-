<?php
/**
 * admin action: shop_goods_toggle
 * 发卡商品上架/下架（单个快捷切换 / 批量）
 * ------------------------------------------------------------------
 * 参数：
 *   id      单个商品（不带 status 时状态取反，列表快捷按钮用）
 *   ids     批量商品（数组或逗号分隔，配合 status 使用）
 *   status  目标状态 1=上架 0=下架；不传且为单 id 时取反
 * 售价 0=免费商品（允许上架，买家下单即完成支付并自动发卡）。
 * 与官网价格套餐的启停（plan_save toggle → status）互相独立。
 *
 * 权限：settings.business（仅超管），与 shop_goods_save 同档。
 */

$rawIds = $input['ids'] ?? $input['id'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = explode(',', (string) $rawIds);
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn ($v) => $v > 0)));
if (!$ids) {
    Response::error(1001, '请选择商品');
}
if (count($ids) > 500) {
    Response::error(1001, '单次最多操作 500 个商品');
}

$status = Util::int($input, 'status', -1);
if ($status !== -1 && $status !== 0 && $status !== 1) {
    Response::error(1001, '状态参数不合法');
}

$in   = implode(',', $ids);
$rows = Database::all(
    'SELECT id, name, shop_status, shop_price FROM ' . Database::t('shop_plans') . " WHERE id IN ({$in})"
);
if (!$rows) {
    Response::error(1004, '商品不存在');
}

$ok   = 0;
$skip = 0;
foreach ($rows as $old) {
    $cur    = (int) ($old['shop_status'] ?? 0);
    $target = $status === -1 ? ($cur === 1 ? 0 : 1) : $status;
    if ($target === $cur) {
        continue; // 已是目标状态
    }
    Database::update('shop_plans', ['shop_status' => $target], 'id = :id', ['id' => (int) $old['id']]);
    $ok++;
}

$batch = count($ids) > 1;
$action = $status === 1 ? '批量上架' : ($status === 0 ? '批量下架' : '切换上架状态');
Audit::log($admin, 'shop_goods_toggle',
    $batch ? "发卡商品批量({$ok}/" . count($ids) . ')' : "发卡商品#{$ids[0]}",
    "{$action} 成功 {$ok}" . ($skip > 0 ? "，跳过 {$skip}（未配置售价）" : ''),
    [], ['ids' => $ids, 'status' => $status, 'ok' => $ok, 'skip' => $skip]);

$msg = ($status === 1 ? '已上架 ' : ($status === 0 ? '已下架 ' : '已切换 ')) . $ok . ' 个';
if ($skip > 0) {
    $msg .= "，跳过 {$skip} 个（未配置发卡售价）";
}
Response::ok(['ok' => $ok, 'skip' => $skip], $msg);
