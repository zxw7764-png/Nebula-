<?php
/**
 * 每日接口调用配额（按用户组）
 * ------------------------------------------------------------------
 * 语义：
 *   nb_groups.daily_quota > 0 时生效 —— 该组下【每个用户】每天可调用的
 *   客户端接口次数上限；daily_quota = 0 表示不限。
 *   配额按「用户 × 自然日」独立计数（同一组内用户互不影响）。
 *
 * 计数范围（见 api/index.php 的 $quotaFreeActions）：
 *   计入：heartbeat / activate / unbind / devices / userinfo
 *   不计入：init / register / login / logout / notice / version
 *     —— 登录、登出属进出系统的门槛且另有独立限流，不受配额拦截，
 *        否则配额用尽后用户连「为什么不能用」的提示都看不到。
 *
 * 注意：心跳默认每 60 秒一次，一天约 1440 次。设置配额时务必把心跳算进去，
 *       否则配额会在当天较早时就被心跳耗尽。
 */
class Quota
{
    /** 请求内缓存：user_id => 配额（0=不限），避免同一请求重复查库 */
    private static array $quotaCache = [];

    /**
     * 解析某用户在当前用户组下的每日配额（0=不限）
     */
    public static function quotaOf(int $userId, ?int $groupId = null): int
    {
        if (isset(self::$quotaCache[$userId])) {
            return self::$quotaCache[$userId];
        }

        if ($groupId === null) {
            $groupId = (int) Database::value(
                'SELECT group_id FROM ' . Database::t('users') . ' WHERE id = ?',
                [$userId]
            );
        }

        $quota = 0;
        if ($groupId > 0) {
            $quota = (int) Database::value(
                'SELECT daily_quota FROM ' . Database::t('groups') . ' WHERE id = ?',
                [$groupId]
            );
        }

        self::$quotaCache[$userId] = $quota;
        return $quota;
    }

    /**
     * 计数 + 校验。超出配额返回 ok=false（业务码 5005）。
     *
     * @return array{ok:bool, code?:int, msg?:string, used:int, quota:int}
     */
    public static function enforce(int $userId, ?int $groupId = null): array
    {
        $quota = self::quotaOf($userId, $groupId);

        // 不限：不写计数表，避免无意义的表增长
        if ($quota <= 0) {
            return ['ok' => true, 'used' => 0, 'quota' => 0];
        }

        // 原子自增并取回新值：
        //   LAST_INSERT_ID(expr) 会把会话级的 last_insert_id 设为 expr，
        //   无论是 INSERT 还是 ON DUPLICATE KEY UPDATE 分支，都能一次拿到自增后的计数，
        //   避免「先 UPDATE 再 SELECT」在高并发下读到别人的值。
        Database::exec(
            'INSERT INTO ' . Database::t('api_quota') . ' (`user_id`, `day`, `cnt`) '
            . 'VALUES (?, CURDATE(), LAST_INSERT_ID(1)) '
            . 'ON DUPLICATE KEY UPDATE `cnt` = LAST_INSERT_ID(`cnt` + 1)',
            [$userId]
        );
        $used = (int) Database::value('SELECT LAST_INSERT_ID()');

        if ($used > $quota) {
            return [
                'ok'    => false,
                'code'  => 5005,
                'msg'   => '今日调用配额已用尽（' . $quota . ' 次），请明日再试或联系管理员调整用户组配额',
                'used'  => $used,
                'quota' => $quota,
            ];
        }

        return ['ok' => true, 'used' => $used, 'quota' => $quota];
    }

    /**
     * 查询某用户今日已用次数（后台展示用，不计数）
     */
    public static function usedToday(int $userId): int
    {
        return (int) Database::value(
            'SELECT cnt FROM ' . Database::t('api_quota') . ' WHERE user_id = ? AND day = CURDATE()',
            [$userId]
        );
    }

    /**
     * 某用户组今日调用总次数（后台展示用）
     */
    public static function groupUsedToday(int $groupId): int
    {
        return (int) Database::value(
            'SELECT COALESCE(SUM(q.cnt), 0) FROM ' . Database::t('api_quota') . ' q '
            . 'INNER JOIN ' . Database::t('users') . ' u ON u.id = q.user_id '
            . 'WHERE u.group_id = ? AND q.day = CURDATE()',
            [$groupId]
        );
    }

    /** 清理历史计数（默认保留 7 天，仅供定时任务调用） */
    public static function gc(int $keepDays = 7): int
    {
        return Database::exec(
            'DELETE FROM ' . Database::t('api_quota') . ' WHERE `day` < DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            [$keepDays]
        );
    }
}
