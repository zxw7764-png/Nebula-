<?php
/**
 * admin action: shop_order_list
 * 发卡订单列表 + 统计（官网 /shop/ 的订单）
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(100, max(1, Util::int($input, 'size', 20)));
$status = Util::str($input, 'status', '');
$kw     = Util::str($input, 'kw', '');

$table = Database::t('shop_orders');
$where = [];
$args  = [];
Tenant::applyPositional($where, $args, 'software_id');

if ($status !== '' && in_array($status, ['0', '1', '2', '3'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}
if ($kw !== '') {
    // 订单号 / 买家联系方式 / 支付流水号 三路模糊
    $where[] = '(order_no LIKE ? OR contact LIKE ? OR trade_no LIKE ?)';
    $like    = '%' . $kw . '%';
    array_push($args, $like, $like, $like);
}

$baseSql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

[$total, $rows] = Database::paginate($baseSql, $args, $page, $size, '`id` DESC');

// 归属软件名映射（订单表已存 software_id 快照，随商品带入）
$swMap = [];
foreach (Software::all() as $sw) {
    $swMap[(int) $sw['id']] = (string) $sw['name'];
}

$list = array_map(static function (array $o) use ($swMap) {
    $status = (int) $o['status'];
    $sid    = (int) ($o['software_id'] ?? 0);
    return [
        'id'            => (int) $o['id'],
        'order_no'      => (string) $o['order_no'],
        'plan_name'     => (string) $o['plan_name'],
        'software_id'   => $sid,
        'software_name' => isset($swMap[$sid]) && $swMap[$sid] !== '' ? $swMap[$sid] : ('软件 #' . $sid),
        'card_type'     => (int) $o['card_type'],
        'card_type_text'=> Shop::cardTypeText((int) $o['card_type']),
        'spec_text'     => Shop::specText((int) $o['card_type'], (int) $o['duration'], (int) $o['max_devices']),
        'amount_text'   => number_format(((int) $o['amount']) / 100, 2, '.', ''),
        'pay_type'      => (int) $o['pay_type'],
        'pay_type_text' => (int) $o['pay_type'] === Shop::PAY_EPAY ? Shop::payLabel((string) $o['channel']) : '人工',
        'channel'       => (string) $o['channel'],
        'status'        => $status,
        'status_text'   => Shop::orderStatusText($status),
        'contact'       => (string) $o['contact'],
        'card_code'     => (string) ($o['card_code'] ?? ''),
        'trade_no'      => (string) ($o['trade_no'] ?? ''),
        'client_ip'     => (string) ($o['client_ip'] ?? ''),
        'remark'        => (string) ($o['remark'] ?? ''),
        'created_at'    => Util::date((int) $o['created_at']),
        'paid_at'       => Util::date((int) $o['paid_at']),
        'delivered_at'  => Util::date((int) $o['delivered_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'stats' => Shop::adminStats(),
    'list'  => $list,
]);
