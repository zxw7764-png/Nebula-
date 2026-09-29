<?php
/**
 * admin action: card_batch_op
 * 卡密批量操作：批量作废 / 批量延长有效期 / 删除批次
 */

$op  = Util::str($input, 'op', '');
$now = time();

switch ($op) {
    // ---------------- 批量作废 ----------------
    case 'void':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, '请选择卡密');
        }
        if (count($ids) > 2000) {
            Response::error(1001, '单次最多操作 2000 张');
        }
        $in = implode(',', $ids);

        // 租户隔离：目标卡密必须全部归属自己范围内的软件
        Tenant::requireTouchAll($admin, 'cards', $ids);

        // 只作废未使用的
        $n = Database::exec(
            'UPDATE ' . Database::t('cards') . ' SET status = 2'
            . " WHERE id IN ($in) AND status = 0",
            []
        );

        Audit::log($admin, 'card_batch', '卡密批量(作废)', "批量作废 {$n} 张卡密",
            [], [], ['ids' => $ids, 'affected' => $n]);
        Response::ok(['count' => $n], "已作废 {$n} 张卡密");
        break;

    // ---------------- 批量延长有效期 ----------------
    case 'extend':
        $ids  = $input['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $days = Util::int($input, 'days', 0);

        if (!$ids) {
            Response::error(1001, '请选择卡密');
        }
        if ($days <= 0 || $days > 3650) {
            Response::error(1001, '天数需在 1-3650 之间');
        }
        if (count($ids) > 2000) {
            Response::error(1001, '单次最多操作 2000 张');
        }
        $in    = implode(',', $ids);
        $delta = $days * 86400;

        // 租户隔离：目标卡密必须全部归属自己范围内的软件
        Tenant::requireTouchAll($admin, 'cards', $ids);

        // expire_at = 0 表示永久有效，保持不变
        $n = Database::exec(
            'UPDATE ' . Database::t('cards')
            . ' SET expire_at = CASE'
            . '   WHEN expire_at = 0 THEN 0'
            . '   WHEN expire_at > ? THEN expire_at + ?'
            . '   ELSE ? + ?'
            . ' END'
            . " WHERE id IN ($in) AND status = 0",
            [$now, $delta, $now, $delta]
        );

        Audit::log($admin, 'card_batch', '卡密批量(延长)', "批量延长 {$n} 张卡密有效期 {$days} 天",
            [], [], ['ids' => $ids, 'days' => $days, 'affected' => $n]);
        Response::ok(['count' => $n], "已延长 {$n} 张卡密有效期 {$days} 天");
        break;

    // ---------------- 批量删除卡密（不可恢复） ----------------
    case 'delete':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, '请选择卡密');
        }
        if (count($ids) > 2000) {
            Response::error(1001, '单次最多删除 2000 张');
        }

        Deleter::confirmPassword($admin, $input, '批量删除卡密');

        // 租户隔离：删除目标必须全部归属自己范围内的软件
        Tenant::requireTouchAll($admin, 'cards', $ids);

        $scope = Util::str($input, 'scope', 'all');
        if (!in_array($scope, ['all', 'unused', 'void'], true)) {
            $scope = 'all';
        }
        $r = Deleter::cards($ids, $scope);

        Audit::log($admin, 'card_batch', '卡密批量(删除)',
            "批量删除 {$r['deleted']} 张卡密"
            . ($r['skipped'] > 0 ? "（跳过 {$r['skipped']} 张不符合条件）" : '')
            . '，示例：' . implode(', ', array_slice($r['codes'], 0, 10)),
            [], [], ['ids' => $ids, 'scope' => $scope, 'deleted' => $r['deleted'], 'codes' => $r['codes']]);

        Response::ok(['count' => $r['deleted'], 'skipped' => $r['skipped']],
            "已删除 {$r['deleted']} 张卡密"
            . ($r['skipped'] > 0 ? "，跳过 {$r['skipped']} 张" : ''));
        break;

    // ---------------- 删除批次 ----------------
    case 'delete_batch':
        $batchId = Util::int($input, 'batch_id', 0);
        if ($batchId <= 0) {
            Response::error(1001, '缺少 batch_id');
        }
        $batch = Database::one('SELECT * FROM ' . Database::t('card_batches') . ' WHERE id = ?', [$batchId]);
        if (!$batch) {
            Response::error(1001, '批次不存在');
        }
        // 租户隔离：批次必须归属自己范围内的软件
        Tenant::touchRow($admin, 'card_batches', $batch);

        Database::begin();
        try {
            if ((int) $batch['type'] === 0) {
                // 外部导入批次：清理外部卡密池中「未售」卡密；已售保留但脱离批次
                // （内容快照在订单 card_code 里，买家查看与对账不受影响）
                $del = Database::exec(
                    'DELETE FROM ' . Database::t('shop_cards') . ' WHERE batch_id = ? AND status = 0',
                    [$batchId]
                );
                Database::exec(
                    'UPDATE ' . Database::t('shop_cards') . ' SET batch_id = 0 WHERE batch_id = ?',
                    [$batchId]
                );
                $unit = '条未售外部卡密';
            } else {
                // 删除该批次中「未使用」的卡密
                $del = Database::exec(
                    'DELETE FROM ' . Database::t('cards') . ' WHERE batch_id = ? AND status = 0',
                    [$batchId]
                );
                // 已使用的卡密保留，但解除批次关联
                Database::exec(
                    'UPDATE ' . Database::t('cards') . ' SET batch_id = 0 WHERE batch_id = ?',
                    [$batchId]
                );
                $unit = '张未使用卡密';
            }
            Database::exec('DELETE FROM ' . Database::t('card_batches') . ' WHERE id = ?', [$batchId]);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('admin_batch_delete', 0, $e->getMessage());
            Response::error(9999, '删除失败：' . $e->getMessage());
        }

        Audit::log($admin, 'card_batch', "批次#{$batchId}",
            "删除批次「{$batch['name']}」，清理 {$del} {$unit}",
            ['batch' => $batch], [], ['deleted' => $del]);
        Response::ok(['deleted' => $del], "批次已删除，清理 {$del} {$unit}");
        break;

    // ---------------- 批量删除批次（不可恢复） ----------------
    case 'delete_batches':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, '请选择批次');
        }
        if (count($ids) > 500) {
            Response::error(1001, '单次最多删除 500 个批次');
        }

        Deleter::confirmPassword($admin, $input, '批量删除批次');

        // 租户隔离：批次必须全部归属自己范围内的软件
        Tenant::requireTouchAll($admin, 'card_batches', $ids);

        // 按类型分组：外部导入批次(type=0)清理外部卡密池，系统卡批次走原删除逻辑
        $in  = implode(',', $ids);
        $all = Database::all(
            'SELECT id, name, type FROM ' . Database::t('card_batches') . " WHERE id IN ({$in})"
        );
        $extIds   = [];
        $extNames = [];
        foreach ($all as $b) {
            if ((int) $b['type'] === 0) {
                $extIds[]   = (int) $b['id'];
                $extNames[] = $b['name'];
            }
        }
        $sysIds = array_values(array_diff($ids, $extIds));

        $extCards = 0;
        if ($extIds) {
            Database::begin();
            try {
                foreach ($extIds as $bid) {
                    $extCards += Database::exec(
                        'DELETE FROM ' . Database::t('shop_cards') . ' WHERE batch_id = ? AND status = 0',
                        [$bid]
                    );
                    Database::exec(
                        'UPDATE ' . Database::t('shop_cards') . ' SET batch_id = 0 WHERE batch_id = ?',
                        [$bid]
                    );
                }
                Database::exec(
                    'DELETE FROM ' . Database::t('card_batches')
                    . ' WHERE type = 0 AND id IN (' . implode(',', $extIds) . ')'
                );
                Database::commit();
            } catch (Throwable $e) {
                Database::rollback();
                Response::error(9999, '删除外部批次失败：' . $e->getMessage());
            }
        }

        $r = $sysIds ? Deleter::batches($sysIds) : ['deleted' => 0, 'cards' => 0, 'names' => []];

        $deleted = $r['deleted'] + count($extIds);
        $cards   = $r['cards'] + $extCards;
        $names   = array_merge($r['names'], $extNames);

        Audit::log($admin, 'card_batch', '批次批量(删除)',
            "批量删除 {$deleted} 个批次，清理 {$cards} 张未使用卡密："
            . implode(', ', array_slice($names, 0, 20)),
            [], [], ['ids' => $ids, 'deleted' => $deleted, 'cards' => $cards]);

        Response::ok(['count' => $deleted, 'cards' => $cards],
            "已删除 {$deleted} 个批次，清理 {$cards} 张未使用卡密");
        break;

    default:
        Response::error(1001, '未知操作');
}
