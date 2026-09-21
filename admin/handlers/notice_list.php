<?php
/**
 * admin action: notice_list
 * 参数: page, size, sw(软件ID筛选；0=全部软件通用，空=不筛选), type(公告类型筛选；空=不筛选；1=官网门户 4=客户端公告)
 */

$page = max(1, Util::int($input, 'page', 1));
$size = min(100, max(1, Util::int($input, 'size', 20)));
$sw   = Util::get($input, 'sw', '');
$type = Util::get($input, 'type', '');

$where  = ['1=1'];
$params = [];
if ($sw !== '' && $sw !== null) {
    $where[] = 'software_id = :sw';
    $params['sw'] = (int) $sw;
}
if ($type !== '' && $type !== null) {
    // 支持逗号分隔多类型，如 type=2,4（客户端公告 = 弹窗2 + 列表4）
    $types = array_values(array_filter(array_map('intval', explode(',', (string) $type))));
    if ($types) {
        $marks = [];
        foreach ($types as $i => $tv) {
            $key = "type_{$i}";
            $marks[] = ":{$key}";
            $params[$key] = $tv;
        }
        $where[] = 'type IN (' . implode(',', $marks) . ')';
    }
}

$baseSql = 'SELECT * FROM ' . Database::t('notices') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`sort` DESC, `id` DESC');

// 软件名映射（0 = 全部软件）
$swMap = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('softwares') . ' ORDER BY id ASC') as $s) {
    $swMap[(int) $s['id']] = (string) $s['name'];
}

$list = array_map(function ($n) use ($swMap) {
    $swId = (int) ($n['software_id'] ?? 0);
    return [
        'id'            => (int) $n['id'],
        'title'         => $n['title'],
        'content'       => $n['content'],
        'type'          => (int) $n['type'],
        'type_text'     => [1 => '官网门户', 2 => '弹窗公告', 3 => '立即公告', 4 => '列表公告'][(int) $n['type']] ?? '官网门户',
        'software_id'   => $swId,
        'software_name' => $swId === 0 ? '全部软件' : (string) ($swMap[$swId] ?? ('软件#' . $swId)),
        'status'        => (int) $n['status'],
        'sort'          => (int) $n['sort'],
        'start_at'      => (int) $n['start_at'],
        'end_at'        => (int) $n['end_at'],
        'start_text'    => (int) $n['start_at'] > 0 ? Util::date((int) $n['start_at']) : '立即',
        'end_text'      => (int) $n['end_at'] > 0 ? Util::date((int) $n['end_at']) : '永久',
        'created_at'    => Util::date((int) $n['created_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total, 'page' => $page, 'size' => $size,
    'pages' => (int) ceil($total / $size), 'list' => $list,
    'softwares' => array_map(function ($id, $name) { return ['id' => $id; }, 'name' => $name],
        array_keys($swMap), array_values($swMap)),
]);
