#pragma once
// Nebula 客户端 C++ SDK  (header-only)
// 协议与 docs/API.md 严格对齐：
//   · 请求信封  { data, sign, t, n, k }
//     data  = base64( iv[16] + AES-256-CBC密文 )，key = SHA256(AES_KEY) 前 32 字节；
//             IV 随密文前 16 字节传输（服务端可能每次随机 IV，客户端解密一律取前缀，天然兼容）
//     sign  = HMAC-SHA256( data + "|" + t + "|" + n , 盐 ) 小写 hex；t 秒级时间戳；n ≥8 位一次性随机串
//     k     = 会话密钥 ID（init 下发 session.k），init/notice/version/online 四个白名单接口不带
//   · 盐：白名单接口用主盐 SIGN_SALT；其余接口用 init 下发的会话盐 session.s（服务端未开会话密钥时回落主盐）
//   · 响应信封 { data, sign, t, n, code }，用同一把盐先验签再解密，得业务响应 { code, msg, time, data:{...} }
//   · 离线宽限票据 G1.<payload-b64url>.<sig-b64url>，本地 ES256（ECDSA P-256）验签（bcrypt 实现）
//
// 依赖（均为 Windows 系统库，#pragma comment 自动链接）：bcrypt + WinHTTP + advapi32(wincrypt) + crypt32
// 仅 Windows；VS / MSVC 工具链。
//
// ── 客户端加固（可选组件 nebula_protect.hpp，**默认全部关闭**）───────────────
//   ① 壳标记（VMProtect / Themida·WinLicense / 自定义）  NEBULA_SHELL_ENABLE 1
//   ② 核心代码混淆（字符串加密 / 间接调用 / 不透明谓词）  NEBULA_OBF_STRINGS  1
//   ③ 运行时防护（反调试 / 反虚拟机沙箱 / 代码补丁自检）  NEBULA_PROTECT_LEVEL 1|2|3
//   一键全开：在工程预处理器里加一行  NEBULA_HARDEN=1
//   什么都不定义 → 与不加固版本完全一致（零开销）。详见 sdk/SDK_PROTECTION.md
// ───────────────────────────────────────────────────────────────────────────
//
// 用法:
//   nebula::Client c("http://api.example.com/api/index.php", "<aes_key>", "<sign_salt>", "<machine_id>");
//   auto init = c.init();                       // 1. 初始化：下发会话密钥/登录方式/版本/公告/离线公钥
//   auto lr = c.login("user", "pwd");           // 2. 登录（按服务器下发的登录方式自动组装）
//   if (lr.ok) { /* lr.token / lr.user ... */ }
//   c.startHeartbeat(lr.token, [](int code, const std::string& msg, const nebula::HeartbeatInfo& hb) {
//       if (hb.need_relogin || hb.kick) { /* 回登录界面 */ }
//   }, 60000);
//   c.stopHeartbeat();
//   c.logout(lr.token);
//
//   离线宽限：心跳网络失败时用缓存票据本地验签：
//   auto gr = c.checkOffline(c.graceTicket(), lr.token);

#define NOMINMAX
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <winhttp.h>
#include <bcrypt.h>
#include <wincrypt.h>
#pragma comment(lib, "winhttp.lib")
#pragma comment(lib, "bcrypt.lib")
#pragma comment(lib, "advapi32.lib")
#pragma comment(lib, "crypt32.lib")
#include <iphlpapi.h>   // GetAdaptersInfo（网卡 MAC）
#include <objbase.h>    // COM 初始化（WMI 硬件指纹）
#include <wbemidl.h>    // WMI
#pragma comment(lib, "iphlpapi.lib")
#pragma comment(lib, "wbemuuid.lib")

// bcrypt.h 把 BCRYPT_SUCCESS 定义成了宏 #define BCRYPT_SUCCESS(Status)(((NTSTATUS)(Status))>=0)，
// 会劫持下面 Bcrypt 类的同名成员函数声明（C2059/C2143/C2334），先取消宏
#ifdef BCRYPT_SUCCESS
#undef BCRYPT_SUCCESS
#endif
#include <string>
#include <vector>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <cctype>
#include <string_view>
#include <functional>
#include <chrono>
#include <atomic>
#include <thread>
#include <utility>
#include <memory>
#include <ctime>
#include <random>

// ---------------------------------------------------------------------------
// 运行时多样性开关（Runtime Diversity）：数据面 + 判断形态随每次启动而变。
//   NEBULA_RUNTIME_DIVERSE=1 → SecureString 每次随机密钥 + 不透明谓词随机形态。
//   NEBULA_RUNTIME_DIVERSE=0 → SecureString 固定密钥(仍防明文) + 固定谓词(零开销)。
//   默认 0；NEBULA_HARDEN=1 时自动打开；可单独强制开启/关闭。见 sdk/SDK_PROTECTION.md。
// ---------------------------------------------------------------------------
#ifndef NEBULA_RUNTIME_DIVERSE
#  define NEBULA_RUNTIME_DIVERSE 0
#endif

// ---------------------------------------------------------------------------
// 客户端加固模块（可选）：与本文件同目录的 nebula_protect.hpp
//   找不到该文件时自动跳过（NEBULA_HAS_PROTECT=0），不影响编译。
//   是否真正生效由 nebula_protect.hpp 顶部的宏决定，默认全部关闭。
// ---------------------------------------------------------------------------
#if defined(__has_include)
#  if __has_include("nebula_protect.hpp")
#    include "nebula_protect.hpp"
#    define NEBULA_HAS_PROTECT 1
#  endif
#endif
#ifndef NEBULA_HAS_PROTECT
#  define NEBULA_HAS_PROTECT 0
#endif

// 没找到 nebula_protect.hpp 时，混淆/壳标记宏退化为「原样字符串 / 直接调用 / 空宏」，
// 保证接入方配置区与本文件核心代码里写 NEBULA_STR(...) / NEBULA_MARK_*(...) /
// obf::vcall* / obf::opaque* 都能编译，且语义与不开加固完全一致（零开销）。
#ifndef NEBULA_STR
#  define NEBULA_STR(s)  (std::string(s))
#endif
#ifndef NEBULA_WSTR
#  define NEBULA_WSTR(s) (std::wstring(s))
#endif
#ifndef NEBULA_MARK_ULTRA_BEGIN
#  define NEBULA_MARK_ULTRA_BEGIN()
#  define NEBULA_MARK_ULTRA_END()
#  define NEBULA_MARK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()
#  define NEBULA_MARK_SCOPE_END()
#endif
#ifndef NEBULA_DEAD_BRANCH
#  define NEBULA_DEAD_BRANCH() do { } while (0)
#endif

// ============================================================
// ★ runtimeRandByte()：每次进程启动都不同的真随机字节源
//   用「高分辨率性能计数器 + 进程 PID + 时钟纳秒」做 mt19937 种子（magic static，
//   C++11 线程安全、只初始化一次），保证同一 exe 每次运行产生的随机序列都不同。
//   用途：SecureString 随机密钥、不透明谓词、随机填充 —— 运行时「数据面多样性」。
//   只依赖标准库 + windows，不写可执行内存，不与壳/杀软/DEP 冲突。
// ============================================================
inline unsigned char runtimeRandByte(unsigned char lo = 1, unsigned char hi = 255) {
    static std::mt19937 gen = [] {
        LARGE_INTEGER pc; QueryPerformanceCounter(&pc);
        auto ns = std::chrono::high_resolution_clock::now()
                      .time_since_epoch().count();
        std::seed_seq ss{
            (unsigned)GetCurrentProcessId(),
            (unsigned)pc.QuadPart,
            (unsigned)(pc.QuadPart >> 32),
            (unsigned)ns, (unsigned)(ns >> 32),
        };
        return std::mt19937(ss);
    }();
    if (hi <= lo) return lo;
    return (unsigned char)(lo + (unsigned)(gen() % (unsigned)(hi - lo + 1)));
}

// ============================================================
// ★ SecureString：防「常驻明文」的擦除型短字符串 + 运行时多样性
//   —— 专治 kAppKey 这类长度 ≤15 会被 std::string SSO 内联进 .data 静态区、
//      内存 dump 一眼可见的敏感值。
//   机制：
//     · 内部只保存混淆后的密文（字节与明文无直接关系）；
//     · 构造时用 runtimeRandByte() 生成【每次运行都不同的随机密钥】并存入成员，
//       str() 用同一密钥解码到栈上临时对象，用完即销毁，不常驻堆/静态区。
//     · 因此：同一 exe 每次启动，cfg 静态区的密文形态都不同，
//       破解者无法用固定的字符串特征做批量匹配。
//   用法：
//       inline const SecureString kAppKey = SecureString(NEBULA_STR("SW83CBD02D913F"));
//       std::string key = kAppKey.str();   // 只在需要时取明文，用完自动析构擦除
//   注意：SecureString 不可拷贝（保证明文只有一处源）；如需多次取用请各自 str()。
// ============================================================
class SecureString {
public:
    explicit SecureString(const std::string& raw)
        // NEBULA_RUNTIME_DIVERSE=1 → 每次运行随机密钥(形态每次启动不同)；
        // =0 → 固定编译期密钥 75(仍防 SSO 静态区明文，形态固定、可复现)。
        : key_(NEBULA_RUNTIME_DIVERSE ? runtimeRandByte() : 75) {
        buf_.reserve(raw.size());
        for (size_t i = 0; i < raw.size(); ++i)
            buf_ += (char)((unsigned char)raw[i] ^ (unsigned char)(key_ + i));
    }
    SecureString(const SecureString&) = delete;
    SecureString& operator=(const SecureString&) = delete;
    SecureString(SecureString&& o) noexcept : key_(o.key_), buf_(std::move(o.buf_)) {}
    // 解码到临时 std::string；调用方使用后由析构擦除（不常驻、不在静态区留明文）
    std::string str() const {
        std::string out;
        out.reserve(buf_.size());
        unsigned char k = key_;
        for (size_t i = 0; i < buf_.size(); ++i)
            out += (char)((unsigned char)buf_[i] ^ (unsigned char)(k + i));
        return out;
    }
    size_t size() const { return buf_.size(); }
    bool empty() const { return buf_.empty(); }
private:
    const unsigned char key_;
    std::string buf_;
};

#if !NEBULA_HAS_PROTECT
namespace nebula { namespace obf {
template <typename... Args, typename... CallArgs>
inline void vcall(void (*fn)(Args...), CallArgs&&... args) { fn(std::forward<CallArgs>(args)...); }
template <typename Ret, typename... Args, typename... CallArgs>
inline Ret vcallR(Ret (*fn)(Args...), CallArgs&&... args) { return fn(std::forward<CallArgs>(args)...); }
#if NEBULA_RUNTIME_DIVERSE
    inline bool opaqueTrue()  {
        // 运行时多样性恒真：每次进程启动取不同随机操作数，但表达式恒等于 true，
        // 保持死代码去折叠语义不变，同时反汇编形态每次不同、无法固定特征绕过。
        const unsigned a = runtimeRandByte();
        const unsigned b = runtimeRandByte();
        return (a + b) == (b + a) && (a * 1) == a && ((a & b) | a) == a;
    }
    inline bool opaqueFalse() {
        // 运行时多样性恒假：恒为 false 但随机形态，配合 opacity 分支做干扰。
        const unsigned a = runtimeRandByte();
        const unsigned b = runtimeRandByte();
        return (a + 1) == a;   // 恒 false（整数加 1 不可能等于原值）
    }
#else
    inline bool opaqueTrue()  { return true;  }   // 固定恒真（零开销）
    inline bool opaqueFalse() { return false; }   // 固定恒假
#endif
} }
#endif

namespace nebula {

// ============================================================
//  ★★★ 接入方配置区（唯一需要修改的地方）★★★
//  在后台「软件管理」目标软件行点「复制」取得 AES_KEY / SIGN_SALT，
//  连同 API 地址一起填到下面即可；入口处一行 nebula::createDefaultClient(...) 完成接入。
//
//  ── 填写步骤总览（共 4 项，来源都从后台拿）──────────────────
//   ① kApiUrl        你的 API 入口地址（http:// 或 https://，强烈建议 https）
//   ② kAppKey        后台「软件管理」列表里对应软件的 app_key
//   ③ kAesKey        后台「软件管理」列表里对应软件的 AES_KEY（32位hex）
//   ④ kSignSalt      后台「软件管理」列表里对应软件的 SIGN_SALT（48位hex）
//   ⑤ kRespSignPubKey  后台「系统设置 → 系统 → 响应签名公钥 → 复制 C++ 代码」
//                      （必填！不填所有请求直接失败，填法见下方详细说明）
//   ⑥ kTlsCertSha256 可选：服务器证书 SHA256「证书」指纹（填法见下方详细说明）
//
//  ── 关于壳 / 加固（本配置区不用改，只需在工程预处理器加宏）────────────
//   SDK 自带壳标记（VMProtect / Themida）、字符串混淆、反调试/反虚拟机，
//   默认全部关闭；发布版在项目属性 → C/C++ → 预处理器加一行：
//     NEBULA_HARDEN=1        （一键全开：壳标记 + 混淆 + 运行时防护）
//   什么都不加 = 与不加固版本完全一致（零开销）。完整手册见 sdk/SDK_PROTECTION.md。
//   注意：壳标记在 post() 加解密段与 checkOffline() 验签段已内置，无需自己插；
//   若想给业务代码也上保护，用同一套宏：NEBULA_MARK_VM_BEGIN/END（最强）、
//   NEBULA_MARK_MUTATE_BEGIN/END（高频函数）。
//
//  注意：API 入口支持两种形式，SDK 自动适配：
//    · 文件形式  http://host/api/index.php   → <base>?action=xx
//    · 目录形式  http://host/api/            → <base>/?action=xx
//
//  字符串一律写在 NEBULA_STR("...") 里面（不要写裸字面量）：
//  开启混淆（预处理器 NEBULA_OBF_STRINGS=1）后编译期加解密，strings/IDA 搜不到
//  AES_KEY / SIGN_SALT / API 地址；未开启时等价于普通 std::string，零开销。
// ============================================================
namespace cfg {
inline const std::string kApiUrl   = NEBULA_STR("https://yz.baige.fun/api/index.php");  // ← 改成你的 API 入口
// kAppKey 用 SecureString 存储：长度 ≤15 会被 std::string SSO 内联进 .data 静态区，
// 直接放 std::string 会让明文在内存 dump 时一眼可见；SecureString 只在 str() 时临时解码。
inline const SecureString kAppKey = SecureString(NEBULA_STR("SWBFE6879E94DD"));                 // ← 改成你的软件 app_key
inline const std::string kAesKey   = NEBULA_STR("eb32f8087805a06cf8e45e306e7a8d5f");      // ← 32位hex，后台软件管理复制
inline const std::string kSignSalt = NEBULA_STR("147ea3cc63530253a1617df4da45d7b0cc1a345f67fe2db3"); // ← 48位hex，后台软件管理复制

// ★ 响应防伪造签名（必填 ）：
//   把服务端 config/grace_keys.php 里的 public 字段（PEM）原样填到这里，
//   SDK 强制校验每条响应的服务端私钥签名 sig —— 私钥只在服务端，
//   攻击者提取客户端全部密钥也无法伪造响应（假服务器/hosts 劫持直接失效）。
//   不填 = 所有请求直接失败（故意设计：不给「不校验」留口子）。
//   注意：服务端换过密钥（后台「重新生成密钥」）后这里必须同步更新。
//
//   【填写方法】C++ 字符串字面量不能直接换行 —— 不能把 PEM 原样贴进来，
//   否则报 E0274「宏调用错误地终止」。两种正确写法：
//
//   写法 A（推荐）：每行一段引号 + \n 结尾，相邻字面量自动拼接：
//     inline const std::string kRespSignPubKey = NEBULA_STR(
//         "-----BEGIN PUBLIC KEY-----\n"
//         "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE....==\n"
//         "-----END PUBLIC KEY-----\n");
//   （后台「系统设置 → 系统 → 响应签名公钥 → 复制 C++ 代码」按钮
//     复制的就是这种格式，直接粘贴到 = 右侧即可编译。）
//
//   写法 B：压成一行，换行全部写成 \n：
//     inline const std::string kRespSignPubKey = NEBULA_STR(
//         "-----BEGIN PUBLIC KEY-----\nMFkw...==\n-----END PUBLIC KEY-----\n");
//
//   ✅ 必须保留 -----BEGIN/END PUBLIC KEY----- 头尾标记（SDK 靠它们定位密钥体，
//      去掉后验签必败）；❌ 引号内不能有真实回车；空格/加号/等号原样保留。
inline const std::string kRespSignPubKey = NEBULA_STR(
    "-----BEGIN PUBLIC KEY-----\n"
    "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE5eoEEPofNhvvXs39pnNgP6C48ypS\n"
    "RtTZoHHTfsb1kqJK6EGc2sST5tuVGSxn628z2N7f+QNnAla3SCyA6+qzAg==\n"
    "-----END PUBLIC KEY-----\n");

// ★ TLS 证书指纹锁定（可选但强烈建议，防透明代理 / 中间人抓包）：
//   填服务端 HTTPS 证书的 SHA256 指纹（64 位 hex，大小写均可、可带冒号）。
//
//   【填「证书」还是「公钥」？】浏览器/工具常同时给两个指纹：
//     · 证书指纹（certificate fingerprint）：对整张证书 DER 编码做的 SHA256
//       —— 本 SDK 校验的就是这个！SDK 握手后取的是完整证书再算哈希。
//     · 公钥指纹（public key fingerprint / SPKI pin）：只对证书内公钥部分做的哈希
//       —— 填这个会校验失败，所有请求直接断开！
//   即：把「证书」那一栏的指纹填到这里，【不要】填「公钥」栏的。
//
//   获取方法：浏览器打开你的 API 地址 → 点地址栏锁图标 → 证书详情 → SHA-256 指纹
//   （注意选「证书」指纹，不是「公钥」指纹），
//   或在服务器上执行：
//     openssl s_client -connect your-domain.com:443 </dev/null 2>/dev/null \
//       | openssl x509 -fingerprint -sha256 -noout
//   （openssl x509 -fingerprint 输出的就是证书指纹，可直接用；冒号分隔、大小写均可，
//     SDK 内部统一归一化为小写无冒号再比较。）
//   校验规则：SDK 与服务端握手后比对证书指纹，不匹配立即断开 ——
//   抓包工具（Fiddler/Charles/mitmproxy 及各类透明代理）安装的假证书会直接握手失败。
//   ⚠️ 注意：
//   · 只对 https:// 生效（http:// 没有证书，无法校验）；
//   · 服务器换证书（续期/更换 CA）后这里必须同步更新，否则所有客户端连不上；
//   · 留空 = 不校验证书指纹（仍走系统标准 TLS 校验）。
//   · 填写后 SDK 会同时拒绝 http:// 的 API 地址（明文传输 + 无法锁证书）。
inline const std::string kTlsCertSha256 = NEBULA_STR("f31dc7cd4dbed7b9b6034bae7577452a6e50102ff3121775e64698b76f08b80d");  // ← 服务端证书 SHA256 指纹，留空不锁定

// ============================================================
// ★ 疑似环境处置策略（false=宽松[默认]，true=严格）
//   影响 setProtectAction() 对「疑似环境」类命中（hook / 虚拟机 / 沙箱 / BIOS VMW）
//   的处置：宽松时只记录不拦截（避免误伤挂加速器/跑 VM 的正常用户）；
//   严格时这些命中也会按 action 弹窗退出（能拦下"隐身 VM"等伪装环境，但会误伤 VM 用户）。
//   注意：真实调试铁证（debugged）无论开关都按 action 处置，不受此开关影响。
// ============================================================
inline const bool kProtectStrictPolicy = true;   // 严格：VM/沙箱/hook 一律拦截（误伤 VM 用户）
} // namespace cfg

constexpr int MAX_TOKEN_LEN  = 256;
constexpr int MAX_MSG_LEN    = 1024;
constexpr int CLOCK_SKEW     = 120;   // 与服务端 grace.clock_skew 默认一致

// ============================================================
//  基础工具
// ============================================================

inline std::string toUtf8(const wchar_t* w, int len = -1) {
    if (!w) return {};
    if (len < 0) len = (int)wcslen(w);
    int n = WideCharToMultiByte(CP_UTF8, 0, w, len, nullptr, 0, nullptr, nullptr);
    std::string s(n, 0);
    WideCharToMultiByte(CP_UTF8, 0, w, len, &s[0], n, nullptr, nullptr);
    return s;
}
inline std::wstring toWide(const std::string& s) {
    int n = MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), nullptr, 0);
    std::wstring w(n, 0);
    MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), &w[0], n);
    return w;
}

inline void randBytes(unsigned char* buf, ULONG len) {
    // 检查返回值（C6031）：系统 PRNG 失败时用时间源兑底（仅用于机器码，不用于密钥）
    // 注：BCryptGenRandom 返回 NTSTATUS，< 0 即失败（此处尚在 Bcrypt 类定义之前，直接比较）
    if (BCryptGenRandom(nullptr, buf, len, BCRYPT_USE_SYSTEM_PREFERRED_RNG) < 0) {
        unsigned int seed = (unsigned int)std::time(nullptr);
        for (ULONG i = 0; i < len; ++i)
            buf[i] = (unsigned char)((seed >> ((i & 3) * 8)) ^ (i * 31));
    }
}

inline std::string bytesToHex(const unsigned char* b, size_t n) {
    static const char* x = "0123456789abcdef";
    std::string r; r.reserve(n * 2);
    for (size_t i = 0; i < n; ++i) { r += x[b[i] >> 4]; r += x[b[i] & 0x0f]; }
    return r;
}

// ---------------- 极简 JSON 工具（仅本 SDK 内部使用，扁平搜索） ----------------

inline std::string jsonString(std::string_view v) {
    std::string r = "\"";
    for (char c : v) {
        if      (c == '\\') r += "\\\\";
        else if (c == '"')  r += "\\\"";
        else if (c == '\n') r += "\\n";
        else if (c == '\r') r += "\\r";
        else if (c == '\t') r += "\\t";
        else                r += c;
    }
    r += '"';
    return r;
}
inline std::string jsonBool(bool b) { return b ? "true" : "false"; }

// 字符串字面量安全跳过（i 停在结束引号上）
inline void skipJsonString(const std::string& s, size_t& i) {
    i++; // 起始引号
    while (i < s.size()) {
        if (s[i] == '\\') { i += 2; continue; }
        if (s[i] == '"') break;
        i++;
    }
}

// 在 JSON 文本中查找指定键的标量值（true/false/数字/字符串），扁平全文搜索
// 字符串值解码：从起始引号（含）开始，还原 \" \\ \/ \n \r \t \b \f \uXXXX（含代理对）。
// 服务端 PHP json_encode 会把 base64 中的 / 转义成 \/，直接取原文会导致响应验签失败。
inline std::string decodeJsonString(const std::string& s, size_t q) {
    ++q;   // 跳过起始引号
    std::string out;
    while (q < s.size()) {
        char c = s[q];
        if (c == '"') return out;
        if (c == '\\') {
            ++q;
            if (q >= s.size()) return {};
            char e = s[q];
            switch (e) {
            case '"':  out += '"';  break;
            case '\\': out += '\\'; break;
            case '/':  out += '/';  break;
            case 'n':  out += '\n'; break;
            case 'r':  out += '\r'; break;
            case 't':  out += '\t'; break;
            case 'b':  out += '\b'; break;
            case 'f':  out += '\f'; break;
            case 'u': {
                if (q + 4 >= s.size()) return {};
                unsigned cp = 0;
                for (int k = 1; k <= 4; ++k) {
                    char h = s[q + k];
                    cp <<= 4;
                    if      (h >= '0' && h <= '9') cp += unsigned(h - '0');
                    else if (h >= 'a' && h <= 'f') cp += unsigned(h - 'a' + 10);
                    else if (h >= 'A' && h <= 'F') cp += unsigned(h - 'A' + 10);
                    else return {};
                }
                q += 4;
                // 高代理：尝试拼接低代理 \uDC00-\uDFFF
                if (cp >= 0xD800 && cp <= 0xDBFF && q + 6 < s.size()
                    && s[q + 1] == '\\' && s[q + 2] == 'u') {
                    unsigned lo = 0; bool ok = true;
                    for (int k = 3; k <= 6; ++k) {
                        char h = s[q + k];
                        lo <<= 4;
                        if      (h >= '0' && h <= '9') lo += unsigned(h - '0');
                        else if (h >= 'a' && h <= 'f') lo += unsigned(h - 'a' + 10);
                        else if (h >= 'A' && h <= 'F') lo += unsigned(h - 'A' + 10);
                        else { ok = false; break; }
                    }
                    if (ok && lo >= 0xDC00 && lo <= 0xDFFF) {
                        cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                        q += 6;
                    }
                }
                if (cp < 0x80) {
                    out += char(cp);
                } else if (cp < 0x800) {
                    out += char(0xC0 | (cp >> 6));
                    out += char(0x80 | (cp & 0x3F));
                } else if (cp < 0x10000) {
                    out += char(0xE0 | (cp >> 12));
                    out += char(0x80 | ((cp >> 6) & 0x3F));
                    out += char(0x80 | (cp & 0x3F));
                } else {
                    out += char(0xF0 | (cp >> 18));
                    out += char(0x80 | ((cp >> 12) & 0x3F));
                    out += char(0x80 | ((cp >> 6) & 0x3F));
                    out += char(0x80 | (cp & 0x3F));
                }
                break;
            }
            default: return {};
            }
            ++q;
            continue;
        }
        out += c;
        ++q;
    }
    return {};   // 未找到结束引号
}

inline std::string findJsonValue(const std::string& s, const char* key) {
    std::string pat = "\"" + std::string(key) + "\"";
    size_t q = s.find(pat);
    while (q != std::string::npos) {
        q = s.find(':', q + pat.size());
        if (q == std::string::npos) return {};
        q++;
        while (q < s.size() && (s[q] == ' ' || s[q] == '\t' || s[q] == '\r' || s[q] == '\n')) q++;
        if (q >= s.size()) return {};
        if (s.compare(q, 4, "true") == 0)  return "1";
        if (s.compare(q, 5, "false") == 0) return "0";
        if (s[q] == '-' || (s[q] >= '0' && s[q] <= '9')) {
            auto e = s.find_first_of(",}]", q);
            if (e == std::string::npos) e = s.size();
            return s.substr(q, e - q);
        }
        if (s[q] == '"') {
            return decodeJsonString(s, q);
        }
        // 值是对象/数组等非标量 → 继续找下一个同名键
        q = s.find(pat, q);
    }
    return {};
}

// 提取指定键对应的「对象」原始子串（{...} 大括号平衡，字符串内不误判）
inline std::string extractObject(const std::string& s, const char* key) {
    std::string pat = "\"" + std::string(key) + "\"";
    size_t q = s.find(pat);
    while (q != std::string::npos) {
        q = s.find(':', q + pat.size());
        if (q == std::string::npos) return {};
        q++;
        while (q < s.size() && (s[q] == ' ' || s[q] == '\t' || s[q] == '\r' || s[q] == '\n')) q++;
        if (q < s.size() && s[q] == '{') {
            size_t start = q, depth = 0;
            while (q < s.size()) {
                char c = s[q];
                if (c == '"') { skipJsonString(s, q); }
                else if (c == '{') depth++;
                else if (c == '}') { depth--; if (depth == 0) { q++; return s.substr(start, q - start); } }
                q++;
            }
            return {};
        }
        q = s.find(pat, q);
    }
    return {};
}

// 提取指定键对应的「数组」中每个对象的原始子串
inline std::vector<std::string> findJsonObjects(const std::string& s, const char* key) {
    std::vector<std::string> out;
    std::string pat = "\"" + std::string(key) + "\"";
    size_t q = s.find(pat);
    while (q != std::string::npos) {
        q = s.find('[', q + pat.size());
        if (q == std::string::npos) return out;
        size_t i = q + 1;
        bool closed = false;
        while (i < s.size()) {
            while (i < s.size() && s[i] != '{' && s[i] != ']') {
                if (s[i] == '"') skipJsonString(s, i);
                i++;
            }
            if (i >= s.size()) break;
            if (s[i] == ']') { closed = true; break; }
            size_t start = i, depth = 0;
            while (i < s.size()) {
                char c = s[i];
                if (c == '"') { skipJsonString(s, i); }
                else if (c == '{') depth++;
                else if (c == '}') { depth--; if (depth == 0) { i++; break; } }
                i++;
            }
            if (i > start) out.push_back(s.substr(start, i - start));
        }
        (void)closed;
        break;
    }
    return out;
}

// ============================================================
//  密码学桥接（bcrypt + wincrypt）
// ============================================================
class Bcrypt {
public:
    static constexpr size_t AES_KEY_LEN = 32;   // AES-256
    static constexpr size_t AES_IV_LEN  = 16;
    static constexpr size_t HMAC_LEN    = 32;   // SHA-256

    static std::string sha256(const std::string& data) {
        return hmac("", data);
    }
    // 文档式 MD5（用于固定 IV：MD5(AES_KEY) 前 16 字节）
    static std::string md5(const std::string& data) {
        HCRYPTPROV hProv = 0;
        if (!CryptAcquireContextW(&hProv, nullptr, nullptr, PROV_RSA_AES, CRYPT_VERIFYCONTEXT)) return {};
        HCRYPTHASH h = 0;
        std::string out;
        if (CryptCreateHash(hProv, CALG_MD5, 0, 0, &h)) {
            if (CryptHashData(h, (const BYTE*)data.data(), (DWORD)data.size(), 0)) {
                DWORD len = 16; out.resize(16);
                // 检查返回值：取哈希失败则清空输出，避免返回未初始化数据
                if (CryptGetHashParam(h, HP_HASHVAL, (BYTE*)out.data(), &len, 0)) {
                    out.resize(len);
                } else {
                    out.clear();
                }
            }
            CryptDestroyHash(h);
        }
        CryptReleaseContext(hProv, 0);
        return out;
    }
    static std::string hmac(const std::string& key, const std::string& data) {
        bool hmacMode = !key.empty();
        BCRYPT_ALG_HANDLE alg = nullptr;
        if (!BCRYPT_SUCCESS(BCryptOpenAlgorithmProvider(&alg, BCRYPT_SHA256_ALGORITHM, nullptr,
                hmacMode ? BCRYPT_ALG_HANDLE_HMAC_FLAG : 0))) return {};
        BCRYPT_HASH_HANDLE h = nullptr;
        std::string out(HMAC_LEN, 0);
        NTSTATUS st = BCryptCreateHash(alg, &h, nullptr, 0,
                                       hmacMode ? (PUCHAR)key.data() : nullptr,
                                       hmacMode ? (ULONG)key.size() : 0, 0);
        if (BCRYPT_SUCCESS(st)) {
            // 检查返回值（C6031）：任一步失败则清空输出，避免返回中间态数据
            if (BCRYPT_SUCCESS(BCryptHashData(h, (PUCHAR)data.data(), (ULONG)data.size(), 0)) &&
                BCRYPT_SUCCESS(BCryptFinishHash(h, (PUCHAR)out.data(), (ULONG)out.size(), 0))) {
                // hash 已写入 out
            } else {
                out.clear();
            }
            BCryptDestroyHash(h);
        }
        BCryptCloseAlgorithmProvider(alg, 0);
        return out;
    }
    static std::string hmacHex(const std::string& key, const std::string& data) {
        std::string mac = hmac(key, data);
        return bytesToHex((const unsigned char*)mac.data(), mac.size());
    }

    // ---------------- 文件完整性（更新包哈希 / 大小比对） ----------------
    // 分块读取文件计算哈希：sha256=true → 64 位小写 hex（后台「文件哈希」SHA256）；
    // sha256=false → 32 位小写 hex（MD5）。文件不存在或读取失败返回空串。
    // 用法：下载更新包后与服务端 init/version 下发的 file_hash 比对，不一致则拒绝安装。
    static std::string fileHashHex(const std::string& pathUtf8, bool sha256) {
        HANDLE hFile = CreateFileW(toWide(pathUtf8).c_str(), GENERIC_READ, FILE_SHARE_READ,
                                   nullptr, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
        if (hFile == INVALID_HANDLE_VALUE) return "";
        BCRYPT_ALG_HANDLE alg = nullptr;
        if (!BCRYPT_SUCCESS(BCryptOpenAlgorithmProvider(&alg,
                sha256 ? BCRYPT_SHA256_ALGORITHM : BCRYPT_MD5_ALGORITHM, nullptr, 0))) {
            CloseHandle(hFile);
            return "";
        }
        BCRYPT_HASH_HANDLE hHash = nullptr;
        std::string digest(sha256 ? 32 : 16, '\0');
        bool ok = BCRYPT_SUCCESS(BCryptCreateHash(alg, &hHash, nullptr, 0, nullptr, 0, 0));
        if (ok) {
            // 64KB 缓冲改堆分配（C6262：栈帧过大）
            std::vector<char> buf(65536);
            DWORD n = 0;
            while (ok && ReadFile(hFile, buf.data(), (DWORD)buf.size(), &n, nullptr) && n > 0)
                ok = BCRYPT_SUCCESS(BCryptHashData(hHash, (PUCHAR)buf.data(), n, 0));
            if (ok) ok = BCRYPT_SUCCESS(BCryptFinishHash(hHash, (PUCHAR)digest.data(),
                                                         (ULONG)digest.size(), 0));
            BCryptDestroyHash(hHash);
        }
        BCryptCloseAlgorithmProvider(alg, 0);
        CloseHandle(hFile);
        return ok ? bytesToHex((const unsigned char*)digest.data(), digest.size()) : std::string();
    }

    // 文件字节数（与 init/version 下发的 file_size 比对）；不存在返回 -1
    static long long fileSizeBytes(const std::string& pathUtf8) {
        WIN32_FILE_ATTRIBUTE_DATA fad {};
        if (!GetFileAttributesExW(toWide(pathUtf8).c_str(), GetFileExInfoStandard, &fad)) return -1;
        return (long long)(((unsigned long long)fad.nFileSizeHigh << 32) | fad.nFileSizeLow);
    }

    // AES-256-CBC + PKCS7 解密 iv[16] + 密文；失败返回 ""
    static std::string aesDecrypt(const std::string& key32, const std::string& blob) {
        if (blob.size() < AES_IV_LEN || (blob.size() - AES_IV_LEN) % 16 != 0) return "";
        std::string iv = blob.substr(0, AES_IV_LEN);
        std::string ct = blob.substr(AES_IV_LEN);
        BCRYPT_ALG_HANDLE alg = nullptr;
        if (!BCRYPT_SUCCESS(BCryptOpenAlgorithmProvider(&alg, BCRYPT_AES_ALGORITHM, nullptr, 0))) return "";
        if (!setcbc(alg)) { BCryptCloseAlgorithmProvider(alg, 0); return ""; }
        BCRYPT_KEY_HANDLE k = nullptr;
        std::string out;
        DWORD objLen = 0, cb = 0;
        // 检查返回值（C6031）：取不到对象长度则置 0，下方用 1 字节兑底缓冲兜住
        if (!BCRYPT_SUCCESS(BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH, (PUCHAR)&objLen, sizeof(objLen), &cb, 0)))
            objLen = 0;
        std::vector<BYTE> obj(objLen ? objLen : 1);
        if (BCRYPT_SUCCESS(BCryptGenerateSymmetricKey(alg, &k, obj.data(), objLen,
                (PUCHAR)key32.data(), AES_KEY_LEN, 0))) {
            std::string data = ct;
            out.resize(data.size());
            ULONG acted = 0;
            if (BCRYPT_SUCCESS(BCryptDecrypt(k, (PUCHAR)data.data(), (ULONG)data.size(), nullptr,
                    (PUCHAR)iv.data(), (ULONG)iv.size(),
                    (PUCHAR)out.data(), (ULONG)out.size(), &acted, 0))) {
                out.resize(acted);
            } else out.clear();
            BCryptDestroyKey(k);
        }
        BCryptCloseAlgorithmProvider(alg, 0);
        if (out.empty() || !unpadPkcs7(out)) return "";
        return out;
    }

        // ECDSA P-256（ES256）验签：sig 为 DER 编码（服务端票据签名格式）
    static bool es256Verify(const std::string& pem, const std::string& msg, const std::string& sigDer) {
        std::string point = pemToUncompressedPoint(pem);
        if (point.size() != 65 || point[0] != 0x04) return false;
        std::string raw = derSigToRaw(sigDer);
        if (raw.size() != 64) return false;
        BCRYPT_ALG_HANDLE alg = nullptr;
        if (!BCRYPT_SUCCESS(BCryptOpenAlgorithmProvider(&alg, BCRYPT_ECDSA_P256_ALGORITHM, nullptr, 0))) return false;
        bool ok = false;
        std::vector<BYTE> blob(sizeof(BCRYPT_ECCKEY_BLOB) + 64);
        BCRYPT_ECCKEY_BLOB* hdr = (BCRYPT_ECCKEY_BLOB*)blob.data();
        hdr->dwMagic = BCRYPT_ECDSA_PUBLIC_P256_MAGIC;
        hdr->cbKey = 32;
        memcpy(blob.data() + sizeof(BCRYPT_ECCKEY_BLOB), point.data() + 1, 64);
        BCRYPT_KEY_HANDLE k = nullptr;
        if (BCRYPT_SUCCESS(BCryptImportKeyPair(alg, nullptr, BCRYPT_ECCPUBLIC_BLOB, &k,
                blob.data(), (ULONG)blob.size(), 0))) {
            std::string digest = sha256(msg);
            ok = BCRYPT_SUCCESS(BCryptVerifySignature(k, nullptr, (PUCHAR)digest.data(), (ULONG)digest.size(),
                                                      (PUCHAR)raw.data(), (ULONG)raw.size(), 0));
            BCryptDestroyKey(k);
        }
        BCryptCloseAlgorithmProvider(alg, 0);
        return ok;
    }

    // Client::post 需要调用 aesRaw，故为 public；以下辅助函数保持 private
    static bool BCRYPT_SUCCESS(NTSTATUS s) { return s >= 0; }
    // DER ECDSA 签名 SEQUENCE{INTEGER r, INTEGER s} → bcrypt 原生 r[32]||s[32]
    static std::string derSigToRaw(const std::string& der) {
        std::string raw(64, '\0');
        size_t i = 0;
        if (der.size() < 8 || der[i++] != 0x30) return {};
        if (der[i] & 0x80) {
            size_t lenBytes = der[i] & 0x7f;
            if (lenBytes == 0 || i + lenBytes >= der.size()) return {};
            i += 1 + lenBytes;   // 跳过长形式长度标记与长度字节
        } else {
            i++;
        }
        for (int part = 0; part < 2; ++part) {
            if (i + 2 > der.size() || der[i] != 0x02) return {};
            int len = (unsigned char)der[i + 1]; i += 2;
            if (len > 33 || i + (size_t)len > der.size()) return {};
            // 跳过正数标记的前导 0
            while (len > 0 && (unsigned char)der[i] == 0x00) { i++; len--; }
            if (len > 32) return {};
            // 右侧对齐拷入 32 字节槽位（高位补 0）
            size_t slot = (size_t)part * 32;
            memcpy(raw.data() + slot + (32 - len), der.data() + i, (size_t)len);
            i += len;
        }
        return raw;
    }
    static bool setcbc(BCRYPT_ALG_HANDLE alg) {
        // 返回 bool 并由调用方检查（消除 C6031「返回值被忽略」）
        return BCRYPT_SUCCESS(BCryptSetProperty(alg, BCRYPT_CHAINING_MODE,
                          (PUCHAR)L"ChainingModeCBC", sizeof(L"ChainingModeCBC"), 0));
    }
    static bool unpadPkcs7(std::string& out) {
        if (out.empty()) return false;
        unsigned char pad = (unsigned char)out.back();
        if (pad < 1 || pad > 16 || (size_t)pad > out.size()) return false;
        for (unsigned char i = 0; i < pad; ++i)
            if ((unsigned char)out[out.size() - 1 - i] != pad) return false;
        out.resize(out.size() - pad);
        return true;
    }
    static std::string aesRaw(const std::string& key32, const std::string& iv,
                              const std::string& plain, bool encrypt) {
        (void)encrypt;
        BCRYPT_ALG_HANDLE alg = nullptr;
        if (!BCRYPT_SUCCESS(BCryptOpenAlgorithmProvider(&alg, BCRYPT_AES_ALGORITHM, nullptr, 0))) return {};
        if (!setcbc(alg)) { BCryptCloseAlgorithmProvider(alg, 0); return {}; }
        BCRYPT_KEY_HANDLE k = nullptr;
        std::string out;
        DWORD objLen = 0, cb = 0;
        // 检查返回值（C6031）：取不到对象长度则置 0，下方用 1 字节兑底缓冲兜住
        if (!BCRYPT_SUCCESS(BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH, (PUCHAR)&objLen, sizeof(objLen), &cb, 0)))
            objLen = 0;
        std::vector<BYTE> obj(objLen ? objLen : 1);
        if (BCRYPT_SUCCESS(BCryptGenerateSymmetricKey(alg, &k, obj.data(), objLen,
                (PUCHAR)key32.data(), AES_KEY_LEN, 0))) {
            std::string iv2 = iv;   // BCrypt 会破坏性更新 IV，传副本
            size_t pad = 16 - (plain.size() % 16);
            std::string data = plain;
            data.resize(data.size() + pad, (char)pad);
            out.resize(data.size());
            ULONG acted = 0;
            if (BCRYPT_SUCCESS(BCryptEncrypt(k, (PUCHAR)data.data(), (ULONG)data.size(), nullptr,
                    (PUCHAR)iv2.data(), (ULONG)iv2.size(),
                    (PUCHAR)out.data(), (ULONG)out.size(), &acted, 0))) {
                out.resize(acted);
                out.insert(out.begin(), iv.begin(), iv.end());   // iv + 密文
            } else out.clear();
            BCryptDestroyKey(k);
        }
        BCryptCloseAlgorithmProvider(alg, 0);
        return out;
    }
private:
    // PEM（SPKI）→ 65 字节非压缩点（0x04||X||Y）
    static std::string pemToUncompressedPoint(const std::string& pem) {
        // 去头尾与空白 → DER
        std::string b64;
        size_t p = pem.find("-----BEGIN");
        size_t e = pem.find("-----END");
        if (p == std::string::npos || e == std::string::npos) return {};
        // 从 BEGIN 行之后的换行处开始收集，避免把表头/表尾行里的
        // P/U/B/L/I/C/K/E/Y 等字母误当作 base64 混入（否则解码出错误 DER）
        size_t s = pem.find('\n', p);
        if (s == std::string::npos) s = p;
        for (size_t i = s + 1; i < e; ++i) {
            char c = pem[i];
            if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9')
                || c == '+' || c == '/' || c == '=') b64 += c;
        }
        DWORD sz = 0;
        if (!CryptStringToBinaryA(b64.c_str(), (DWORD)b64.size(), CRYPT_STRING_BASE64,
                                  nullptr, &sz, nullptr, nullptr)) return {};
        std::vector<BYTE> der(sz);
        if (!CryptStringToBinaryA(b64.c_str(), (DWORD)b64.size(), CRYPT_STRING_BASE64,
                                  der.data(), &sz, nullptr, nullptr)) return {};
        // SPKI 里找 BIT STRING(03 42 00 04)：65 字节非压缩点
        for (size_t i = 0; i + 68 <= der.size(); ++i) {
            if (der[i] == 0x03 && der[i+1] == 0x42 && der[i+2] == 0x00 && der[i+3] == 0x04)
                return std::string((const char*)&der[i + 3], 65);
        }
        return {};
    }
};

// ---------------- base64（信封）与 base64url（离线票据） ----------------

inline std::string b64Encode(const std::string& raw) {
    DWORD sz = 0;
    CryptBinaryToStringA((const BYTE*)raw.data(), (DWORD)raw.size(),
                         CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, nullptr, &sz);
    std::string s(sz, 0);
    CryptBinaryToStringA((const BYTE*)raw.data(), (DWORD)raw.size(),
                         CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, &s[0], &sz);
    while (!s.empty() && (s.back() == '\0' || s.back() == '\r' || s.back() == '\n')) s.pop_back();
    return s;
}
inline std::string b64Decode(const std::string& enc) {
    std::string s;
    for (char c : enc) if (c != '\r' && c != '\n' && c != ' ') s += c;
    DWORD sz = 0;
    if (!CryptStringToBinaryA(s.c_str(), (DWORD)s.size(), CRYPT_STRING_BASE64,
                              nullptr, &sz, nullptr, nullptr)) return {};
    std::vector<BYTE> out(sz ? sz : 1);
    if (!CryptStringToBinaryA(s.c_str(), (DWORD)s.size(), CRYPT_STRING_BASE64,
                              out.data(), &sz, nullptr, nullptr)) return {};
    return std::string((const char*)out.data(), sz);
}
inline std::string b64urlEncode(const std::string& raw) {
    std::string s = b64Encode(raw);
    for (auto& c : s) { if (c == '+') c = '-'; else if (c == '/') c = '_'; }
    while (!s.empty() && s.back() == '=') s.pop_back();   // 票据为无填充 base64url
    return s;
}
inline std::string b64urlDecode(const std::string& enc) {
    std::string s = enc;
    for (auto& c : s) { if (c == '-') c = '+'; else if (c == '_') c = '/'; }
    while (s.size() % 4) s += '=';
    return b64Decode(s);
}

// ============================================================
//  HTTP (WinHTTP，同步)
// ============================================================
class Http {
public:
    using Result = std::pair<int, std::string>;   // <http_status, body>
    static Result post(const std::string& url, const std::string& body) {
        return send(url, "POST", body);
    }
    static void setTimeout(int connect_ms, int recv_ms) { conn_ms_ = connect_ms; recv_ms_ = recv_ms; }

    // 设置/更换证书指纹（覆盖 cfg::kTlsCertSha256；空串 = 不锁定）。
    // 指纹支持带冒号 / 大小写混合，内部统一为小写无冒号再比较。
    static void setCertSha256(const std::string& fp) { cert_fp_ = normalizeFp(fp); }
    static const std::string& certSha256() { return cert_fp_; }

private:
    static int conn_ms_, recv_ms_;
    static std::string cert_fp_;   // 归一化（小写无冒号）的服务端证书 SHA256 指纹；空 = 不锁定

    static std::string normalizeFp(const std::string& fp) {
        std::string s;
        s.reserve(fp.size());
        for (char c : fp) {
            if (c == ':' || c == ' ' ) continue;
            s += (char)tolower((unsigned char)c);
        }
        return s;
    }

    // 握手后取对端证书 SHA256 指纹（64 位小写 hex）；失败返回空串
    // BCryptHash 需要 Win10+；为兼容老系统这里手写 SHA256 压缩（复用 Bcrypt::sha256）
    static std::string peerCertFingerprint(HINTERNET request) {
        PCCERT_CONTEXT cert = nullptr;
        DWORD len = sizeof(cert);
        if (!WinHttpQueryOption(request, WINHTTP_OPTION_SERVER_CERT_CONTEXT, &cert, &len) || !cert) {
            return "";
        }
        std::string pem;
        pem.assign((const char*)cert->pbCertEncoded, cert->cbCertEncoded);
        CertFreeCertificateContext(cert);
        // sha256 返回 32 字节裸哈希 → 转 64 位小写 hex（与 normalizeFp 输出同格式）
        std::string d = Bcrypt::sha256(pem);
        return bytesToHex((const unsigned char*)d.data(), d.size());
    }

    static Result send(const std::string& url, const char* method, const std::string& body) {
        Result r = {0, ""};
        HINTERNET session = WinHttpOpen(L"NebulaSDK", WINHTTP_ACCESS_TYPE_NO_PROXY,
                                        nullptr, nullptr, 0);
        if (!session) return r;
        // 解析 url：scheme://host[:port]/path
        size_t schemeEnd = url.find("://");
        if (schemeEnd == std::string::npos) { WinHttpCloseHandle(session); return r; }
        size_t hostBegin = schemeEnd + 3;
        size_t pathSlash = url.find('/', hostBegin);
        std::string host = (pathSlash == std::string::npos)
            ? url.substr(hostBegin) : url.substr(hostBegin, pathSlash - hostBegin);
        std::string path = (pathSlash == std::string::npos) ? "/" : url.substr(pathSlash);
        WORD port = 80;
        bool secure = url.rfind("https://", 0) == 0;
        size_t colon = host.find(':');
        if (colon != std::string::npos) {
            port = (WORD)atoi(host.substr(colon + 1).c_str());
            host = host.substr(0, colon);
        }
        if (secure && colon == std::string::npos) port = 443;

        HINTERNET connect = WinHttpConnect(session, toWide(host).c_str(), port, 0);
        if (!connect) { WinHttpCloseHandle(session); return r; }
        HINTERNET request = WinHttpOpenRequest(connect, toWide(method).c_str(),
                                               toWide(path).c_str(), nullptr, WINHTTP_NO_REFERER,
                                               WINHTTP_DEFAULT_ACCEPT_TYPES,
                                               secure ? WINHTTP_FLAG_SECURE : 0);
        if (!request) {
            WinHttpCloseHandle(connect); WinHttpCloseHandle(session); return r;
        }        DWORD timeout = (DWORD)conn_ms_;
        WinHttpSetOption(request, WINHTTP_OPTION_CONNECT_TIMEOUT, &timeout, sizeof(timeout));
        timeout = (DWORD)recv_ms_;
        WinHttpSetOption(request, WINHTTP_OPTION_RECEIVE_TIMEOUT, &timeout, sizeof(timeout));

        // 证书指纹锁定：填了指纹还走 http → 直接拒绝（明文传输 + 无法锁证书）
        if (!cert_fp_.empty() && !secure) {
            WinHttpCloseHandle(request); WinHttpCloseHandle(connect); WinHttpCloseHandle(session);
            return r;   // status=0，上层报网络错误
        }

        std::wstring headers = L"Content-Type: application/json\r\n";
        BOOL ok = WinHttpSendRequest(request, headers.c_str(), (DWORD)headers.size(),
                                     body.empty() ? nullptr : (LPVOID)body.data(),
                                     (DWORD)body.size(), (DWORD)body.size(), 0);
        if (ok) ok = WinHttpReceiveResponse(request, nullptr);
        if (ok && secure && !cert_fp_.empty()) {
            // 握手成功 → 比对对端证书 SHA256 指纹；不匹配立即断开（防透明代理 / 假证书 MITM）
            std::string got = peerCertFingerprint(request);
            if (got != cert_fp_) {
                WinHttpCloseHandle(request); WinHttpCloseHandle(connect); WinHttpCloseHandle(session);
                return r;   // status=0，上层报网络错误
            }
        }
        if (ok) {
            DWORD status = 0, slen = sizeof(status);
            WinHttpQueryHeaders(request, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                WINHTTP_HEADER_NAME_BY_INDEX, &status, &slen, WINHTTP_NO_HEADER_INDEX);
            r.first = (int)status;
            std::string out;
            std::vector<char> buf(8192);
            DWORD n = 0;
            while (WinHttpReadData(request, buf.data(), (DWORD)buf.size(), &n) && n > 0)
                out.append(buf.data(), n);
            r.second = out;
        }
        WinHttpCloseHandle(request);
        WinHttpCloseHandle(connect);
        WinHttpCloseHandle(session);
        return r;
    }
};
inline int Http::conn_ms_ = 8000;
inline int Http::recv_ms_ = 15000;
inline std::string Http::cert_fp_ = Http::normalizeFp(cfg::kTlsCertSha256);   // 证书指纹锁定（空 = 不锁定）

// ============================================================
//  完整性自校验（客户端 exe 与服务端登记的哈希/大小比对，防篡改）
// ============================================================
// 服务端在 init 的 version 对象下发 self_file_hash / self_file_size
// （即「版本管理」里给客户端当前版本号登记的哈希与字节数）。
// 返回空串 = 校验通过（含服务端未登记 → 跳过）；否则返回拒绝原因。
// 建议在 init() 成功后立刻调用，失败则弹窗并退出，不得继续登录。
// 用法：verifySelfIntegrity(initResult.self_file_hash, initResult.self_file_size)
inline std::string verifySelfIntegrity(const std::string& selfFileHash, long long selfFileSize) {
    if (selfFileHash.empty() && selfFileSize <= 0)
        return "";   // 服务端未登记完整性数据，跳过（发布时在「版本管理」填写哈希/大小即启用）
    wchar_t exe[MAX_PATH] = {};
    if (!GetModuleFileNameW(nullptr, exe, MAX_PATH)) return "无法定位程序文件";
    const std::string path = toUtf8(exe);
    if (!selfFileHash.empty()) {
        std::string local = Bcrypt::fileHashHex(path, selfFileHash.size() == 64);
        if (local.empty()) return "无法读取程序文件，完整性校验失败";
        if (local != selfFileHash) return "程序文件已被修改，请从官方渠道重新下载";
    }
    if (selfFileSize > 0) {
        long long sz = Bcrypt::fileSizeBytes(path);
        if (sz >= 0 && sz != selfFileSize) return "程序文件已被修改，请从官方渠道重新下载";
    }
    return "";
}

// 一站式完整性校验（推荐接入方使用）：init 成功后调用一次即可。
//   · 校验通过 → 返回 true，继续正常流程
//   · 校验失败 → SDK 直接弹出中文提示窗，返回 false（接入方应立即退出程序）
// 提示由 SDK 内置，接入方无需自己写任何 UI 文案。
inline bool enforceSelfIntegrity(const std::string& selfFileHash, long long selfFileSize) {
    const std::string err = verifySelfIntegrity(selfFileHash, selfFileSize);
    if (err.empty()) return true;
    MessageBoxW(nullptr, toWide(err).c_str(), L"Nebula 安全校验", MB_ICONERROR);
    return false;
}

// ============================================================
//  结果
// ============================================================

// 公告结构（notice 接口 list / init 下发 notices）
struct Notice {
    int id = 0;
    std::string title;
    std::string content;
    int type = 1;
    std::string type_text;
};

// ============================================================
//  立即下发公告（type=3）本地已读记录
//  看过即不再显示：ID 与标记时间存本地文件，按 app_key 区分软件，
//  30 天前的旧记录读取时自动清理。以下函数供 Client 内部与自定义接入方共用。
// ============================================================

inline std::string noticeReadStorePath(const std::string& appKey) {
    char ad[MAX_PATH] = {};
    if (GetEnvironmentVariableA("APPDATA", ad, MAX_PATH) > 0 && ad[0]) {
        std::string dir = std::string(ad) + "\\NebulaSDK";
        CreateDirectoryA(dir.c_str(), nullptr);   // 已存在时失败，忽略
        return dir + "\\notices_" + appKey + ".txt";
    }
    // 回退：exe 同目录
    wchar_t exe[MAX_PATH] = {};
    if (GetModuleFileNameW(nullptr, exe, MAX_PATH)) {
        std::string dir = toUtf8(exe);
        size_t p = dir.find_last_of("\\/");
        if (p != std::string::npos) dir = dir.substr(0, p);
        return dir + "\\notices_" + appKey + ".txt";
    }
    return std::string("notices_") + appKey + ".txt";
}

inline std::vector<std::pair<int64_t, int64_t>> loadNoticeReads(const std::string& path) {
    std::vector<std::pair<int64_t, int64_t>> out;
    FILE* f = nullptr;
    if (fopen_s(&f, path.c_str(), "rb") != 0 || !f) return out;
    char line[128] = {};
    const int64_t cutoff = (int64_t)time(nullptr) - 30 * 86400;
    while (fgets(line, sizeof(line), f)) {
        long long id = 0, ts = 0;
        if (sscanf_s(line, "%lld %lld", &id, &ts) == 2 && id > 0 && ts >= cutoff)
            out.push_back({ (int64_t)id, (int64_t)ts });
    }
    fclose(f);
    return out;
}

inline void saveNoticeReads(const std::string& path,
                            const std::vector<std::pair<int64_t, int64_t>>& reads) {
    FILE* f = nullptr;
    if (fopen_s(&f, path.c_str(), "wb") != 0 || !f) return;
    for (auto& p : reads) fprintf(f, "%lld %lld\r\n", (long long)p.first, (long long)p.second);
    fclose(f);
}

inline bool noticeIsRead(const std::vector<std::pair<int64_t, int64_t>>& reads, int64_t id) {
    for (auto& p : reads) if (p.first == id) return true;
    return false;
}

struct Response {
    int  code      = 0;      // 业务 code；本地错误：-1 网络失败 / -2 信封验签或解密失败 / -3 HTTP 非 200
    int  http_code = 0;
    std::string msg;
    std::string raw;         // 解密后的业务响应 JSON 全文（扁平搜索/findJsonObjects 用）
    bool ok() const { return code == 0; }
};

// ============================================================
//  客户端
// ============================================================
class Client {
public:
    // 心跳回调：code=业务码（0 正常），hb 为解析后的心跳数据
    struct HeartbeatInfo {
        int  remain = -1;          // 剩余秒数
        bool online = true;
        bool force_offline = false;
        bool has_notice = false;
        bool need_relogin = false;
        bool kick = false;
        bool need_activate = false;
        std::string grace_ticket;  // 最新票据（收到即覆盖本地缓存）
        int64_t grace_until = 0;
        // 随心跳下发的立即公告（type=3）：auto_flash_ 开启时 SDK 已自动弹出并标记已读；
        // 关闭 auto_flash_（setAutoFlash(false)）后由接入方自行处理（isNoticeRead 过滤 + markNoticeRead）
        std::vector<Notice> flash_notices;
    };
    using HeartbeatCb = std::function<void(int code, const std::string& msg, const HeartbeatInfo& hb)>;

    struct InitResult {
        bool ok = false;
        std::string msg;
        int64_t server_time = 0;
        std::string site_name;
        std::string software_name;     // software.name（init 下发，用于派生提示框标题）
        int     heartbeat_interval = 60;
        int64_t session_ttl = 0;
        bool    register_enable = true;
        bool    maintain_mode = false;
        std::string login_method;              // password / username_code / code
        bool    need_update = false, force_update = false;
        std::string latest, min_ver, update_url, update_note;
        std::string file_hash;                 // 最新版安装包哈希（MD5 32 位 / SHA256 64 位 hex，后台未填则空）
        long long   file_size = 0;             // 最新版安装包字节数（后台未填则 0）
        std::string self_file_hash;            // 客户端自身版本登记的哈希（未登记为空 → 跳过自校验）
        long long   self_file_size = 0;        // 客户端自身版本登记的字节数（0 = 未登记）
        // 比对示例：下载完成后 Bcrypt::fileHashHex(path, hash.size()==64) != file_hash → 拒绝安装
        bool    grace_enable = false;
        int     grace_seconds = 0;
        std::string grace_public_key, grace_algorithm, grace_kid, grace_prefix = "G1";
        std::vector<Notice> notices;
    };

    struct LoginUser {
        int user_id = 0;
        std::string username, nickname;
        int64_t vip_expire = 0;
        std::string vip_text;
        int points = 0, max_devices = 0, status = 1, group_id = 0;
    };
    struct LoginResult {
        bool ok = false;
        int  code = 0;
        std::string msg;
        std::string token;
        int64_t expire_at = 0, ttl = 0;
        std::string login_method;
        bool account_created = false;
        LoginUser user;
        std::string grace_ticket;              // 离线宽限票据（原样缓存）
        int64_t grace_until = 0;
        bool need_relogin = false;
    };

    // app_key 为必填第 4 参：客户端必须声明自己属于哪个软件，没有"默认通用软件"
    explicit Client(const std::string& api_url, const std::string& aes_key,
                    const std::string& sign_salt, const std::string& app_key,
                    const std::string& machine_id = "",
                    const std::string& os_info = "",
                    const std::string& client_version = "1.0.0",
                    const std::string& resp_sign_pub = cfg::kRespSignPubKey,
                    const std::string& tls_cert_sha256 = cfg::kTlsCertSha256)
        : api_(api_url), aes_key_(aes_key), salt_(sign_salt), app_key_(app_key),
          machine_id_(machine_id), os_info_(os_info), client_ver_(client_version),
          resp_pub_(resp_sign_pub),
          device_name_(computerName()) {
        if (machine_id_.empty()) generateMachineId();
        // TLS 证书指纹（构造时可覆盖默认配置）
        Http::setCertSha256(tls_cert_sha256);
        // 统一去掉末尾 '/'，按 <base>/index.php?action=xx 组装
        while (api_.size() > 1 && api_.back() == '/') api_.pop_back();
    }

    /** 运行时设置/更换响应防伪造公钥（覆盖 cfg::kRespSignPubKey；空串仍视为未配置，全部请求失败） */
    void setRespSignPublicKey(const std::string& pem) { resp_pub_ = pem; }
    const std::string& respSignPublicKey() const { return resp_pub_; }
    ~Client() {
        stopHeartbeat();
#if NEBULA_HAS_PROTECT
        nebula::protect::stopWatchdog();   // 加固巡检线程（未开启时为空操作）
#endif
    }
    Client(const Client&) = delete;
    Client& operator=(const Client&) = delete;

    // ---------------- init · 初始化（主盐，不带 k） ----------------
    InitResult init() {
        InitResult res;
#if NEBULA_HAS_PROTECT
        // 客户端加固：连服务端之前先自检（默认关闭时这段不参与编译）
        //   · 默认策略 action=1 → 只回调上报，不打断正常用户
        //   · action>=2 → 命中即中止（返回 ok=false）
        if (nebula::protect::enabled() && nebula::protect::level() > 0) {
            protect_report_ = nebula::protect::scan();
            if (!nebula::protect::enforce(protect_report_)) {
                res.msg = "运行环境异常，已中止连接（请关闭调试/分析工具后重试）";
                return res;
            }
        }
#endif
        std::string payload = std::string() + "{\"client_ver\":" + jsonString(client_ver_)
                            + ",\"machine_id\":" + jsonString(machine_id_) + "}";
        Response r = post("init", payload);
        if (!r.ok()) { res.msg = r.msg.empty() ? ("code " + std::to_string(r.code)) : r.msg; return res; }
        res.ok = true;
        const std::string& d = r.raw;
        res.server_time         = atoll(findJsonValue(d, "server_time").c_str());
        res.site_name           = findJsonValue(d, "site_name");
        // software.{name} 内嵌对象，先 extractObject 再取 name
        {
            std::string sw = extractObject(d, "software");
            if (!sw.empty()) res.software_name = findJsonValue(sw, "name");
        }
        std::string hi          = findJsonValue(d, "heartbeat_interval");
        res.heartbeat_interval  = hi.empty() ? 60 : atoi(hi.c_str());
        res.session_ttl         = atoll(findJsonValue(d, "session_ttl").c_str());
        res.register_enable     = findJsonValue(d, "register_enable") == "1";
        res.maintain_mode       = findJsonValue(d, "maintain_mode") == "1";

        // 会话密钥 session.{k,s}（业务接口签名与 k 字段的来源）
        std::string session = extractObject(d, "session");
        if (!session.empty()) {
            session_k_ = findJsonValue(session, "k");
            session_s_ = findJsonValue(session, "s");
        }

        // 登录规格 login.{method,...}
        std::string loginSpec = extractObject(d, "login");
        if (!loginSpec.empty()) {
            std::string m = findJsonValue(loginSpec, "method");
            if (!m.empty()) login_method_ = m;
        }
        if (login_method_.empty()) login_method_ = "password";
        res.login_method = login_method_;

        // 版本 version.{...}
        std::string ver = extractObject(d, "version");
        if (!ver.empty()) {
            res.need_update  = findJsonValue(ver, "need_update") == "1";
            res.force_update = findJsonValue(ver, "force_update") == "1";
            res.latest       = findJsonValue(ver, "latest");
            res.min_ver      = findJsonValue(ver, "min");
            res.update_url   = findJsonValue(ver, "update_url");
            res.update_note  = findJsonValue(ver, "update_note");
            res.file_hash    = findJsonValue(ver, "file_hash");
            res.file_size    = atoll(findJsonValue(ver, "file_size").c_str());
            res.self_file_hash = findJsonValue(ver, "self_file_hash");
            res.self_file_size = atoll(findJsonValue(ver, "self_file_size").c_str());
        }

        // 离线宽限 grace.{...}
        std::string grace = extractObject(d, "grace");
        if (!grace.empty()) {
            res.grace_enable     = findJsonValue(grace, "enable") == "1";
            std::string gs       = findJsonValue(grace, "seconds");
            res.grace_seconds    = gs.empty() ? 0 : atoi(gs.c_str());
            res.grace_public_key = findJsonValue(grace, "public_key");
            res.grace_algorithm  = findJsonValue(grace, "algorithm");
            res.grace_kid        = findJsonValue(grace, "kid");
            std::string pre      = findJsonValue(grace, "ticket_prefix");
            if (!pre.empty()) res.grace_prefix = pre;
            // 缓存到成员（checkOffline 使用）
            if (!res.grace_public_key.empty()) grace_public_key_ = res.grace_public_key;
            grace_prefix_ = res.grace_prefix;
        }
        res.notices = parseNoticeList(d);
        hb_default_ms_ = res.heartbeat_interval > 0 ? res.heartbeat_interval * 1000 : 60000;
        state_ = STATE_READY;
        last_init_ = res;   // 缓存最近一次 init 结果（内置提示 versionAlert / enforceSelfIntegrity 使用）
        deriveTitles();
        return res;
    }

    // ---------------- 内置提示：默认弹窗，可完全自定义 ----------------
    // 默认（开箱即用）：SDK 直接弹中文提示窗（MessageBoxW + UTF-16，不乱码），
    //   接入方根据返回值决定是否继续，无需写任何提示文案/窗口。
    // 自定义：init 前调用 setUiHandler(cb) 后，SDK 不再弹默认窗，改为回调
    //   cb(kind, msg_utf8)，kind 取值：
    //     "integrity" 完整性校验失败 / "version" 版本更新 / "maintain" 维护中
    //     / "kick" 被踢下线 / "flash" 立即下发公告（每条回调一次，回调返回即视为已读）
    //   接入方可用 msg 与返回值自行渲染任意 UI；回调在调用线程执行。
    using UiHandler = std::function<void(const char* kind, const std::string& msg)>;
    void setUiHandler(UiHandler h) { ui_ = std::move(h); }

    // 自定义内置提示框标题（init 前调用，影响所有 Alert/Notice 弹窗）
    void setVersionTitle(const std::wstring& t)   { title_version_   = t; }
    void setMaintainTitle(const std::wstring& t)  { title_maintain_  = t; }
    void setKickTitle(const std::wstring& t)      { title_kick_      = t; }
    void setIntegrityTitle(const std::wstring& t) { title_integrity_ = t; }
    void setFlashTitle(const std::wstring& t)     { title_flash_     = t; }
    void setPopupTitle(const std::wstring& t)     { title_popup_     = t; }

    // ---------------- 客户端加固（可选 · 默认关闭 · 见 sdk/SDK_PROTECTION.md） ----------------
    // 三项加固（壳标记 / 代码混淆 / 运行时防护）默认全部关闭；开启后可用下面几个接口微调。
    // 编译期没开启（NEBULA_PROTECT_LEVEL=0）时，这些接口仍可调用，只是不做任何事。
#if NEBULA_HAS_PROTECT
    // 运行时调整检测等级（0=关 1=基础 2=标准 3=严格；不会超过编译期 NEBULA_PROTECT_LEVEL 上限）
    void setProtectLevel(int lv)  { nebula::protect::setLevel(lv); }
    // 命中后的处置：0=只记录 1=回调上报(默认) 2=降级(拒绝业务) 3=弹窗并退出
    // 只要传入 act>0，就同时隐式「启用检测」：把等级提到编译期上限并打开 enabled 开关，
    // 否则 setProtectAction 只设了处置方式、扫描始终被 enabled()=false 短路而不会真正执行。
    void setProtectAction(int act) {
        nebula::protect::setAction(act);
        if (act > 0) {
            // 按接入方配置区 kProtectStrictPolicy 决定宽松/严格：
            // false=宽松：疑似环境(hook/VM/沙箱/BIOS VMW)只记录；true=严格：一律按 action 拦截。
            nebula::protect::setSuspiciousPolicy(cfg::kProtectStrictPolicy);
            nebula::protect::setLevel((int)NEBULA_PROTECT_LEVEL);
            nebula::protect::setEnabled(true);
        }
    }
    // 命中后回调（推荐在这里把结果上报到你自己服务端，或写本地日志）
    void setProtectCallback(std::function<void(const nebula::protect::Report&)> cb) {
        nebula::protect::setCallback(std::move(cb));
    }
    // 一行启动加固：立即检测一次（按策略处置）+ 可选后台巡检（interval_ms=0 表示不巡检）
    // 建议放在 main() 里、创建 Client 之后立刻调用：
    //     auto c = nebula::createDefaultClient();
    //     c->enableProtection(0, 5000);
    nebula::protect::Report enableProtection(int level = 0, int interval_ms = 0) {
        if (level > 0) nebula::protect::setLevel(level);
        nebula::protect::setEnabled(true);
        nebula::protect::Report r = nebula::protect::scan();
        nebula::protect::enforce(r);
        if (interval_ms > 0) nebula::protect::startWatchdog(interval_ms);
        return r;
    }
    // 手动检测一次（不改动任何状态）
    nebula::protect::Report protectScan() { return nebula::protect::scan(); }
    // 最近一次 init() 内置检测的结果
    nebula::protect::Report protectReport() const { return protect_report_; }
    // 是否已进入「降级」态（策略 2 命中后会置位）
    bool protectionDegraded() const { return nebula::protect::degraded(); }
#endif

    void uiAlert(const char* kind, const std::string& msg,
                 const std::wstring& title, UINT icon) const {
        if (ui_) { ui_(kind, msg); return; }
        MessageBoxW(nullptr, toWide(msg).c_str(), title.c_str(), icon);
    }

    // init 成功后调用（使用最近一次 init 的结果）：版本过期 / 发现新版本提示。
    //   · 强制更新 → 提示「当前版本过低，请升级到 X 后使用」，返回 false（应中止登录）
    //   · 可选更新 → 提示「发现新版本 X，建议尽快升级」，返回 true（不阻止登录）
    //   · 已是最新 → 不提示，返回 true
    bool versionAlert() const {
        if (last_init_.force_update) {
            uiAlert("version",
                    (std::string("当前版本过低（") + client_ver_ + "），请升级到 "
                     + last_init_.latest + " 后使用。"),
                    title_version_, MB_ICONWARNING);
            return false;
        }
        if (last_init_.need_update) {
            uiAlert("version",
                    "发现新版本 " + last_init_.latest + "，建议尽快升级。",
                    title_version_, MB_ICONINFORMATION);
        }
        return true;
    }

    // init 成功后调用：维护模式提示（登录仍由服务端 6002 兜底拦截）
    void maintainAlert() const {
        uiAlert("maintain", "服务器维护中，请稍后再试。", title_maintain_, MB_ICONWARNING);
    }

    // 心跳被踢 / 顶号 / 需重新登录时调用（可在心跳回调线程内）：提示服务端下线原因
    void kickAlert(const std::string& serverMsg) const {
        uiAlert("kick",
                serverMsg.empty() ? "您的账号已下线，请重新登录。" : serverMsg,
                title_kick_, MB_ICONWARNING);
    }

    // 被踢/下线展示文案（返回 UTF-8，接入方自行转宽字符显示）：
    // 服务端 msg 优先（可区分顶号/管理员强制下线/会话过期），为空时按业务码兜底。
    // 业务码：1002=会话失效 4002=设备已解绑 2004=账号过期
    static std::string kickText(int code, const std::string& msg) {
        if (!msg.empty()) return msg;
        switch (code) {
            case 1002: return "账号已在其他设备登录";
            case 4002: return "当前设备已被解绑";
            case 2004: return "账号已过期，请激活后再登录";
            default:   return "登录状态已失效";
        }
    }

    // 一站式完整性自校验（使用最近一次 init 的结果）：
    //   通过 → 返回 true；失败 → 提示（默认弹窗 / 自定义回调）并返回 false，接入方应退出程序。
    bool enforceSelfIntegrity() const {
        const std::string err = verifySelfIntegrity(last_init_.self_file_hash, last_init_.self_file_size);
        if (err.empty()) return true;
        uiAlert("integrity", err, title_integrity_, MB_ICONERROR);
        return false;
    }

    // ---------------- login · 登录（按 init 下发的登录方式组装） ----------------
    LoginResult login(const std::string& account, const std::string& secret) {
        LoginResult lr;
        if (state_ != STATE_READY) {
            lr.code = -1; lr.msg = "初始化失败，请重启程序";
            return lr;
        }
        std::string payload = buildLoginPayload(account, secret);
        Response r = post("login", payload);
        lr.code = r.code;
        lr.msg  = r.msg;
        if (!r.ok()) {
            lr.need_relogin = findJsonValue(r.raw, "need_relogin") == "1";
            return lr;
        }
        const std::string& d = r.raw;
        lr.ok             = true;
        lr.token          = findJsonValue(d, "token");
        lr.expire_at      = atoll(findJsonValue(d, "expire_at").c_str());
        lr.ttl            = atoll(findJsonValue(d, "ttl").c_str());
        std::string lm    = findJsonValue(d, "login_method");
        if (!lm.empty()) lr.login_method = lm; else lr.login_method = login_method_;
        lr.account_created = findJsonValue(d, "account_created") == "1";
        std::string user  = extractObject(d, "user");
        if (!user.empty()) {
            lr.user.user_id   = atoi(findJsonValue(user, "user_id").c_str());
            lr.user.username  = findJsonValue(user, "username");
            lr.user.nickname  = findJsonValue(user, "nickname");
            lr.user.vip_expire= atoll(findJsonValue(user, "vip_expire").c_str());
            lr.user.vip_text  = findJsonValue(user, "vip_text");
            lr.user.points    = atoi(findJsonValue(user, "points").c_str());
            lr.user.max_devices = atoi(findJsonValue(user, "max_devices").c_str());
            lr.user.status    = atoi(findJsonValue(user, "status").c_str());
            lr.user.group_id  = atoi(findJsonValue(user, "group_id").c_str());
        }
        std::string grace = extractObject(d, "grace");
        if (!grace.empty()) {
            lr.grace_ticket = findJsonValue(grace, "ticket");
            lr.grace_until  = atoll(findJsonValue(grace, "until").c_str());
            grace_ticket_   = lr.grace_ticket;
            grace_until_    = lr.grace_until;
        }
        if (!lr.token.empty()) { token_ = lr.token; state_ = STATE_LOGIN; }
        return lr;
    }

    // ★ 内置登录判定（可选）：把「发起登录 → 判定成功/失败」整段收进 SDK，
    //   并在壳虚拟化区路由回调 —— 接入层不再暴露一眼可 patch 的 if(ok)。
    //   判定代码（lr.ok）真正实现在 SDK 内部，接入方只提供成功/失败动作。
    //
    //   【可用可不用】:
    //     · 用：判定分支被壳虚拟化（需 NEBULA_SHELL_ENABLE=1 + VMP），patch 难。
    //     · 不用：保留 login() 后自行 if(lr.ok) 即可，未定义该宏时空宏零开销。
    //
    //   用法：
    //       c.loginAndGuard(account, secret,
    //           [&](nebula::Client::LoginResult& lr){ StartMain(std::move(c)); },  // 成功
    //           [&](nebula::Client::LoginResult& lr){ ShowLoginFailed(lr.msg); }); // 失败
    template <typename Ok, typename Fail>
    void loginAndGuard(const std::string& account, const std::string& secret,
                       Ok&& onOk, Fail&& onFail) {
        LoginResult lr = login(account, secret);
        if (lr.ok) {
            onOk(lr);
        } else {
            onFail(lr);
        }
    }

    // ---------------- 业务接口（登录后） ----------------

    Response logout(const std::string& token) {
        Response r = post("logout", "{\"token\":" + jsonString(token) + "}");
        if (r.ok()) state_ = STATE_READY;
        return r;
    }

    Response activate(const std::string& token, const std::string& code) {
        return post("activate", "{\"token\":" + jsonString(token)
                    + ",\"machine_id\":" + jsonString(machine_id_)
                    + ",\"code\":" + jsonString(code) + "}");
    }

    Response devices(const std::string& token) {
        return post("devices", "{\"token\":" + jsonString(token) + "}");
    }

    // machine_id 为空 = 解绑当前设备；all = 解绑该账号全部设备
    Response unbindDevice(const std::string& token, const std::string& machine_id = "",
                          const std::string& password = "", bool all = false) {
        std::string payload = "{\"token\":" + jsonString(token);
        if (!machine_id.empty()) payload += ",\"machine_id\":" + jsonString(machine_id);
        if (!password.empty())   payload += ",\"password\":" + jsonString(password);
        if (all)                 payload += ",\"all\":true";
        payload += "}";
        return post("unbind", payload);
    }

    Response userinfo(const std::string& token) {
        return post("userinfo", "{\"token\":" + jsonString(token) + "}");
    }

    // ---------------- 心跳 ----------------

    Response heartbeat(const std::string& token) {
        Response r = post("heartbeat", "{\"token\":" + jsonString(token)
                    + ",\"machine_id\":" + jsonString(machine_id_) + "}");
        if (r.ok()) {
            const std::string& d = r.raw;
            std::string grace = extractObject(d, "grace");
            if (!grace.empty()) {
                std::string t = findJsonValue(grace, "ticket");
                if (!t.empty()) { grace_ticket_ = t; grace_until_ = atoll(findJsonValue(grace, "until").c_str()); }
            }
            if (findJsonValue(d, "need_relogin") == "1") state_ = STATE_EXPIRED;
        }
        return r;
    }

    // 从心跳 Response 解析便捷结构（runHeartbeatLoop 内部使用，也可自行调用）
    static HeartbeatInfo parseHeartbeat(const Response& r) {
        HeartbeatInfo hb;
        if (r.ok()) {
            const std::string& d = r.raw;
            std::string remain = findJsonValue(d, "remain");
            hb.remain        = remain.empty() ? -1 : atoi(remain.c_str());
            hb.online        = findJsonValue(d, "online") != "0";
            hb.force_offline = findJsonValue(d, "force_offline") == "1";
            hb.has_notice    = findJsonValue(d, "has_notice") == "1";
            hb.need_relogin  = findJsonValue(d, "need_relogin") == "1";
            hb.kick          = findJsonValue(d, "kick") == "1";
            hb.need_activate = findJsonValue(d, "need_activate") == "1";
            std::string grace = extractObject(d, "grace");
            if (!grace.empty()) {
                hb.grace_ticket = findJsonValue(grace, "ticket");
                hb.grace_until  = atoll(findJsonValue(grace, "until").c_str());
            }
            // 随心跳下发的立即公告（type=3，服务端已按归属软件过滤）
            for (auto& obj : findJsonObjects(d, "flash_notices")) {
                Notice n;
                n.id      = atoi(findJsonValue(obj, "id").c_str());
                n.title   = findJsonValue(obj, "title");
                n.content = findJsonValue(obj, "content");
                n.type    = 3;
                if (n.id > 0) hb.flash_notices.push_back(std::move(n));
            }
        } else {
            hb.need_relogin = findJsonValue(r.raw, "need_relogin") == "1";
        }
        return hb;
    }

    void startHeartbeat(const std::string& token, HeartbeatCb cb, int interval_ms = 0) {
        stopHeartbeat();
        hb_token_ = token;
        hb_cb_ = std::move(cb);
        hb_interval_ms_ = interval_ms;
        hb_running_ = true;
        hb_thread_ = std::thread([this] { runHeartbeatLoop(); });
    }
    void stopHeartbeat() {
        hb_running_ = false;
        if (hb_thread_.joinable()) hb_thread_.join();
    }

    // ---------------- 白名单只读接口 ----------------

    Response getNotices(int id = 0) {
        return post("notice", "{\"id\":" + std::to_string(id) + "}");
    }
    static std::vector<Notice> parseNoticeList(const std::string& decrypted) {
        std::vector<Notice> list;
        for (auto& obj : findJsonObjects(decrypted, "list")) {
            Notice n;
            n.id        = atoi(findJsonValue(obj, "id").c_str());
            n.title     = findJsonValue(obj, "title");
            n.content   = findJsonValue(obj, "content");
            std::string t = findJsonValue(obj, "type");
            n.type      = t.empty() ? 1 : atoi(t.c_str());
            n.type_text = findJsonValue(obj, "type_text");
            list.push_back(std::move(n));
        }
        return list;
    }

    Response checkVersion(const std::string& version, const std::string& channel = "stable") {
        return post("version", "{\"version\":" + jsonString(version)
                    + ",\"channel\":" + jsonString(channel) + "}");
    }

    Response getOnlineCount() {
        return post("online", "{}");
    }

    // ---------------- 立即下发公告（type=3，看过即不再显示） ----------------
    // 已读记录存本地（%APPDATA%\NebulaSDK\notices_<app_key>.txt，按软件区分）。
    // 用法一（零代码内置）：登录成功后一行 c.flashNotices() —— 拉取未读、默认弹窗
    //   逐条展示（setUiHandler 时回调 kind="flash"，msg 为 标题+换行+内容）、
    //   每条确认后自动标记已读，之后不再显示。
    // 用法二（完全自定义 UI）：
    //   auto list = c.fetchFlashNotices();        // 只拉未读，不弹窗不标记
    //   ……接入方自行渲染，用户确认一条后调 c.markNoticeRead(n.id);
    std::vector<Notice> fetchFlashNotices() {
        std::vector<Notice> out;
        Response r = post("notice", "{}");
        if (!r.ok()) return out;
        const auto reads = loadNoticeReads(noticeReadStorePath(app_key_));
        for (auto& n : parseNoticeList(r.raw))
            if (n.type == 3 && !noticeIsRead(reads, n.id)) out.push_back(std::move(n));
        return out;
    }

    void markNoticeRead(int64_t id) {
        const std::string path = noticeReadStorePath(app_key_);
        auto reads = loadNoticeReads(path);
        const int64_t now = (int64_t)time(nullptr);
        for (auto& p : reads)
            if (p.first == id) { p.second = now; saveNoticeReads(path, reads); return; }
        reads.push_back({ id, now });
        saveNoticeReads(path, reads);
    }

    // 立即公告是否已被读过（自定义 UI 时配合 fetchFlashNotices / 心跳 hb.flash_notices 使用）
    bool isNoticeRead(int64_t id) {
        return noticeIsRead(loadNoticeReads(noticeReadStorePath(app_key_)), id);
    }

    // 清空本地已读记录（测试或需要把全部立即公告重新下发一遍时使用）
    void clearNoticeReads() {
        DeleteFileA(noticeReadStorePath(app_key_).c_str());
    }

    // 心跳自动弹立即公告开关（默认开启）：开启时 SDK 在心跳线程自动弹出未读立即公告并标记已读；
    // 关闭后 hb.flash_notices 原样交给心跳回调，由接入方自行展示与 markNoticeRead
    void setAutoFlash(bool on) { auto_flash_ = on; }

    std::vector<Notice> flashNotices() {
        auto list = fetchFlashNotices();
        for (auto& n : list) {
            uiAlert("flash",
                    n.title + (n.content.empty() ? std::string() : ("\n\n" + n.content)),
                    title_flash_, MB_ICONINFORMATION);
            markNoticeRead(n.id);
        }
        return list;
    }

    // 弹窗公告（type=2）：拉取并逐条弹窗展示（setUiHandler 时回调 kind="popup"）。
    // 每次登录都会提示（无已读机制）；公告栏只展示列表公告，弹窗公告走这里。
    std::vector<Notice> popupNotices() {
        std::vector<Notice> out;
        Response r = post("notice", "{}");
        if (!r.ok()) return out;
        for (auto& n : parseNoticeList(r.raw))
            if (n.type == 2) out.push_back(std::move(n));
        for (auto& n : out) {
            uiAlert("popup",
                    n.title + (n.content.empty() ? std::string() : ("\n\n" + n.content)),
                    title_popup_, MB_ICONINFORMATION);
        }
        return out;
    }

    // ---------------- 离线宽限（本地 ES256 验签，见 docs/API.md 2.16） ----------------

    struct GraceResult {
        bool ok = false;
        int  code = 0;   // 0=通过 / -1 验签失败 / -2 格式错误 / -3 机器码或会话不匹配 / -4 已到期 / -5 未启用
        std::string msg;
        int remain_sec = 0;
        int64_t until_ts = 0;
        int payload_userid = 0;
        int64_t payload_vip_expire = 0;   // 会员到期（-1 = 永久）
    };

    GraceResult checkOffline(const std::string& ticket, const std::string& token) {
        GraceResult gr;
        if (grace_public_key_.empty()) { gr.code = -5; gr.msg = "服务端未开启离线宽限（无公钥）"; return gr; }
        if (ticket.size() < 10)        { gr.code = -2; gr.msg = "票据格式错误"; return gr; }

        const std::string& prefix = grace_prefix_.empty() ? std::string("G1") : grace_prefix_;
        size_t p1 = ticket.find('.');
        size_t p2 = (p1 == std::string::npos) ? std::string::npos : ticket.find('.', p1 + 1);
        if (p1 == std::string::npos || p2 == std::string::npos) {
            gr.code = -2; gr.msg = "票据格式错误"; return gr;
        }
        if (ticket.compare(0, p1, prefix) != 0) { gr.code = -2; gr.msg = "票据前缀不匹配"; return gr; }

        std::string payload_b64 = ticket.substr(p1 + 1, p2 - p1 - 1);
        std::string sig_b64url  = ticket.substr(p2 + 1);

        // ② 核心代码混淆：不透明谓词 + 虚假分支
        if (obf::opaqueFalse()) { NEBULA_DEAD_BRANCH(); }

        // 1. ES256 验签：签名对象为 "G1.<payload-b64url>"
        //    ② 混淆：验签走间接调用，隐藏真正的校验点
        std::string signedData = ticket.substr(0, p2);
        std::string sig = b64urlDecode(sig_b64url);
        if (!obf::vcallR(&Bcrypt::es256Verify, grace_public_key_, signedData, sig)) {
            gr.code = -1; gr.msg = "票据验签失败"; return gr;
        }
        // 2. 解析载荷
        std::string payload = b64urlDecode(payload_b64);
        gr.until_ts        = atoll(findJsonValue(payload, "g").c_str());
        gr.payload_userid  = atoi(findJsonValue(payload, "u").c_str());
        gr.payload_vip_expire = atoll(findJsonValue(payload, "e").c_str());
        std::string m_digest = findJsonValue(payload, "m");
        std::string k_digest = findJsonValue(payload, "k");
        // 3. 机器码摘要校验：m == sha256(machine_id) 前 16 位 hex
        if (!m_digest.empty()) {
            std::string local_m = obf::vcallR(&Bcrypt::sha256, machine_id_);
            if (local_m.compare(0, 16, m_digest) != 0) {
                gr.code = -3; gr.msg = "票据与当前机器不匹配"; return gr;
            }
        }
        // 4. 会话令牌摘要校验：k == sha256(token) 前 16 位 hex
        if (!k_digest.empty()) {
            if (token.empty()) { gr.code = -3; gr.msg = "票据与当前会话不匹配"; return gr; }
            std::string local_k = obf::vcallR(&Bcrypt::sha256, token);
            if (local_k.compare(0, 16, k_digest) != 0) {
                gr.code = -3; gr.msg = "票据与当前会话不匹配"; return gr;
            }
        }
        // 5. 有效期（容忍 120 秒时钟偏差）
        int64_t now = nowSec();
        if (gr.until_ts > 0 && now > gr.until_ts + CLOCK_SKEW) {
            gr.code = -4; gr.msg = "离线宽限已到期"; return gr;
        }
        gr.ok = true; gr.code = 0; gr.msg = "ok";
        gr.remain_sec = gr.until_ts > now ? (int)(gr.until_ts - now) : 0;
        return gr;
    }

    // 本地缓存的票据（login/heartbeat 成功后自动覆盖）
    const std::string& graceTicket() const { return grace_ticket_; }
    int64_t            graceUntil()  const { return grace_until_; }
    const std::string& getGracePublicKey() const { return grace_public_key_; }
    const std::string& getLoginMethod()    const { return login_method_; }

private:
    enum State { STATE_NEW, STATE_READY, STATE_LOGIN, STATE_EXPIRED };

    // 从 init 下发的 software.name 派生所有提示框标题；为空时回退到 site_name
    void deriveTitles() {
        std::string sw = last_init_.software_name.empty()
            ? last_init_.site_name : last_init_.software_name;
        if (sw.empty()) return;
        const std::wstring w = toWide(sw);
        title_version_   = w + L" 版本更新";
        title_maintain_  = w + L" 公告";
        title_kick_      = w + L" 下线通知";
        title_integrity_ = w + L" 安全校验";
        title_flash_     = w + L" 公告";
        title_popup_     = w + L" 公告";
    }

    static bool isWhitelist(const std::string& action) {
        return action == "init" || action == "notice" || action == "version" || action == "online";
    }

    std::string actionUrl(const std::string& action) const {
        std::string base = api_;
        // 两种入口形式：
        //   1) base 以 index.php 结尾 → <base>?action=xx（文档主形式）
        //   2) 目录形式 → <base>/?action=xx（实测部分服务器 index.php 形式会被后台页面占用）
        if (base.size() >= 10 && base.compare(base.size() - 9, 9, "index.php") == 0)
            return base + "?action=" + action;
        if (base.empty() || base.back() != '/') base += '/';
        return base + "?action=" + action;
    }

    std::string buildLoginPayload(const std::string& account, const std::string& secret) {
        // 硬件指纹：首次登录时采集并缓存（失败只试一次，为空则不上报，行为与旧版一致）
        if (!device_fp_tried_) {
            device_fp_tried_ = true;
            device_fp_json_ = collectDeviceFp();
        }
        std::string base = ",\"machine_id\":" + jsonString(machine_id_)
                         + ",\"device_name\":" + jsonString(device_name_)
                         + ",\"os_info\":" + jsonString(os_info_)
                         + ",\"client_ver\":" + jsonString(client_ver_);
        if (!device_fp_json_.empty()) base += ",\"device_fp\":" + device_fp_json_;
        if (login_method_ == "code")
            return "{\"code\":" + jsonString(secret) + base + "}";
        if (login_method_ == "username_code")
            return "{\"username\":" + jsonString(account) + ",\"code\":" + jsonString(secret) + base + "}";
        return "{\"username\":" + jsonString(account) + ",\"password\":" + jsonString(secret) + base + "}";
    }

    // 加密信封请求（协议见 docs/API.md 1.1 / 1.2 / 1.2.1 / 1.3）
    Response post(const std::string& action, const std::string& payloadJson) {
        Response r;
        r.code = -1; r.msg = "网络错误";

        // 0. app_key 硬校验：未设置软件标识直接拒绝发请求
        if (app_key_.empty()) {
            r.code = -2; r.msg = "缺少 app_key：构造 Client 时必须传入软件标识";
            return r;
        }

        // 核心代码混淆：不透明谓词 + 虚假分支（打乱静态分析的控制流视图）
        if (obf::opaqueFalse()) { NEBULA_DEAD_BRANCH(); }

        // 1. 加密业务参数：key = SHA256(AES_KEY) 前 32 字节；IV = MD5(AES_KEY) 前 16 字节（随密文前 16 字节发送）
        //    ② 混淆：密钥派生与加密一律走间接调用，打散调用图（不被内联/静态识别调用点）
        std::string key32 = obf::vcallR(&Bcrypt::sha256, aes_key_);
        if (key32.size() > 32) key32.resize(32);
        std::string iv = obf::vcallR(&Bcrypt::md5, aes_key_);
        std::string blob = obf::vcallR(&Bcrypt::aesRaw, key32, iv, payloadJson, true);
        if (blob.empty()) { r.code = -2; r.msg = "本地加密失败"; return r; }
        std::string data_b64 = b64Encode(blob);
        NEBULA_MARK_MUTATE_BEGIN();
        // 2. 时间戳（秒）+ 一次性随机串
        int64_t t = nowSec();
        std::string n = generateNonce();

        // 3. 签名：白名单接口用主盐，业务接口用会话盐（未开会话密钥的服务端回落主盐）
        //    ② 混淆：签名同样走间接调用
        bool whitelist = isWhitelist(action);
        std::string salt = whitelist ? salt_ : (session_s_.empty() ? salt_ : session_s_);
        std::string sign = obf::vcallR(&Bcrypt::hmacHex, salt, data_b64 + "|" + std::to_string(t) + "|" + n);

        // 4. 信封：{ data, sign, t, n, k? }
        std::string envelope = "{\"data\":" + jsonString(data_b64)
                             + ",\"sign\":" + jsonString(sign)
                             + ",\"t\":" + std::to_string(t)
                             + ",\"n\":" + jsonString(n);
        if (!whitelist && !session_k_.empty())
            envelope += ",\"k\":" + jsonString(session_k_);
        // 软件标识（外层明文，必带：服务端 resolve 用它选软件 → 决定用哪套密钥与数据隔离）
        envelope += ",\"app_key\":" + jsonString(app_key_);
        envelope += "}";
        NEBULA_MARK_MUTATE_END();
        // 5. 发送
        auto [status, body] = Http::post(actionUrl(action), envelope);
        r.http_code = status;
        if (status == 0) return r;
        if (status != 200) { r.code = -3; r.msg = "HTTP " + std::to_string(status); return r; }

        // 6. 响应信封验签（服务端用「验请求所用的同一把盐」签名）
        std::string resp_data = findJsonValue(body, "data");
        std::string resp_sign = findJsonValue(body, "sign");
        std::string resp_t    = findJsonValue(body, "t");
        std::string resp_n    = findJsonValue(body, "n");
        if (resp_data.empty()) { r.code = -2; r.msg = "响应缺少 data"; return r; }
        std::string expect = Bcrypt::hmacHex(salt, resp_data + "|" + resp_t + "|" + resp_n);        if (resp_sign.empty() || expect != resp_sign) {
            r.code = -2; r.msg = "响应验签失败"; return r;
        }
        // 6.5 响应防伪造签名（非对称，服务端私钥签、客户端内置公钥验）
        //     强制校验：未配置公钥 / 缺 sig / 验签失败一律拒绝，不做旧服务端兼容 ——
        //     这是防「假服务器 + hosts 劫持」的关键：攻击者拿不到服务端私钥。
        if (resp_pub_.empty()) {
            r.code = -2; r.msg = "未配置响应签名公钥（cfg::kRespSignPubKey），拒绝连接"; return r;
        }
        {
            std::string sig    = b64Decode(findJsonValue(body, "sig"));
            std::string sdata  = resp_data + "|" + resp_t + "|" + resp_n;
            if (sig.empty() || !obf::vcallR(&Bcrypt::es256Verify, resp_pub_, sdata, sig)) {
                r.code = -2; r.msg = "响应签名校验失败（可能连到了伪造服务器）"; return r;
            }
        }

        // 7. 解密业务响应 { code, msg, time, data:{...} }
        std::string plain = Bcrypt::aesDecrypt(key32, b64Decode(resp_data));
        if (plain.empty()) { r.code = -2; r.msg = "响应解密失败"; return r; }
        r.raw  = plain;
        r.msg  = findJsonValue(plain, "msg");
        std::string code_s = findJsonValue(plain, "code");
        r.code = code_s.empty() ? 0 : atoi(code_s.c_str());
        return r;
    }

    void runHeartbeatLoop() {
        while (hb_running_) {
            Response r = heartbeat(hb_token_);
            HeartbeatInfo hb = parseHeartbeat(r);
            if (!hb.grace_ticket.empty()) { grace_ticket_ = hb.grace_ticket; grace_until_ = hb.grace_until; }
            // 立即公告：默认自动弹出未读的并标记已读（看过即不再显示）；setAutoFlash(false) 时交接入方处理
            if (!hb.flash_notices.empty() && auto_flash_) {
                const auto reads = loadNoticeReads(noticeReadStorePath(app_key_));
                for (auto& n : hb.flash_notices) {
                    if (noticeIsRead(reads, n.id)) continue;
                    uiAlert("flash",
                            n.title + (n.content.empty() ? std::string() : ("\n\n" + n.content)),
                            title_flash_, MB_ICONINFORMATION);
                    markNoticeRead(n.id);
                }
            }
            if (hb_cb_) hb_cb_(r.code, r.msg, hb);
            if (!hb_running_) break;
            int ms = hb_interval_ms_ > 0 ? hb_interval_ms_ : hb_default_ms_;
            for (int waited = 0; hb_running_ && waited < ms; waited += 200)
                std::this_thread::sleep_for(std::chrono::milliseconds(ms - waited > 200 ? 200 : ms - waited));
        }
    }

    void generateMachineId() {
        unsigned char b[8] = {};
        randBytes(b, 8);
        machine_id_ = bytesToHex(b, 8);   // 16 位 hex
    }

    // ---------------- 硬件指纹（device_fp，登录时随 payload 上报） ----------------
    // WMI 单属性查询：失败 / 无结果返回空串（不影响登录，仅少一个指纹组件）
    static std::string wmiQuery(const wchar_t* wql, const wchar_t* prop) {
        std::string out;
        HRESULT hr = CoInitializeEx(nullptr, COINIT_MULTITHREADED);
        const bool uninit = SUCCEEDED(hr);          // RPC_E_CHANGED_MODE：线程已是 STA，同样可用
        if (FAILED(hr) && hr != RPC_E_CHANGED_MODE) return out;

        IWbemLocator* loc = nullptr;
        IWbemServices* svc = nullptr;
        IEnumWbemClassObject* en = nullptr;
        IWbemClassObject* obj = nullptr;
        ULONG ret = 0;
        BSTR ns = SysAllocString(L"ROOT\\CIMV2");
        BSTR lang = SysAllocString(L"WQL");
        BSTR q = SysAllocString(wql);
        do {
            if (FAILED(CoCreateInstance(CLSID_WbemLocator, nullptr, CLSCTX_INPROC_SERVER,
                                        IID_IWbemLocator, (void**)&loc)) || !loc) break;
            if (FAILED(loc->ConnectServer(ns, nullptr, nullptr, nullptr, 0, nullptr, nullptr, &svc)) || !svc) break;
            // 显式忽略返回值：代理鉴权设置失败不影响查询（常见于权限受限环境），只影响性能
            (void)CoSetProxyBlanket(svc, RPC_C_AUTHN_WINNT, RPC_C_AUTHZ_NONE, nullptr,
                              RPC_C_AUTHN_LEVEL_CALL, RPC_C_IMP_LEVEL_IMPERSONATE, nullptr, EOAC_NONE);
            if (FAILED(svc->ExecQuery(lang, q, WBEM_FLAG_FORWARD_ONLY | WBEM_FLAG_RETURN_IMMEDIATELY,
                                      nullptr, &en)) || !en) break;
            if (FAILED(en->Next(WBEM_INFINITE, 1, &obj, &ret)) || ret == 0 || !obj) break;
            VARIANT v;
            VariantInit(&v);
            if (SUCCEEDED(obj->Get(prop, 0, &v, nullptr, nullptr)) && v.vt == VT_BSTR && v.bstrVal) {
                int n = WideCharToMultiByte(CP_UTF8, 0, v.bstrVal, -1, nullptr, 0, nullptr, nullptr);
                if (n > 1) {
                    out.resize((size_t)n - 1);
                    WideCharToMultiByte(CP_UTF8, 0, v.bstrVal, -1, &out[0], n, nullptr, nullptr);
                }
            }
            VariantClear(&v);
        } while (false);
        if (obj) obj->Release();
        if (en) en->Release();
        if (svc) svc->Release();
        if (loc) loc->Release();
        SysFreeString(ns); SysFreeString(lang); SysFreeString(q);
        if (uninit) CoUninitialize();
        return out;
    }

    // 主网卡 MAC（12 位小写裸 hex；服务端用 OUI 识别虚拟机）
    static std::string primaryMac() {
        std::string out;
        ULONG sz = 0;
        if (GetAdaptersInfo(nullptr, &sz) != ERROR_BUFFER_OVERFLOW) return out;
        std::vector<char> buf(sz);
        PIP_ADAPTER_INFO ai = reinterpret_cast<IP_ADAPTER_INFO*>(buf.data());
        if (GetAdaptersInfo(ai, &sz) != NO_ERROR) return out;
        for (; ai; ai = ai->Next) {
            if (ai->AddressLength != 6) continue;
            char hex[13] = {};
            for (int i = 0; i < 6; ++i)
                sprintf_s(hex + i * 2, 3, "%02x", ai->Address[i]);
            if (strcmp(hex, "000000000000") == 0) continue;
            out = hex;
            break;
        }
        return out;
    }

    // 指纹组件哈希：MD5 原始值 → 前 16 位 hex（服务端只认 [a-z0-9]，哈希也避免上报原始硬件信息）
    static std::string fpHash(const std::string& raw) {
        std::string h = bytesToHex((const unsigned char*)Bcrypt::md5(raw).data(), 16);
        return h.substr(0, 16);
    }

    // 过滤 OEM 占位值（"Default string" 之类的无效序列号会让不同机器得到相同组件）
    static bool fpValueOk(const std::string& s) {
        if (s.size() < 4) return false;
        std::string low;
        low.reserve(s.size());
        for (char c : s) low += (char)tolower((unsigned char)c);
        return low.find("default") == std::string::npos
            && low.find("to be filled") == std::string::npos
            && low.find("not specified") == std::string::npos
            && low.find("system serial") == std::string::npos
            && low.find("chassis") == std::string::npos
            && low.find("unknown") == std::string::npos
            && low != "none" && low != "0123456789" && low != "0000000000";
    }

    // 采集全部组件并组装 device_fp JSON；一个有效组件都没有时返回 ""（不上报）
    static std::string collectDeviceFp() {
        std::string board = wmiQuery(L"SELECT SerialNumber FROM Win32_BaseBoard", L"SerialNumber");
        std::string cpuId = wmiQuery(L"SELECT ProcessorId FROM Win32_Processor", L"ProcessorId");
        std::string disk  = wmiQuery(L"SELECT SerialNumber FROM Win32_DiskDrive", L"SerialNumber");
        std::string bios  = wmiQuery(L"SELECT SerialNumber FROM Win32_BIOS", L"SerialNumber");
        std::string gpu   = wmiQuery(L"SELECT Name FROM Win32_VideoController", L"Name");
        std::string mac   = primaryMac();

        std::string fp = "{";
        auto add = [&fp](const char* key, const std::string& val) {
            if (val.empty()) return;
            if (fp.size() > 1) fp += ",";
            fp += std::string("\"") + key + "\":" + jsonString(val);
        };
        if (fpValueOk(board)) add("board", fpHash(board));
        if (fpValueOk(cpuId)) add("cpu", fpHash(cpuId));
        if (fpValueOk(disk))  add("disk", fpHash(disk));
        if (fpValueOk(bios))  add("bios", fpHash(bios));
        add("mac", mac);                       // 裸 MAC：服务端做虚拟机 OUI 识别
        if (fpValueOk(gpu)) add("gpu", fpHash(gpu));
        fp += "}";
        return fp.size() > 2 ? fp : "";
    }

    // 真实电脑主机名（GetComputerNameW → UTF-8；取不到回落 "Windows-PC"）
    static std::string computerName() {
        wchar_t wbuf[MAX_COMPUTERNAME_LENGTH + 1] = {};
        DWORD wlen = MAX_COMPUTERNAME_LENGTH + 1;
        if (!GetComputerNameW(wbuf, &wlen) || wlen == 0) return "Windows-PC";
        int need = WideCharToMultiByte(CP_UTF8, 0, wbuf, (int)wlen, nullptr, 0, nullptr, nullptr);
        if (need <= 0) return "Windows-PC";
        std::string out((size_t)need, '\0');
        WideCharToMultiByte(CP_UTF8, 0, wbuf, (int)wlen, &out[0], need, nullptr, nullptr);
        return out;
    }
    static std::string generateNonce() {
        unsigned char b[8] = {};
        randBytes(b, 8);
        return bytesToHex(b, 8);          // 16 位 hex，一次性
    }
    static int64_t nowSec() {
        return std::chrono::duration_cast<std::chrono::seconds>(
            std::chrono::system_clock::now().time_since_epoch()).count();
    }

    std::string api_;
    std::string aes_key_;
    std::string salt_;
    std::string app_key_;    // 软件标识，非空时随信封外层明文携带
    std::string machine_id_;
    std::string os_info_;
    std::string client_ver_;
    std::string device_name_;   // 设备名（构造时取真实电脑主机名）
    std::string device_fp_json_;   // 硬件指纹 JSON（懒采集，登录 payload 用）
    bool device_fp_tried_ = false;

    // init 下发的会话密钥（1.2.1 节）
    std::string session_k_;      // 密钥 ID，业务信封 k 字段
    std::string session_s_;      // 会话盐，业务接口签名用

    // init 下发的登录方式 / 离线宽限公钥
    std::string login_method_;
    std::string grace_public_key_;
    std::string resp_pub_;   // 响应防伪造签名公钥（pin；空 = 不校验）
    std::string grace_prefix_ = "G1";

    // 会话状态
    State state_ = STATE_NEW;
    std::string token_;
    std::string grace_ticket_;
    int64_t grace_until_ = 0;

    // 心跳
    std::string hb_token_;
    HeartbeatCb hb_cb_;
    int hb_interval_ms_ = 0;
    int hb_default_ms_  = 60000;
    std::atomic<bool> hb_running_{false};
    std::thread hb_thread_;

    // 内置提示：最近一次 init 结果 + 可选的自定义 UI 处理器（setUiHandler）
    InitResult last_init_;
    UiHandler  ui_;
    std::wstring title_version_   = L"Nebula 版本更新";
    std::wstring title_maintain_  = L"Nebula 公告";
    std::wstring title_kick_      = L"Nebula 下线通知";
    std::wstring title_integrity_ = L"Nebula 安全校验";
    std::wstring title_flash_     = L"Nebula 公告";
    std::wstring title_popup_     = L"Nebula 公告";

    // 心跳自动弹立即公告（默认开启，见 setAutoFlash）
    bool auto_flash_ = true;

#if NEBULA_HAS_PROTECT
    // 客户端加固：最近一次 init() 内置自检的报告（默认关闭时该成员不存在）
    nebula::protect::Report protect_report_;
#endif
};

// ============================================================
//  一行接入工厂：使用顶部 cfg 配置区常量构造 Client
//  用法：auto c = nebula::createDefaultClient(machineId, "Windows", "1.0.1");
//  machine_id 留空则由 SDK 内部生成随机临时机器码
// ============================================================
inline std::unique_ptr<Client> createDefaultClient(
        const std::string& machine_id    = "",
        const std::string& os_info       = "Windows",
        const std::string& client_version = "1.0.0") {
    return std::unique_ptr<Client>(
        new Client(cfg::kApiUrl, cfg::kAesKey, cfg::kSignSalt, cfg::kAppKey.str(),
                   machine_id, os_info, client_version));
}

// ============================================================
// ★ 授权门卫（可选）：把「登录/授权判定 → 走成功/失败」整段收进壳
//   虚拟化区，接入层不再暴露一眼可 patch 的裸 if(jz/jnz) 分支。
//
//   【开放可选用 / 不用，二选一】
//     · 想用（推荐）：判定的关键跳转被 VMP 虚拟化，难被 patch。
//     · 不用：完全可跳过，你原有的返回码 if(ok) 照常工作。
//       本函数不改变任何协议与业务逻辑，纯粹是在"判定分支"外包了一层保护。
//
//   前提：工程预处理器定义 NEBULA_SHELL_ENABLE=1（SDK 才会链入
//   VMProtectSDK64.lib 并把标记展开成 VMProtectBeginVirtualization/End）；
//   未定义时 NEBULA_MARK_* 是空宏，此调用零开销、等价于普通 if。
//
//   用法：
//       bool ok = resp.code == 0;
//       nebula::guardAuth(ok,
//           [&]{ StartMain(std::move(client)); },   // 成功 -> 进主界面
//           [&]{ ShowLoginFailed(); });             // 失败 -> 提示
// ============================================================
template <typename FnOk, typename FnFail>
inline void guardAuth(bool ok, FnOk&& onOk, FnFail&& onFail) {
    NEBULA_MARK_VM_BEGIN();          // 判定分支进入壳虚拟化段（防 patch）
    if (ok) {
        onOk();
    } else {
        onFail();
    }
    NEBULA_MARK_VM_END();
}

} // namespace nebula
