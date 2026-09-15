<?php
/**
 * admin action: shop_goods_import
 * 外部卡密导入（发卡商品 card_source=1 的卡密池补货）
 * ------------------------------------------------------------------
 * 把外部平台的卡密（非本验证系统的商品）批量导入 nb_shop_cards：
 *   · 一行一条，自动去空行 / 去重（本次输入内 + 池中已存在的同商品同内容）
 *   · 单次上限 1000 条，单条最长 500 字符
 * 导入后商品库存实时增加，买家购买即从池中自动发货。
 *
 * 权限：settings.business（仅超管），与 shop_goods_save 同档。
 */

$planId = Util::int($input, 'plan_id', 0);
$raw    = Util::str($input, 'contents', '');
// 挂卡类型（多规格下按类型入池）：未跑迁移 / 未传时为 0（未分类）
$cardType = Util::int($input, 'card_type', 0);
$hasTypeCol = false;
try {
    $hasTypeCol = (bool) Database::one("SHOW COLUMNS FROM " . Database::t('shop_cards') . " LIKE 'card_type'");
} catch (Throwable $e) {
    $hasTypeCol = false;
}
if (!$hasTypeCol) {
    $cardType = 0;
}

$plan = Database::one(
    'SELECT id, name, card_source FROM ' . Database::t('shop_plans') . ' WHERE id = ?',
    [$planId]
);
if (!$plan) {
    Response::error(1004, '商品不存在');
}
if ((int) ($plan['card_source'] ?? 0) !== 1) {
    Response::error(1001, '该商品不是外部卡密商品，无法导入（请先在发卡商品配置中把卡密来源切换为外部卡密）');
}

// 解析：一行一条，去空行、输入内去重
$items = [];
$seen  = [];
foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
    $line = trim($line);
    if ($line === '' || isset($seen[$line])) {
        continue;
    }
    if (mb_strlen($line) > 500) {
        Response::error(1001, '单条卡密过长（超过 500 字符）：' . mb_substr($line, 0, 30) . '…');
    }
    $seen[$line] = true;
    $items[] = $line;
}
if (!$items) {
    Response::error(1001, '请填写卡密内容，一行一条');
}
if (count($items) > 1000) {
    Response::error(1001, '单次最多导入 1000 条，请分批导入');
}

// 与池中已存在的同商品内容去重
$exists = Database::all(
    'SELECT content FROM ' . Database::t('shop_cards') . ' WHERE plan_id = ?',
    [$planId]
);
$have = array_flip(array_column($exists, 'content'));

$duplicate = 0;
$toInsert  = [];
foreach ($items as $content) {
    if (isset($have[$content])) {
        $duplicate++;
        continue;
    }
    $have[$content] = true;
    $toInsert[] = $content;
}
if (!$toInsert) {
    Response::ok(['inserted' => 0, 'duplicate' => $duplicate], "全部为重复卡密，跳过 {$duplicate} 条");
}

// 建「外部导入」批次（nb_card_batches.type=0），导入的卡密都挂到这个批次下，
// 卡密中心列表（来源=外部导入）和卡密批次页都能按批次查看外部卡密
$now = time();
$batchId = (int) Database::insert('card_batches', [
    'name'        => mb_substr('外部导入 · ' . $plan['name'], 0, 60),
    'prefix'      => '',
    'type'        => 0,
    'duration'    => 0,
    'max_devices' => 0,
    'group_id'    => 0,
    'count'       => count($toInsert),
    'used_count'  => 0,
    'admin_id'    => (int) ($admin['id'] ?? 0),
    'agent_id'    => 0,
    'created_at'  => $now,
]);

$inserted = 0;
foreach ($toInsert as $content) {
    $row = [
        'plan_id'    => $planId,
        'batch_id'   => $batchId,
        'content'    => $content,
        'status'     => 0,
        'order_id'   => 0,
        'created_at' => $now,
        'sold_at'    => 0,
    ];
    if ($hasTypeCol) {
        $row['card_type'] = $cardType;
    }
    Database::insert('shop_cards', $row);
    $inserted++;
}

Audit::log($admin, 'shop_goods_import', "发卡商品#{$planId} {$plan['name']}",
    "导入外部卡密 {$inserted} 条（批次 #{$batchId}）" . ($duplicate > 0 ? "，跳过重复 {$duplicate} 条" : ''),
    [], ['plan_id' => $planId, 'batch_id' => $batchId, 'inserted' => $inserted, 'duplicate' => $duplicate]);

Response::ok(
    ['inserted' => $inserted, 'duplicate' => $duplicate, 'batch_id' => $batchId],
    "导入 {$inserted} 条（批次 #{$batchId}）" . ($duplicate > 0 ? "，跳过重复 {$duplicate} 条" : '')
);
