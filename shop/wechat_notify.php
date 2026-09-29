<?php
/**
 * Nebula · 微信支付 v3 异步通知
 * ------------------------------------------------------------------
 * 微信服务端以 POST JSON 推送订单结果（Wechatpay-* 签名头 + resource 密文），
 * 我们调 Pay::verifyNotify('wechat') 验签并解密后完成发卡。
 *
 * v3 应答规范：处理成功返回 200 + {"code":"SUCCESS"}；
 * 失败必须返回 4xx/5xx + {"code":"FAIL"}，微信才会按退避策略重试
 * （v1 时代的纯文本 success/fail 已不适用——200 + fail 会被微信视为应答成功）。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/../web/inc/portal.php';

/** v3 规范失败应答（5xx 让微信重试） */
function wechatNotifyFail(string $msg): void
{
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 'FAIL', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wechatNotifyFail('method not allowed');
}
if (!RateLimit::hit('shopwechat:' . Util::ip(), 60, 60)) {
    wechatNotifyFail('rate limited');
}

[$signOk, $orderNo, $amountFen, $transactionId] = Pay::verifyNotify([], 'wechat');
if (!$signOk || $orderNo === '') {
    Logger::log('shop_notify', 0, '微信支付验签失败', ['ip' => Util::ip()]);
    wechatNotifyFail('verify failed');
}

$order = Database::one(
    'SELECT * FROM ' . Database::t('shop_orders') . ' WHERE order_no = ?',
    [$orderNo]
);
if (!$order) {
    wechatNotifyFail('order not found');
}

$payMoney = $amountFen >= 0 ? $amountFen : -1;
if ($payMoney !== (int) $order['amount']) {
    Logger::log('shop_notify', 0, '微信支付回调金额不一致', [
        'order_no' => $orderNo, 'pay_money' => $payMoney, 'order_amount' => (int) $order['amount'],
    ]);
    wechatNotifyFail('amount mismatch');
}

$status = (int) $order['status'];
if ($status === Shop::ORDER_DELIVERED) {
    echo json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($status === Shop::ORDER_CLOSED) {
    Logger::log('shop_notify', 0, '微信回调已关闭订单', ['order_no' => $orderNo]);
    wechatNotifyFail('order closed');
}

Database::update('shop_orders', [
    'trade_no' => $transactionId,
    'paid_at'  => time(),
], 'order_no = :ono AND status = :st', ['ono' => $orderNo, 'st' => $status]);

$r = Shop::deliver((int) $order['id']);
Logger::log('shop_notify', $r['ok'] ? 1 : 0, '微信支付回调' . ($r['ok'] ? '发卡成功' : '发卡失败'), [
    'order_no' => $orderNo, 'shortage' => $r['data']['shortage'] ?? false,
]);
echo json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);
