<?php
/**
 * 通信加密层（Nebula 3.1 —— 仅 ECDH 会话协议）
 * ----------------------------------------------------------------------------
 * 3.0 静态密钥信封（AES-256-CBC + HMAC + 会话盐 k）已于 3.1 起完全移除：
 *   · 请求只接受两种形式：
 *       ① 3.1 会话信封 { sid, seq, t, data, mac }（见 lib/Handshake.php）
 *       ② 明文参数（仅公开只读接口 notice / version / online / handshake）
 *   · 响应只接受 3.1 GCM 信封（会话活跃时）或明文（无会话时的错误输出）。
 *
 * 3.1 报文约定（客户端 -> 服务端）:
 *   {
 *     "proto": 31,
 *     "sid":   "<32位会话ID>",
 *     "seq":   1,                       // 严格单调递增，服务端原子校验防重放
 *     "t":     1726000000,              // 秒级时间戳（握手时按服务器时钟校准）
 *     "data":  "<base64(iv[12] + AES-256-GCM(业务JSON) + tag[16])>",
 *     "mac":   "<hex(HMAC-SHA256(sk_mac, sid|seq|t|sha256(data)))>"
 *   }
 *
 * 响应约定（服务端 -> 客户端）:
 *   { proto:31, sid, data(GCM), sig(ES256, 防伪造服务器), code }
 */

class Crypto
{
    /**
     * 兼容保留（bootstrap 启动时调用）。3.1 会话密钥在握手时由
     * Handshake 落库，这里不再持有任何静态对称密钥。
     */
    public static function init(array $cfg): void
    {
        unset($cfg);
    }

    // ------------------------------------------------------------------
    // 请求解析
    // ------------------------------------------------------------------

    /**
     * 解析客户端请求，返回业务数据数组
     *
     * $allowPlain = true 时（公开只读接口 / 关闭强制加密）：
     *   未携带 3.1 信封字段的请求直接以明文参数放行。
     *
     * @param bool $allowPlain 是否允许明文
     * @return array{data:array, raw:array, plain:bool}
     * @throws CryptoException
     */
    public static function parseRequest(array $input, bool $allowPlain = false): array
    {
        // 3.1 会话信封：带 sid 字段即走 ECDH 会话路径（GCM + seq 单调防重放）
        if (isset($input['sid']) && class_exists('Handshake')) {
            return Handshake::openRequest($input);
        }

        // 明文模式（公开只读接口）
        if ($allowPlain && !isset($input['data'], $input['mac'])) {
            return ['data' => $input, 'raw' => $input, 'plain' => true];
        }

        // 其余一律拒绝 —— 3.0 静态密钥信封已移除，必须先握手
        throw new CryptoException('missing_field', '不支持的信封格式：请先调用 handshake 建立 3.1 会话');
    }

    /**
     * 构造响应体。
     * 会话活跃 → 3.1 GCM 信封 + ES256 签名（Handshake::buildResponse）；
     * 无会话（握手前出错、明文接口报错）→ 明文 JSON 兜底，仅含 code/msg/time，
     * 客户端据此提示，不会泄露业务数据。
     */
    public static function buildResponse(array $payload): array
    {
        if (class_exists('Handshake') && Handshake::active()) {
            return Handshake::buildResponse($payload);
        }
        return $payload;
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
