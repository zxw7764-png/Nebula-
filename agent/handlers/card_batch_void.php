<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_batch_void
 * 批量作废我名下未使用的卡密（是否允许由主管理员在代理档案里控制）
 */

$agentId = (int) $agent['id'];

if ((int) $agent['can_void'] !== 1) {
    Response::error(1004, '管理员未开放作废权限，请联系管理员');
}

$ids = $input['ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) {
    Response::error(1001, '请选择要作废的卡密');
}

// 限制单次批量数量，防止超大批次
$ids = array_slice(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; }), 0, 500);
if (count($ids) === 0) {
    Response::error(1001, '请选择要作废的卡密');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// 批量作废：只更新属于当前代理且未使用的卡密，一条原子 UPDATE 完成
$n = Database::exec(
    'UPDATE ' . Database::t('cards') . '
     SET status = ?
     WHERE agent_id = ? AND status = ? AND id IN (' . $placeholders . ')',
    array_merge([Card::STATUS_VOID, $agentId, Card::STATUS_UNUSED], $ids)
);

if ($n > 0) {
    // 批量记日志（每张一条，与单张作废保持一致）
    $now = time();
    $ip = Util::ip();
    $reason = '代理商批量作废（' . $agent['username'] . '）';
    // 取被作废的卡密 code 用于日志
    $cards = Database::all(
        'SELECT id, code FROM ' . Database::t('cards') . '
         WHERE agent_id = ? AND status = ? AND id IN (' . $placeholders . ')',
        array_merge([$agentId, Card::STATUS_VOID], $ids)
    );
    foreach ($cards as $c) {
        Database::insert('card_logs', [
            'card_id'    => (int) $c['id'],
            'code'       => $c['code'],
            'user_id'    => 0,
            'action'     => 'void',
            'detail'     => $reason,
            'ip'         => $ip,
            'created_at' => $now,
        ]);
    }

    Agent::log($agentId, 'batch_void', '批量作废 ' . $n . ' 张卡密', -$n);
}

$skipped = count($ids) - $n;
$msg = '已作废 ' . $n . ' 张卡密';
if ($skipped > 0) {
    $msg .= '（' . $skipped . ' 张非未使用状态或已跳过）';
}

Response::ok(['count' => $n, 'skipped' => $skipped], $msg);
