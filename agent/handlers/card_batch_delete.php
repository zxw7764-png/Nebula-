<?php
// 仅允许由 api.php 引入
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_batch_delete
 * 批量删除我名下的卡密（从数据库真正删除，主后台也看不到）
 * 仅允许删除未使用(status=0)和已作废(status=2)的卡密，已使用(status=1)的保留
 */

$agentId = (int) $agent['id'];

$ids = $input['ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) {
    Response::error(1001, '请选择要删除的卡密');
}

$ids = array_slice(array_filter(array_map('intval', $ids), static fn($x) => $x > 0), 0, 500);
if (count($ids) === 0) {
    Response::error(1001, '请选择要删除的卡密');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// 只删除属于当前代理且未使用/已作废的卡密（已使用的不允许删除）
$cards = Database::all(
    'SELECT id, code, status, batch_id FROM ' . Database::t('cards') . '
     WHERE agent_id = ? AND status IN (0, 2) AND id IN (' . $placeholders . ')',
    array_merge([$agentId], $ids)
);

if (!$cards) {
    Response::error(1001, '没有可删除的卡密（已使用的卡密不允许删除，请改用作废）');
}

$realIds = array_map('intval', array_column($cards, 'id'));
$realIn  = implode(',', $realIds);

Database::begin();
try {
    // 删除卡密日志
    Database::exec('DELETE FROM ' . Database::t('card_logs') . " WHERE card_id IN ({$realIn})", []);
    // 删除卡密
    Database::exec('DELETE FROM ' . Database::t('cards') . " WHERE id IN ({$realIn})", []);

    // 批次计数同步
    $batchIds = array_values(array_unique(array_filter(
        array_map('intval', array_column($cards, 'batch_id')),
        static fn($v) => $v > 0
    )));
    foreach ($batchIds as $bid) {
        $cnt = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE batch_id = ? AND agent_id = ?',
            [$bid, $agentId]
        );
        Database::exec(
            'UPDATE ' . Database::t('card_batches') . ' SET used_count = used_count, count = count WHERE id = ?',
            [$bid]
        );
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollback();
    Logger::log('agent_card_batch_delete', 0, $e->getMessage());
    Response::error(9999, '删除失败：' . $e->getMessage());
}

$deleted = count($realIds);
$skipped = count($ids) - $deleted;

Agent::log($agentId, 'batch_delete', '批量删除 ' . $deleted . ' 张卡密', -$deleted);

$msg = "已删除 {$deleted} 张卡密";
if ($skipped > 0) {
    $msg .= "（{$skipped} 张已使用不允许删除，已跳过）";
}

Response::ok(['count' => $deleted, 'skipped' => $skipped], $msg);
