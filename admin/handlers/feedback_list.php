<?php
/**
 * admin action: feedback_list
 * 用户反馈列表（支持按状态、类型、关键字过滤）
 *
 * 参数：
 *   page   页码（默认 1）
 *   size   每页条数（默认 20，上限 100）
 *   status 处理状态过滤：'' / 0待处理 / 1处理中 / 2已回复 / 3已关闭
 *   type   反馈类型过滤：'' / 1..4
 *   kw     关键字（匹配标题、内容或用户名）
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(100, max(1, Util::int($input, 'size', 20)));
$status = Util::str($input, 'status', '');
$type   = Util::str($input, 'type', '');
$kw     = trim(Util::str($input, 'kw', ''));
$sw     = Util::get($input, 'sw', '');

$table = Database::t('feedbacks');
$where = [];
$args  = [];

if ($status !== '' && in_array($status, ['0', '1', '2', '3'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}
if ($type !== '' && in_array($type, ['1', '2', '3', '4'], true)) {
    $where[] = 'type = ?';
    $args[]  = (int) $type;
}
if ($kw !== '') {
    $where[] = '(title LIKE ? OR content LIKE ? OR username LIKE ?)';
    $like    = '%' . $kw . '%';
    $args[]  = $like;
    $args[]  = $like;
    $args[]  = $like;
}
if ($sw !== '' && $sw !== null && WebInteract::hasSwCol('feedbacks')) {
    $where[] = 'software_id = ?';
    $args[]  = (int) $sw;
}

$baseSql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

[$total, $rows] = Database::paginate($baseSql, $args, $page, $size, '`id` DESC');

$types = WebInteract::feedbackTypes();

// 软件名映射（0 = 总站/通用）
$swMap = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('softwares') . ' ORDER BY id ASC') as $s) {
    $swMap[(int) $s['id']] = (string) $s['name'];
}
$hasSw = WebInteract::hasSwCol('feedbacks');

$list = array_map(static function (array $f) use ($types, $swMap, $hasSw) {
    $st   = (int) $f['status'];
    $swId = $hasSw ? (int) ($f['software_id'] ?? 0) : 0;
    return [
        'id'            => (int) $f['id'],
        'user_id'       => (int) $f['user_id'],
        'username'      => $f['username'],
        'type'          => (int) $f['type'],
        'type_text'     => $types[(int) $f['type']] ?? '其他',
        'title'         => $f['title'],
        'content'       => $f['content'],
        'contact'       => (string) $f['contact'],
        'status'        => $st,
        'status_text'   => WebInteract::feedbackStatusText($st),
        'reply'         => (string) $f['reply'],
        'reply_admin'   => (string) $f['reply_admin'],
        'replied_at'    => (int) $f['replied_at'] > 0 ? Util::date((int) $f['replied_at']) : '',
        'software_id'   => $swId,
        'software_name' => $swId === 0 ? '总站/通用' : (string) ($swMap[$swId] ?? ('软件#' . $swId)),
        'created_at'    => Util::date((int) $f['created_at']),
        'created_ts'    => (int) $f['created_at'],
    ];
}, $rows);

// 各状态计数：用作筛选标签上的数字提示
$counts = ['all' => (int) $total, 'pending' => 0, 'doing' => 0, 'replied' => 0, 'closed' => 0];
$stat = Database::all("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status");
foreach ($stat as $s) {
    $map = [0 => 'pending', 1 => 'doing', 2 => 'replied', 3 => 'closed'];
    $k = $map[(int) $s['status']] ?? null;
    if ($k) { $counts[$k] = (int) $s['c']; }
}
// 无过滤条件时的总数（用于「全部」标签）
if ($where) {
    $counts['all'] = (int) (Database::one("SELECT COUNT(*) AS c FROM {$table}")['c'] ?? 0);
}

Response::ok([
    'total'     => $total,
    'page'      => $page,
    'size'      => $size,
    'pages'     => (int) ceil($total / $size),
    'counts'    => $counts,
    'types'     => $types,
    'list'      => $list,
    'softwares' => array_map(function ($id, $name) { return ['id' => $id; }, 'name' => $name],
        array_keys($swMap), array_values($swMap)),
]);
