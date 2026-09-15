<?php
/**
 * 会话管理
 */
class Session
{
    /**
     * 创建会话
     */
    public static function create(int $userId, array $info, int $ttl): string
    {
        $token = Util::token(32);
        $now   = time();

        // 同一用户同一设备，踢掉旧会话
        if (!empty($info['machine_id'])) {
            Database::exec(
                'UPDATE ' . Database::t('sessions') . '
                 SET status = 2 WHERE user_id = ? AND machine_id = ? AND status = 1',
                [$userId, $info['machine_id']]
            );
        }

        Database::insert('sessions', [
            'token'       => $token,
            'user_id'     => $userId,
            'software_id' => Software::currentId(),
            'machine_id'  => $info['machine_id'] ?? null,
            'ip'          => $info['ip'] ?? null,
            'client_ver'  => $info['client_ver'] ?? null,
            'login_at'    => $now,
            'last_active' => $now,
            'expire_at'   => $now + $ttl,
            'status'      => 1,
        ]);

        return $token;
    }

    /**
     * 校验令牌
     * @return array{ok:bool, code:int, msg:string, session:?array}
     */
    public static function validate(string $token, ?string $machineId = null): array
    {
        if ($token === '') {
            return ['ok' => false, 'code' => 1002, 'msg' => '缺少令牌', 'session' => null];
        }

        $s = Database::one(
            'SELECT * FROM ' . Database::t('sessions') . ' WHERE token = ?',
            [$token]
        );

        if (!$s) {
            return ['ok' => false, 'code' => 1002, 'msg' => '令牌无效', 'session' => null];
        }
        if ((int) $s['status'] === 2) {
            return ['ok' => false, 'code' => 1002, 'msg' => '账号已在其他设备登录', 'session' => $s];
        }
        if ((int) $s['status'] === 3) {
            return ['ok' => false, 'code' => 1002, 'msg' => '账号已被强制下线', 'session' => $s];
        }
        if ((int) $s['status'] === 0) {
            return ['ok' => false, 'code' => 1002, 'msg' => '会话已退出', 'session' => $s];
        }
        if ($s['expire_at'] > 0 && (int) $s['expire_at'] < time()) {
            return ['ok' => false, 'code' => 1003, 'msg' => '会话已过期', 'session' => $s];
        }

        // 设备一致性校验：防止令牌被复制到其他机器
        if ($machineId !== null && $machineId !== '' && $s['machine_id'] !== null
            && !hash_equals((string) $s['machine_id'], $machineId)) {
            return ['ok' => false, 'code' => 4002, 'msg' => '令牌与设备不匹配', 'session' => $s];
        }

        // 软件一致性校验：会话只能用于登录它的那个软件（多软件隔离）
        $swId = (int) ($s['software_id'] ?? 0);
        if ($swId > 0 && Software::currentId() > 0 && $swId !== Software::currentId()) {
            return ['ok' => false, 'code' => 4005, 'msg' => '会话与当前软件不匹配', 'session' => $s];
        }

        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'session' => $s];
    }

    /** 刷新活跃时间与过期时间 */
    public static function touch(string $token, int $ttl): void
    {
        Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET last_active = ?, expire_at = ? WHERE token = ?',
            [time(), time() + $ttl, $token]
        );
    }

    /** 主动退出 */
    public static function destroy(string $token): void
    {
        Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET status = 0 WHERE token = ?',
            [$token]
        );
    }

    /** 踢出某用户所有会话（status=3：管理员/系统踢出，区别于顶号 status=2） */
    public static function kickUser(int $userId): int
    {
        return Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE user_id = ? AND status = 1',
            [$userId]
        );
    }

    /** 踢出指定会话（status=3） */
    public static function kickById(int $sessionId): bool
    {
        return Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE id = ?',
            [$sessionId]
        ) > 0;
    }

    /** 在线列表 */
    public static function online(int $timeout): array
    {
        return Database::all(
            'SELECT s.*, u.username FROM ' . Database::t('sessions') . ' s
             LEFT JOIN ' . Database::t('users') . ' u ON u.id = s.user_id
             WHERE s.status = 1 AND s.last_active > ?
             ORDER BY s.last_active DESC',
            [time() - $timeout]
        );
    }

    /** 在线人数 */
    public static function onlineCount(int $timeout): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('sessions') . ' WHERE status = 1 AND last_active > ?',
            [time() - $timeout]
        );
    }

    /**
     * 在线统计快照
     * ------------------------------------------------------------------
     * 客户端 /api/online 与官网 /web/api.php?action=online 共用同一口径：
     * nb_sessions 中 status = 1 且 last_active 在心跳超时内的会话数，
     * 与后台首页「在线会话」完全一致，避免三处口径打架。
     */
    public static function onlineStat(): array
    {
        $timeout = Policy::heartbeatTimeout();
        if ($timeout <= 0) {
            $timeout = 180;
        }

        return [
            'online'      => self::onlineCount($timeout),
            // 在线判定窗口（秒）：超过这个时长没心跳就算离线
            'timeout'     => $timeout,
            'server_time' => time(),
        ];
    }

    /** 清理过期会话 */
    public static function gc(): int
    {
        return Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET status = 0
             WHERE status = 1 AND expire_at > 0 AND expire_at < ?',
            [time()]
        );
    }
}
