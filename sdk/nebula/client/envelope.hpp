#pragma once
// ============================================================================
// Nebula SDK · 通信信封（**协议的唯一实现点**）
// ----------------------------------------------------------------------------
// 与 docs/API.md 1.1 / 1.2 / 1.2.1 / 1.3 严格对齐，也与服务端 lib/Crypto.php
// 一一对应。任何协议改动都必须同时改这里与服务端，并同步 docs/API.md。
//
//   请求  { data, sign, t, n, [k], app_key }
//     data = base64( iv[16] + AES-256-CBC(业务JSON) )
//            key = SHA256(AES_KEY)[0..32)   iv = MD5(AES_KEY)[0..16)
//     sign = hex(HMAC-SHA256( data|t|n , salt ))
//     t    = 秒级时间戳（服务端容忍 300 秒偏差）
//     n    = 一次性随机串（>= 8 位，服务端做重放去重）
//     k    = 会话密钥 ID（init 下发；**仅业务接口携带**，白名单接口不带）
//     app_key = 软件标识（外层明文，服务端据此选定软件的密钥与数据隔离）
//
//   响应  { data, sign, t, n, code, [sig] }
//     先用"验请求所用的同一把盐"验 HMAC，再用服务端公钥验 sig（ES256/RS256），
//     最后解密 data 得到业务响应 { code, msg, time, data:{...} }。
//     —— 先验签后解密（encrypt-then-MAC），篡改密文必然在第一步被拒。
// ============================================================================

#include "../core/crypto.hpp"
#include "../core/json.hpp"

namespace nebula {

/** 白名单接口：允许明文调用、不要求会话密钥 k、不计配额（见 docs/API.md 1.3.1） */
NEBULA_MUST_CHECK inline bool isPublicAction(const std::string& action) {
    return action == "init" || action == "notice" || action == "version" || action == "online";
}

/**
 * 组装接口 URL。两种入口形式都支持：
 *   · base 以 index.php 结尾 → <base>?action=xx
 *   · 目录形式                → <base>/?action=xx
 */
NEBULA_MUST_CHECK inline std::string actionUrl(const std::string& apiBase, const std::string& action) {
    std::string base = apiBase;
    const char* kIndex = "index.php";
    const size_t kIndexLen = 9;
    if (base.size() >= kIndexLen && base.compare(base.size() - kIndexLen, kIndexLen, kIndex) == 0) {
        return base + "?action=" + action;
    }
    if (base.empty() || base.back() != '/') base += '/';
    return base + "?action=" + action;
}

/** 一次请求的签名材料 */
struct RequestKeys {
    std::string aes_key;       ///< 原始 AES_KEY（32 位 hex）
    std::string salt;          ///< 本次请求使用的盐：白名单=主盐，业务=会话盐
    std::string session_kid;   ///< 会话密钥 ID（init 下发）；白名单接口请留空
};

/**
 * 构造请求信封 JSON。
 * @param timestamp 秒级时间戳
 * @param nonce     一次性随机串（建议 16 位 hex）
 * @return 信封 JSON；本地加密失败（密钥非法 / 随机源不可用）时返回空串
 */
NEBULA_MUST_CHECK inline std::string buildRequestEnvelope(const std::string& payloadJson,
                                                          const RequestKeys& keys,
                                                          int64_t timestamp,
                                                          const std::string& nonce,
                                                          const std::string& appKey) {
    const std::string key32 = crypto::deriveKey32(keys.aes_key);
    const std::string iv    = crypto::deriveIv16(keys.aes_key);
    if (key32.size() != 32 || iv.size() != 16) return {};

    const std::string blob = crypto::aes256CbcEncrypt(key32, iv, payloadJson);
    if (blob.empty()) return {};

    const std::string data = b64Encode(blob);
    if (data.empty()) return {};
    const std::string sign = crypto::hmacSha256Hex(keys.salt, data + "|" + std::to_string(timestamp) + "|" + nonce);
    if (sign.empty()) return {};

    std::string envelope = "{";
    envelope += json::pair("data", json::quote(data));
    envelope += "," + json::pair("sign", json::quote(sign));
    envelope += "," + json::pair("t", json::number(timestamp));
    envelope += "," + json::pair("n", json::quote(nonce));
    if (!keys.session_kid.empty()) envelope += "," + json::pair("k", json::quote(keys.session_kid));
    envelope += "," + json::pair("app_key", json::quote(appKey));
    envelope += "}";
    return envelope;
}

/** 响应拆封结果 */
struct OpenedResponse {
    Error status = Error::Ok;      ///< Ok 表示已验签并解密成功
    std::string msg;               ///< 失败原因（中文，可直接展示）
    std::string plain;             ///< 解密后的业务响应 JSON
    int businessCode = 0;          ///< 业务响应里的 code（成功时为 0）
};

/** 恒定时间比较（防时序侧信道；长度不同直接返回 false） */
NEBULA_MUST_CHECK inline bool constantTimeEquals(const std::string& a, const std::string& b) {
    if (a.size() != b.size()) return false;
    unsigned char diff = 0;
    for (size_t i = 0; i < a.size(); ++i) {
        diff |= (unsigned char)(a[i] ^ b[i]);
    }
    return diff == 0;
}

/**
 * 校验并解开响应信封。
 *
 * @param body              HTTP 响应体原文
 * @param aesKey            原始 AES_KEY
 * @param salt              **验请求所用的同一把盐**（服务端用它签名响应）
 * @param respSignPubKey    服务端响应签名公钥（PEM）
 * @param requireSignature  true = 强制要求非对称签名（默认，安全）；
 *                          false = 仅校验 HMAC（服务端未配置签名密钥时才有必要）
 */
NEBULA_MUST_CHECK inline OpenedResponse openResponse(const std::string& body,
                                                     const std::string& aesKey,
                                                     const std::string& salt,
                                                     const std::string& respSignPubKey,
                                                     bool requireSignature = true) {
    OpenedResponse out;

    const std::string data = json::findString(body, "data");
    if (data.empty()) {
        out.status = Error::Envelope;
        out.msg    = "响应缺少 data 字段";
        return out;
    }
    const std::string sign = json::findString(body, "sign");
    const std::string t    = json::findString(body, "t");
    const std::string n    = json::findString(body, "n");
    const std::string signedBody = data + "|" + t + "|" + n;

    // ① HMAC：先验签，后解密
    const std::string expected = crypto::hmacSha256Hex(salt, signedBody);
    if (sign.empty() || expected.empty() || !constantTimeEquals(expected, sign)) {
        out.status = Error::Envelope;
        out.msg    = "响应验签失败（报文可能被篡改，或会话密钥已失效）";
        return out;
    }

    // ② 服务端非对称签名：私钥只在服务端，攻击者拿到客户端全部密钥也伪造不了
    if (requireSignature) {
        if (respSignPubKey.empty()) {
            out.status = Error::Config;
            out.msg    = "未配置响应签名公钥（cfg::kRespSignPubKey），拒绝连接";
            return out;
        }
        const std::string sig = b64Decode(json::findString(body, "sig"));
        if (sig.empty() || !crypto::verifySignature(respSignPubKey, signedBody, sig)) {
            out.status = Error::Envelope;
            out.msg    = "响应签名校验失败（可能连到了伪造服务器）";
            return out;
        }
    }

    // ③ 解密
    const std::string plain = crypto::aes256CbcDecrypt(crypto::deriveKey32(aesKey), b64Decode(data));
    if (plain.empty()) {
        out.status = Error::Envelope;
        out.msg    = "响应解密失败（密钥不匹配）";
        return out;
    }

    out.status       = Error::Ok;
    out.plain        = plain;
    out.businessCode = json::findInt(plain, "code", 0);
    out.msg          = json::findString(plain, "msg");
    return out;
}

/** 兼容旧名 */
NEBULA_MUST_CHECK inline bool isWhitelistAction(const std::string& action) { return isPublicAction(action); }

} // namespace nebula
