<?php
/**
 * admin action: card_void
 * 作废卡密（单张或按批次）
 */

$op      = Util::str($input, 'op', 'single');
$cardId  = Util::int($input, 'card_id', 0);
$batchId = Util::int($input, 'batch_id', 0);
$reason  = Util::str($input, 'reason', '管理员作废');

if ($op === 'batch') {
    if ($batchId <= 0) {
        Response::error(1001, '缺少 batch_id');
    }
    $n = Card::voidBatch($batchId);
    Audit::log($admin, 'card_void', "批次#{$batchId}", "作废批次 {$batchId} 的 {$n} 张卡密",
        [], [], ['batch_id' => $batchId, 'count' => $n, 'reason' => $reason]);
    Response::ok(['count' => $n], "已作废 {$n} 张卡密");
}

if ($cardId <= 0) {
    Response::error(1001, '缺少 card_id');
}

$card = Database::one('SELECT * FROM ' . Database::t('cards') . ' WHERE id = ?', [$cardId]);
$ok   = Card::void($cardId, $reason);

Audit::log($admin, 'card_void', "卡密#{$cardId}" . ($card ? ' ' . $card['code'] : ''),
    $ok ? "作废卡密" : '作废失败（可能已使用）',
    ['status' => $card ? (int) $card['status'] : null],
    ['status' => 2],
    ['reason' => $reason]);

if (!$ok) {
    Response::error(1001, '作废失败：卡密不存在或已使用');
}

Response::ok(null, '卡密已作废');
