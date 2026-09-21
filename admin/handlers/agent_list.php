<?php
/**
 * admin action: agent_list
 * 代理商列表
 * 参数: page, size, keyword(账号/名称/联系方式), status(1/0), mode(1/2/3), all=1 只取下拉选项
 */

// 只取下拉选项（供卡密页「来源」筛选使用）：不分页，返回 id/name 数组
if (Util::int($input, 'all', 0) === 1) {
    $options = [];
    foreach (Database::all(
        'SELECT id, username, nickname FROM ' . Database::t('agents') . ' ORDER BY id ASC'
    ) as $a) {
        $options[] = [
            'id'   => (int) $a['id'],
            'name' => (string) ($a['nickname'] !== null && $a['nickname'] !== '' ? $a['nickname'] : $a['username']),
        ];
    }
    Response::ok([
        'options'    => $options,
        'modes'      => Agent::allModes(),
        'card_types' => array_map(function ($t) { return ['type' => $t, 'name' => Card::typeName($t)]; }, Card::TYPE_LIST),
    ]);
}

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');
$mode    = Util::int($input, 'mode', 0);

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    // 命名占位符在真实预处理下不可重复使用，这里拆成三个
    $where[] = '(username LIKE :kw1 OR nickname LIKE :kw2 OR contact LIKE :kw3)';
    $params['kw1'] = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
    $params['kw3'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($mode > 0) {
    $where[] = 'charge_mode = :md';
    $params['md'] = $mode;
}

$baseSql = 'SELECT * FROM ' . Database::t('agents') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

$list = [];
foreach ($rows as $a) {
    $info = Agent::publicInfo($a);
    $info['stats'] = Agent::stats((int) $a['id']);
    $list[] = $info;
}

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
    'modes' => Agent::allModes(),
]);
