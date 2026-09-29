<?php
/**
 * 风险评分模型（Risk Score）
 *
 * 四维加权，全部维度可通过 Config 调整：
 *   - IP 异常     +30  security.risk_ip_weight     （该用户最近登录 IP 近 24h 登录失败 ≥ bruteforce_ip_threshold）
 *   - 账号失败    +20  security.risk_user_weight   （该账号近 24h 登录失败 ≥ bruteforce_user_threshold）
 *   - 设备异常    +20  security.risk_device_weight （名下在用设备 vm_flag=1 或带 risk_flags）
 *   - 代理异常    +30  security.risk_agent_weight  （发卡代理商被锁定 / 连续登录失败 ≥ 5）
 *
 * 总分 ≥ security.risk_freeze_score（默认 80）且账号状态正常时自动冻结
 * （nb_users.status=2），并写日志 action=risk_freeze。
 *
 * 评分结果按用户缓存 10 分钟（Cache 三级驱动），后台列表与实时评估共用。
 */

class RiskScore
{
    /** 单用户缓存时长（秒） */
    public const TTL = 600;

    /** 评估单个用户（persist=true 时按阈值自动冻结） */
    public static function evaluate(int $userId, bool $persist = false): array
    {
        $ck = 'risk_user_' . $userId;
        $hit = Cache::get($ck);
        if (is_array($hit) && isset($hit['score'])) {
            return $hit;
        }

        $r = self::compute($userId);
        Cache::set($ck, $r, self::TTL);

        if ($persist) {
            self::maybeFreeze($userId, $r);
        }
        return $r;
    }

    /** 列表行快捷取分（内部走同一缓存） */
    public static function scoreOf(array $userRow): int
    {
        $r = self::evaluate((int) $userRow['id']);
        return (int) $r['score'];
    }

    /** 清除某用户评分缓存（管理员解冻/处理后调用） */
    public static function flush(int $userId): void
    {
        Cache::del('risk_user_' . $userId);
    }

    // ------------------------------------------------------------------

    /**
     * 读取后台设置项，未配置/存空时回退出厂默认
     * （config.php 里没有 risk_* 出厂值，不能用 intWithConfig 的 0 兜底）
     */
    private static function w(string $key, int $default): int
    {
        if (!Setting::isSet($key)) {
            return $default;
        }
        $v = trim((string) Setting::get($key, ''));
        return $v === '' ? $default : max(0, (int) $v);
    }

    private static function compute(int $userId): array
    {
        $since = time() - 86400;
        $bd = [];   // breakdown

        $user = Database::one(
            'SELECT id, username, status, card_code, last_login_ip, login_fail_cnt FROM ' . Database::t('users') . ' WHERE id = ?',
            [$userId]
        );
        if (!$user) {
            return ['score' => 0, 'breakdown' => [], 'frozen' => false];
        }

        // 1) IP 异常：该用户最近登录 IP 的 24h 全局登录失败次数
        $ipTh = max(5, (int) Config::get('security.bruteforce_ip_threshold', 10));
        $ipW  = self::w('risk_ip_weight', 30);
        $ip   = (string) ($user['last_login_ip'] ?? '');
        if ($ip !== '') {
            $row = Database::one(
                'SELECT COUNT(*) c FROM ' . Database::t('logs')
                . " WHERE action = 'login' AND result = 0 AND ip = ? AND created_at >= ?",
                [$ip, $since]
            );
            $c = (int) ($row['c'] ?? 0);
            if ($c >= $ipTh) {
                $bd[] = ['dim' => 'IP 异常', 'hit' => "IP {$ip} 近24h登录失败 {$c} 次（阈值 {$ipTh}）", 'score' => $ipW];
            }
        }

        // 2) 账号失败：本账号 24h 失败次数 或 当前连续失败计数
        $uTh = max(5, (int) Config::get('security.bruteforce_user_threshold', 10));
        $uW  = self::w('risk_user_weight', 20);
        $row = Database::one(
            'SELECT COUNT(*) c FROM ' . Database::t('logs')
            . " WHERE action = 'login' AND result = 0 AND username = ? AND created_at >= ?",
            [(string) $user['username'], $since]
        );
        $c = (int) ($row['c'] ?? 0);
        $failTh = max(3, (int) Config::get('policy.login_fail_threshold', 5));
        if ($c >= $uTh || (int) $user['login_fail_cnt'] >= $failTh) {
            $bd[] = ['dim' => '账号失败', 'hit' => "近24h失败 {$c} 次 / 连续失败 " . (int) $user['login_fail_cnt'] . " 次", 'score' => $uW];
        }

        // 3) 设备异常：名下在用设备带 VM/模拟器标记或风险标记
        $dW = self::w('risk_device_weight', 20);
        $row = Database::one(
            'SELECT COUNT(*) c FROM ' . Database::t('devices')
            . " WHERE user_id = ? AND status = 1 AND (vm_flag = 1 OR (risk_flags IS NOT NULL AND risk_flags <> ''))",
            [$userId]
        );
        $c = (int) ($row['c'] ?? 0);
        if ($c > 0) {
            $bd[] = ['dim' => '设备异常', 'hit' => "{$c} 台在用设备疑似虚拟机/模拟器或带风险标记", 'score' => $dW];
        }

        // 4) 代理异常：激活卡密归属的代理商被锁 / 连续登录失败
        $aW = self::w('risk_agent_weight', 30);
        $cardCode = (string) ($user['card_code'] ?? '');
        if ($cardCode !== '') {
            $agentId = (int) (Database::one(
                'SELECT agent_id FROM ' . Database::t('cards') . ' WHERE code = ?',
                [$cardCode]
            )['agent_id'] ?? 0);
            if ($agentId > 0) {
                $ag = Database::one(
                    'SELECT username, lock_until, login_fail_cnt FROM ' . Database::t('agents') . ' WHERE id = ?',
                    [$agentId]
                );
                if ($ag && ((int) $ag['lock_until'] > time() || (int) $ag['login_fail_cnt'] >= 5)) {
                    $bd[] = ['dim' => '代理异常', 'hit' => '发卡代理 ' . $ag['username'] . ' 处于锁定/异常状态', 'score' => $aW];
                }
            }
        }

        return [
            'score'     => array_sum(array_column($bd, 'score')),
            'breakdown' => $bd,
            'frozen'    => false,
        ];
    }

    /** 达到冻结阈值且账号正常时自动冻结 */
    private static function maybeFreeze(int $userId, array $r): void
    {
        $th = self::w('risk_freeze_score', 80);
        if ($th <= 0 || $r['score'] < $th || empty($r['breakdown'])) {
            return;
        }
        $u = Database::one(
            'SELECT id, username, status FROM ' . Database::t('users') . ' WHERE id = ?',
            [$userId]
        );
        if (!$u || (int) $u['status'] !== 1) {
            return; // 只冻结正常状态账号；封禁(0)/已冻结(2)不动
        }
        try {
            Database::exec(
                'UPDATE ' . Database::t('users') . ' SET status = 2, updated_at = ? WHERE id = ? AND status = 1',
                [time(), $userId]
            );
        } catch (Throwable $e) {
            return;
        }
        $dims = implode('、', array_column($r['breakdown'], 'dim'));
        Logger::log('risk_freeze', 0, '风险评分 ' . $r['score'] . ' 自动冻结：' . $u['username'] . '（' . $dims . '）');
        $r['frozen'] = true;
        Cache::set('risk_user_' . $userId, $r, self::TTL);
    }
}
