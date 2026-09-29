<?php
/**
 * 通信加密层
 * 算法: AES-256-CBC + HMAC-SHA256 签名
 *
 * 报文约定（客户端 -> 服务端）:
 *   {
 *     "data": "<base64(iv[16] + ciphertext)>",
 *     "sign": "<HMAC-SHA256(data + timestamp + nonce, salt) 的 hex>",
 *     "t":    1726000000,      // 秒级时间戳
 *     "n":    "随机字符串"      // nonce，防重放
 *   }
 *
 * 响应约定（服务端 -> 客户端）:
 *   同样使用 data/sign 包裹，客户端解密后得到业务 JSON
 *   （响应签名盐 = 请求验签所用盐；无会话密钥时为主盐）
 */
class Crypto
{
    private static string $aesKey = '';
    private static string $signSalt = '';
    private static int $timeWindow = 300;

    /** 本次请求的响应签名盐覆盖（会话密钥模式） */
    private static ?string $respSalt = null;

    /** 会话密钥绑定的 machine_id（解密后与请求体中的 machine_id 校验） */
    private static string $sessionMachineId = '';

    /**
     * 切换当前请求使用的通信密钥（多软件：init 前按 app_key 命中软件后调用）。
     * 之后 encrypt/decrypt/verifySign/buildResponse 全部使用该软件的密钥。
     */
    public static function useKeys(string $aesKey, string $signSalt): void
    {
        if ($aesKey !== '') {
            self::$aesKey = $aesKey;
        }
        if ($signSalt !== '') {
            self::$signSalt = $signSalt;
        }
        // 换了主钥，会话盐覆盖也要随之清掉，避免跨软件残留
        self::$respSalt = null;
    }

    /** 已用过的 nonce 缓存目录 */
    private static string $nonceDir = '';

    public static function init(array $cfg): void
    {
        self::$aesKey     = $cfg['aes_key'];
        self::$signSalt   = $cfg['sign_salt'];
        self::$timeWindow = $cfg['time_window'] ?? 300;
        // nonce 去重目录放在项目 logs/ 下（部署模板已整目录 deny），
        // 不再用系统共享临时目录 —— 同机多站点会互相污染，且 /tmp 可被预置。
        self::$nonceDir   = defined('NB_ROOT')
            ? NB_ROOT . '/logs/nonce'
            : sys_get_temp_dir() . '/nb_nonce';
        if (!is_dir(self::$nonceDir)) {
            @mkdir(self::$nonceDir, 0700, true);
        }
    }

    /** 归一化密钥到 32 字节 */
    private static function key(): string
    {
        return substr(hash('sha256', self::$aesKey, true), 0, 32);
    }

    /**
     * 由主钥派生的固定 IV。
     * 仅作为 random_bytes 不可用时的回落，以及兼容极老客户端报文的兜底 ——
     * 正常路径一律使用每次加密新生成的随机 IV（见 encryptCbc）。
     */
    private static function iv(): string
    {
        return substr(hash('md5', self::$aesKey, true), 0, 16);
    }

    // ------------------------------------------------------------------
    // 加解密
    // ------------------------------------------------------------------

    /** 加密：返回 base64(iv + ciphertext) */
    public static function encrypt(string $plain): string
    {
        return self::encryptCbc($plain);
    }

    /** 解密 base64(iv + ciphertext) */
    public static function decrypt(string $b64): ?string
    {
        return self::decryptCbc($b64);
    }

    /** AES-256-CBC，返回 base64(iv + ciphertext) */
    public static function encryptCbc(string $plain): string
    {
        // 每次加密生成独立随机 IV，并随密文一起下发（报文格式本就是 iv[16] + ciphertext）。
        // 客户端解密时按协议取前 16 字节作为 IV，因此对既有 SDK 完全兼容；
        // 固定 IV 会让相同明文前缀产生相同密文前缀，泄露报文结构，故不再使用。
        try {
            $iv = random_bytes(16);
        } catch (Throwable $e) {
            $iv = self::iv();
        }
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $cipher);
    }

    /** 解密 base64(iv + ciphertext) */
    public static function decryptCbc(string $b64): ?string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain !== false) {
            return $plain;
        }
        // 兼容兜底：极老的自实现客户端可能发「固定 IV + 纯密文」（不带头部 IV），
        // 此时整段 raw 都是密文，用派生 IV 再试一次。签名校验在解密之前完成，
        // 所以这里不会因为多试一次而放宽任何安全边界。
        $plain2 = openssl_decrypt($raw, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, self::iv());
        return $plain2 === false ? null : $plain2;
    }

    // ------------------------------------------------------------------
    // 签名
    // ------------------------------------------------------------------

    /**
     * 计算签名：HMAC-SHA256(data|timestamp|nonce, salt)
     */
    public static function sign(string $data, int $timestamp, string $nonce): string
    {
        return self::signWithSalt($data, $timestamp, $nonce, self::$signSalt);
    }

    /** 指定盐计算签名（会话密钥测试/工具用） */
    public static function signWithSalt(string $data, int $timestamp, string $nonce, string $salt): string
    {
        return hash_hmac('sha256', $data . '|' . $timestamp . '|' . $nonce, $salt);
    }

    /** 恒定时间比较，防时序攻击 */
    public static function verifySign(string $data, int $timestamp, string $nonce, string $sign, ?string $salt = null): bool
    {
        $expect = self::signWithSalt($data, $timestamp, $nonce, $salt ?? self::$signSalt);
        return hash_equals($expect, $sign);
    }

    // ------------------------------------------------------------------
    // 防重放
    // ------------------------------------------------------------------

    /** 校验时间戳是否在允许窗口内 */
    public static function checkTimestamp(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= self::$timeWindow;
    }

    /**
     * 校验 nonce 是否已使用；未使用则登记
     * 用「独占创建」实现原子去重：并发下的同一 nonce 只可能有一个请求创建成功。
     */
    public static function checkNonce(string $nonce): bool
    {
        if ($nonce === '' || strlen($nonce) < 8) {
            return false;
        }
        $file = self::$nonceDir . '/' . substr(hash('sha256', $nonce), 0, 32);

        // 'x' 模式：文件已存在时直接失败。这比「先 file_exists 再写」可靠 ——
        // 后者在两个请求同时到达时会双双通过检查，形成重放窗口。
        if (self::claimNonceFile($file)) {
            return true;
        }

        $mtime = @filemtime($file);
        // 过期文件视为可复用（超过时间窗口 * 2）：删除后重试一次
        if ($mtime !== false && (time() - $mtime) > self::$timeWindow * 2) {
            @unlink($file);
            return self::claimNonceFile($file);
        }
        return false;
    }

    /**
     * 回滚 nonce 占用（密钥轮换重试专用）。
     * 场景：主钥验签通过但解密失败（decrypt_fail）时，nonce 已被登记；
     * 若服务端存在轮换前的旧钥并需要用旧钥重试解析，必须先撤销本次
     * nonce 占用，否则重试会被误判为重放。仅在受控的重试路径中调用。
     */
    public static function forgetNonce(string $nonce): void
    {
        if ($nonce === '') {
            return;
        }
        @unlink(self::$nonceDir . '/' . substr(hash('sha256', $nonce), 0, 32));
    }

    /** 独占创建 nonce 文件，成功返回 true */
    private static function claimNonceFile(string $file): bool
    {
        $fh = @fopen($file, 'x');        if ($fh === false) {
            return false;
        }
        @fwrite($fh, (string) time());
        @fclose($fh);
        return true;
    }

    /** 清理过期 nonce 文件 */
    public static function gcNonce(): void
    {
        if (!is_dir(self::$nonceDir)) {
            return;
        }
        $deadline = time() - self::$timeWindow * 2;
        foreach ((array) glob(self::$nonceDir . '/*') as $f) {
            if (is_file($f) && @filemtime($f) < $deadline) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------
    // 请求解析
    // ------------------------------------------------------------------

    /**
     * 解析客户端请求，返回业务数据数组
     *
     * $allowPlain = true 时（白名单接口 / 关闭强制加密）：
     *   - 若未携带加密字段（无 data/sign/t/n），直接以明文参数放行，
     *     这样无参的查询接口（notice / version / init）无需构造加密报文即可调用；
     *   - 若携带了加密字段，仍走完整校验流程，两种模式可共存。
     *
     * @param bool $allowPlain 是否允许明文
     * @return array{data:array, raw:array, plain:bool}
     * @throws CryptoException
     */
    public static function parseRequest(array $input, bool $allowPlain = false): array
    {
        $hasEnvelope = isset($input['data'], $input['sign'], $input['t'], $input['n']);

        // 明文模式
        if ($allowPlain) {
            if (!$hasEnvelope) {
                // 无加密信封：整体作为明文参数
                return ['data' => $input, 'raw' => $input, 'plain' => true];
            }
            if (isset($input['data']) && is_array($input['data'])) {
                // 兼容 { data: { ... } } 形式
                return ['data' => $input['data'], 'raw' => $input, 'plain' => true];
            }
        }

        if (!$hasEnvelope) {
            throw new CryptoException('missing_field', '缺少必要字段 data/sign/t/n');
        }

        $dataB64 = (string) $input['data'];
        $sign    = (string) $input['sign'];
        $t       = (int) $input['t'];
        $nonce   = (string) $input['n'];

        // 0. 会话级签名密钥解析（信封可选字段 k，由 init 下发）
        //    命中则验签与响应签名都改用会话盐；未携带 k 则用主盐。
        $kid = isset($input['k'])
            ? preg_replace('/[^a-f0-9]/', '', (string) $input['k'])
            : '';
        $salt = self::$signSalt;
        self::$sessionMachineId = '';
        if ($kid !== '') {
            // 校验 software_id：会话密钥必须属于当前软件，
            // 防止 A 软件的会话密钥用于 B 软件的请求（跨软件密钥复用）
            $swId = Software::currentId();
            $row = Database::one(
                'SELECT skey, machine_id FROM ' . Database::t('sign_keys')
                . ' WHERE kid = ? AND expire_at > ? AND software_id = ?',
                [$kid, time(), $swId]
            );
            if (!$row) {
                throw new CryptoException('bad_sign', '会话密钥无效或已过期，请重新初始化');
            }
            $salt = (string) $row['skey'];
            // 暂存 machine_id，供解密后与请求体内的 machine_id 比对
            self::$sessionMachineId = (string) $row['machine_id'];
        }
        // 响应一律用「验请求所用的同一把盐」签名，客户端才有对称的验证能力
        self::$respSalt = $salt;

        // 1. 时间戳窗口
        if (!self::checkTimestamp($t)) {
            throw new CryptoException('time_expired', '请求时间戳超出允许范围');
        }

        // 2. 签名校验
        if (!self::verifySign($dataB64, $t, $nonce, $sign, $salt)) {
            throw new CryptoException('bad_sign', '签名校验失败');
        }

        // 3. 防重放
        if (!self::checkNonce($nonce)) {
            throw new CryptoException('replay', '请求重复提交');
        }

        // 4. 解密
        $plain = self::decrypt($dataB64);
        if ($plain === null) {
            throw new CryptoException('decrypt_fail', '数据解密失败');
        }

        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new CryptoException('bad_json', '数据格式错误');
        }

        return ['data' => $data, 'raw' => $input, 'plain' => false, 'kid' => $kid !== '' ? $kid : null, 'session_mid' => self::$sessionMachineId];
    }

    /**
     * 构造加密响应体（签名盐 = 请求验签所用盐；无会话时为主盐）
     *
     * 防伪造服务器：除 HMAC（对称，客户端内嵌盐可被提取）外，
     * 另用服务端私钥做非对称签名 sig（客户端内置公钥指纹校验）。
     * 私钥只在服务端，攻击者即使提取了客户端全部密钥也无法伪造 sig。
     */
    public static function buildResponse(array $payload): array
    {
        $json  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data  = self::encrypt($json);
        $t     = time();
        $nonce = bin2hex(random_bytes(8));

        $resp = [
            'data' => $data,
            'sign' => self::signWithSalt($data, $t, $nonce, self::$respSalt ?? self::$signSalt),
            't'    => $t,
            'n'    => $nonce,
        ];

        // 非对称签名（ES256/RS256，密钥与离线宽限共用；签名对象 = data|t|n）
        // 失败时静默省略 sig —— 客户端只在开启 pin 时才强制要求。
        if (class_exists('Grace') && method_exists('Grace', 'signMessage')) {
            $sig = Grace::signMessage($data . '|' . $t . '|' . $nonce);
            if ($sig !== null && $sig !== '') {
                $resp['sig']    = base64_encode($sig);
                $resp['sig_kid'] = Grace::keyId();
                $resp['sig_algo'] = Grace::algorithm();
            }
        }

        return $resp;
    }
}

class CryptoException extends Exception
{
    /** 错误标识（如 bad_sign / time_expired），注意不能覆盖 Exception::$code */
    public string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
