<?php
/**
 * admin action: version_batch
 * 版本批量操作（op=publish 发布 | unpublish 下架 | delete 删除）
 */

$op  = Util::str($input, 'op', '');
$ids = array_values(array_unique(array_filter(
    array_map('intval', (array) ($input['ids'] ?? [])),
    fn ($v) => $v > 0
)));

if (!in_array($op, ['publish', 'unpublish', 'delete'], true) || $ids === []) {
    Response::error(1001, '缺少操作类型或未选择版本');
}

// 发布 / 下架：一条 UPDATE 搞定
if ($op !== 'delete') {
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $n = Database::exec(
        'UPDATE ' . Database::t('versions') . " SET status = ? WHERE id IN ($ph)",
        array_merge([$op === 'publish' ? 1 : 0], $ids)
    );
    Audit::log($admin, 'version_batch', '版本批量',
        ($op === 'publish' ? '批量发布 ' : '批量下架 ') . (int) $n . " 个版本（#{$ids[0]}" . (count($ids) > 1 ? ' 等' . count($ids) . '个' : '') . '）');
    Response::ok(['count' => (int) $n], $op === 'publish' ? "已发布 {$n} 个版本" : "已下架 {$n} 个版本");
}

// 删除
$done = 0;
foreach ($ids as $id) {
    Database::exec('DELETE FROM ' . Database::t('versions') . ' WHERE id = ?', [$id]);
    Audit::log($admin, 'version_batch', "版本#{$id}", '批量删除版本');
    $done++;
}
Response::ok(['deleted' => $done], "已删除 {$done} 个版本");
