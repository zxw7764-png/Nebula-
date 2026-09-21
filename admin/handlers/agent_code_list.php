<?php
/**
 * admin action: agent_code_list
 * 代理商激活码列表
 * 参数: page, size, keyword(激活码/名称/备注), status(1/0), usable=1 只看真正可用的码
 * ------------------------------------------------------------------
 * 列表里附带「该码注册出来的代理」，便于对账：谁用哪个码注册的、码还剩几次。
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    $where[] = '(code LIKE :kw1 OR nickname LIKE :kw2 OR remark LIKE :kw3)';
    $params['kw1'] = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
    $params['kw3'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
// 只看可用：启用 + 未过期 + 还有剩余次数
if (Util::int($input, 'usable', 0) === 1) {
    $where[] = 'status = 1';
    $where[] = '(expire_at = 0 OR expire_at >= UNIX_TIMESTAMP())';
    $where[] = 'used_count < max_uses';
}

$baseSql = 'SELECT * FROM ' . Database::t('agent_codes') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

// 批量取「每个码注册出来的代理」，避免逐条查询
$codeList = array_column($rows, 'code');
$byCode   = [];
if ($codeList) {
    $in = implode(',', array_fill(0, count($codeList), '?'));
    foreach (Database::all(
        'SELECT id, username, nickname, status, created_at, reg_code
         FROM ' . Database::t('agents') . " WHERE reg_code IN ($in) ORDER BY id DESC",
        $codeList
    ) as $a) {
        $byCode[(string) $a['reg_code']][] = [
            'id'              => (int) $a['id'],
            'username'        => (string) $a['username'],
            'nickname'        => (string) ($a['nickname'] ?? ''),
            'status'          => (int) $a['status'],
            'created_at_text' => Util::date((int) $a['created_at']),
        ];
    }
}

$list = [];
foreach ($rows as $c) {
    $info = AgentCode::publicInfo($c);
    $info['agents']    = $byCode[(string) $c['code']] ?? [];
    $info['reg_count'] = count($info['agents']);
    $list[] = $info;
}

Response::ok([
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'pages'   => (int) ceil($total / $size),
    'list'    => $list,
    'modes'   => Agent::allModes(),
    // 卡类型选项（唯一来源：Card::typeName），前端表单据此渲染
    'card_types' => array_map(
        function ($t) { return ['type' => $t; }, 'name' => Card::typeName($t)],
        Card::TYPE_LIST
    ),
]);
