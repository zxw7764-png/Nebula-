<?php
/**
 * admin action: seller_list
 * 购买商家列表
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(100, max(1, Util::int($input, 'size', 50)));
$status = Util::str($input, 'status', '');
$sw     = Util::get($input, 'sw', '');

$table = Database::t('sellers');
$where = [];
$args  = [];

if ($status !== '' && in_array($status, ['0', '1'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}
if ($sw !== '' && $sw !== null && WebInteract::hasSwCol('sellers')) {
    $where[] = 'software_id = ?';
    $args[]  = (int) $sw;
}

$baseSql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

[$total, $rows] = Database::paginate($baseSql, $args, $page, $size, '`sort` DESC, `id` ASC');

// 软件名映射（0 = 全部软件通用）
$swMap = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('softwares') . ' ORDER BY id ASC') as $s) {
    $swMap[(int) $s['id']] = (string) $s['name'];
}
$hasSw = WebInteract::hasSwCol('sellers');

$list = array_map(static function (array $s) use ($swMap, $hasSw) {
    $swId = $hasSw ? (int) ($s['software_id'] ?? 0) : 0;
    return [
        'id'            => (int) $s['id'],
        'name'          => $s['name'],
        'logo'          => (string) $s['logo'],
        'desc'          => (string) $s['desc'],
        'contact'       => (string) $s['contact'],
        'url'           => (string) $s['url'],
        'badge'         => (string) $s['badge'],
        'highlight'     => (int) $s['highlight'] === 1,
        'sort'          => (int) $s['sort'],
        'status'        => (int) $s['status'],
        'status_text'   => (int) $s['status'] === 1 ? '启用' : '停用',
        'software_id'   => $swId,
        'software_name' => $swId === 0 ? '全部软件' : (string) ($swMap[$swId] ?? ('软件#' . $swId)),
        'created_at'    => Util::date((int) $s['created_at']),
    ];
}, $rows);

Response::ok([
    'total'     => $total,
    'page'      => $page,
    'size'      => $size,
    'pages'     => (int) ceil($total / $size),
    'list'      => $list,
    'softwares' => array_map(function ($id, $name) { return ['id' => $id; }, 'name' => $name],
        array_keys($swMap), array_values($swMap)),
]);
