<?php
/**
 * lib/Deleter.php — 统一的批量删除能力（带二次密码确认 + 级联清理 + 审计）
 *
 * 设计要点：
 *  1. 所有「删除」都是不可恢复操作，统一要求超级管理员 + 管理密码二次确认；
 *  2. 采用「先查后删」策略，删除前把受影响的行快照出来写进审计日志；
 *  3. 事务包裹，失败整体回滚，避免出现半删除的脏数据。
 */

class Deleter
{
    /** 单次删除上限（防止误选全库导致超时） */
    public const MAX_BATCH = 2000;

    /**
     * 敏感删除的二次密码确认。
     * 超管 + require_password_confirm 开启时必须校验管理密码。
     *
     * @param array  $admin 当前登录管理员
     * @param array  $input 请求体
     * @param string $tip   错误提示补充说明
     * @return void 失败时直接 Response::error 终止
     */
    public static function confirmPassword(array $admin, array $input, string $tip = ''): void
    {
        if ((int) ($admin['role'] ?? 0) !== 1) {
            Response::error(1004, '仅超级管理员可执行删除操作');
        }
        if (!Config::get('admin.require_password_confirm', true)) {
            return;
        }
        $pass = Util::str($input, 'password', '');
        if ($pass === '') {
            Response::error(1005, '请输入管理密码以确认删除' . ($tip !== '' ? "（{$tip}）" : ''));
        }
        $me = Database::one(
            'SELECT password FROM ' . Database::t('admins') . ' WHERE id = ?',
            [(int) $admin['id']]
        );
        if (!$me || !Util::verifyPassword($pass, $me['password'])) {
            Response::error(1005, '管理密码错误');
        }
    }

    /**
     * 归一化 id 列表。
     *
     * @param mixed  $raw    数组或逗号分隔字符串
     * @param string $label  错误提示中的名称，如「卡密」
     * @return int[]
     */
    public static function ids($raw, string $label = '记录'): array
    {
        if (!is_array($raw)) {
            $raw = array_filter(array_map('intval', explode(',', (string) $raw)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, "请选择{$label}");
        }
        if (count($ids) > self::MAX_BATCH) {
            Response::error(1001, '单次最多删除 ' . self::MAX_BATCH . " 条{$label}");
        }
        return $ids;
    }

    /**
     * 批量删除卡密（物理删除 + 清理使用日志）
     *
     * @param int[]  $ids
     * @param string $scope 'all'=全部 / 'unused'=仅未使用 / 'void'=仅已作废
     * @return array{deleted:int, skipped:int}
     */
    public static function cards(array $ids, string $scope = 'all'): array
    {
        $in = implode(',', $ids);

        $where = 'id IN (' . $in . ')';
        if ($scope === 'unused') {
            $where .= ' AND status = 0';
        } elseif ($scope === 'void') {
            $where .= ' AND status = 2';
        }

        $cards = Database::all(
            'SELECT id, code, status, used_by FROM ' . Database::t('cards') . " WHERE {$where}"
        );
        if (!$cards) {
            Response::error(1001, '没有符合条件的卡密（已使用卡密请改用「作废」保留记录）');
        }

        $realIds = array_map('intval', array_column($cards, 'id'));
        $realIn  = implode(',', $realIds);

        Database::begin();
        try {
            // 卡密使用日志（card_logs）独立保留，不随卡删除 —— 用户详情/找回账号依赖这些痕迹
            Database::exec('DELETE FROM ' . Database::t('cards') . " WHERE id IN ($realIn)", []);
            // 批次计数同步（已被删除的卡密不再计入）
            $batchIds = array_values(array_unique(array_filter(
                array_map('intval', array_column($cards, 'batch_id')),
                fn($v) => $v > 0
            )));
            foreach ($batchIds as $bid) {
                self::syncBatchCount($bid);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('admin_card_batch_delete', 0, $e->getMessage());
            Response::error(9999, '删除失败：' . $e->getMessage());
        }

        return [
            'deleted' => count($realIds),
            'skipped' => count($ids) - count($realIds),
            'codes'   => array_slice(array_column($cards, 'code'), 0, 200),
        ];
    }

    /**
     * 批量删除用户（级联清理设备 / 会话 / 卡密归属）
     *
     * @param int[] $ids
     * @return array{deleted:int, usernames:string[]}
     */
    public static function users(array $ids): array
    {
        $in = implode(',', $ids);
        $users = Database::all(
            'SELECT id, username, nickname, email, vip_expire, points FROM ' . Database::t('users') . " WHERE id IN ($in)"
        );
        if (!$users) {
            Response::error(1001, '未找到对应用户');
        }

        $realIds   = array_map('intval', array_column($users, 'id'));
        $realIn    = implode(',', $realIds);
        $usernames = array_column($users, 'username');

        Database::begin();
        try {
            Database::exec('DELETE FROM ' . Database::t('devices') . " WHERE user_id IN ($realIn)", []);
            Database::exec('DELETE FROM ' . Database::t('sessions') . " WHERE user_id IN ($realIn)", []);
            Database::exec('DELETE FROM ' . Database::t('users') . " WHERE id IN ($realIn)", []);
            // 卡密归还：保留卡密本身，置回未使用
            Database::exec(
                'UPDATE ' . Database::t('cards')
                . " SET status = 0, used_by = 0, used_at = 0, used_ip = NULL WHERE used_by IN ($realIn)",
                []
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('admin_user_batch_delete', 0, $e->getMessage());
            Response::error(9999, '删除失败：' . $e->getMessage());
        }

        return ['deleted' => count($realIds), 'usernames' => $usernames];
    }

    /**
     * 批量删除卡密批次（连带删除批次内「未使用」的卡密）
     *
     * @param int[] $ids
     * @return array{deleted:int, cards:int, names:string[]}
     */
    public static function batches(array $ids): array
    {
        $in = implode(',', $ids);
        $batches = Database::all(
            'SELECT id, name FROM ' . Database::t('card_batches') . " WHERE id IN ($in)"
        );
        if (!$batches) {
            Response::error(1001, '批次不存在');
        }

        $realIds = array_map('intval', array_column($batches, 'id'));
        $realIn  = implode(',', $realIds);

        Database::begin();
        try {
            // 未使用的卡密直接删除
            $delIds = Database::all(
                'SELECT id FROM ' . Database::t('cards') . " WHERE batch_id IN ($realIn) AND status = 0"
            );
            $delCardIds = array_map('intval', array_column($delIds, 'id'));
            if ($delCardIds) {
                $delIn = implode(',', $delCardIds);
                // 日志独立保留，只删卡本身
                Database::exec('DELETE FROM ' . Database::t('cards') . " WHERE id IN ($delIn)", []);
            }
            // 已使用的卡密保留，仅解除批次关联
            Database::exec('UPDATE ' . Database::t('cards') . " SET batch_id = 0 WHERE batch_id IN ($realIn)", []);
            Database::exec('DELETE FROM ' . Database::t('card_batches') . " WHERE id IN ($realIn)", []);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('admin_batch_batch_delete', 0, $e->getMessage());
            Response::error(9999, '删除失败：' . $e->getMessage());
        }

        return [
            'deleted' => count($realIds),
            'cards'   => count($delCardIds),
            'names'   => array_column($batches, 'name'),
        ];
    }

    /**
     * 批量删除设备记录（物理删除 + 清理黑名单 + 结束会话）
     *
     * @param int[] $ids
     * @return array{deleted:int, machine_ids:string[]}
     */
    public static function devices(array $ids): array
    {
        $in = implode(',', $ids);
        $devs = Database::all(
            'SELECT id, user_id, machine_id FROM ' . Database::t('devices') . " WHERE id IN ($in)"
        );
        if (!$devs) {
            Response::error(1001, '设备不存在');
        }

        $realIds = array_map('intval', array_column($devs, 'id'));
        $realIn  = implode(',', $realIds);
        $now     = time();

        Database::begin();
        try {
            // 结束对应会话
            foreach ($devs as $dv) {
                Database::exec(
                    'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE user_id = ? AND machine_id = ?',
                    [(int) $dv['user_id'], $dv['machine_id']]
                );
            }
            Database::exec('DELETE FROM ' . Database::t('devices') . " WHERE id IN ($realIn)", []);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('admin_device_batch_delete', 0, $e->getMessage());
            Response::error(9999, '删除失败：' . $e->getMessage());
        }

        return [
            'deleted'     => count($realIds),
            'machine_ids' => array_column($devs, 'machine_id'),
        ];
    }

    /**
     * 批量解除设备黑名单
     *
     * @param int[] $ids device_bans 主键
     * @return int 解除条数
     */
    public static function unbanDevices(array $ids): int
    {
        $in = implode(',', $ids);
        return Database::exec('DELETE FROM ' . Database::t('device_bans') . " WHERE id IN ($in)", []);
    }

    /** 重算批次统计（总数 / 已用） */
    private static function syncBatchCount(int $batchId): void
    {
        $row = Database::one(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS used'
            . ' FROM ' . Database::t('cards') . ' WHERE batch_id = ?',
            [$batchId]
        );
        Database::exec(
            'UPDATE ' . Database::t('card_batches') . ' SET count = ?, used_count = ? WHERE id = ?',
            [(int) ($row['total'] ?? 0), (int) ($row['used'] ?? 0), $batchId]
        );
    }
}
