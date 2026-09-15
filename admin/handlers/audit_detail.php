<?php
/**
 * admin action: audit_detail
 * 审计日志详情（含字段级变更明细）
 */

$id = Util::int($input, 'audit_id', 0);
if ($id <= 0) {
    Response::error(1001, '缺少 audit_id');
}

$a = Database::one('SELECT * FROM ' . Database::t('audit_logs') . ' WHERE id = ?', [$id]);
if (!$a) {
    Response::error(1001, '记录不存在');
}

$changes = [];
if (!empty($a['changes'])) {
    $decoded = json_decode($a['changes'], true);
    if (is_array($decoded)) {
        $changes = $decoded;
    }
}

Response::ok([
    'audit' => [
        'id'          => (int) $a['id'],
        'admin_id'    => (int) $a['admin_id'],
        'admin_name'  => $a['admin_name'] ?? '',
        'action'      => $a['action'],
        'action_text' => $a['action_text'] ?: Audit::actionText($a['action']),
        'target'      => $a['target'] ?? '',
        'summary'     => $a['summary'] ?? '',
        'ip'          => $a['ip'] ?? '',
        'ua'          => $a['ua'] ?? '',
        'created_at'  => Util::date((int) $a['created_at']),
    ],
    'changes' => $changes,
]);
