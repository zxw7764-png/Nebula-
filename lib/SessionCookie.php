<?php
/**
 * 管理端会话 Cookie 工具（P1-09）
 * ==================================================================
 * 背景（修复的问题）：
 *   会话令牌此前存在 localStorage 并通过 X-Token 头提交。任何一处 XSS
 *   （第三方脚本、被注入的留言内容、浏览器扩展）都能一句
 *   `localStorage.getItem(key)` 把令牌读走，然后离线重放到任意机器上。
 *   HttpOnly Cookie 是浏览器层面的硬约束：JS 无论如何都读不到。
 *
 * 设计要点：
 *   1. token 存 HttpOnly Cookie（JS 不可读），服务端每次请求从 Cookie 取。
 *   2. 兼容旧客户端：仍接受 X-Token 头（优先级高于 Cookie），
 *      便于灰度与排查；`admin.cookie_session=false` 可整体关掉 Cookie 模式。
 *   3. **与会话密钥（P0-02）配合**：
 *      Cookie 会被浏览器自动携带，因此单靠 Cookie 反而引入 CSRF 面。
 *      这里保留 X-Session-Key（JS 持有、Cookie 里没有），
 *      攻击者诱导浏览器发请求时带不上第二因子 —— 两机制互补：
 *        · Cookie     防「XSS 窃取令牌」
 *        · 会话密钥   防「CSRF 冒用会话」
 *   4. SameSite=Strict + X-CSRF 作为第三层保险。
 *
 * 所属后台通过常量 NB_SESS_COOKIE_DEFAULT 指定默认 Cookie 名，
 * 管理端与代理端各自调用，互不干扰。
 */

final class SessionCookie
{
    /** 当前生效的 Cookie 名 */
    private static string $name = '';
    /** 是否启用 Cookie 模式 */
    private static bool $enabled = false;
    /** 本次请求是否已经设置过 Cookie（避免重复下发） */
    private static bool $issued = false;

    /**
     * 初始化（在每个入口的早期调用一次）
     *
     * @param string $defaultName 默认 Cookie 名
     * @param string $configKey   config 里开关的键（如 admin.cookie_session）
     * @param string $nameKey     config 里 Cookie 名的键（如 admin.cookie_name）
     * @param string $secureKey   config 里 secure 开关的键
     */
    public static function init(
        string $defaultName,
        string $configKey,
        string $nameKey,
        string $secureKey
    ): void {
        self::$enabled = (bool) Config::get($configKey, true);
        $name = (string) Config::get($nameKey, $defaultName);
        // 名字只保留安全字符，避免 header 注入
        self::$name = preg_replace('/[^A-Za-z0-9_\-]/', '', $name) ?: $defaultName;
        self::$issued = false;

        // secure 标记：配置显式开启，或请求本身就是 HTTPS
        self::$secure = (bool) Config::get($secureKey, false) || !empty($_SERVER['HTTPS']);
    }

    private static bool $secure = false;

    /**
     * 是否应该真正调用 setcookie()
     * ------------------------------------------------------------------
     * CLI（含 cron.php、install/migrate_*.php、测试脚本）下没有 HTTP 响应，
     * setcookie() 会因为「headers already sent」直接终止进程（exit code 255），
     * 把命令行脚本打死。因此 CLI 下只更新内部状态、不发送响应头，
     * 让同一套代码在 Web 与 CLI 下都能安全调用。
     */
    private static function canSendCookie(): bool
    {
        return PHP_SAPI !== 'cli' && !headers_sent();
    }

    /** Cookie 模式是否启用 */
    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** 当前 Cookie 名 */
    public static function name(): string
    {
        return self::$name;
    }

    /**
     * 从请求里取令牌
     * 优先级：X-Token 头（旧客户端/调试） > body.token > Cookie（新默认）
     */
    public static function fromRequest(array $input): string
    {
        $t = $_SERVER['HTTP_X_TOKEN'] ?? ($input['token'] ?? '');
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }
        if (self::$enabled && self::$name !== '') {
            $c = $_COOKIE[self::$name] ?? '';
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }
        return '';
    }

    /**
     * 下发会话 Cookie（登录成功 / 续期时调用）
     * @param int $ttl 有效期秒数；0 表示浏览器会话级
     */
    public static function issue(string $token, int $ttl = 0): void
    {
        if (!self::$enabled || self::$name === '' || self::$issued) {
            return;
        }
        self::$issued = true;

        $opts = [
            'expires'  => $ttl > 0 ? time() + $ttl : 0,
            'path'     => '/',
            'httponly' => true,          // 核心：JS 读不到
            // Strict 会阻止「从外站链接跳进来时携带 Cookie」，
            // 而后台页面本身不依赖跨站跳转，因此用 Strict 换取最强 CSRF 防护。
            'samesite' => 'Strict',
            'secure'   => self::$secure,
        ];
        if (self::canSendCookie()) {
            setcookie(self::$name, $token, $opts);
        }
        // 同步到本次请求的超全局，便于同请求内后续读取
        $_COOKIE[self::$name] = $token;
    }

    /** 清除会话 Cookie（退出登录 / 会话失效时调用） */
    public static function clear(): void
    {
        if (self::$name === '') {
            return;
        }
        if (self::canSendCookie()) {
            setcookie(self::$name, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => self::$secure,
            ]);
        }
        unset($_COOKIE[self::$name]);
    }

    /**
     * 构造本次下发会用的 Cookie 属性（供测试断言 HttpOnly / SameSite）
     * 与 issue() 使用的选项保持同源，避免测试与实现漂移。
     */
    public static function describeOptions(int $ttl = 0): array
    {
        return [
            'expires'  => $ttl > 0 ? time() + $ttl : 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => self::$secure,
        ];
    }
}
