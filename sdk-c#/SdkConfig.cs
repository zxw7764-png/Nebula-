// ============================================================================
// Nebula C# SDK · 接入方配置区 ★★★ 唯一需要修改的文件 ★★★
//
// ── 填写清单 ───────────────────────────────────────────────────────────────
// 以下参数均来自后台「软件管理」页面，直接复制粘贴填入即可。
//
//   ① ApiUrl          API 入口地址（http:// 或 https://）
//                      例：https://yz.baige.fun/api/index.php
//                      注意：必须以 /api/index.php 结尾，不要带多余参数
//
//   ② AppKey          软件标识（由系统随机生成的字母数字组合）
//                      例：SWBFE6879E94DD
//                      每个软件拥有独立 AppKey，不可混用
//
//   ③ AesKey          软件通信密钥（32 位十六进制字符串）
//                      用于数据加密和解密
//                      示例：eb32f8087805a06cf8e45e306e7a8d5f
//                      ⚠ 此密钥等同于软件的"身份证"，务必妥善保管
//
//   ④ SignSalt        签名盐值（48 位十六进制字符串）
//                      用于生成请求和响应的 HMAC 签名
//                      示例：147ea3cc63530253a1617df4da45d7b0cc1a345f67fe2db3
//                      ⚠ 每次请求都依赖此盐值验证身份，不可泄露
//
//   ⑤ RespSignPubKey  响应签名公钥（PEM 格式，必填！）
//
//      格式说明：
//      ┌────────────────────────────────────────────────────────┐
//      │ -----BEGIN PUBLIC KEY-----                             │
//      │ MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE...           │  ← base64 编码的公钥数据
//      │ ...                                                      │
//      │ -----END PUBLIC KEY-----                               │
//      └────────────────────────────────────────────────────────┘
//
//      获取方式：
//      1. 后台「软件管理」→「重新生成密钥」→ 复制「响应签名公钥」字段
//      2. 或直接查看服务器 config/grace_keys.php 中的 'public' 字段
//
//      注意事项：
//      · 必须保留 "-----BEGIN/END PUBLIC KEY-----" 两行，不可省略
//      · 多行 base64 内容合并为单行，行首用 `\n` 换行
//      · 密钥更新后必须同步此字段，否则所有请求签名验证失败
//
//      代码格式示例：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEOcJAuC3Q19fcDAP1wkU+3Z9Uhrqa\n"+
//            "SAEgSwTwQhBYcMnrpl8NaLFKGRJwOQtbCLg3tkKuYxMuEd5sP1k5AOGcIQ==\n"+
//            "-----END PUBLIC KEY-----\n";
//
//      ES256（椭圆曲线）单行 base64 示例（无换行）：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE19wi9XDuk7JifokeoyOSkkNJWe723WQJV6rG5CpdKCA7Gu95j8T66q274K/15CqawUHukTRw1QYgaVhy5qYFcg==\n"+
//            "-----END PUBLIC KEY-----\n";
//
//      RS256（RSA 2048）多行 base64 示例（约 300+ 字符）：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWyF8PbnGy0AHB7Mqv\n"+
//            "...（完整 RSA 公钥）\n"+
//            "-----END PUBLIC KEY-----\n";
//
//   ⑥ TlsCertSha256   TLS 证书指纹（可选，仅 HTTPS 生效）
//                      锁定服务器证书，防止中间人攻击
//                      若服务器更换证书，需更新此指纹
//
// ── 安全建议 ───────────────────────────────────────────────────────────────
//   · 生产环境务必配置 RespSignPubKey，防止伪造响应劫持会话
//   · 定期更换 AesKey / SignSalt，避免密钥泄露后被批量破解
//   · 不要将包含真实密钥的代码提交到公开仓库
//   · 如发生密钥泄露，请立即在后台重新生成密钥并更新本文件
//
// ── 常见坑点 ───────────────────────────────────────────────────────────────
//   1. AesKey 必须是 32 位十六进制（16 字节），长度不对会导致加解密失败
//   2. RespSignPubKey 若为空，所有请求会返回"未配置响应签名公钥"错误
//   3. 服务器密钥更新后，RespSignPubKey 和 AesKey/SignSalt 可能同时变化，
//      需一并同步
//   4. TlsCertSha256 仅在 HTTPS 时生效；HTTP 请求完全不受约束
// ============================================================================
namespace Nebula.Sdk
{
    public static class SdkConfig
    {
        /// <summary>SDK 版本号，请勿手动修改</summary>
        public const string SdkVersion = "1.0.3";

        /// <summary>① API 入口地址（http:// 或 https://）</summary>
        public const string ApiUrl = "https://your-domain.com/api/index.php";

        /// <summary>② 软件标识（app_key）</summary>
        public const string AppKey = "YOUR_APP_KEY";

        /// <summary>③ 软件通信密钥（32 位 hex）</summary>
        public const string AesKey = "YOUR_AES_KEY_32HEX";

        /// <summary>④ 签名盐（48 位 hex）</summary>
        public const string SignSalt = "YOUR_SIGN_SALT_48HEX";

        /// <summary>⑤ 响应签名公钥（PEM，必填；服务端「重新生成密钥」后须同步）</summary>
        public const string RespSignPubKey =
    "-----BEGIN PUBLIC KEY-----\n"+
    "YOUR_RESP_SIGN_PUBLIC_KEY\n"+
    "-----END PUBLIC KEY-----\n";

        /// <summary>⑥ TLS 证书指纹锁定（可选，只对 https:// 生效）</summary>
        public const string TlsCertSha256 = "";

        /// <summary>调试日志开关（写到 exe 同目录 nebula_debug.log；发布置 false）</summary>
        public const bool DebugLog = false;
    }
}
