<?php
/**
 * action: notice
 * 获取公告列表（无需登录）
 * 参数: id (可选，获取单条)
 */

$id  = (int) Util::get($requestData, 'id', 0);
$now = time();

if ($id > 0) {
    $row = Database::one(
        'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
         WHERE id = ? AND status = 1',
        [$id]
    );
    if (!$row) {
        Response::error(1001, '公告不存在');
    }
    $row['created_at_text'] = Util::date((int) $row['created_at']);
    Response::ok($row);
}

$list = Database::all(
    'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
     WHERE status = 1 AND type IN (2, 3, 4)
       AND (start_at = 0 OR start_at <= ?)
       AND (end_at = 0 OR end_at >= ?)
     ORDER BY type ASC, sort DESC, id DESC LIMIT 20',
    [$now, $now]
);

foreach ($list as &$r) {
    $r['created_at_text'] = Util::date((int) $r['created_at']);
    $r['type_text'] = [2 => '弹窗公告', 3 => '立即公告', 4 => '列表公告'][(int) $r['type']] ?? '公告';
}
unset($r);

Response::ok(['list' => $list, 'total' => count($list)]);
