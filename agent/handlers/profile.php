<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: profile
 * 我的资料 + 额度/余额 + 发货统计
 */

Response::ok([
    'agent'  => Agent::publicInfo($agent),
    'stats'  => Agent::stats((int) $agent['id']),
    'modes'  => Agent::allModes(),
]);
