<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_void
 * 作废我名下未使用的卡密（是否允许由主管理员在代理档案里控制）
 */

$agentId = (int) $agent['id'];

if ((int) $agent['can_void'] !== 1) {
    Response::error(1004, '管理员未开放作废权限，请联系管理员');
}

$cardId = Util::int($input, 'card_id', 0);
if ($cardId <= 0) {
    Response::error(1001, '请选择要作废的卡密');
}

$r = Card::voidByAgent($cardId, $agentId, '代理商作废（' . $agent['username'] . '）');
if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Agent::log($agentId, 'void', '作废卡密 #' . $cardId, -1);

Response::ok(['card_id' => $cardId], $r['msg']);
