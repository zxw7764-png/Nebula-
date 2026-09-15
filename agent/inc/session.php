<?php
/**
 * 代理商后台 · 会话引导
 * ------------------------------------------------------------------
 * 页面入口（index.php）与接口入口（api.php）必须共用同一套会话参数，
 * 否则 CSRF 令牌会落在不同 Session 里，写操作一律报「校验失败」。
 * 这里把 Cookie 名/参数收敛到一处，两个入口只调用 nb_agent_session_start()。
 *
 * 与主管理后台（默认 PHPSESSID）刻意使用不同的 Cookie 名，
 * 避免同一浏览器同时打开两个后台时会话互相覆盖。
 */

if (!defined('NB_AGENT_ENTRY')) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}

if (!function_exists('nb_agent_session_start')) {
    function nb_agent_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_name('NBAGSID');
        @session_start();
    }
}
