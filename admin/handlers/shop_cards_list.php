<?php
/**
 * admin action: shop_cards_list
 * 外部卡密池查看（卡密中心 → 「外部卡密」按钮）
 * ------------------------------------------------------------------
 *   无参：返回全部外部卡密商品（card_source=1）的池子汇总（未售/已售条数）
 *   plan_id>0：返回该商品的卡密明细（最新 1000 条）
 *   plan_id>0 + unsold_only=1：仅返回未售卡密内容（弹窗内一键复制导出用，最多 5000 条）
 *
 * 只读查看，权限归 card.read（与卡密中心同档，操作员可查不可改）。
 * 未跑 migrate_extcards.php（缺表/缺列）时降级返回空，不影响卡密中心。
 */

$planId     = Util::int($input, 'plan_id', 0);
$unsoldOnly = Util::int($input, 'unsold_only', 0) === 1;

$tCards = Database::t('shop_cards');
$tPlans = Database::t('shop_plans');

try {
    if ($planId > 0) {
        $plan = Database::one("SELECT id, name FROM {$tPlans} WHERE id = ? AND card_source = 1", [$planId]);
        if (!$plan) {
            Response::error(1004, '外部卡密商品不存在');
        }

        $sum = Database::one(
            "SELECT COALESCE(SUM(status = 0), 0) AS unsold, COALESCE(SUM(status = 1), 0) AS sold
             FROM {$tCards} WHERE plan_id = ?",
            [$planId]
        ) ?: ['unsold' => 0, 'sold' => 0];
        $head = [
            'plan'   => ['id' => (int) $plan['id'], 'name' => (string) $plan['name']],
            'unsold' => (int) $sum['unsold'],
            'sold'   => (int) $sum['sold'],
        ];

        if ($unsoldOnly) {
            $rows = Database::all(
                "SELECT id, content FROM {$tCards}
                 WHERE plan_id = ? AND status = 0
                 ORDER BY id ASC LIMIT 5000",
                [$planId]
            );
            Response::ok($head + ['list' => array_map(function (array $r) {
                return [
                    'id'      => (int) $r['id'],
                    'content' => (string) $r['content'],
                ];
            }, $rows)]);
        }

        $rows = Database::all(
            "SELECT id, content, status, order_id, sold_at
             FROM {$tCards} WHERE plan_id = ?
             ORDER BY id DESC LIMIT 1000",
            [$planId]
        );
        Response::ok($head + ['list' => array_map(static function (array $r) {
            $sold = (int) $r['status'] === 1;
            return [
                'id'           => (int) $r['id'],
                'content'      => (string) $r['content'],
                'status'       => (int) $r['status'],
                'order_id'     => (int) $r['order_id'],
                'sold_at_text' => $sold && (int) $r['sold_at'] > 0 ? Util::date((int) $r['sold_at']) : '',
            ];
        }, $rows)]);
    }

    // 汇总：全部外部卡密商品
    $rows = Database::all(
        "SELECT p.id AS plan_id, p.name AS plan_name,
                COALESCE(SUM(c.status = 0), 0) AS unsold, COALESCE(SUM(c.status = 1), 0) AS sold
         FROM {$tPlans} p
         LEFT JOIN {$tCards} c ON c.plan_id = p.id
         WHERE p.card_source = 1
         GROUP BY p.id, p.name
         ORDER BY p.id ASC"
    );
    Response::ok(['list' => array_map(function (array $r) {
        return [
            'plan_id'   => (int) $r['plan_id'],
            'plan_name' => (string) $r['plan_name'],
            'unsold'    => (int) $r['unsold'],
            'sold'      => (int) $r['sold'],
        ];
    }, $rows)]);
} catch (Throwable $e) {
    // 未跑迁移：缺表/缺列时降级为空
    Response::ok(['list' => []]);
}
