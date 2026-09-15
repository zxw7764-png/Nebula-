<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: dashboard
 * 概览：额度/余额 + 统计 + 最近操作记录
 */

$id = (int) $agent['id'];

$logs = [];
foreach (Agent::recentLogs($id, 10) as $l) {
    $logs[] = [
        'action'      => (string) $l['action'],
        'action_text' => Agent::actionName((string) $l['action']),
        'detail'      => (string) ($l['detail'] ?? ''),
        'amount'      => (int) $l['amount'],
        'time_text'   => Util::date((int) $l['created_at']),
    ];
}

Response::ok([
    'agent' => Agent::publicInfo($agent),
    'stats' => Agent::stats($id),
    'logs'  => $logs,
]);
