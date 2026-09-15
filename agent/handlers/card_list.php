<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_list
 * 我生成的卡密（仅返回归属本代理的卡密）
 */

$agentId = (int) $agent['id'];
$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');
$batchId = Util::int($input, 'batch_id', 0);

$where  = ['agent_id = :aid'];
$params = ['aid' => $agentId];

if ($keyword !== '') {
    $where[] = 'code LIKE :kw';
    $params['kw'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($batchId > 0) {
    $where[] = 'batch_id = :bid';
    $params['bid'] = $batchId;
}

$baseSql = 'SELECT * FROM ' . Database::t('cards') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

$list = array_map(static function (array $c) {
    return [
        'id'            => (int) $c['id'],
        'code'          => $c['code'],
        'batch_id'      => (int) $c['batch_id'],
        'type'          => (int) $c['type'],
        'type_text'     => Card::typeName((int) $c['type']),
        'duration_text' => (int) $c['type'] === 1 ? Util::duration((int) $c['duration']) : (string) $c['duration'],
        'max_devices'   => (int) $c['max_devices'],
        'status'        => (int) $c['status'],
        'status_text'   => Card::statusName((int) $c['status']),
        'used_by'       => (int) $c['used_by'],
        'used_at_text'  => (int) $c['used_at'] > 0 ? Util::date((int) $c['used_at']) : '',
        'expire_at'     => (int) $c['expire_at'],
        'expire_text'   => (int) $c['expire_at'] > 0 ? Util::date((int) $c['expire_at']) : '永久',
        'created_at_text' => Util::date((int) $c['created_at']),
    ];
}, $rows);

Response::ok([
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'pages'   => (int) ceil($total / $size),
    'list'    => $list,
    'can_void'=> (int) $agent['can_void'] === 1,
]);
