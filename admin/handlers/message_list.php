<?php
/**
 * admin action: message_list
 * 留言板列表（支持按审核状态、关键字过滤）
 *
 * 参数：
 *   page   页码（默认 1）
 *   size   每页条数（默认 20，上限 100）
 *   status 审核状态过滤：'' / 0 / 1 / 2
 *   kw     关键字（匹配内容或昵称）
 *   only_parent 1=只看主楼（默认 0，回复一并返回）
 */

$page   = max(1, Util::int($input, 'page', 1));
$size   = min(100, max(1, Util::int($input, 'size', 20)));
$status = Util::str($input, 'status', '');
$kw     = trim(Util::str($input, 'kw', ''));
$sw     = Util::get($input, 'sw', '');

$table = Database::t('messages');
$where = [];
$args  = [];

if ($status !== '' && in_array($status, ['0', '1', '2'], true)) {
    $where[] = 'status = ?';
    $args[]  = (int) $status;
}
if ($kw !== '') {
    $where[] = '(content LIKE ? OR username LIKE ?)';
    $like    = '%' . $kw . '%';
    $args[]  = $like;
    $args[]  = $like;
}
if ($sw !== '' && $sw !== null && WebInteract::hasSwCol('messages')) {
    $where[] = 'software_id = ?';
    $args[]  = (int) $sw;
}

$baseSql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

[$total, $rows] = Database::paginate($baseSql, $args, $page, $size, '`id` DESC');

// 主楼标题锚点：回复要显示"回复了哪条"，这里一次性取出涉及的主楼
$parentIds = [];
foreach ($rows as $r) {
    $pid = (int) $r['parent_id'];
    if ($pid > 0) { $parentIds[$pid] = true; }
}
$parentMap = [];
if ($parentIds) {
    $ids  = array_keys($parentIds);
    $hold = implode(',', array_fill(0, count($ids), '?'));
    $ps   = Database::all("SELECT id, username, content FROM {$table} WHERE id IN ({$hold})", $ids);
    foreach ($ps as $p) {
        $parentMap[(int) $p['id']] = $p;
    }
}

// 软件名映射（0 = 总站/通用）
$swMap = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('softwares') . ' ORDER BY id ASC') as $s) {
    $swMap[(int) $s['id']] = (string) $s['name'];
}

$hasSw = WebInteract::hasSwCol('messages');

$list = array_map(static function (array $m) use ($parentMap, $swMap, $hasSw) {
    $pid   = (int) $m['parent_id'];
    $parent = $parentMap[$pid] ?? null;
    $swId  = $hasSw ? (int) ($m['software_id'] ?? 0) : 0;

    return [
        'id'            => (int) $m['id'],
        'user_id'       => (int) $m['user_id'],
        'username'      => $m['username'],
        'content'       => $m['content'],
        'parent_id'     => $pid,
        'is_reply'      => $pid > 0,
        'parent_text'   => $parent
            ? ('回复 ' . $parent['username'] . '：' . mb_substr((string) $parent['content'], 0, 40, 'UTF-8'))
            : '',
        'reply_to'      => (string) $m['reply_to'],
        'likes'         => (int) $m['likes'],
        'status'        => (int) $m['status'],
        'status_text'   => WebInteract::messageStatusText((int) $m['status']),
        'admin_note'    => (string) $m['admin_note'],
        'ip'            => (string) $m['ip'],
        'software_id'   => $swId,
        'software_name' => $swId === 0 ? '总站/通用' : (string) ($swMap[$swId] ?? ('软件#' . $swId)),
        'created_at'    => Util::date((int) $m['created_at']),
        'created_ts'    => (int) $m['created_at'],
    ];
}, $rows);

// 待审数量：用作菜单红点提示
$pending = (int) (Database::one("SELECT COUNT(*) AS c FROM {$table} WHERE status = 0")['c'] ?? 0);

Response::ok([
    'total'     => $total,
    'page'      => $page,
    'size'      => $size,
    'pages'     => (int) ceil($total / $size),
    'pending'   => $pending,
    'list'      => $list,
    'softwares' => array_map(function ($id, $name) { return ['id' => $id; }, 'name' => $name],
        array_keys($swMap), array_values($swMap)),
]);
