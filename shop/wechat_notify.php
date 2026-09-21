<?php
/**
 * Nebula · 微信支付 v3 异步通知
 * ------------------------------------------------------------------
 * 微信服务端以 POST JSON 推送订单结果，我们调 Pay::verifyNotify('wechat')
 * 验签并解密后完成发卡。成功输出 success，失败输出 fail。
 */

if (!defined('NB_WEB_ENTRY')) {
    define('NB_WEB_ENTRY', true);
}
require_once __DIR__ . '/../web/inc/portal.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo 'fail';
    exit;
}
if (!RateLimit::hit('shopwechat:' . Util::ip(), 60, 60)) {
    echo 'fail';
    exit;
}

[$signOk, $orderNo, $amountFen] = Pay::verifyNotify([], 'wechat');
if (!$signOk || $orderNo === '') {
    Logger::log('shop_notify', 0, '微信支付验签失败', ['ip' => Util::ip()]);
    echo 'fail';
    exit;
}

$order = Database::one(
    'SELECT * FROM ' . Database::t('shop_orders') . ' WHERE order_no = ?',
    [$orderNo]
);
if (!$order) {
    echo 'fail';
    exit;
}

$payMoney = $amountFen >= 0 ? $amountFen : -1;
if ($payMoney !== (int) $order['amount']) {
    Logger::log('shop_notify', 0, '微信支付回调金额不一致', [
        'order_no' => $orderNo, 'pay_money' => $payMoney, 'order_amount' => (int) $order['amount'],
    ]);
    echo 'fail';
    exit;
}

$status = (int) $order['status'];
if ($status === Shop::ORDER_DELIVERED) {
    echo 'success';
    exit;
}
if ($status === Shop::ORDER_CLOSED) {
    Logger::log('shop_notify', 0, '微信回调已关闭订单', ['order_no' => $orderNo]);
    echo 'fail';
    exit;
}

Database::update('shop_orders', [
    'trade_no' => (string) ($_POST['transaction_id'] ?? ''),
    'paid_at'  => time(),
], 'order_no = :ono AND status = :st', ['ono' => $orderNo, 'st' => $status]);

$r = Shop::deliver((int) $order['id']);
Logger::log('shop_notify', $r['ok'] ? 1 : 0, '微信支付回调' . ($r['ok'] ? '发卡成功' : '发卡失败'), [
    'order_no' => $orderNo, 'shortage' => $r['data']['shortage'] ?? false,
]);
echo 'success';