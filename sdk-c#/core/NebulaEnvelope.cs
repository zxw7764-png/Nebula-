// ============================================================================
// Nebula C# SDK · 通信信封（协议唯一实现点）
// 与 docs/API.md 1.1-1.3、服务端 lib/Crypto.php 严格对齐。
//
//   请求  { data, sign, t, n, [k], app_key }
//     data = base64( iv[16] + AES-256-CBC(业务JSON) )
//     sign = hex(HMAC-SHA256( data|t|n , salt ))
//   响应  { data, sign, t, n, code, [sig] } —— 先验 HMAC，再验 sig，最后解密
// ============================================================================
using System;
using System.Text;

namespace Nebula.Sdk
{
    public static class Envelope
    {
        /// <summary>白名单接口：允许明文调用、不要求会话密钥 k</summary>
        public static bool IsPublicAction(string action)
            => action == "init" || action == "notice" || action == "version" || action == "online";

        /// <summary>组装接口 URL（文件形式 / 目录形式都支持）</summary>
        public static string ActionUrl(string apiBase, string action)
        {
            var base_ = apiBase;
            if (base_.EndsWith("index.php", StringComparison.OrdinalIgnoreCase))
                return base_ + "?action=" + action;
            if (base_.Length == 0 || !base_.EndsWith('/')) base_ += '/';
            return base_ + "?action=" + action;
        }

        /// <summary>一次请求的签名材料</summary>
        public sealed class RequestKeys
        {
            public string AesKey = "";
            public string Salt = "";
            public string SessionKid = "";
        }

        /// <summary>构造请求信封 JSON；失败返回空串</summary>
        public static string BuildRequestEnvelope(string payloadJson, RequestKeys keys,
                                                  long timestamp, string nonce, string appKey)
        {
            byte[] key32 = Crypto.DeriveKey32(keys.AesKey);
            byte[] iv = Crypto.DeriveIv16(keys.AesKey);
            if (key32.Length != 32 || iv.Length != 16) return "";

            byte[] blob = Crypto.Aes256CbcEncrypt(key32, iv, Crypto.Utf8(payloadJson));
            if (blob.Length == 0) return "";
            string data = Crypto.B64Encode(blob);
            if (data.Length == 0) return "";

            string sign = Crypto.HmacSha256Hex(keys.Salt, data + "|" + timestamp + "|" + nonce);
            if (sign.Length == 0) return "";

            var sb = new StringBuilder("{");
            sb.Append(Json.Pair("data", Json.Quote(data)));
            sb.Append(",").Append(Json.Pair("sign", Json.Quote(sign)));
            sb.Append(",").Append(Json.Pair("t", Json.Number(timestamp)));
            sb.Append(",").Append(Json.Pair("n", Json.Quote(nonce)));
            if (keys.SessionKid.Length > 0)
                sb.Append(",").Append(Json.Pair("k", Json.Quote(keys.SessionKid)));
            sb.Append(",").Append(Json.Pair("app_key", Json.Quote(appKey)));
            sb.Append("}");
            return sb.ToString();
        }

        /// <summary>响应拆封结果</summary>
        public sealed class OpenedResponse
        {
            public Error Status = Error.Ok;
            public string Msg = "";
            public string Plain = "";
            public int BusinessCode;
        }

        /// <summary>恒定时间比较（防时序侧信道）</summary>
        public static bool ConstantTimeEquals(string a, string b)
        {
            if (a.Length != b.Length) return false;
            int diff = 0;
            for (int i = 0; i < a.Length; i++) diff |= a[i] ^ b[i];
            return diff == 0;
        }

        /// <summary>校验并解开响应信封（先 HMAC → 服务端签名 → 解密）</summary>
        public static OpenedResponse OpenResponse(string body, string aesKey, string salt,
                                                  string respSignPubKey, bool requireSignature = true)
        {
            var out_ = new OpenedResponse();

            string data = Json.FindString(body, "data");
            if (data.Length == 0)
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应缺少 data 字段";
                return out_;
            }
            string sign = Json.FindString(body, "sign");
            string t = Json.FindString(body, "t");
            string n = Json.FindString(body, "n");
            string signedBody = data + "|" + t + "|" + n;

            // ① HMAC
            string expected = Crypto.HmacSha256Hex(salt, signedBody);
            NebulaLog.Write("open", $"data.len={data.Length} t={t} n={n} sign={sign} " +
                                    $"expected={expected} saltHead={(salt.Length > 8 ? salt[..8] : salt)}…");
            if (sign.Length == 0 || expected.Length == 0 || !ConstantTimeEquals(expected, sign))
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应验签失败（报文可能被篡改，或会话密钥已失效）";
                return out_;
            }

            // ② 服务端非对称签名
            if (requireSignature)
            {
                if (respSignPubKey.Length == 0)
                {
                    out_.Status = Error.Config;
                    out_.Msg = "未配置响应签名公钥（SdkConfig.RespSignPubKey），拒绝连接";
                    return out_;
                }
                var sig = Crypto.B64Decode(Json.FindString(body, "sig"));
                NebulaLog.Write("open", $"sig.len={sig.Length} kid={Json.FindString(body, "sig_kid")} " +
                                        $"verify={sig.Length > 0 && Crypto.VerifySignature(respSignPubKey, signedBody, sig)}");
                if (sig.Length == 0 || !Crypto.VerifySignature(respSignPubKey, signedBody, sig))
                {
                    out_.Status = Error.Envelope;
                    out_.Msg = "响应签名校验失败（可能连到了伪造服务器）";
                    return out_;
                }
            }

            // ③ 解密
            string plain = Crypto.Str(Crypto.Aes256CbcDecrypt(Crypto.DeriveKey32(aesKey), Crypto.B64Decode(data))
                                      ?? Array.Empty<byte>());
            if (plain.Length == 0)
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应解密失败（密钥不匹配）";
                return out_;
            }

            out_.Status = Error.Ok;
            out_.Plain = plain;
            out_.BusinessCode = Json.FindInt(plain, "code", 0);
            out_.Msg = Json.FindString(plain, "msg");
            return out_;
        }
    }
}
