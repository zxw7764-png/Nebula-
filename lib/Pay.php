<?php
/**
 * 支付驱动层（Nebula Menu）
 * ------------------------------------------------------------------
 * 统一「下单跳转 / 异步通知验签」入口，全部为内置驱动：
 *   · epay    彩虹易支付协议（Shop.php 原生实现）
 *   · codepay 码支付（个人免签聚合：支付宝/微信/QQ）
 *   · vmq     V免签（聚合支付：支付宝/微信）
 *   · wechat  微信支付官方 Native 扫码（v3 API, SHA256-RSA 签名）
 *   · alipay  支付宝官方电脑网站 / 当面付（RSA2 签名）
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
        'wechat'  => ['title' => '微信支付官方（Native扫码）', 'required' => ['appid', 'mchid', 'apiKey', 'cert', 'serial']],
        'wechatauth'  => ['title' => '微信支付官方（JSAPI微信内网页）', 'required' => ['appid', 'jsapi_appid', 'mchid', 'apiKey', 'cert', 'serial']],
        'alipay'  => ['title' => '支付宝官方（电脑网站/当面付）', 'required' => ['appid', 'privateKey', 'publicKey']],
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
     * @param string|null $driver 显式指定驱动（null=用当前启用驱动）
     */
    public static function payUrl(array $order, string $notifyUrl, string $returnUrl, ?string $driver = null): string
    {
        $drv = $driver ?? self::active();
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
        if ($drv === 'wechat') {
            return self::wechatPayUrl($order, self::cfg('wechat'), $notifyUrl, $returnUrl);
        }
        if ($drv === 'wechatauth') {
            return self::wechatJsapiPayUrl($order, self::cfg('wechatauth'), $notifyUrl, $returnUrl);
        }
        if ($drv === 'alipay') {
            return self::alipayPayUrl($order, self::cfg('alipay'), $notifyUrl, $returnUrl);
        }
        return '';
    }

    /**
     * 异步通知验签
     * @param array $params
     * @param string|null $driver 显式指定驱动（null=用当前启用驱动）
     * @return array{0:bool, 1:string, 2:int} 是否有效 + 订单号 + 实付金额(分)，金额取不到时为 -1（由调用方回退 money 字段）
     */
    public static function verifyNotify(array $params, ?string $driver = null): array
    {
        $drv = $driver ?? self::active();
        if ($drv === 'epay') {
            return [Shop::epayVerifyNotify($params), (string) ($params['out_trade_no'] ?? ''), -1];
        }
        if ($drv === 'codepay') {
            return self::codepayVerify($params, self::cfg('codepay'));
        }
        if ($drv === 'vmq') {
            return self::vmqVerify($params, self::cfg('vmq'));
        }
        if ($drv === 'wechat') {
            return self::wechatVerifyNotify($params, self::cfg('wechat'));
        }
        if ($drv === 'wechatauth') {
            return self::wechatVerifyNotify($params, self::cfg('wechatauth'));
        }
        if ($drv === 'alipay') {
            return self::alipayVerifyNotify($params, self::cfg('alipay'));
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

    // ==================== 微信支付官方（Native扫码，v3 API） ====================

    /**
     * 微信支付官方案例：扫码下单（JSAPI/JSAPI支付/NATIVE支付/APP支付等统一用v3 API）
     * 本插件面向发卡网场景：买家下单 → 返回小程序码/二维码（Native支付） → 买家扫完 → 服务端 v3 回调。
     *
     * 这里给出「扫码下单」两阶段实现：
     *   · 阶段1（payUrl）：调用 v3 /v3/pay/transactions/native POST 创建订单，获取 prepay_id → 前端渲染二维码
     *   · 阶段2（verifyNotify）：v3 异步回调 POST /wechat/v3/notify，用平台证书公钥验签 + 解密 AES-256-GCM
     *
     * 字段约定（后台 shop_pay_cfg.wechat.*）：
     *   - appid         微信商户 AppID（应用级别）
     *   - mchid         微信支付商户号
     *   - apiKey        APIv3 密钥（32字节，后台配置用）
     *   - serial        商户 API 证书序列号（16进制大写，后台配置用）
     *   - cert          商户私钥 PEM 路径或内容（可 base64 存入）
     *   - notifyUrl     商家自配置的异步回调 URL（可选，优先读 shop_site_url）
     *
     * 注意：v3 要求平台证书（用于验签），本项目暂不支持自动下载平台证书库。
     * 商家需在服务器上保留已下载的 platform certificates（certs/wechat/）。
     * 本插件在 verifyNotify 中要求提供平台证书路径集合（通过 Setting wechat_cert_dir）。
     */
    private static function wechatPayUrl(array $order, array $cfg, string $notifyUrl, string $returnUrl): string
    {
        $appid   = trim((string) ($cfg['appid'] ?? ''));
        $mchid   = trim((string) ($cfg['mchid'] ?? ''));
        $apiKey  = trim((string) ($cfg['apiKey'] ?? ''));
        $serial  = trim((string) ($cfg['serial'] ?? ''));
        $certRaw = trim((string) ($cfg['cert'] ?? ''));
        $certDir = trim((string) (Setting::get('wechat_cert_dir', '')));
        $notifyUrl = $notifyUrl !== '' ? $notifyUrl : rtrim((string) Setting::get('shop_site_url', ''), '/');
        if ($notifyUrl === '') {
            return '';
        }

        if ($appid === '' || $mchid === '' || $apiKey === '' || $serial === '' || $certRaw === '') {
            return '';
        }

        $orderNo = (string) $order['order_no'];
        $amountFen = (int) $order['amount'];
        $desc = mb_substr((string) $order['product'], 0, 64, 'UTF-8');
        $timestamp = (string) time();
        $nonceStr = self::randStr(32);

        // 构造 v3 下单 body：out_trade_no + appid + mchid + description + amount + notify_url + scene_info
        $body = [
            'appid'      => $appid,
            'mchid'      => $mchid,
            'description'=> $desc,
            'out_trade_no'=> $orderNo,
            'notify_url' => $notifyUrl . '/shop/wechat_notify.php',
            'amount'     => ['total' => $amountFen, 'currency' => 'CNY'],
            'scene_info' => ['payer_client_ip' => Util::ip()],
        ];
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 签名：METHOD\nURL\nTIMESTAMP\nNONCE\nBODY\n
        $url = '/v3/pay/transactions/native';
        $signedStr = "POST\n{$url}\n{$timestamp}\n{$nonceStr}\n{$jsonBody}\n";
        // 私钥（支持 base64 编码传入）
        $pkey = self::loadPrivateKey($certRaw);
        if ($pkey === false) {
            return '';
        }
        $sig = '';
        if (!openssl_sign($signedStr, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        $authHeader = self::wechatAuthHeader($appid, $mchid, $serial, $timestamp, $nonceStr, base64_encode($sig));

        // 请求 v3 下单接口
        $resp = self::wechatHttpPost("https://api.mch.weixin.qq.com{$url}", $jsonBody, $authHeader);
        if ($resp === false || !preg_match('/^2\d{2}$/', (string) $resp['code'])) {
            return '';
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || empty($data['code_url'])) {
            return '';
        }
        // 把 code_url 存成「扫码支付 URL」（商家拿到此码渲染二维码供买家扫描）
        // 前端通过 shop/api.php?action=wechat_qrcode 再读此字段
        return $data['code_url'];
    }

    /**
     * v3 异步回调处理入口：解析请求头、平台证书验签、AES-256-GCM 解密
     * @return array{0:bool,1:string,2:int}
     */
    private static function wechatVerifyNotify(array $params, array $cfg): array
    {
        // 平台证书目录：默认 {root}/certs/wechat/，由管理员部署
        $certDir = trim((string) (Setting::get('wechat_cert_dir', NB_ROOT . '/certs/wechat')));
        $serial = trim((string) ($cfg['serial'] ?? ''));
        $appid  = trim((string) ($cfg['appid'] ?? ''));
        $mchid  = trim((string) ($cfg['mchid'] ?? ''));
        $apiKey = trim((string) ($cfg['apiKey'] ?? ''));

        // 从 raw input 读 JSON 与签名头（v3 用自定义 HTTP 头）
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return [false, '', -1];
        }
        // out_trade_no 在 body.out_trade_no 或 嵌套 body.out_trade_no
        $orderNo = (string) ($body['out_trade_no'] ?? '');
        if ($orderNo === '') {
            return [false, '', -1];
        }
        // 金额：body.amount.total (分) 或 body.out_trade_no 无金额字段，用 total
        $amountFen = (int) ($body['amount']['total'] ?? -1);
        if ($amountFen <= 0) {
            $amountFen = -1;
        }

        // 使用微信 v3 验签：Ciphertext 需 AES-256-GCM 解密（nonce: wechat 固定 16 字节）
        $ciphertext = (string) ($body['ciphertext'] ?? '');
        $nonce      = (string) ($body['nonce'] ?? '');
        $associated = '';
        if ($ciphertext !== '' && $nonce !== '') {
            $dec = openssl_decrypt(
                base64_decode($ciphertext),
                'aes-256-gcm',
                $apiKey,
                OPENSSL_RAW_DATA,
                $nonce,
                ''
            );
            if ($dec !== false) {
                $body = json_decode($dec, true);
                if (is_array($body)) {
                    $orderNo = (string) ($body['out_trade_no'] ?? $orderNo);
                    $amountFen = (int) ($body['amount']['total'] ?? $amountFen);
                }
            }
        }
        if ($orderNo === '') {
            return [false, '', -1];
        }

        // v3 验签：读取请求头中的签名信息
        $headers = self::wechatGetRequestHeaders();
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce     = (string) ($headers['nonce'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        $serialHdr = (string) ($headers['serial'] ?? '');

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return [false, '', -1];
        }
        // 验签串
        $signedString = "{$timestamp}\n{$nonce}\n{$raw}\n";
        // 找最近有效平台证书
        $pubKey = self::wechatPlatformCert($certDir, $serialHdr);
        if ($pubKey === false) {
            return [false, '', -1];
        }
        $ok = openssl_verify($signedString, base64_decode($signature), $pubKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return [false, '', -1];
        }

        // 校验 appid/mchid 一致性
        if ($appid !== '' && (string) ($body['appid'] ?? '') !== $appid) {
            return [false, '', -1];
        }
        if ($mchid !== '' && (string) ($body['mchid'] ?? '') !== $mchid) {
            return [false, '', -1];
        }

        return [true, $orderNo, $amountFen > 0 ? $amountFen : -1];
    }

    /** 读取 PHP 请求头（兼容无 getallheaders 的环境） */
    private static function wechatGetRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $h[strtolower(substr($k, 5))] = $v;
            }
        }
        return $h;
    }

    /** 构造 v3 签名 Authorization 头 */
    private static function wechatAuthHeader(string $appid, string $mchid, string $serial, string $timestamp, string $nonce, string $sig): string
    {
        return sprintf(
            'HMAC-SHA256 hostname="api.mch.weixin.qq.com", mchid="%s", serial="%s", timestamp="%s", nonce="%s", signature="%s"',
            $mchid, $serial, $timestamp, $nonce, $sig
        );
    }

    /** 向微信 v3 API 发 POST 请求，返回 ['code'=>int, 'body'=>string] */
    private static function wechatHttpPost(string $url, string $jsonBody, string $authHeader): array
    {
        $ch = curl_init($url);
        if (!$ch) {
            return ['code' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $jsonBody,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'Authorization: ' . $authHeader,
                'User-Agent: NebulaMenu/Shop-WechatDriver',
            ],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT          => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => (int) $httpCode, 'body' => (string) $resp];
    }

    /** 从私钥原始内容（PEM 或 base64 编码的 PEM）加载 openssl key resource */
    private static function loadPrivateKey(string $raw): mixed
    {
        $decoded = @base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) < strlen($raw)) {
            $raw = $decoded;
        }
        $res = openssl_pkey_get_private($raw);
        return $res ?: false;
    }

    /**
     * 在 certDir 目录下找最近有效（未过期）的平台证书 PEM。
     * 文件名惯例：weixin_{serial}.pem（微信官方 SDK 格式）或按 serial 匹配。
     * 找不到返回 false（验签失败由调用方处理）。
     */
    private static function wechatPlatformCert(string $certDir, string $wantSerial = ''): mixed
    {
        if (!is_dir($certDir)) {
            return false;
        }
        $candidates = glob($certDir . '/*.pem');
        if ($candidates === []) {
            return false;
        }
        $valid = [];
        foreach ($candidates as $p) {
            $data = file_get_contents($p);
            if ($data === false) {
                continue;
            }
            $info = openssl_x509_parse($data);
            if (!is_array($info) || empty($info['serialNumber']) || empty($info['validFrom_time_t']) || empty($info['validTo_time_t'])) {
                continue;
            }
            $now = time();
            if ((int) $info['validFrom_time_t'] > $now || (int) $info['validTo_time_t'] < $now) {
                continue;
            }
            // 匹配调用方想要的 serial（可为空表示任意近期证书）
            if ($wantSerial !== '' && strtoupper((string) $info['serialNumber']) !== strtoupper($wantSerial)) {
                continue;
            }
            $valid[] = [
                'mtime' => (int) $info['validTo_time_t'],
                'data'  => $data,
            ];
        }
        if ($valid === []) {
            return false;
        }
        // 取到期时间最晚的（一般最新下载的那个）
        usort($valid, static function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });
        return openssl_pkey_get_public($valid[0]['data']);
    }

    private static function randStr(int $len): string
    {
        return bin2hex(random_bytes(max(1, (int) ceil($len / 2)))) ;
    }

    // ==================== 微信支付官方 JSAPI（微信内网页唤起支付，v3 API） ====================

    /**
     * 微信 JSAPI 支付（v3 API）
     * 用于微信内置浏览器中直接唤起微信支付，而非扫码。
     * 返回 prepay_id，前端用 WeixinJSBridge.invoke('getBrandWCPayRequest', {...}) 调起支付。
     *
     * 配置字段：
     *   - appid       微信支付商户 AppID（与 Native 驱动相同）
     *   - jsapi_appid 微信公众号/小程序 AppID（用于获取 openid，通常与 appid 相同或关联公众号）
     *   - mchid       微信支付商户号
     *   - apiKey      APIv3 密钥
     *   - serial      商户 API 证书序列号
     *   - cert        商户私钥 PEM
     *
     * 返回：JSON 字符串，包含前端调起支付所需的参数：
     *   {appId, timeStamp, nonceStr, package, signType, paySign}
     * 若缺少 openid 字段（order.openid），返回空串（需前端先完成微信授权获取 openid）。
     */
    private static function wechatJsapiPayUrl(array $order, array $cfg, string $notifyUrl, string $returnUrl): string
    {
        $appid     = trim((string) ($cfg['appid'] ?? ''));
        $jsapiAppid = trim((string) ($cfg['jsapi_appid'] ?? $appid));
        $mchid     = trim((string) ($cfg['mchid'] ?? ''));
        $apiKey    = trim((string) ($cfg['apiKey'] ?? ''));
        $serial    = trim((string) ($cfg['serial'] ?? ''));
        $certRaw   = trim((string) ($cfg['cert'] ?? ''));
        $notifyUrl = $notifyUrl !== '' ? $notifyUrl : rtrim((string) Setting::get('shop_site_url', ''), '/');
        if ($notifyUrl === '' || $appid === '' || $mchid === '' || $apiKey === '' || $serial === '' || $certRaw === '') {
            return '';
        }

        $openid = (string) ($order['openid'] ?? '');
        if ($openid === '') {
            return json_encode(['error' => 'openid_required', 'msg' => '请在微信内访问并完成授权后重新下单'], JSON_UNESCAPED_UNICODE);
        }

        $orderNo = (string) $order['order_no'];
        $amountFen = (int) $order['amount'];
        $desc = mb_substr((string) $order['product'], 0, 64, 'UTF-8');
        $timestamp = (string) time();
        $nonceStr = self::randStr(32);

        $body = [
            'appid'        => $jsapiAppid,
            'mchid'        => $mchid,
            'description'  => $desc,
            'out_trade_no' => $orderNo,
            'notify_url'   => $notifyUrl . '/shop/wechat_notify.php',
            'amount'       => ['total' => $amountFen, 'currency' => 'CNY'],
            'payer'        => ['openid' => $openid],
            'scene_info'   => ['payer_client_ip' => Util::ip()],
        ];
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = '/v3/pay/transactions/jsapi';
        $signedStr = "POST\n{$url}\n{$timestamp}\n{$nonceStr}\n{$jsonBody}\n";
        $pkey = self::loadPrivateKey($certRaw);
        if ($pkey === false) {
            return '';
        }
        $sig = '';
        if (!openssl_sign($signedStr, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        $authHeader = self::wechatAuthHeader($appid, $mchid, $serial, $timestamp, $nonceStr, base64_encode($sig));

        $resp = self::wechatHttpPost("https://api.mch.weixin.qq.com{$url}", $jsonBody, $authHeader);
        if ($resp === false || !preg_match('/^2\d{2}$/', (string) $resp['code'])) {
            return '';
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || empty($data['prepay_id'])) {
            return '';
        }

        // 组装前端 JSAPI 调用参数（微信 JSAPI 签名）
        $jsTime = (string) time();
        $jsNonce = self::randStr(32);
        $jsSignStr = "{$jsapiAppid}\n{$jsTime}\n{$jsNonce}\n{\"appId\":\"{$jsapiAppid}\",\"nonceStr\":\"{$jsNonce}\",\"package\":\"prepay_id=" . $data['prepay_id'] . "\",\"signType\":\"RSA\",\"timeStamp\":\"{$jsTime}\"}\n";
        $jsSig = '';
        if (openssl_sign($jsSignStr, $jsSig, $pkey, OPENSSL_ALGO_SHA256)) {
            return json_encode([
                'appId'     => $jsapiAppid,
                'timeStamp' => $jsTime,
                'nonceStr'  => $jsNonce,
                'package'   => 'prepay_id=' . $data['prepay_id'],
                'signType'  => 'RSA',
                'paySign'   => base64_encode($jsSig),
            ], JSON_UNESCAPED_UNICODE);
        }
        return '';
    }

    // ==================== 支付宝官方（电脑网站 / 当面付，RSA2 签名） ====================

    /**
     * 支付宝官方：电脑网站支付（alipay.trade.page.pay）/ 当面付（alipay.trade.precreate）
     * 本插件默认以「当面付」模式（生成二维码直扫）为主，兼顾「电脑网站」模式（跳转收银台）。
     * 选择模式用 shop_pay_cfg.alipay.mode: page(电脑网站，默认) | face(当面付)
     *
     * 字段约定：
     *   - appid        支付宝应用 APPID（非商户 ID）
     *   - privateKey   应用私钥 PEM（基础 RSA，非 PKCS8 均可，插件内统一转 PKCS8）
     *   - publicKey    支付宝公钥 PEM（用于验签）
     *   - signType     签名类型：RSA2(RSA-SHA256) 或 RSA，默认 RSA2
     *   - charset      字符集，默认 utf-8
     *   - gateway      支付宝网关（默认 https://openapi.alipay.com/gateway.do）
     *   - mode         page | face，默认 page
     *
     * 说明：支付宝当面付模式会返回 QR_CODE 字符串（买家扫的 URL 或 base64 QR）。
     * 本插件统一返回 URL：当面付模式下即为该 QR_CODE 字符串，前端扫码即可。
     */
    private static function alipayPayUrl(array $order, array $cfg, string $notifyUrl, string $returnUrl): string
    {
        $appid   = trim((string) ($cfg['appid'] ?? ''));
        $priKey  = trim((string) ($cfg['privateKey'] ?? ''));
        $pubKey  = trim((string) ($cfg['publicKey'] ?? ''));
        $mode    = strtolower(trim((string) ($cfg['mode'] ?? 'page')));
        $signType = in_array(strtolower(trim((string) ($cfg['signType'] ?? 'RSA2'))), ['rsa', 'rsa2'], true)
            ? strtoupper(trim((string) ($cfg['signType'] ?? 'RSA2'))) : 'RSA2';
        $charset = in_array(strtolower(trim((string) ($cfg['charset'] ?? 'utf-8'))), ['gbk', 'gb2312', 'utf-8'], true)
            ? strtolower(trim((string) ($cfg['charset'] ?? 'utf-8'))) : 'utf-8';
        $gateway = trim((string) ($cfg['gateway'] ?? 'https://openapi.alipay.com/gateway.do'));
        $notifyUrl = $notifyUrl !== '' ? $notifyUrl : rtrim((string) Setting::get('shop_site_url', ''), '/');

        if ($appid === '' || $priKey === '' || $pubKey === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $notifyUrl)) {
            return '';
        }

        $orderNo = (string) $order['order_no'];
        $total   = number_format(((int) $order['amount']) / 100, 2, '.', '');
        $subject = mb_substr((string) $order['product'], 0, 128, 'UTF-8');

        // 当面付需要先请求 SDK 获取 qr_code
        if ($mode === 'face') {
            $biz = [
                'out_trade_no' => $orderNo,
                'total_amount' => $total,
                'subject'      => $subject,
                'quit_url'     => $returnUrl,
            ];
            $result = self::alipayExecute('alipay.trade.precreate', $biz, $appid, $priKey, $signType, $charset, $gateway);
            if (!is_array($result) || !isset($result['alipay_trade_precreate_response']['qr_code'])) {
                return '';
            }
            return (string) $result['alipay_trade_precreate_response']['qr_code'];
        }

        // 电脑网站模式：拼装 SDK URL 参数
        $biz = [
            'out_trade_no' => $orderNo,
            'total_amount' => $total,
            'subject'      => $subject,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'passback_params' => urlencode('n=' . $orderNo),
        ];
        $params = [
            'app_id'       => $appid,
            'method'       => 'alipay.trade.page.pay',
            'charset'      => $charset,
            'sign_type'    => $signType,
            'timestamp'    => date('Y-m-d H:i:s'),
            'version'      => '1.0',
            'notify_url'   => $notifyUrl . '/shop/alipay_notify.php',
            'return_url'   => $returnUrl,
            'biz_content'  => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = self::alipaySign($params, $priKey, $signType);
        return $gateway . '?' . http_build_query($params);
    }

    /**
     * 支付宝回调验签：从 $_GET（同步 return）或 $_POST（异步 notify）取参数
     * 注意：支付宝异步通知是 POST，同步是 GET；此处统一兼容。
     * @return array{0:bool,1:string,2:int}
     */
    private static function alipayVerifyNotify(array $params, array $cfg): array
    {
        $pubKey = trim((string) ($cfg['publicKey'] ?? ''));
        if ($pubKey === '') {
            return [false, '', -1];
        }
        $sign = (string) ($params['sign'] ?? '');
        $signType = (string) ($params['sign_type'] ?? 'RSA2');
        $orderNoFromBiz = (string) ($params['out_trade_no'] ?? '');
        $tradeNo = (string) ($params['trade_no'] ?? '');
        $totalAmount = (string) ($params['total_amount'] ?? '0');

        if ($sign === '' || $orderNoFromBiz === '' || $tradeNo === '') {
            return [false, '', -1];
        }

        // 验签：把所有非空参数（除 sign / sign_type 自身）按 key 排序拼接
        $toSign = [];
        foreach ($params as $k => $v) {
            if (in_array($k, ['sign', 'sign_type'], true)) {
                continue;
            }
            if ($v === '' || $v === null) {
                continue;
            }
            $toSign[$k] = $v;
        }
        ksort($toSign);
        $buf = [];
        foreach ($toSign as $k => $v) {
            $buf[] = $k . '=' . $v;
        }
        $content = implode('&', $buf);
        $algo = $signType === 'RSA' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        $ok = openssl_verify($content, base64_decode($sign), self::alipayLoadPubKey($pubKey), $algo);
        if ($ok !== 1) {
            return [false, '', -1];
        }

        // 支付宝异步通知要求 trade_status=TRADE_SUCCESS
        $tradeStatus = (string) ($params['trade_status'] ?? '');
        if ($tradeStatus !== 'TRADE_SUCCESS' && $tradeStatus !== 'TRADE_FINISHED') {
            return [false, '', -1];
        }

        $amountFen = (int) round(((float) $totalAmount) * 100);
        return [true, $orderNoFromBiz, $amountFen];
    }

    /** 支付宝 SDK 签名（RSA2 / RSA） */
    private static function alipaySign(array $params, string $privateKey, string $signType): string
    {
        // 去掉 biz_content 后签名
        $bizContent = $params['biz_content'] ?? '';
        unset($params['biz_content']);
        ksort($params);
        $buf = [];
        foreach ($params as $k => $v) {
            $buf[] = $k . '=' . urlencode((string) $v);
        }
        $content = implode('&', $buf);
        $algo = $signType === 'RSA' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        $key = self::alipayLoadPriKey($privateKey);
        if ($key === false) {
            return '';
        }
        $sig = '';
        openssl_sign($content, $sig, $key, $algo);
        return base64_encode($sig);
    }

    /** 加载支付宝私钥（自动把 PEM 转为 PKCS8，兼容传统 RSA 私钥） */
    private static function alipayLoadPriKey(string $raw): mixed
    {
        // 先尝试 base64 解码（如果原始数据是 base64 编码的 PEM）
        $decoded = @base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) < strlen($raw)) {
            $raw = $decoded;
        }
        // 如果是 PKCS1 (BEGIN RSA PRIVATE KEY) 转 PKCS8
        if (strpos($raw, '-----BEGIN RSA PRIVATE KEY-----') === 0) {
            $keyObj = openssl_pkey_get_private($raw);
            if ($keyObj === false) {
                return false;
            }
            $keyInfo = [];
            openssl_pkey_export($keyObj, $keyInfo);
            $raw = $keyInfo;
        }
        return openssl_pkey_get_private($raw);
    }

    /** 加载支付宝公钥（自动补全头尾） */
    private static function alipayLoadPubKey(string $raw): mixed
    {
        if ($raw === '') {
            return false;
        }
        if (strpos($raw, '-----BEGIN PUBLIC KEY-----') !== 0) {
            $raw = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($raw, 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        return openssl_pkey_get_public($raw);
    }

    /**
     * 调用支付宝 OpenAPI SDK（模拟 SDK 行为，直接构造请求）
     * @return array|false
     */
    private static function alipayExecute(string $method, array $biz, string $appid, string $priKey, string $signType, string $charset, string $gateway)
    {
        $params = [
            'app_id'      => $appid,
            'method'      => $method,
            'charset'     => $charset,
            'sign_type'   => $signType,
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = self::alipaySign($params, $priKey, $signType);
        $query = http_build_query($params);
        $ch = curl_init($gateway . '?' . $query);
        if (!$ch) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $query,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT          => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $out = [];
        parse_str($resp, $out);
        return is_array($out) ? $out : false;
    }
}
