<?php
/**
 * admin action: audit_list
 * 管理端审计日志列表
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 30)));
$keyword = Util::str($input, 'keyword', '');
$action  = Util::str($input, 'action', '');
$adminId = Util::int($input, 'admin_id', 0);
$from    = Util::str($input, 'date_from', '');
$to      = Util::str($input, 'date_to', '');

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    $where[] = '(admin_name LIKE ? OR target LIKE ? OR summary LIKE ? OR ip LIKE ?)';
    $kw = '%' . $keyword . '%';
    array_push($params, $kw, $kw, $kw, $kw);
}
if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($adminId > 0) {
    $where[] = 'admin_id = ?';
    $params[] = $adminId;
}
if ($from !== '') {
    $ts = strtotime($from . ' 00:00:00');
    if ($ts) {
        $where[] = 'created_at >= ?';
        $params[] = $ts;
    }
}
if ($to !== '') {
    $ts = strtotime($to . ' 23:59:59');
    if ($ts) {
        $where[] = 'created_at <= ?';
        $params[] = $ts;
    }
}

$baseSql = 'SELECT id, admin_id, admin_name, action, action_text, target, summary, has_diff, ip, created_at'
         . ' FROM ' . Database::t('audit_logs')
         . ' WHERE ' . implode(' AND ', $where);

list($total, $rows) = Database::paginate($baseSql, $params, $page, $size, 'id DESC');

$list = [];
foreach ($rows as $r) {
    $list[] = [
        'id'          => (int) $r['id'],
        'admin_id'    => (int) $r['admin_id'],
        'admin_name'  => $r['admin_name'] ?? '',
        'action'      => $r['action'],
        'action_text' => $r['action_text'] ?: Audit::actionText($r['action']),
        'target'      => $r['target'] ?? '',
        'summary'     => $r['summary'] ?? '',
        'has_diff'    => (int) $r['has_diff'] === 1,
        'ip'          => $r['ip'] ?? '',
        'created_at'  => Util::date((int) $r['created_at']),
    ];
}

// 可选的动作列表
$actions = [];
foreach (Audit::ACTION_TEXT as $k => $v) {
    $actions[] = ['value' => $k, 'text' => $v];
}

// 可选的操作人列表
$admins = Database::all(
    'SELECT DISTINCT admin_id, admin_name FROM ' . Database::t('audit_logs')
    . ' WHERE admin_id > 0 ORDER BY admin_id ASC LIMIT 50'
);
$adminOpts = [];
foreach ($admins as $a) {
    $adminOpts[] = ['id' => (int) $a['admin_id'], 'name' => $a['admin_name'] ?: ('#' . $a['admin_id'])];
}

Response::ok([
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'list'    => $list,
    'actions' => $actions,
    'admins'  => $adminOpts,
]);
