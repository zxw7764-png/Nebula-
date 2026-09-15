<?php
/**
 * 支付驱动层（Nebula Menu）
 * ------------------------------------------------------------------
 * 统一「下单跳转 / 异步通知验签」入口，全部为内置驱动：
 *   · epay    彩虹易支付协议（Shop.php 原生实现）
 *   · codepay 码支付（个人免签聚合：支付宝/微信/QQ）
 *   · vmq     V免签（聚合支付：支付宝/微信）
 *
 * 驱动在管理后台「商品与交易 → 发卡网配置」下拉选择并配置。
 * 本文件由 lib/bootstrap.php 加载，Shop.php 与管理端 handlers 共用。
 */

class Pay
{
    const DRV_KEY = 'shop_pay_driver'; // 当前启用驱动（缺省 epay）
    const CFG_KEY = 'shop_pay_cfg';    // 各驱动配置 JSON {driver:{field:value}}

    /** 内置驱动元信息：name => [title, required 字段] */
    const DRIVERS = [
        'codepay' => ['title' => '码支付（个人免签）', 'required' => ['gateway', 'pid', 'key']],
        'vmq'     => ['title' => 'V免签（聚合支付）', 'required' => ['gateway', 'key']],
    ];

    public static function driverExists(string $name): bool
    {
        return $name === 'epay' || isset(self::DRIVERS[$name]);
    }

    /** 当前启用驱动（非法值回退 epay） */
    public static function active(): string
    {
        $d = strtolower(trim((string) Setting::get(self::DRV_KEY, 'epay')));
        return self::driverExists($d) ? $d : 'epay';
    }

    /** 指定驱动的配置 */
    public static function cfg(string $driver): array
    {
        $all = json_decode((string) Setting::get(self::CFG_KEY, '{}'), true);
        $c = $all[$driver] ?? [];
        return is_array($c) ? $c : [];
    }

    /** 驱动是否配置齐全 */
    public static function ready(string $driver): bool
    {
        if ($driver === 'epay') {
            return Shop::epayReady();
        }
        if (!isset(self::DRIVERS[$driver])) {
            return false;
        }
        $cfg = self::cfg($driver);
        foreach (self::DRIVERS[$driver]['required'] as $f) {
            if (trim((string) ($cfg[$f] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 创建支付跳转地址
     * @param array $order {order_no, product, amount(分), channel}
     */
    public static function payUrl(array $order, string $notifyUrl, string $returnUrl): string
    {
        $drv = self::active();
        if ($drv === 'epay') {
            return Shop::epayPayUrl(
                (string) $order['order_no'],
                (string) $order['product'],
                (int) $order['amount'],
                (string) $order['channel'],
                $notifyUrl,
                $returnUrl
            );
        }
        if ($drv === 'codepay') {
            return self::codepayPayUrl($order, self::cfg('codepay'), $notifyUrl, $returnUrl);
        }
        if ($drv === 'vmq') {
            return self::vmqPayUrl($order, self::cfg('vmq'), $notifyUrl, $returnUrl);
        }
        return '';
    }

    /**
     * 异步通知验签
     * @return array{0:bool, 1:string, 2:int} 是否有效 + 订单号 + 实付金额(分)，金额取不到时为 -1（由调用方回退 money 字段）
     */
    public static function verifyNotify(array $params): array
    {
        $drv = self::active();
        if ($drv === 'epay') {
            return [Shop::epayVerifyNotify($params), (string) ($params['out_trade_no'] ?? ''), -1];
        }
        if ($drv === 'codepay') {
            return self::codepayVerify($params, self::cfg('codepay'));
        }
        if ($drv === 'vmq') {
            return self::vmqVerify($params, self::cfg('vmq'));
        }
        return [false, '', -1];
    }

    // ==================== 码支付（codepay） ====================

    private static function codepayPayUrl(array $order, array $cfg, string $notifyUrl, string $returnUrl): string
    {
        $gateway = rtrim(trim((string) ($cfg['gateway'] ?? '')), '/');
        $pid     = (string) ($cfg['pid'] ?? '');
        $key     = (string) ($cfg['key'] ?? '');
        if ($gateway === '' || $pid === '' || $key === '') {
            return '';
        }
        $params = [
            'id'           => $pid,
            'type'         => in_array($order['channel'] ?? '', ['alipay', 'wxpay', 'qqpay'], true) ? $order['channel'] : 'alipay',
            'out_trade_no' => (string) $order['order_no'],
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => mb_substr((string) $order['product'], 0, 60, 'UTF-8'),
            'money'        => number_format(((int) $order['amount']) / 100, 2, '.', ''),
        ];
        // 签名：参数名 ASCII 升序拼 a=v&b=v，末尾接密钥后 md5
        ksort($params);
        $buf = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) continue;
            $buf[] = $k . '=' . $v;
        }
        $params['sign']      = md5(implode('&', $buf) . $key);
        $params['sign_type'] = 'MD5';
        return $gateway . '/submit.php?' . http_build_query($params);
    }

    /** @return array{0:bool,1:string,2:int} [是否有效, 订单号, 实付金额(分)] */
    private static function codepayVerify(array $params, array $cfg): array
    {
        $key = (string) ($cfg['key'] ?? '');
        $orderNo = (string) ($params['out_trade_no'] ?? '');
        $sign    = (string) ($params['sign'] ?? '');
        if ($key === '' || $orderNo === '' || $sign === '') {
            return [false, '', -1];
        }
        $money = (string) ($params['money'] ?? '0');
        $expect = md5($orderNo . $money . (string) ($params['trade_no'] ?? '') . $key);
        if (!hash_equals($expect, $sign)) {
            return [false, '', -1];
        }
        return [true, $orderNo, (int) round(((float) $money) * 100)];
    }

    // ==================== V免签（vmq） ====================

    private static function vmqPayUrl(array $order, array $cfg, string $notifyUrl, string $returnUrl): string
    {
        $gateway = rtrim(trim((string) ($cfg['gateway'] ?? '')), '/');
        $key     = trim((string) ($cfg['key'] ?? ''));
        if ($gateway === '' || $key === '') {
            return '';
        }
        // 渠道：V免签 type 1=微信 2=支付宝
        $type = (($order['channel'] ?? '') === 'wxpay') ? '1' : '2';
        $payId = (string) $order['order_no'];
        $price = number_format(((int) $order['amount']) / 100, 2, '.', '');
        $sign = md5($payId . 'shop' . $type . $price . $key);

        $q = http_build_query([
            'payId'     => $payId,
            'param'     => 'shop',
            'type'      => $type,
            'price'     => $price,
            'isHtml'    => '1',
            'notifyUrl' => $notifyUrl,
            'returnUrl' => $returnUrl,
            'sign'      => $sign,
        ]);
        return $gateway . '/createOrder?' . $q;
    }

    /** @return array{0:bool,1:string,2:int} [是否有效, 订单号, 实付金额(分)] */
    private static function vmqVerify(array $params, array $cfg): array
    {
        $key = trim((string) ($cfg['key'] ?? ''));
        $payId = (string) ($params['payId'] ?? '');
        $sign  = (string) ($params['sign'] ?? '');
        if ($key === '' || $payId === '' || $sign === '') {
            return [false, '', -1];
        }
        $reallyPrice = (string) ($params['reallyPrice'] ?? ($params['price'] ?? '0'));
        $expect = md5($payId . (string) ($params['param'] ?? '') . (string) ($params['type'] ?? '') . (string) ($params['price'] ?? '') . $reallyPrice . $key);
        if (!hash_equals($expect, $sign)) {
            return [false, '', -1];
        }
        return [true, $payId, (int) round(((float) $reallyPrice) * 100)];
    }
}
