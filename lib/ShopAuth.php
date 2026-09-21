<?php
/**
 * ShopAuth · 发卡网买家登录态（与验证系统共用 nb_users 用户表）
 * ------------------------------------------------------------------
 * 会话：复用 portal.php 的 NBWEBSID 会话，$_SESSION['shop_uid'] 记录登录用户。
 * 登录 / 注册直接读写 nb_users（bcrypt 密码），与官网客户端账号体系一致——
 * 同一账号在客户端激活的卡密（nb_cards.used_by）可在发卡网个人中心直接看到。
 */

class ShopAuth
{
    /** 当前登录用户（id / username / nickname），未登录返回 null */
    public static function user(): ?array
    {
        $uid = (int) ($_SESSION['shop_uid'] ?? $_SESSION['nb_web_uid'] ?? 0);  // 与官网登录态互通
        if ($uid <= 0) {
            return null;
        }
        static $cache = null;
        if ($cache !== null && (int) $cache['id'] === $uid) {
            return $cache;
        }
        try {
            $row = Database::one(
                'SELECT id, username, nickname, status FROM ' . Database::t('users') . ' WHERE id = ?',
                [$uid]
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!$row || (int) $row['status'] !== 1) {
            // 账号被封禁 / 注销：视为未登录
            unset($_SESSION['shop_uid']);
            return null;
        }
        $cache = ['id' => (int) $row['id'], 'username' => (string) $row['username'],
                  'nickname' => (string) ($row['nickname'] !== '' ? $row['nickname'] : $row['username'])];
        return $cache;
    }

    /**
     * 写入登录态前轮换 Session ID，防会话固定攻击。
     * 与官网 web_login()（web/inc/portal.php）保持同一策略：
     * 只要身份发生变化就先换 Session ID，攻击者预先塞给受害者的
     * 固定 Session ID 在登录后即失效，无法窃取登录后的会话。
     */
    private static function rotateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    /** 写入登录态（卡密登录 / 自动建号后复用），会话键与 login() 完全一致 */
    public static function adoptUid(int $uid): void
    {
        self::rotateSession();
        $_SESSION['shop_uid']    = $uid;
        $_SESSION['nb_web_uid']  = $uid;   // 官网个人中心同步登录
        $_SESSION['nb_web_time'] = time();
    }

    /** 登录：username + 密码（bcrypt），成功后写会话 */
    public static function login(string $username, string $password): array
    {
        $username = trim(mb_substr($username, 0, 64, 'UTF-8'));
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '请填写用户名和密码'];
        }
        try {
            $u = Database::one(
                'SELECT id, username, password, status, lock_until, login_fail_cnt FROM ' . Database::t('users') . ' WHERE username = ?',
                [$username]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '用户系统不可用，请联系管理员'];
        }
        // 锁定检查（在密码校验前）
        if ($u && (int) ($u['lock_until'] ?? 0) > time()) {
            $left = (int) $u['lock_until'] - time();
            return ['ok' => false, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后再试'];
        }
        if (!$u || !password_verify($password, (string) $u['password'])) {
            // 账号级失败锁定：与 Auth::onLoginFail 同策略
            if ($u) {
                $fail     = (int) ($u['login_fail_cnt'] ?? 0) + 1;
                $threshold = (int) Config::get('policy.login_fail_threshold', 5);
                $lockSec  = (int) Config::get('policy.login_lock_seconds', 900);
                $data = ['login_fail_cnt' => $fail];
                if ($fail >= $threshold) {
                    $data['lock_until'] = time() + $lockSec;
                    $data['login_fail_cnt'] = 0;
                }
                try {
                    Database::update('users', $data, 'id = :id', ['id' => (int) $u['id']]);
                } catch (Throwable $e) {
                    // 统计字段写失败不影响拒绝
                }
            }
            return ['ok' => false, 'msg' => '用户名或密码不正确'];
        }
        if ((int) $u['status'] !== 1) {
            return ['ok' => false, 'msg' => '账号已被封禁或冻结，请联系管理员'];
        }
        self::rotateSession();
        $_SESSION['shop_uid']   = (int) $u['id'];
        $_SESSION['nb_web_uid'] = (int) $u['id'];   // 官网个人中心同步登录
        $_SESSION['nb_web_time'] = time();
        try {
            // 登录成功：重置失败计数与锁定
            Database::update('users', [
                'last_login_time' => time(),
                'last_login_ip'   => Util::ip(),
                'login_fail_cnt'  => 0,
                'lock_until'      => 0,
            ], 'id = :id', ['id' => (int) $u['id']]);
        } catch (Throwable $e) {
            // 统计字段写失败不影响登录
        }
        return ['ok' => true, 'msg' => '登录成功'];
    }

    /** 注册：与客户端同表同规则（用户名 3-32 位字母数字下划线，密码 6-64） */
    public static function register(string $username, string $password, string $email = ''): array
    {
        $username = trim($username);
        $email    = trim(mb_substr($email, 0, 128, 'UTF-8'));
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return ['ok' => false, 'msg' => '用户名需 3-32 位字母、数字或下划线'];
        }
        if (($pwIssue = Util::passwordIssue($password)) !== null) {
            return ['ok' => false, 'msg' => $pwIssue];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '邮箱格式不正确'];
        }
        try {
            $exists = Database::one(
                'SELECT id FROM ' . Database::t('users') . ' WHERE username = ?',
                [$username]
            );
            if ($exists) {
                return ['ok' => false, 'msg' => '用户名已被占用'];
            }
            $uid = Database::insert('users', [
                'username'    => $username,
                'password'    => password_hash($password, PASSWORD_DEFAULT),
                'email'       => $email !== '' ? $email : null,
                'nickname'    => $username,
                'status'      => 1,
                'register_ip' => Util::ip(),
                'created_at'  => time(),
                'updated_at'  => time(),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '注册失败，请稍后再试'];
        }
        self::rotateSession();
        $_SESSION['shop_uid']   = (int) $uid;
        $_SESSION['nb_web_uid'] = (int) $uid;   // 官网个人中心同步登录
        $_SESSION['nb_web_time'] = time();
        return ['ok' => true, 'msg' => '注册成功，已自动登录'];
    }

    /** 退出登录（同步清官网会话键，否则 user() 会回退 nb_web_uid 仍视为已登录） */
    public static function logout(): void
    {
        unset($_SESSION['shop_uid'], $_SESSION['nb_web_uid'], $_SESSION['nb_web_time']);
    }

    // ------------------------------------------------------------------
    // 激活码找回（后台「激活码找回密码」开关开启时，发卡网前端可用）
    // 场景：登录方式曾为「用户名+激活码 / 纯激活码」，自动建号账号没有密码，
    // 切回「用户名+密码」后用户不知道用户名/密码。凭绑定的激活码找回。
    // 安全：激活码即凭证（仅已使用且绑定了账号的卡）；用户名必须与绑定账号一致；
    //       前端另有图形验证码 + 每 IP 每小时 5 次限流。
    // ------------------------------------------------------------------

    private static function reclaimCard(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'code' => 1001, 'msg' => '请输入激活码'];
        }
        try {
            $card = Database::one(
                'SELECT code, status, used_by, expire_at FROM ' . Database::t('cards') . ' WHERE code = ?',
                [$code]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 5000, 'msg' => '用户系统不可用，请联系管理员'];
        }
        if (!$card) {
            return ['ok' => false, 'code' => 3001, 'msg' => '激活码不存在'];
        }
        if ((int) $card['status'] === 0 || (int) $card['used_by'] <= 0) {
            return ['ok' => false, 'code' => 3002, 'msg' => '该激活码尚未绑定任何账号，无需找回'];
        }
        if ((int) $card['expire_at'] > 0 && (int) $card['expire_at'] < time()) {
            return ['ok' => false, 'code' => 3004, 'msg' => '激活码已过期'];
        }
        try {
            $user = Database::one(
                'SELECT id, username, status FROM ' . Database::t('users') . ' WHERE id = ?',
                [(int) $card['used_by']]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 5000, 'msg' => '用户系统不可用，请联系管理员'];
        }
        if (!$user || (int) $user['status'] !== 1) {
            return ['ok' => false, 'code' => 3005, 'msg' => '该激活码绑定的账号不可用'];
        }
        return ['ok' => true, 'user' => $user];
    }

    /** 第一步：激活码 → 绑定账号的用户名（供前端自动填入） */
    public static function reclaimLookup(string $code): array
    {
        $r = self::reclaimCard($code);
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => true, 'username' => (string) $r['user']['username']];
    }

    /** 第二步：激活码 + 用户名（须一致）+ 新密码 + 确认密码 → 设置新密码 */
    public static function reclaimSave(string $code, string $username, string $password, string $password2): array
    {
        $r = self::reclaimCard($code);
        if (!$r['ok']) {
            return $r;
        }
        $user  = $r['user'];
        $username = trim($username);
        if ($username === '') {
            return ['ok' => false, 'code' => 1001, 'msg' => '请填写用户名（可先点「查询用户名」自动填入）'];
        }
        if (strcasecmp($username, (string) $user['username']) !== 0) {
            return ['ok' => false, 'code' => 3006, 'msg' => '用户名与该激活码绑定的账号不一致'];
        }
        if (($pwIssue = Util::passwordIssue($password)) !== null) {
            return ['ok' => false, 'code' => 1001, 'msg' => $pwIssue];
        }
        if ($password !== $password2) {
            return ['ok' => false, 'code' => 1001, 'msg' => '两次输入的密码不一致'];
        }
        try {
            Database::update('users', [
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => time(),
            ], 'id = :id', ['id' => (int) $user['id']]);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 5000, 'msg' => '保存失败，请稍后再试'];
        }
        return ['ok' => true];
    }
}
