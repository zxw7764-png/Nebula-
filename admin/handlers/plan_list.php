<?php
/**
 * admin action: plan_list
 * 价格套餐列表
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(100, max(1, Util::int($input, 'size', 50)));
$status = Util::str($input, 'status', '');

$table = Database::t('plans');
$where = [];
$args  = [];

// 已从官网删除（web_deleted=1）的套餐不再出现在官网价格套餐列表；
// 老库未跑迁移（无该列）时跳过过滤，保持可用
if ((bool) Database::one('SHOW COLUMNS FROM ' . $table . " LIKE 'web_deleted'")) {
    $where[] = 'web_deleted = 0';
}

if ($status !== '' && in_array($status, ['0', '1'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}

$baseSql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

[$total, $rows] = Database::paginate($baseSql, $args, $page, $size, '`sort` DESC, `id` ASC');

$list = array_map(static function (array $p) {
    return [
        'id'               => (int) $p['id'],
        'name'             => $p['name'],
        'price'            => $p['price'],
        'unit'             => (string) $p['unit'],
        'duration'         => (string) $p['duration'],
        'desc'             => (string) $p['desc'],
        'badge'            => (string) $p['badge'],
        'shop_category'    => (string) ($p['shop_category'] ?? ''),
        'shop_icon'        => (string) ($p['shop_icon'] ?? ''),
        'shop_intro'       => (string) ($p['shop_intro'] ?? ''),
        'highlight'        => (int) $p['highlight'] === 1,
        'sort'             => (int) $p['sort'],
        'status'           => (int) $p['status'],
        'status_text'      => (int) $p['status'] === 1 ? '启用' : '停用',
        // 官网展示归属软件（0=全部软件通用；老库未跑迁移时缺列，用 ?? 兜底）
        'web_software_id'  => (int) ($p['web_software_id'] ?? 0),
        // 发卡商品化字段（老库未跑迁移时缺列，用 ?? 兜底）
        'shop_status'      => (int) ($p['shop_status'] ?? 0),
        'shop_price'       => (string) ($p['shop_price'] ?? '0.00'),
        'card_type'        => (int) ($p['card_type'] ?? 1),
        'card_duration'    => (int) ($p['card_duration'] ?? 0),
        'card_max_devices' => (int) ($p['card_max_devices'] ?? 1),
        'card_group_id'    => (int) ($p['card_group_id'] ?? 0),
        'created_at'       => Util::date((int) $p['created_at']),
    ];
}, $rows);

// 软件选项（套餐编辑弹窗「官网展示归属软件」下拉用）
$softwares = array_map(static fn($s) => [
    'id'     => (int) $s['id'],
    'name'   => (string) $s['name'],
    'status' => (int) $s['status'],
], Software::all());

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
    'softwares' => $softwares,
]);
