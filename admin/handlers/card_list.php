<?php
/**
 * admin action: card_list
 * 卡密列表
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(500, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');
$type    = Util::int($input, 'type', 0);
$batchId = Util::int($input, 'batch_id', 0);
$agentId = Util::get($input, 'agent_id', '');
$swFilter = Util::int($input, 'software_id', 0);

// 来源筛选 agent_id='ext'：外部导入卡密（nb_shop_cards，发卡商品导入池），
// 不在 nb_cards 里，走独立的 JOIN 查询并直接输出
if ($agentId === 'ext') {
    $where  = [];
    $params = [];
    if ($keyword !== '') {
        $where[] = 'c.content LIKE :kw';
        $params['kw'] = "%{$keyword}%";
    }
    if ($status !== '' && $status !== null) {
        $where[] = 'c.status = :st';
        $params['st'] = (int) $status;
    }
    if ($batchId > 0) {
        $where[] = 'c.batch_id = :bid';
        $params['bid'] = $batchId;
    }
    $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
    $baseSql = 'SELECT c.*, p.name AS plan_name
                FROM ' . Database::t('shop_cards') . ' c
                LEFT JOIN ' . Database::t('plans') . ' p ON p.id = c.plan_id
                WHERE 1=1' . $whereSql;
    [$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`c`.`id` DESC');

    $list = array_map(static function (array $c) {
        $sold = (int) $c['status'] === 1;
        return [
            'id'         => (int) $c['id'],
            'code'       => $c['content'],
            'batch_id'   => (int) $c['batch_id'],
            'type'       => 0,
            'type_text'  => '外部卡密',
            'duration'   => 0,
            'duration_text' => '-',
            'max_devices'=> 0,
            'group_id'   => 0,
            'group_name' => (string) ($c['plan_name'] ?? ''),
            'agent_id'   => 'ext',
            'agent_name' => '外部导入',
            'is_ext'     => 1,
            'status'     => (int) $c['status'],
            'status_text'=> $sold ? '已售' : '未售',
            'used_by'    => (int) $c['order_id'],
            'used_text'  => $sold ? '订单#' . (int) $c['order_id'] : '',
            'used_at'    => Util::date((int) $c['sold_at']),
            'used_ip'    => '',
            'expire_at'  => 0,
            'expire_text'=> '永久',
            'remark'     => '',
            'created_at' => Util::date((int) $c['created_at']),
        ];
    }, $rows);

    Response::ok([
        'total' => $total,
        'page'  => $page,
        'size'  => $size,
        'pages' => (int) ceil($total / $size),
        'list'  => $list,
    ]);
}

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    $where[] = 'code LIKE :kw';
    $params['kw'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($type > 0) {
    $where[] = 'type = :tp';
    $params['tp'] = $type;
}
if ($batchId > 0) {
    $where[] = 'batch_id = :bid';
    $params['bid'] = $batchId;
}
if ($swFilter > 0) {
    $where[] = 'software_id = :swid';
    $params['swid'] = $swFilter;
}
// 来源筛选：'' 全部 / '0' 官方直发 / '>0' 指定代理商
if ($agentId !== '' && $agentId !== null) {
    $where[] = 'agent_id = :aid';
    $params['aid'] = (int) $agentId;
}

$baseSql = 'SELECT * FROM ' . Database::t('cards') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

// 用户组名映射（组数量少，一次性载入）
$groupNames = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('groups') . ' ORDER BY id ASC') as $g) {
    $groupNames[(int) $g['id']] = $g['name'];
}

// 代理商名映射（卡密来源展示用）
$agentNames = Agent::nameMap();

// 软件名映射（多软件展示用）
$swNames = [];
foreach (Software::all() as $swRow) {
    $swNames[(int) $swRow['id']] = $swRow['name'];
}

$list = array_map(function ($c) use ($groupNames, $agentNames) {
    $gid = isset($c['group_id']) ? (int) $c['group_id'] : 0;
    $aid = isset($c['agent_id']) ? (int) $c['agent_id'] : 0;
    $cSw = (int) ($c['software_id'] ?? 1);
    return [
        'id'         => (int) $c['id'],
        'code'       => $c['code'],
        'batch_id'   => (int) $c['batch_id'],
        'software_id'   => $cSw,
        'software_name' => $swNames[$cSw] ?? ('软件#' . $cSw),
        'type'       => (int) $c['type'],
        'type_text'  => Card::typeName((int) $c['type']),
        'duration'   => (int) $c['duration'],
        'duration_text' => (int) $c['type'] === 1 ? Util::duration((int) $c['duration']) : (string) $c['duration'],
        'max_devices'=> (int) $c['max_devices'],
        'group_id'   => $gid,
        'group_name' => $gid > 0 ? ($groupNames[$gid] ?? ('用户组#' . $gid)) : '',
        'agent_id'   => $aid,
        'agent_name' => $aid > 0 ? ($agentNames[$aid] ?? ('代理商#' . $aid)) : '',
        'status'     => (int) $c['status'],
        'status_text'=> Card::statusName((int) $c['status']),
        'used_by'    => (int) $c['used_by'],
        'used_text'  => (int) $c['used_by'] > 0 ? 'UID:' . (int) $c['used_by'] : '',
        'used_at'    => Util::date((int) $c['used_at']),
        'used_ip'    => $c['used_ip'],
        'expire_at'  => (int) $c['expire_at'],
        'expire_text'=> (int) $c['expire_at'] > 0 ? Util::date((int) $c['expire_at']) : '永久',
        'remark'     => $c['remark'],
        'created_at' => Util::date((int) $c['created_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
]);
