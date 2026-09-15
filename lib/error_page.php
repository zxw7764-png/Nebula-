<?php
/**
 * 自定义 HTTP 错误页渲染器（独立函数，不依赖框架引导）
 * ------------------------------------------------------------------
 * 复用 web/404.html 的「信号丢失」深空风格页面，把页面里的数字
 * （标题 / HTTP 404 / SECTOR 404 等）替换为实际状态码，
 * 让 400 / 403 / 404 / 500 等错误呈现同一套视觉。
 *
 * 使用方：bootstrap（IP 黑名单、配置缺失）、portal、agent 守卫、cron 等。
 * 本文件必须保持零依赖：任何入口在任何加载阶段都可 require。
 */

if (!function_exists('nb_error_page')) {
    /**
     * 输出与状态码对应的自定义错误页并终止。
     * $msg 可选：替换页面副标题与错误行文案（如 IP 黑名单的「你已被封禁」）。
     */
    function nb_error_page(int $code, string $msg = ''): void
    {
        // 仅放行常见 HTTP 错误码，防止注入奇怪数字
        $code = in_array($code, [400, 401, 403, 404, 405, 429, 500, 502, 503], true) ? $code : 404;

        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');

        $page = dirname(__DIR__) . '/web/404.html';
        if (is_file($page)) {
            $html = (string) @file_get_contents($page);
            // 页面内所有 404 字样（标题 / HTTP 404 / SECTOR 404）统一换成状态码
            $html = str_replace('404', (string) $code, $html);
            if ($msg !== '') {
                // 自定义提示：替换副标题行与控制台错误行（其余特效保留）
                $html = str_replace(
                    ['SIGNAL LOST · 信号丢失于深空', '错误：该坐标不在已知星图内（HTTP ' . $code . '）'],
                    [$msg, '错误：' . $msg . '（HTTP ' . $code . '）'],
                    $html
                );
            }

            // ------------------------------------------------------------------
            // CSP nonce 注入
            // ------------------------------------------------------------------
            // 404.html 是被 file_get_contents 读进来的静态文件，里面的 PHP 标签
            // 不会被解析，所以不能像普通页面那样在模板里直接输出 nonce 常量。
            // 这里在输出前把内联 script 补上 nonce —— 仅当 bootstrap 已定义
            // 该常量（说明本次响应确实带了 CSP 头）时才处理；静态直出 404.html
            // 的路径没有 CSP 头，不需要也不该改。
            // ------------------------------------------------------------------
            if (defined('NB_CSP_NONCE') && NB_CSP_NONCE !== '') {
                $nbNonce = (string) NB_CSP_NONCE;
                $html = (string) preg_replace_callback('/<script\b([^>]*)>/i', function ($m) use ($nbNonce) {
                    $attr = $m[1];
                    if (stripos($attr, 'src=') !== false || stripos($attr, 'nonce=') !== false) {
                        return $m[0];
                    }
                    return '<script' . $attr . ' nonce="' . $nbNonce . '">';
                }, $html);
            }

            header('Content-Length: ' . (string) strlen($html));
            echo $html;
        } else {
            // 页面缺失兜底：极简文本页
            echo "<!DOCTYPE html><html><head><title>{$code}</title></head>"
               . "<body><h1>{$code}</h1>" . ($msg !== '' ? "<p>" . htmlspecialchars($msg, ENT_QUOTES) . "</p>" : '')
               . "</body></html>";
        }
        exit;
    }
}
