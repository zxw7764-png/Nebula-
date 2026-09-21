<?php
/**
 * admin action: agent_recharge_list
 * 代理商充值卡密列表（余额充值 / 张数额度）
 * 参数: page, size, keyword(卡密/备注), kind(1余额 2额度), status(1/0), usable=1 只看真正可用的码
 * ------------------------------------------------------------------
 * 充值卡密与「注册用激活码」是两张表：激活码用于开户，充值卡密用于续费 / 加量。
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');
$kind    = Util::get($input, 'kind', '');

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    $where[] = '(code LIKE :kw1 OR remark LIKE :kw2)';
    $params['kw1'] = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($kind !== '' && $kind !== null) {
    $where[] = 'kind = :kd';
    $params['kd'] = (int) $kind;
}
// 只看可用：启用 + 未过期 + 还有剩余次数
if (Util::int($input, 'usable', 0) === 1) {
    $where[] = 'status = 1';
    $where[] = '(expire_at = 0 OR expire_at >= UNIX_TIMESTAMP())';
    $where[] = 'used_count < max_uses';
}

$baseSql = 'SELECT * FROM ' . Database::t('agent_recharge_codes') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

$list = [];
foreach ($rows as $r) {
    $list[] = AgentRecharge::publicInfo($r);
}

Response::ok([
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'pages'   => (int) ceil($total / $size),
    'list'    => $list,
    'kinds'   => AgentRecharge::kinds(),
    // 卡类型选项（唯一来源：Card::typeName），前端表单据此渲染
    'card_types' => array_map(function ($t) { return ['type' => $t, 'name' => Card::typeName($t)]; }, Card::TYPE_LIST),
]);
