<?php
/**
 * 管理员认证
 */
class AdminAuth
{
    /**
     * 只读角色允许的接口
     * ------------------------------------------------------------------
     * 【已废弃 — 仅为兼容保留，请勿再依赖】
     * 权限判断已统一迁移到 lib/AdminPermission.php 的 ACTION_PERM 表 +
     * ROLE_MATRIX 矩阵（见 admin/index.php 的 AdminPermission::requireAction）。
     *
     * 原因：旧实现只在入口判断「role=3 不能写」，导致 role=2 操作员与超管
     * 在业务上完全等同，可改安全设置 / 生成卡密 / 发代理充值卡（P0-01）。
     * 新增或调整权限请改 AdminPermission，不要再往这里加 action。
     */
    const READONLY_ACTIONS = [
        'login', 'logout', 'profile', 'dashboard', 'user_list', 'user_detail',
        'agent_list', 'agent_detail', 'agent_code_list', 'agent_recharge_list',
        'card_list', 'card_batch_list', 'card_detail', 'device_list', 'log_list',
        'session_list', 'audit_list', 'audit_detail', 'notice_list', 'version_list',
        'group_list', 'stat_overview', 'stat_trend', 'setting_get', 'card_export',
        // 官网互动功能：只读列表
        'message_list', 'feedback_list', 'plan_list', 'seller_list', 'screenshot_list',
    ];

    /**
     * 管理员登录
     *
     * @param string|null $totp 二次验证动态码（6 位数字）或一次性恢复码（10 位十六进制）
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function login(string $username, string $password, ?string $totp = null): array
    {
        $admin = Database::one(
            'SELECT * FROM ' . Database::t('admins') . ' WHERE username = ?',
            [$username]
        );

        // 登录失败统一记录（不区分账号不存在/密码错，避免账号枚举）
        $failLog = function (string $msg) use ($username) {
            Logger::log('admin_login', 0, $msg, [
                'username' => $username,
                'raw'      => ['user' => $username],
            ]);
        };

        if (!$admin) {
            $failLog('登录失败：账号不存在');
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }
        if ((int) $admin['lock_until'] > time()) {
            $left = (int) $admin['lock_until'] - time();
            $failLog('登录被拒：账号锁定中');
            return ['ok' => false, 'code' => 2003, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后重试', 'data' => null];
        }
        if ((int) $admin['status'] !== 1) {
            $failLog('登录被拒：账号已禁用');
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被禁用', 'data' => null];
        }

        $threshold = (int) Config::get('admin.login_fail_threshold', 5);
        $lockSec   = (int) Config::get('admin.login_lock_seconds', 900);

        $nbRehash = false;
        if (!Util::verifyPassword($password, (string) $admin['password'], $nbRehash)) {
            // ------------------------------------------------------------------
            // 失败计数原子自增：原实现「读 login_fail_cnt -> +1 -> 写回」在并发爆破下
            // 会丢失计数（两个请求都读到 4，都写 5），锁定阈值形同虚设。
            // 这里改为单条 SET login_fail_cnt = login_fail_cnt + 1，由数据库保证原子。
            // ------------------------------------------------------------------
            Database::exec(
                'UPDATE ' . Database::t('admins') . '
                 SET login_fail_cnt = login_fail_cnt + 1
                 WHERE id = ?',
                [$admin['id']]
            );
            $fail = (int) Database::value(
                'SELECT login_fail_cnt FROM ' . Database::t('admins') . ' WHERE id = ?',
                [$admin['id']]
            );

            if ($fail >= $threshold) {
                // 触发锁定：用条件更新收口，只有结算时计数仍 >= 阈值的那一个请求
                // 能写 lock_until 并归零（rowCount()=1），避免并发下重复锁定 / 计数被多次清零。
                $locked = Database::exec(
                    'UPDATE ' . Database::t('admins') . '
                     SET lock_until = ?, login_fail_cnt = 0
                     WHERE id = ? AND login_fail_cnt >= ?',
                    [time() + $lockSec, $admin['id'], $threshold]
                );
                if ($locked === 1) {
                    $failLog("登录失败：连续 {$fail} 次，账号锁定 " . ($lockSec / 60) . ' 分钟');
                }
            } else {
                $failLog("登录失败：密码错误（第 {$fail} 次）");
            }
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }

        // ------------------------------------------------------------------
        // 二次验证（TOTP）
        // ------------------------------------------------------------------
        // 账号绑定动态验证码后，密码正确只是第一因子，必须再给一次 30 秒
        // 一变的 6 位码（或一枚一次性恢复码）才建立会话。
        //
        // 降级：站点若漏跑 install/migrate_admin_totp.php，totp_enabled 列不存在，
        // `?? 0` 让本段直接跳过 —— 老站登录行为与升级前完全一致，不会被打挂。
        // ------------------------------------------------------------------
        if ((int) ($admin['totp_enabled'] ?? 0) === 1) {
            $totpCode = preg_replace('/\s+/', '', (string) $totp);
            if ($totpCode === '') {
                // 只提示补码，不建会话、不计失败：这不是一次失败的尝试
                return ['ok' => false, 'code' => 2006, 'msg' => '请输入动态验证码', 'data' => ['need_totp' => true]];
            }
            if (!self::verifyTotp($admin, $totpCode)) {
                // 动态码错误计入失败次数，防止把第二因子当成无限次爆破口
                Database::exec(
                    'UPDATE ' . Database::t('admins') . '
                     SET login_fail_cnt = login_fail_cnt + 1
                     WHERE id = ?',
                    [$admin['id']]
                );
                $failLog('登录失败：动态验证码错误');
                return ['ok' => false, 'code' => 2007, 'msg' => '动态验证码错误', 'data' => ['need_totp' => true]];
            }
        }

        // 生成令牌
        $token = Util::token(32);
        $ttl   = (int) Config::get('admin.session_ttl', 7200);
        $now   = time();

        // ------------------------------------------------------------------
        // 会话密钥（P0-02）
        // ------------------------------------------------------------------
        // 令牌 token 只是「会话标识」，本身不是密钥；一旦泄露（XSS 窃取
        // localStorage / 内网抓包 / 共享终端残留），拿 token 就能完整接管会话。
        // 因此登录时额外签发一份 session_key，只在本次响应里下发一次，
        // 服务端只落库它的 SHA-256 摘要。后续请求必须同时带上 token 与
        // session_key 才放行 —— 拿到库里的 sk_hash 无法反推出明文密钥。
        $sessionKey = Util::token(32);
        $now        = time();

        // 历史弱哈希（无盐 md5 / 低成本 bcrypt）借这次明文已验证的机会升级
        if ($nbRehash) {
            try {
                Database::exec(
                    'UPDATE ' . Database::t('admins') . ' SET password = ? WHERE id = ?',
                    [Util::hashPassword($password), $admin['id']]
                );
            } catch (Throwable $e) {
                // 升级失败不影响本次登录
            }
        }

        Database::exec(
            'UPDATE ' . Database::t('admins') . '
             SET last_login_ip = ?, last_login_time = ?, login_fail_cnt = 0, lock_until = 0
             WHERE id = ?',
            [Util::ip(), $now, $admin['id']]
        );

        // 令牌存到独立的管理员会话表。
        // sk_hash / ua_hash 是 migrate_admin_session_bind.php 新增的列：
        // 站点若漏跑迁移，这里必须降级写旧列，不能把登录打挂（见部署约定）。
        $sessionRow = [
            'token'       => $token,
            'admin_id'    => (int) $admin['id'],
            'ip'          => Util::ip(),
            'ua'          => Util::ua(),
            'login_at'    => $now,
            'last_active' => $now,
            'expire_at'   => $now + $ttl,
            'status'      => 1,
            'sk_hash'     => hash('sha256', $sessionKey),
            'ua_hash'     => hash('sha256', Util::ua()),
        ];
        try {
            Database::insert('admin_sessions', $sessionRow);
        } catch (Throwable $e) {
            // 降级：去掉新列重试一次（站点未执行迁移时走这条）
            unset($sessionRow['sk_hash'], $sessionRow['ua_hash']);
            Database::insert('admin_sessions', $sessionRow);
            Logger::log('admin_session_bind', 0, '降级：sk_hash 列缺失，会话密钥绑定未生效，请执行 install/migrate_admin_session_bind.php');
            $sessionKey = ''; // 未落库则不让前端携带，避免误判
        }

        // 登录成功日志 + 审计
        Logger::log('admin_login', 1, '登录成功', [
            'admin_id' => (int) $admin['id'],
            'username' => $admin['username'],
        ]);
        Audit::log($admin, 'admin_login', "管理员#{$admin['id']} {$admin['username']}", '登录管理后台');

        // 刷新最后登录信息用于返回
        $admin['last_login_ip']   = Util::ip();
        $admin['last_login_time'] = $now;

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '登录成功',
            'data' => [
                'token'       => $token,
                // 会话密钥：仅此一次下发，客户端必须自行保存并随每次请求回传。
                // 服务端只存摘要，丢了只能重新登录。
                'session_key' => $sessionKey,
                'expire_at'   => $now + $ttl,
                'admin'       => self::publicInfo($admin),
            ],
        ];
    }

    /**
     * 校验令牌，返回管理员信息
     *
     * @param string      $token      会话令牌（X-Token）
     * @param string|null $sessionKey 会话密钥（X-Session-Key）；null = 调用方未提供
     *
     * 绑定策略（P0-02）：
     *   · sk_hash 为 NULL 的历史会话：按旧模型放行（升级不踢人）。若要强制
     *     所有会话重新登录，可执行「UPDATE nb_admin_sessions SET status=0」。
     *   · sk_hash 非 NULL 的新会话：必须提供 session_key 且摘要匹配，
     *     否则一律拒绝。缺少 session_key 的请求说明客户端未升级或密钥已丢失。
     *   · UA 变化：判定为异常，记审计并拒绝（UA 在正常使用中几乎不会变）。
     *   · IP 变化：默认只记录（admin.strict_ip_bind=false）。管理后台常在
     *     动态 IP / 多出口 / 移动办公场景使用，硬绑会把真实管理员锁在门外。
     */
    public static function check(string $token, ?string $sessionKey = null): ?array
    {
        $s = Database::one(
            'SELECT * FROM ' . Database::t('admin_sessions') . ' WHERE token = ?',
            [$token]
        );
        if (!$s) {
            return null;
        }
        if ((int) $s['status'] !== 1) {
            return null;
        }
        if ((int) $s['expire_at'] > 0 && (int) $s['expire_at'] < time()) {
            return null;
        }

        // ---------------------- 会话密钥绑定校验 ----------------------
        $skHash = $s['sk_hash'] ?? null;
        if ($skHash !== null && $skHash !== '') {
            // 该会话已启用绑定：必须提供正确密钥
            if (!is_string($sessionKey) || $sessionKey === '') {
                Logger::log('admin_session_bind', 0, '拒绝：会话已启用密钥绑定但请求未携带 X-Session-Key', [
                    'admin_id' => (int) $s['admin_id'],
                ]);
                return null;
            }
            if (!hash_equals((string) $skHash, hash('sha256', $sessionKey))) {
                // 密钥错误 = 典型的 token 盗用特征：有人拿着 token 但没有密钥。
                // 直接吊销整个会话，避免攻击者反复试错。
                Database::exec(
                    'UPDATE ' . Database::t('admin_sessions') . ' SET status = 0 WHERE id = ?',
                    [(int) $s['id']]
                );
                Logger::log('admin_session_bind', 0, '会话密钥不匹配，已吊销该会话', [
                    'admin_id' => (int) $s['admin_id'],
                    'session'  => (int) $s['id'],
                ]);
                return null;
            }

            // UA 绑定：正常使用中 UA 不会变，变了基本就是会话被搬运
            $uaHash = $s['ua_hash'] ?? null;
            if ($uaHash !== null && $uaHash !== '') {
                if (!hash_equals((string) $uaHash, hash('sha256', Util::ua()))) {
                    Database::exec(
                        'UPDATE ' . Database::t('admin_sessions') . ' SET status = 0 WHERE id = ?',
                        [(int) $s['id']]
                    );
                    Logger::log('admin_session_bind', 0, 'UA 与登录时不符，已吊销该会话', [
                        'admin_id' => (int) $s['admin_id'],
                        'session'  => (int) $s['id'],
                    ]);
                    return null;
                }
            }

            // IP 绑定：默认软绑（只记录）。命中变化时写一条日志便于审计追踪，
            // 但不打断真实使用。strict_ip_bind=true 时才硬拦。
            $loginIp = (string) ($s['ip'] ?? '');
            $nowIp   = Util::ip();
            if ($loginIp !== '' && $nowIp !== '' && $loginIp !== $nowIp) {
                Logger::log('admin_session_bind', 1, '会话 IP 发生变化', [
                    'admin_id' => (int) $s['admin_id'],
                    'session'  => (int) $s['id'],
                    'raw'      => ['login_ip' => $loginIp, 'now_ip' => $nowIp],
                ]);
                if (Config::get('admin.strict_ip_bind', false)) {
                    Database::exec(
                        'UPDATE ' . Database::t('admin_sessions') . ' SET status = 0 WHERE id = ?',
                        [(int) $s['id']]
                    );
                    Logger::log('admin_session_bind', 0, '严格 IP 绑定：IP 变化，已吊销该会话', [
                        'admin_id' => (int) $s['admin_id'],
                    ]);
                    return null;
                }
            }
        }

        $admin = Database::one('SELECT * FROM ' . Database::t('admins') . ' WHERE id = ?', [(int) $s['admin_id']]);
        if (!$admin || (int) $admin['status'] !== 1) {
            return null;
        }

        // 续期
        $ttl = (int) Config::get('admin.session_ttl', 7200);
        Database::exec(
            'UPDATE ' . Database::t('admin_sessions') . ' SET last_active = ?, expire_at = ? WHERE token = ?',
            [time(), time() + $ttl, $token]
        );

        return $admin;
    }

    /** 退出 */
    public static function logout(string $token): void
    {
        $s = Database::one('SELECT * FROM ' . Database::t('admin_sessions') . ' WHERE token = ?', [$token]);
        Database::exec('UPDATE ' . Database::t('admin_sessions') . ' SET status = 0 WHERE token = ?', [$token]);

        if ($s) {
            $admin = Database::one('SELECT * FROM ' . Database::t('admins') . ' WHERE id = ?', [(int) $s['admin_id']]);
            if ($admin) {
                Logger::log('admin_logout', 1, '退出登录', [
                    'admin_id' => (int) $admin['id'],
                    'username' => $admin['username'],
                ]);
                Audit::log($admin, 'admin_logout', "管理员#{$admin['id']} {$admin['username']}", '退出管理后台');
            }
        }
    }

    /**
     * 校验指定管理员的登录密码 —— 敏感操作二次确认专用。
     * 与登录共用同一套哈希校验（含历史 md5 兼容）。
     * 这里不写失败日志：调用方（handler）负责记录，避免本方法被当成
     * 独立的密码试探入口，也避免失败日志刷屏。
     */
    public static function verifyPassword(int $adminId, string $password): bool
    {
        if ($adminId <= 0 || $password === '') {
            return false;
        }
        $row = Database::one(
            'SELECT password FROM ' . Database::t('admins') . ' WHERE id = ? AND status = 1',
            [$adminId]
        );
        if (!$row) {
            return false;
        }
        return Util::verifyPassword($password, (string) $row['password']);
    }

    // ==================================================================
    // 二次验证（TOTP）
    // ==================================================================

    /** 校验动态码，失败再尝试一次性恢复码 */
    public static function verifyTotp(array $admin, string $code): bool
    {
        $secret = (string) ($admin['totp_secret'] ?? '');
        if ($secret !== '' && Totp::verify($secret, $code)) {
            return true;
        }
        return self::consumeRecovery((int) ($admin['id'] ?? 0), $code);
    }

    /** 核销一枚恢复码（一次性，用掉即从列表移除） */
    private static function consumeRecovery(int $adminId, string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($adminId <= 0 || strlen($code) !== 10) {
            return false;
        }
        try {
            $row = Database::one(
                'SELECT totp_recovery FROM ' . Database::t('admins') . ' WHERE id = ?',
                [$adminId]
            );
        } catch (Throwable $e) {
            return false;
        }
        if (!$row || empty($row['totp_recovery'])) {
            return false;
        }
        $list = json_decode((string) $row['totp_recovery'], true);
        if (!is_array($list) || !$list) {
            return false;
        }
        $hash = hash('sha256', $code);
        $idx  = array_search($hash, $list, true);
        if ($idx === false) {
            return false;
        }
        unset($list[$idx]);
        Database::exec(
            'UPDATE ' . Database::t('admins') . ' SET totp_recovery = ? WHERE id = ?',
            [json_encode(array_values($list)), $adminId]
        );
        Logger::log('admin_totp', 1, '使用恢复码登录', ['admin_id' => $adminId]);
        return true;
    }

    /**
     * 本站是否有任何管理员开启了二次验证。
     *
     * 用途：登录页据此决定要不要显示「动态验证码」输入框 ——
     *   有 → 直接显示，开了 2FA 的账号一步就能登录完（不用先失败一次等 2006）；
     *   没有 → 整个字段不渲染，别给普通站点添乱。
     *
     * 只暴露「本站启用了 2FA」这个事实，不暴露是哪个账号，
     * 因此不会造成账号枚举。未跑 totp 迁移（列不存在）时返回 false。
     */
    public static function totpInUse(): bool
    {
        try {
            $row = Database::one(
                'SELECT COUNT(*) AS n FROM ' . Database::t('admins') . ' WHERE totp_enabled = 1'
            );
        } catch (Throwable $e) {
            return false;
        }
        return ((int) ($row['n'] ?? 0)) > 0;
    }

    /** 绑定状态（不回传密钥明文） */
    public static function totpStatus(int $adminId): array
    {
        try {
            $row = Database::one(
                'SELECT totp_secret, totp_enabled, totp_recovery FROM ' . Database::t('admins') . ' WHERE id = ?',
                [$adminId]
            );
        } catch (Throwable $e) {
            // 漏跑迁移 → 该功能不可用，但登录照常
            return ['supported' => false, 'enabled' => false, 'pending' => false, 'recovery_left' => 0];
        }
        if (!$row) {
            return ['supported' => true, 'enabled' => false, 'pending' => false, 'recovery_left' => 0];
        }
        $list = json_decode((string) ($row['totp_recovery'] ?? ''), true);
        return [
            'supported'     => true,
            'enabled'       => (int) ($row['totp_enabled'] ?? 0) === 1,
            'pending'       => !empty($row['totp_secret']) && (int) ($row['totp_enabled'] ?? 0) !== 1,
            'recovery_left' => is_array($list) ? count($list) : 0,
        ];
    }

    /**
     * 第一步：生成待绑定密钥
     * 只写 totp_secret、不置 totp_enabled —— 必须等管理员用验证器算出一次
     * 正确动态码（totpEnable）才算绑定成功，避免"扫了码但没配对"就被锁死。
     */
    public static function totpInit(int $adminId): array
    {
        $admin = Database::one('SELECT username FROM ' . Database::t('admins') . ' WHERE id = ?', [$adminId]);
        if (!$admin) {
            return ['ok' => false, 'msg' => '账号不存在'];
        }
        $secret = Totp::secret(20);
        try {
            Database::exec(
                'UPDATE ' . Database::t('admins') . ' SET totp_secret = ?, totp_enabled = 0, totp_recovery = NULL WHERE id = ?',
                [$secret, $adminId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '写入失败，请确认已执行 install/migrate_admin_totp.php'];
        }

        $issuer = 'Nebula Menu';
        try {
            $name = (string) Setting::get('site_name', '');
            if ($name !== '') {
                $issuer = $name;
            }
        } catch (Throwable $e) {
        }

        return [
            'ok'     => true,
            'msg'    => '已生成密钥，请在验证器中添加后输入动态码完成绑定',
            'secret' => $secret,
            'uri'    => Totp::uri($secret, (string) $admin['username'], $issuer),
            'issuer' => $issuer,
        ];
    }

    /** 第二步：输入一次正确动态码后正式启用，并下发一次性恢复码 */
    public static function totpEnable(int $adminId, string $code): array
    {
        $row    = Database::one('SELECT totp_secret FROM ' . Database::t('admins') . ' WHERE id = ?', [$adminId]);
        $secret = (string) ($row['totp_secret'] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'msg' => '请先生成密钥'];
        }
        if (!Totp::verify($secret, $code)) {
            return ['ok' => false, 'msg' => '动态验证码不正确，请确认手机时间是否准确'];
        }
        $codes  = Totp::recoveryCodes(8);
        $hashes = array_map(function (string $c): string { return hash('sha256', $c); }, $codes);
        Database::exec(
            'UPDATE ' . Database::t('admins') . ' SET totp_enabled = 1, totp_recovery = ? WHERE id = ?',
            [json_encode($hashes), $adminId]
        );
        Logger::log('admin_totp', 1, '开启二次验证', ['admin_id' => $adminId]);
        return ['ok' => true, 'msg' => '二次验证已开启', 'recovery' => $codes];
    }

    /** 关闭二次验证（需当前密码确认） */
    public static function totpDisable(int $adminId, string $password): array
    {
        if (!self::verifyPassword($adminId, $password)) {
            return ['ok' => false, 'msg' => '密码错误'];
        }
        Database::exec(
            'UPDATE ' . Database::t('admins') . ' SET totp_enabled = 0, totp_secret = NULL, totp_recovery = NULL WHERE id = ?',
            [$adminId]
        );
        Logger::log('admin_totp', 1, '关闭二次验证', ['admin_id' => $adminId]);
        return ['ok' => true, 'msg' => '二次验证已关闭'];
    }

    /** 修改密码 */
    public static function changePassword(int $adminId, string $oldPass, string $newPass): array
    {
        if (($pwIssue = Util::passwordIssue($newPass)) !== null) {
            return ['ok' => false, 'msg' => $pwIssue];
        }
        $admin = Database::one('SELECT * FROM ' . Database::t('admins') . ' WHERE id = ?', [$adminId]);
        if (!$admin) {
            return ['ok' => false, 'msg' => '账号不存在'];
        }
        if (!Util::verifyPassword($oldPass, (string) $admin['password'])) {
            return ['ok' => false, 'msg' => '原密码错误'];
        }
        Database::update('admins', ['password' => Util::hashPassword($newPass)], 'id = :id', ['id' => $adminId]);

        // 改密后使该账号的其他会话全部失效
        Database::exec(
            'UPDATE ' . Database::t('admin_sessions') . ' SET status = 0 WHERE admin_id = ?',
            [$adminId]
        );

        return ['ok' => true, 'msg' => '密码修改成功，请重新登录'];
    }

    /** 角色名称 */
    public static function roleName(int $role): string
    {
        return [1 => '超级管理员', 2 => '操作员', 3 => '只读'][$role] ?? '未知';
    }

    /** 对外信息 */
    public static function publicInfo(array $admin): array
    {
        return [
            'id'              => (int) $admin['id'],
            'username'        => $admin['username'],
            'nickname'        => $admin['nickname'] ?: $admin['username'],
            'role'            => (int) $admin['role'],
            'role_text'       => self::roleName((int) $admin['role']),
            // 权限点：前端据此隐藏无权访问的菜单与按钮。
            // 超管下发 '*'（通配），前端一律视为全部放行。
            // 注意：前端隐藏只是体验优化，真正的拦截始终在服务端
            // AdminPermission::requireAction()，不要依赖前端做安全。
            'permissions'     => AdminPermission::permissionsOf($admin),
            'last_login_ip'   => $admin['last_login_ip'] ?? '',
            'last_login_text' => !empty($admin['last_login_time'])
                ? Util::date((int) $admin['last_login_time']) : '',
        ];
    }
}
