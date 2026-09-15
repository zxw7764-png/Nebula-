<?php
/**
 * admin action: version_list
 */

$page = max(1, Util::int($input, 'page', 1));
$size = min(100, max(1, Util::int($input, 'size', 20)));

$where  = ['1=1'];
$params = [];
// 软件子页面：按软件过滤（software_id=0 表示不过滤）
$swId = Util::int($input, 'software_id', 0);
if ($swId > 0) {
    $where[] = 'software_id = ?';
    $params[] = $swId;
}

$baseSql = 'SELECT * FROM ' . Database::t('versions')
         . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

// 软件名映射（多软件展示用）
$swNames = [];
foreach (Software::all() as $swRow) {
    $swNames[(int) $swRow['id']] = $swRow['name'];
}

$list = array_map(fn($v) => [
    'id'           => (int) $v['id'],
    'software_id'  => (int) ($v['software_id'] ?? 1),
    'software_name'=> $swNames[(int) ($v['software_id'] ?? 1)] ?? ('软件#' . (int) ($v['software_id'] ?? 1)),
    'version'      => $v['version'],
    'channel'      => $v['channel'],
    'download_url' => $v['download_url'],
    'file_hash'    => $v['file_hash'],
    'file_size'    => (int) $v['file_size'],
    'file_size_text' => $v['file_size'] > 0 ? round($v['file_size'] / 1048576, 2) . ' MB' : '-',
    'changelog'    => $v['changelog'],
    'force_update' => (int) $v['force_update'],
    'status'       => (int) $v['status'],
    'created_at'   => Util::date((int) $v['created_at']),
], $rows);

Response::ok([
    'total' => $total, 'page' => $page, 'size' => $size,
    'pages' => (int) ceil($total / $size), 'list' => $list,
]);
