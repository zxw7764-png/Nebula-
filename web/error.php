<?php
/**
 * Nginx 自定义错误页入口（两条触发路径）
 * ① nginx error_page 指令重定向：由 REDIRECT_STATUS 感知真实状态码
 * ② .htaccess rewrite 兜底（serv00 等不支持 error_page 的环境）：
 *    RewriteCond %{REQUEST_FILENAME} !-f !-d → web/error.php?c=404
 * 复用 lib/error_page.php 渲染「信号丢失」深空页，数字随状态码变化。
 */
if (!function_exists('nb_err_code')) {
    function nb_err_code(): int
    {
        $allowed = [400, 401, 403, 404, 405, 429, 500, 502, 503];
        $c = (int) ($_GET['c'] ?? ($_SERVER['REDIRECT_STATUS'] ?? 404));
        return in_array($c, $allowed, true) ? $c : 404;
    }
}
require_once dirname(__DIR__) . '/lib/error_page.php';
nb_error_page(nb_err_code());
