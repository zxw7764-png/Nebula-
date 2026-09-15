<?php
/**
 * admin action: shop_goods_options
 * 卡密生成弹窗「关联发卡商品」下拉的选项数据源。
 * 只取上架（shop_status=1）系统卡商品（card_source=0）的挂卡规格，供印卡时一键带出；
 * 外部卡密商品（card_source=1）不挂本系统规格，不出现在关联下拉里。
 * 权限归 card.generate——凡能生成卡密的操作员都需要按商品规格取卡，
 * 故不与 shop_goods_list（settings.business 业务档）同权限。
 */

$rows = [];
$hasShopSw = Shop::hasShopSwCol();
$swCol = $hasShopSw ? ', shop_software_id' : '';
try {
    $rows = Database::all(
        'SELECT id, name, software_id, card_type, card_duration, card_max_devices, card_group_id' . $swCol . '
         FROM ' . Database::t('shop_plans') . '
         WHERE shop_status = 1 AND card_source = 0
         ORDER BY id ASC'
    );
} catch (Throwable $e) {
    // 未跑 migrate_extcards.php（缺 card_source 列）时退回全部上架商品
    $rows = Database::all(
        'SELECT id, name, software_id, card_type, card_duration, card_max_devices, card_group_id' . $swCol . '
         FROM ' . Database::t('shop_plans') . '
         WHERE shop_status = 1
         ORDER BY id ASC'
    );
}

$list = array_map(static function (array $p) {
    $cards = Shop::planCards((int) $p['id']);
    return [
        'id'               => (int) $p['id'],
        'name'             => (string) $p['name'],
        'software_id'      => (int) ($p['software_id'] ?? 1),
        'shop_software_id' => (int) ($p['shop_software_id'] ?? 0),
        'card_type'        => (int) ($p['card_type'] ?? 1),
        'card_duration'    => (int) ($p['card_duration'] ?? 0),
        'card_max_devices' => (int) ($p['card_max_devices'] ?? 1),
        'card_group_id'    => (int) ($p['card_group_id'] ?? 0),
        'cards'            => $cards,
    ];
}, $rows);

Response::ok(['list' => $list]);
