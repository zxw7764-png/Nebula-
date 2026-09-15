<?php
/**
 * admin action: screenshot_list
 * 客户端截图列表
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(200, max(1, Util::int($input, 'size', 50)));
$status = Util::str($input, 'status', '');
$sw     = Util::get($input, 'sw', '');

$table = Database::t('screenshots');
$where = [];
$args  = [];

if ($status !== '' && in_array($status, ['0', '1'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}
if ($sw !== '' && $sw !== null && WebInteract::hasSwCol('screenshots')) {
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
$hasSw = WebInteract::hasSwCol('screenshots');

$list = array_map(static function (array $s) use ($swMap, $hasSw) {
    $swId = $hasSw ? (int) ($s['software_id'] ?? 0) : 0;
    // 放行 http/https 外链或站内上传路径（/uploads/...），与保存侧同口径
    $ok = (bool) preg_match('#^(https?://|/)#i', (string) $s['url']);
    return [
        'id'            => (int) $s['id'],
        'title'         => (string) $s['title'],
        'url'           => (string) $s['url'],
        'url_valid'     => $ok,
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
    'softwares' => array_map(static fn($id, $name) => ['id' => $id, 'name' => $name],
        array_keys($swMap), array_values($swMap)),
]);
