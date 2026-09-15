<?php
/**
 * admin action: shop_goods_list
 * 发卡商品列表（商品与交易 → 商品管理）
 * 取 plans 全量套餐的发卡侧字段，并按挂卡规格实时计算库存；
 * 与 plan_list 的区别：这里不带官网展示字段，专为发卡商品页服务。
 */

$tbl = Database::t('shop_plans');

// 显示归属软件列（未跑迁移的老库缺列时补 0，避免整列表报错）
$swCol = Shop::hasShopSwCol() ? ', shop_software_id' : ', 0 AS shop_software_id';
// 查单提示列（未跑迁移的老库缺列时补空串，避免整列表报错）
$noticeCol = Shop::hasShopNoticeCol() ? ', shop_notice' : ", '' AS shop_notice";
$rows = Database::all(
    'SELECT id, name, status, shop_status, shop_price, card_source, software_id, card_type, card_duration, card_max_devices, card_group_id,
            shop_category, shop_icon, shop_intro, shop_detail, shop_name, shop_badge, shop_highlight' . $swCol . $noticeCol . '
     FROM ' . $tbl . '
     ORDER BY shop_status DESC, id ASC'
);

$list = array_map(static function (array $p) {
    $src  = (int) ($p['card_source'] ?? 0);
    $type = (int) ($p['card_type'] ?? 1);
    $dur  = (int) ($p['card_duration'] ?? 0);
    $dev  = (int) ($p['card_max_devices'] ?? 1);
    $gid  = (int) ($p['card_group_id'] ?? 0);
    $on   = (int) ($p['shop_status'] ?? 0) === 1;
    // 发货归属软件：库存按它统计，与下单实际取卡的软件保持一致
    $deliverSw = Shop::planOwnedSoftwareId($p);

    return [
        'id'               => (int) $p['id'],
        'name'             => (string) $p['name'],
        'status'           => (int) $p['status'],
        'shop_status'      => (int) ($p['shop_status'] ?? 0),
        'shop_price'       => (string) ($p['shop_price'] ?? '0.00'),
        'card_source'      => $src,
        'card_type'        => $type,
        'card_duration'    => $dur,
        'card_max_devices' => $dev,
        'card_group_id'    => $gid,
        'shop_category'    => (string) ($p['shop_category'] ?? ''),
        'shop_name'        => (string) ($p['shop_name'] ?? ''),
        'shop_icon'        => (string) ($p['shop_icon'] ?? ''),
        'shop_intro'       => (string) ($p['shop_intro'] ?? ''),
        'shop_detail'      => (string) ($p['shop_detail'] ?? ''),
        'highlight'        => (int) ($p['shop_highlight'] ?? 0),
        'badge'            => (string) ($p['shop_badge'] ?? ''),
        'shop_software_id' => (int) ($p['shop_software_id'] ?? 0),
        'deliver_software_id' => $deliverSw,
        'shop_notice'       => (string) ($p['shop_notice'] ?? ''),
        'cards'             => Shop::planCards((int) $p['id']),
        // 实时库存（仅上架商品计算，减少无谓 COUNT）
        // 外部卡密商品库存=导入池未售条数；系统卡库存=按挂卡规格 + 发货归属软件匹配
        'stock'            => $on ? ($src === 1
            ? Shop::extStock((int) $p['id'])
            : Shop::stock($type, $dur, $dev, $gid, $deliverSw)) : 0,
        // 外部卡密商品：各挂卡类型的分规格库存（导入弹窗展示用）
        'stockByType'      => $src === 1 ? Shop::extStockByTypes((int) $p['id']) : [],
    ];
}, $rows);

Response::ok(['list' => $list]);
