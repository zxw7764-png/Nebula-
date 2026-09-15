<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_export
 * 导出我名下的卡密（txt / csv），只允许导出归属本代理的卡密
 */

$agentId = (int) $agent['id'];
$format  = Util::str($input, 'format', 'txt');
$status  = Util::get($input, 'status', '0');
$batchId = Util::int($input, 'batch_id', 0);
$limit   = min(20000, max(1, Util::int($input, 'limit', 10000)));

$where  = ['agent_id = :aid'];
$params = ['aid' => $agentId];

if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($batchId > 0) {
    $where[] = 'batch_id = :bid';
    $params['bid'] = $batchId;
}

$rows = Database::all(
    'SELECT code, type, duration, max_devices, status, used_at, expire_at, created_at
     FROM ' . Database::t('cards') . '
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY id ASC LIMIT ' . $limit,
    $params
);

if (!$rows) {
    Response::error(1001, '没有符合条件的卡密');
}

Agent::log($agentId, 'export', '导出 ' . count($rows) . ' 条卡密（' . $format . '）', count($rows));

$filename = 'agent_' . $agent['username'] . '_' . date('YmdHis') . '.' . ($format === 'csv' ? 'csv' : 'txt');

header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// BOM 让 Excel 正确识别 UTF-8
echo "\xEF\xBB\xBF";

if ($format === 'csv') {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['卡密', '类型', '时长/点数', '最大设备', '状态', '使用时间', '卡密有效期', '生成时间']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['code'],
            Card::typeName((int) $r['type']),
            $r['duration'],
            $r['max_devices'],
            Card::statusName((int) $r['status']),
            Util::date((int) $r['used_at']),
            (int) $r['expire_at'] > 0 ? Util::date((int) $r['expire_at']) : '永久',
            Util::date((int) $r['created_at']),
        ]);
    }
    fclose($out);
} else {
    foreach ($rows as $r) {
        echo $r['code'] . "\r\n";
    }
}
exit;
