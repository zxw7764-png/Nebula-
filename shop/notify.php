<?php
/**
 * Nebula Menu · 易支付异步回调
 * ------------------------------------------------------------------
 * 易支付服务器以 GET 通知本文件：验签通过、金额一致后自动发卡。
 * 协议要求：处理成功输出纯文本 "success"，失败输出 "fail"（平台会重试）。
 *
 * 注意：本文件不走官网 JSON 响应体系（Response::ok），必须输出纯文本。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/../web/inc/portal.php';

header('Content-Type: text/plain; charset=utf-8');

// 回调必须来自易支付服务器：仅接受 GET（协议约定），并做基础防滥用限流
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    echo 'fail';
    exit;
}
if (!RateLimit::hit('shopnotify:' . Util::ip(), 60, 60)) {
    echo 'fail';
    exit;
}

$r = Shop::handleNotify($_GET);

echo $r['ok'] ? 'success' : 'fail';
