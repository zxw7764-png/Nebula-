<?php
/**
 * 点数/次数卡扣点策略
 * ------------------------------------------------------------------
 * 三种扣点模式（后台「系统设置 → 业务设置」可配）：
 *   - per_login  每次登录扣 1（默认，最常见）
 *   - daily      每天首次登录扣 1（当日重复登录不扣）
 *   - online     按在线时长扣：每 N 分钟扣 1（心跳驱动，挂机也算）
 *
 * 全部使用原子 UPDATE（WHERE points > 0 / points_day <> / points_at <=）
 * 防并发透支；时长卡/永久卡 points=0 天然不受影响。
 */
class Points
{
    /** 扣点模式：per_login | daily | online */
    public static function mode(): string
    {
        $m = (string) Setting::get('points_deduct_mode', 'per_login');
        return in_array($m, ['per_login', 'daily', 'online'], true) ? $m : 'per_login';
    }

    /**
     * 该用户的实际扣点模式。
     * 次数卡（type=3）固定「每次登录扣 1」——次数就是使用次数，不随全局模式；
     * 点数卡（type=2）与其余用户走后台配置的模式。
     */
    public static function modeFor(array $user): string
    {
        if ((int) ($user['card_type'] ?? 0) === 3) {
            return 'per_login';
        }
        return self::mode();
    }

    /** online 模式：每 N 分钟扣 1 点 */
    public static function minutes(): int
    {
        $v = (int) Setting::get('points_deduct_minutes', 30);
        return $v > 0 ? $v : 30;
    }

    /**
     * 登录时扣点。
     * @param array $user 用户行（引用：成功时回写最新 points）
     * @return bool 本次是否实际扣减
     */
    public static function chargeOnLogin(array &$user): bool
    {
        if ((int) $user['points'] <= 0) {
            return false; // 时长卡/永久卡/已耗尽
        }
        $t   = Database::t('users');
        $now = time();
        $id  = (int) $user['id'];

        $mode = self::modeFor($user);
        if ($mode === 'per_login') {
            $n = Database::exec(
                "UPDATE {$t} SET points = points - 1, points_at = ?, updated_at = ?"
                . ' WHERE id = ? AND points > 0',
                [$now, $now, $id]
            );
        } elseif ($mode === 'daily') {
            $day = (int) ($now / 86400); // 当天序号（UTC 日界，足够用于防重复）
            $n = Database::exec(
                "UPDATE {$t} SET points = points - 1, points_day = ?, points_at = ?, updated_at = ?"
                . ' WHERE id = ? AND points > 0 AND points_day <> ?',
                [$day, $now, $now, $id, $day]
            );
        } else { // online：登录只重置计时起点，扣点在心跳
            Database::exec(
                "UPDATE {$t} SET points_at = ?, updated_at = ? WHERE id = ?",
                [$now, $now, $id]
            );
            $user['points_at'] = $now;
            return false;
        }

        if ($n > 0) {
            $user['points'] = (int) $user['points'] - 1;
            return true;
        }
        return false;
    }

    /**
     * 心跳时扣点（仅 online 模式）。
     * @return bool 本次是否实际扣减
     */
    public static function chargeOnHeartbeat(array $user): bool
    {
        if (self::modeFor($user) !== 'online' || (int) $user['points'] <= 0) {
            return false;
        }
        $now       = time();
        $threshold = $now - self::minutes() * 60;
        $n = Database::exec(
            'UPDATE ' . Database::t('users') . ' SET points = points - 1, points_at = ?, updated_at = ?'
            . ' WHERE id = ? AND points > 0 AND points_at <= ?',
            [$now, $now, (int) $user['id'], $threshold]
        );
        return $n > 0;
    }
}
