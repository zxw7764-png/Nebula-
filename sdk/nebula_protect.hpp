#pragma once
// ============================================================================
// Nebula SDK 客户端加固模块（可选组件 · header-only）
// ----------------------------------------------------------------------------
// 本文件提供三类能力，**默认全部关闭**，开启方式只需在工程里定义宏：
//
//   ① 壳标记（VMProtect / Themida·WinLicense / 自定义壳）
//        宏：NEBULA_SHELL_ENABLE 1     ← 默认 0（关闭）
//        作用：在核心函数里插入壳的标记，让加壳工具把这段代码虚拟化/变异。
//              没装壳时全为空宏，零开销、零错误。
//        注意：标记只是"告诉壳要保护这里"，真正加壳仍需用 VMProtect / Themida
//              工具对编译产物做处理，本文件不会替你加壳。
//
//   ② 核心代码混淆（字符串加密 / 间接调用 / 不透明谓词）
//        宏：NEBULA_OBF_STRINGS 1      ← 默认 0（关闭）
//        作用：把明文字符串编译成密文（strings/IDA 搜不到），调用图打散。
//
//   ③ 运行时防护（反调试 + 反虚拟机/沙箱 + 完整性自检）
//        宏：NEBULA_PROTECT_LEVEL 1|2|3 ← 默认 0（关闭）
//        作用：启动 / 心跳时检测调试器、虚拟机、沙箱、API 劫持、代码补丁，
//              命中后按策略回调上报（默认只回调，不误伤）。
//
//   一键全开：  #define NEBULA_HARDEN 1        （= ③三级 + ② + ①）
//
//   在「项目属性 → C/C++ → 预处理器 → 预处理器定义」里填上述宏即可，
//   或在包含 SDK 前用 #define 定义：
//
//       #define NEBULA_HARDEN 1          // 一键全开（发布版推荐）
//       #include "nebula_sdk.hpp"       // 内部会自动包含本文件
//
//   ★ 只想先看看效果？不定义任何宏 → 全部关闭，行为与老版本完全一致。
//
// 详细说明与集成建议见 sdk/SDK_PROTECTION.md。
//
// 诚实的边界（务必理解）：
//   · 客户端加固只能抬高逆向/破解的**成本**，不能保证绝对安全。
//   · 真正的判定与封禁永远在服务端（app_key / aes_key / 会话盐 / 卡密校验 /
//     风控评分）；客户端检测结果只是"线索"。
//   · 检测项存在误报可能（尤其虚拟机 / 云电脑 / VPS 的真实用户），
//     因此默认策略是"回调上报"，而不是直接退出程序。
// ============================================================================

// ---------------------------------------------------------------------------
// 开关（默认全部关闭 —— 不定义任何宏时本文件几乎为空，零开销）
// ---------------------------------------------------------------------------
#ifndef NEBULA_HARDEN
#define NEBULA_HARDEN 0
#endif

#if NEBULA_HARDEN
#  ifndef NEBULA_PROTECT_LEVEL
#    define NEBULA_PROTECT_LEVEL 3
#  endif
#  ifndef NEBULA_OBF_STRINGS
#    define NEBULA_OBF_STRINGS 1
#  endif
#  ifndef NEBULA_SHELL_ENABLE
#    define NEBULA_SHELL_ENABLE 1
#  endif
#endif

// ③ 运行时防护等级：0=关闭(默认) 1=基础 2=标准 3=严格
#ifndef NEBULA_PROTECT_LEVEL
#define NEBULA_PROTECT_LEVEL 0
#endif

// ② 字符串混淆：0=关闭(默认)
#ifndef NEBULA_OBF_STRINGS
#define NEBULA_OBF_STRINGS 0
#endif

// ① 壳标记：0=关闭(默认) 1=开启（自动探测已安装的壳 SDK）
#ifndef NEBULA_SHELL_ENABLE
#define NEBULA_SHELL_ENABLE 0
#endif

// 命中后的动作策略：0=只记录 1=回调上报(默认) 2=降级(拒绝业务接口) 3=弹窗并退出
#ifndef NEBULA_PROTECT_ACTION
#define NEBULA_PROTECT_ACTION 1
#endif

// 时序异常阈值（毫秒），一般不需要改
#ifndef NEBULA_TIMING_THRESHOLD_MS
#define NEBULA_TIMING_THRESHOLD_MS 50.0
#endif

// 关掉编译期提醒（见文件末尾的 #pragma message）
// #define NEBULA_QUIET 1

// 本模块的运行时防护只实现了 Windows（与 SDK 一致）。
// 非 Windows 平台自动降级为"全关"，避免编译报错。
#ifndef _WIN32
#  if NEBULA_PROTECT_LEVEL >= 1
#    undef NEBULA_PROTECT_LEVEL
#    define NEBULA_PROTECT_LEVEL 0
#  endif
#endif

// ---------------------------------------------------------------------------
// 依赖（与 nebula_sdk.hpp 相同的系统库；重复 pragma 无副作用）
// ---------------------------------------------------------------------------
#ifdef _WIN32
#  ifndef NOMINMAX
#    define NOMINMAX
#  endif
#  ifndef WIN32_LEAN_AND_MEAN
#    define WIN32_LEAN_AND_MEAN
#  endif
#  include <windows.h>
#  include <tlhelp32.h>          // 进程 / 线程快照
#  include <iphlpapi.h>          // GetAdaptersInfo（网卡 MAC / 虚拟机 OUI）
#  pragma comment(lib, "advapi32.lib")   // 注册表
#  pragma comment(lib, "iphlpapi.lib")   // 网卡 MAC（虚拟机 OUI）
#  if defined(_M_IX86) || defined(_M_X64)
#    include <intrin.h>          // __cpuid
#  endif
#endif

#include <string>
#include <vector>
#include <cstdint>
#include <cstring>
#include <ctime>
#include <chrono>
#include <atomic>
#include <mutex>
#include <thread>
#include <functional>
#include <utility>      // std::forward（间接调用转发实参）

// ---------------------------------------------------------------------------
// 通用属性宏
// ---------------------------------------------------------------------------
#if defined(_MSC_VER)
#  define NEBULA_NOINLINE __declspec(noinline)
#  define NEBULA_FORCEINLINE __forceinline
#elif defined(__GNUC__) || defined(__clang__)
#  define NEBULA_NOINLINE __attribute__((noinline))
#  define NEBULA_FORCEINLINE inline __attribute__((always_inline))
#else
#  define NEBULA_NOINLINE
#  define NEBULA_FORCEINLINE inline
#endif

// ============================================================================
//  ① 壳标记（VMProtect / Themida·WinLicense / 自定义）
//  未开启或未装壳时，所有宏展开为空 —— 对代码零影响。
// ============================================================================

// 是否自动探测到某个壳 SDK（可用 __has_include 或手动指定 NEBULA_SHELL_VMP 等）
#if NEBULA_SHELL_ENABLE
#  if defined(__has_include)
#    if __has_include("VMProtectSDK.h")
#      define NEBULA_SHELL_VMP 1
#    endif
#    if __has_include(<SecureEngineSDK.h>)
#      define NEBULA_SHELL_THEMIDA 1
#    endif
#  endif
#  if defined(NEBULA_SHELL_VMP) && NEBULA_SHELL_VMP
#    include "VMProtectSDK.h"
#    ifndef NEBULA_SHELL_NO_AUTOLINK
#      ifdef _WIN64
#        pragma comment(lib, "VMProtectSDK64.lib")
#      else
#        pragma comment(lib, "VMProtectSDK32.lib")
#      endif
#    endif
#  endif
#  if defined(NEBULA_SHELL_THEMIDA) && NEBULA_SHELL_THEMIDA
#    include <SecureEngineSDK.h>
#    ifndef NEBULA_SHELL_NO_AUTOLINK
#      ifdef _WIN64
#        pragma comment(lib, "SecureEngineSDK64.lib")
#      else
#        pragma comment(lib, "SecureEngineSDK32.lib")
#      endif
#    endif
#  endif
#endif

// 自定义壳（Enigma Protector / Obsidium / ASProtect 等无统一标记头文件的壳）：
// 自行把标记包一层，下面这些 HOOK 宏就会被统一标记宏调用。
// 例：#define NEBULA_SHELL_HOOK_VM_BEGIN()  /* 你的壳的标记 */
#ifndef NEBULA_SHELL_HOOK_VM_BEGIN
#  define NEBULA_SHELL_HOOK_VM_BEGIN()
#endif
#ifndef NEBULA_SHELL_HOOK_VM_END
#  define NEBULA_SHELL_HOOK_VM_END()
#endif
#ifndef NEBULA_SHELL_HOOK_MUTATE_BEGIN
#  define NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#endif
#ifndef NEBULA_SHELL_HOOK_MUTATE_END
#  define NEBULA_SHELL_HOOK_MUTATE_END()
#endif

// ---------------- 统一标记宏（推荐一律使用这一套） ----------------
// 用法（必须成对，且在同一函数体内）：
//   std::string Client::post(...) {
//       NEBULA_MARK_VM_BEGIN();      // 这一段交给壳做虚拟化
//       ...签名/加解密核心代码...
//       NEBULA_MARK_VM_END();
//   }
//
// 强度说明：ULTRA > VM(虚拟化) > MUTATE(变异) > SCOPE(仅标记区域)
//   · ULTRA   最强最慢，只标最重要的 1~2 个函数（如登录校验、卡密校验）
//   · VM      核心校验/加解密函数
//   · MUTATE  只变异指令、性能影响小，适合常用函数
//   · SCOPE   不改变强度，只划范围（Themida 等按区域处理的壳用）
//
// 宏名对不上？（例如你的壳版本用 SECURE_BEGIN / 自定义 pragma）
//   → 定义 NEBULA_SHELL_FORCE_HOOK 1 并自行填 NEBULA_SHELL_HOOK_* 系列宏即可。
#if NEBULA_SHELL_ENABLE && defined(NEBULA_SHELL_FORCE_HOOK) && NEBULA_SHELL_FORCE_HOOK
#  define NEBULA_MARK_ULTRA_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_ULTRA_END()    NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_VM_BEGIN()     NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()       NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN() NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()   NEBULA_SHELL_HOOK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_SCOPE_END()    NEBULA_SHELL_HOOK_VM_END()
#elif defined(NEBULA_SHELL_VMP) && NEBULA_SHELL_VMP
#  define NEBULA_MARK_ULTRA_BEGIN()  VMProtectBeginUltra(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_ULTRA_END()    VMProtectEnd()
#  define NEBULA_MARK_VM_BEGIN()     VMProtectBeginVirtualization(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_VM_END()       VMProtectEnd()
#  define NEBULA_MARK_MUTATE_BEGIN() VMProtectBeginMutation(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_MUTATE_END()   VMProtectEnd()
#  define NEBULA_MARK_SCOPE_BEGIN()  VMProtectBegin(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_SCOPE_END()    VMProtectEnd()
#elif defined(NEBULA_SHELL_THEMIDA) && NEBULA_SHELL_THEMIDA
#  define NEBULA_MARK_ULTRA_BEGIN()  VM_START
#  define NEBULA_MARK_ULTRA_END()    VM_END
#  define NEBULA_MARK_VM_BEGIN()     VM_START
#  define NEBULA_MARK_VM_END()       VM_END
#  define NEBULA_MARK_MUTATE_BEGIN() MUTATE_START
#  define NEBULA_MARK_MUTATE_END()   MUTATE_END
#  define NEBULA_MARK_SCOPE_BEGIN()  CODE_START
#  define NEBULA_MARK_SCOPE_END()    CODE_END
#elif NEBULA_SHELL_ENABLE
#  define NEBULA_MARK_ULTRA_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_ULTRA_END()    NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_VM_BEGIN()     NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()       NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN() NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()   NEBULA_SHELL_HOOK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_SCOPE_END()    NEBULA_SHELL_HOOK_VM_END()
#else
#  define NEBULA_MARK_ULTRA_BEGIN()
#  define NEBULA_MARK_ULTRA_END()
#  define NEBULA_MARK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()
#  define NEBULA_MARK_SCOPE_END()
#endif

// 行号字符串化（给壳标记起个唯一名字）
#define NEBULA_STR_LINE_HELPER(n) #n
#define NEBULA_STR_LINE NEBULA_STR_LINE_HELPER(__LINE__)

// ============================================================================
//  ② 核心代码混淆
// ============================================================================

// ---------------- 字符串编译期加密 ----------------
// NEBULA_OBF_STRINGS=1 时：明文字面量在编译期被拆成密文存进 .rdata，
// 运行时按需解密到 std::string —— `strings 程序.exe` 搜不到 API 地址、参数名等。
// NEBULA_OBF_STRINGS=0 时：宏等价于 std::string(s)，零开销。
//
// 用法：
//   auto url = NEBULA_STR("http://api.example.com/api/index.php");
//   std::wstring t = NEBULA_WSTR(L"Nebula 提示");
// ⚠ 只能用于字面量，不能传变量或 std::string。
namespace nebula {
namespace obf {

// 编译期密钥派生（每个实例由 __LINE__/__COUNTER__ 生成不同密钥）
constexpr uint8_t keyAt(uint8_t k, size_t i) {
    return (uint8_t)(k + (uint8_t)(i * 31u) + 0x5Au);
}

template <typename C, size_t N, uint8_t K>
struct XorStrT {
    C enc[N];

    constexpr XorStrT(const C (&s)[N]) : enc{} {
        for (size_t i = 0; i < N; ++i) {
            enc[i] = (C)((uint32_t)s[i] ^ (uint32_t)keyAt(K, i));
        }
    }

    // 运行期解密（noinline：防止编译器把密文与明文一起折叠回常量）
    NEBULA_NOINLINE std::basic_string<C> str() const {
        std::basic_string<C> out;
        out.resize(N > 0 ? N - 1 : 0);
        for (size_t i = 0; i + 1 < N; ++i) {
            out[i] = (C)((uint32_t)enc[i] ^ (uint32_t)keyAt(K, i));
        }
        return out;
    }
};

} // namespace obf
} // namespace nebula

#if NEBULA_OBF_STRINGS
#  define NEBULA_OBF_KEY() ((uint8_t)((__LINE__ * 7 + __COUNTER__ * 13) & 0xFF))
// ⚠ 必须用 constexpr 局部对象承载密文数组：这样 XOR 在编译期完成，
//   明文字面量不会落进 .rdata。
//   曾经写成 `XorStrT<...>(s).str()`（临时对象）—— 实测 MSVC 会退化成运行期
//   循环，明文原样留在目标文件里（等价于没加密），已废弃。
#  define NEBULA_STR(s)                                                          \
      ([]{ constexpr ::nebula::obf::XorStrT<char, sizeof(s),                     \
                  NEBULA_OBF_KEY()> _nb_obf{s};                                  \
           return _nb_obf.str(); }())
#  define NEBULA_WSTR(s)                                                         \
      ([]{ constexpr ::nebula::obf::XorStrT<wchar_t,                             \
                  (sizeof(s) / sizeof(wchar_t)), NEBULA_OBF_KEY()> _nb_obf{s};   \
           return _nb_obf.str(); }())
#else
#  define NEBULA_STR(s)  (std::string(s))
#  define NEBULA_WSTR(s) (std::wstring(s))
#endif

// ---------------- 间接调用（打散调用图 / 隐藏导入表特征） ----------------
// 通过 volatile 函数指针调用，阻止编译器内联与静态识别调用关系。
// 建议用在真正敏感的位置：Verifier / 解密 / 签名。
namespace nebula {
namespace obf {

// 无返回值
// 参数类型只从 fn 推导（Args），实参按原样转发（CallArgs）——
// 这样传 std::string 左值也不会和 fn 的 const& 形参推断冲突。
template <typename... Args, typename... CallArgs>
inline void vcall(void (*fn)(Args...), CallArgs&&... args) {
    void (*volatile p)(Args...) = fn;
    void (*const local)(Args...) = p;   // 读一次 volatile 再调，避免直接调用被优化
    local(std::forward<CallArgs>(args)...);
}

// 有返回值
template <typename Ret, typename... Args, typename... CallArgs>
inline Ret vcallR(Ret (*fn)(Args...), CallArgs&&... args) {
    Ret (*volatile p)(Args...) = fn;
    Ret (*const local)(Args...) = p;
    return local(std::forward<CallArgs>(args)...);
}

} // namespace obf
} // namespace nebula

// ---------------- 不透明谓词 / 虚假分支 ----------------
// 用途：把 if 判断搅乱，让静态分析看到"两条路都可能走"。
// 备注：编译器优化后未必保留，真正的控制流保护请交给壳（VMProtect 的
//       Mutation / Obfuscator-LLVM 的 -fla -bcf -sub）。这里只是免费的一层。
namespace nebula {
namespace obf {

NEBULA_NOINLINE inline uint32_t runtimeNoise() {
#ifdef _WIN32
    // GetTickCount64（消除 C28159）：64 位无 49 天回绕；仅取低 32 位做噪声源
    return (uint32_t)(GetTickCount64() ^ (uint64_t)std::time(nullptr));
#else
    return (uint32_t)std::time(nullptr);   // 非 Windows 兜底（运行时防护本身仅 Windows 实现）
#endif
}

// 恒为 true（(a^2 ^ (a+1)^2) 的最低位必定为 1）
NEBULA_NOINLINE inline bool opaqueTrue() {
    const uint32_t a = runtimeNoise();
    const uint32_t b = a * a;
    const uint32_t c = (a + 1u) * (a + 1u);
    return ((b ^ c) & 1u) != 0u;
}

// 恒为 false
NEBULA_NOINLINE inline bool opaqueFalse() {
    return !opaqueTrue();
}

} // namespace obf
} // namespace nebula

// 用法： if (!NEBULA_OPAQUE_TRUE() || !NEBULA_OPAQUE_TRUE()) { NEBULA_DEAD_BRANCH(); }
#define NEBULA_OPAQUE_TRUE()  (::nebula::obf::opaqueTrue())
#define NEBULA_OPAQUE_FALSE() (::nebula::obf::opaqueFalse())

// NEBULA_DEAD_BRANCH：永不到达的干扰代码块（保持面积，别放副作用）
#define NEBULA_DEAD_BRANCH()                     \
    do {                                         \
        volatile uint32_t _nb_dead =              \
            (uint32_t)::nebula::obf::runtimeNoise(); \
        (void)_nb_dead;                          \
    } while (0)

// ---------------- 混淆相关的编译期建议（注释形式，不必使用） ----------------
// · MSVC：/O2 /GL /LTCG /Gy（函数级链接，便于壳丢弃顺序）
// · 不要开 /Ob0 做发布；发布版避免 /RTC* 与 /ZI（会保留调试信息与检查）
// · 发布版关闭「生成调试信息」或至少剥离 PDB（别把 .pdb 一起发出去）
// · 更彻底：Clang + Obfuscator-LLVM（-fla -bcf -sub -sobf）再叠一层壳

// ============================================================================
//  ③ 运行时防护（反调试 + 反虚拟机 / 沙箱 + 完整性自检）
//  默认关闭（NEBULA_PROTECT_LEVEL=0）时全部退化为桩函数，零开销。
// ============================================================================
namespace nebula {
namespace protect {

// 等级
enum Level { LEVEL_OFF = 0, LEVEL_BASIC = 1, LEVEL_STANDARD = 2, LEVEL_STRICT = 3 };

// 命中标记（位或）
enum Flag : unsigned {
    F_NONE        = 0u,
    F_DBG_API     = 1u << 0,    // IsDebuggerPresent / CheckRemoteDebuggerPresent
    F_DBG_PEB     = 1u << 1,    // PEB.BeingDebugged
    F_DBG_NT      = 1u << 2,    // NtQueryInformationProcess（调试端口 / 对象 / 标志）
    F_DBG_HWBP    = 1u << 3,    // 线程硬件断点 Dr0-Dr7
    F_DBG_WINDOW  = 1u << 4,    // 调试器窗口
    F_DBG_TIMING  = 1u << 5,    // 关键代码时序异常
    F_DBG_HEAP    = 1u << 6,    // 调试堆标志（NtGlobalFlag / HeapFlags）
    F_DBG_TOOL    = 1u << 7,    // 调试 / 逆向工具进程
    F_HOOK        = 1u << 8,    // 关键 API 被劫持 / 注入
    F_TAMPER      = 1u << 9,    // 受保护代码段被改写
    F_VM_CPUID    = 1u << 10,   // CPUID hypervisor 位
    F_VM_REGISTRY = 1u << 11,   // 注册表虚拟机痕迹
    F_VM_BIOS     = 1u << 12,   // BIOS / 主板厂商字段
    F_VM_MAC      = 1u << 13,   // 网卡 MAC OUI
    F_VM_PROCESS  = 1u << 14,   // 虚拟机增强工具进程
    F_VM_MODULE   = 1u << 15,   // 虚拟机 / 沙箱模块
    F_VM_FILE     = 1u << 16,   // 驱动文件痕迹
    F_SANDBOX     = 1u << 17,   // 沙箱
    F_ENV_WEAK    = 1u << 18,   // 弱环境线索（低配 / 开机时间过短）
};

// ---------------------------------------------------------------------------
// 检测报告
//   · clean       是否一切正常
//   · debugged / virtualized / sandboxed / hooked  四个分类结论
//   · score       累计风险分（仅供接入方参考 / 上报服务端）
//   · reasons     命中的具体项（中文，可直接打日志或上报）
// ---------------------------------------------------------------------------
struct Report {
    bool clean       = true;
    bool debugged    = false;
    bool virtualized = false;
    bool sandboxed   = false;
    bool hooked      = false;
    int  level       = 0;      // 本次检测使用的等级
    int  score       = 0;      // 总风险分
    int  strong_hits = 0;      // 铁证命中次数（调试器 API / PEB / NT 系列）
    int  weak_hits   = 0;      // 弱线索累计分（硬件断点 / 时序 / 窗口 / 工具进程）
    int  vm_score    = 0;      // 虚拟机线索累计分（≥60 判定为虚拟机）
    unsigned flags   = F_NONE;
    int64_t at       = 0;      // 检测时间（Unix 秒）

    // 一句话摘要
    std::string summary() const {
        if (clean) return "环境正常（风险分 " + std::to_string(score) + "）";
        std::string s = "环境异常：";
        bool first = true;
        const char* parts[4] = {nullptr, nullptr, nullptr, nullptr};
        if (debugged)    parts[0] = "检测到调试器";
        if (hooked)      parts[1] = "关键接口被劫持";
        if (virtualized) parts[2] = "运行于虚拟机";
        if (sandboxed)   parts[3] = "运行于沙箱";
        for (int i = 0; i < 4; ++i) {
            if (!parts[i]) continue;
            if (!first) s += " / ";
            s += parts[i];
            first = false;
        }
        return s + "（风险分 " + std::to_string(score) + "）";
    }

    // 命中项明细（"a | b | c"）
    std::string detail() const {
        std::string s;
        for (size_t i = 0; i < reasons.size(); ++i) {
            if (i) s += " | ";
            s += reasons[i];
        }
        return s;
    }

    std::vector<std::string> reasons;
};

// ===========================================================================
//  内部实现
// ===========================================================================
#if NEBULA_PROTECT_LEVEL >= 1
namespace detail {

inline char lowerCh(char c) { return (c >= 'A' && c <= 'Z') ? (char)(c + 'A' - 'A' + 32) : c; }

// ---------------- 安全读内存（VirtualQuery 校验，避免 SEH） ----------------
template <typename T>
inline bool safeRead(const void* addr, T& out) {
    if (!addr) return false;
    MEMORY_BASIC_INFORMATION mbi{};
    if (VirtualQuery(addr, &mbi, sizeof(mbi)) == 0) return false;
    if (mbi.State != MEM_COMMIT) return false;
    if (mbi.Protect & (PAGE_NOACCESS | PAGE_GUARD)) return false;
    const BYTE* regEnd = (const BYTE*)mbi.BaseAddress + mbi.RegionSize;
    const BYTE* p = (const BYTE*)addr;
    if (p + sizeof(T) > regEnd) return false;
    std::memcpy(&out, addr, sizeof(T));
    return true;
}

// ---------------- PEB / 堆标志 ----------------
inline BYTE* pebBase() {
#if defined(_M_X64)
    return (BYTE*)__readgsqword(0x60);
#elif defined(_M_IX86)
    return (BYTE*)__readfsdword(0x30);
#else
    return nullptr;
#endif
}

inline bool pebBeingDebugged() {
    BYTE* peb = pebBase();
    BYTE v = 0;
    return peb && safeRead(peb + 2, v) && v != 0;
}

inline uint32_t pebNtGlobalFlag() {
    BYTE* peb = pebBase();
    uint32_t v = 0;
    if (!peb) return 0;
#if defined(_M_X64)
    safeRead(peb + 0xBC, v);
#else
    safeRead(peb + 0x68, v);
#endif
    return v;
}

inline bool heapDebugFlags() {
    BYTE* peb = pebBase();
    if (!peb) return false;
    void* heap = nullptr;
#if defined(_M_X64)
    if (!safeRead(peb + 0x30, heap)) return false;
    uint32_t flags = 0, force = 0;
    if (!safeRead((BYTE*)heap + 0x14, flags)) return false;
    if (!safeRead((BYTE*)heap + 0x18, force)) return false;
#else
    if (!safeRead(peb + 0x18, heap)) return false;
    uint32_t flags = 0, force = 0;
    if (!safeRead((BYTE*)heap + 0x0C, flags)) return false;
    if (!safeRead((BYTE*)heap + 0x10, force)) return false;
#endif
    // 正常进程 ForceFlags=0；被调试时通常被置位
    return (force != 0) || ((flags & 0x70u) != 0);
}

// ---------------- NtQueryInformationProcess（动态解析，不落导入表） ----------------
typedef LONG(NTAPI* NtQipFn)(HANDLE, ULONG, PVOID, ULONG, PULONG);

inline NtQipFn ntQip() {
    static NtQipFn fn = (NtQipFn)(void*)GetProcAddress(
        GetModuleHandleA("ntdll.dll"), "NtQueryInformationProcess");
    return fn;
}

inline bool ntDebugPort() {
    NtQipFn fn = ntQip();
    if (!fn) return false;
    HANDLE port = nullptr; ULONG ret = 0;
    LONG st = fn(GetCurrentProcess(), 7 /*ProcessDebugPort*/, &port, sizeof(port), &ret);
    return st >= 0 && port != nullptr;
}

inline bool ntDebugObject() {
    NtQipFn fn = ntQip();
    if (!fn) return false;
    HANDLE h = nullptr; ULONG ret = 0;
    LONG st = fn(GetCurrentProcess(), 0x1E /*ProcessDebugObjectHandle*/, &h, sizeof(h), &ret);
    return st >= 0 && h != nullptr;
}

inline bool ntDebugFlags() {
    NtQipFn fn = ntQip();
    if (!fn) return false;
    ULONG flags = 1; ULONG ret = 0;
    LONG st = fn(GetCurrentProcess(), 0x1F /*ProcessDebugFlags*/, &flags, sizeof(flags), &ret);
    return st >= 0 && flags == 0;   // 1 = 未被调试；0 = 正在被调试
}

// ---------------- 硬件断点（扫描本进程所有线程的 DR0-Dr7） ----------------
inline bool hardwareBreakpoints() {
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPTHREAD, 0);
    if (snap == INVALID_HANDLE_VALUE) return false;
    const DWORD pid = GetCurrentProcessId();
    THREADENTRY32 te{};
    te.dwSize = sizeof(te);
    bool found = false;
    if (Thread32First(snap, &te)) {
        do {
            if (te.th32OwnerProcessID != pid) continue;
            HANDLE th = OpenThread(THREAD_GET_CONTEXT | THREAD_QUERY_INFORMATION, FALSE, te.th32ThreadID);
            if (!th) continue;
            CONTEXT ctx{};
            ctx.ContextFlags = CONTEXT_DEBUG_REGISTERS;
            if (GetThreadContext(th, &ctx)) {
                if (ctx.Dr0 || ctx.Dr1 || ctx.Dr2 || ctx.Dr3 || (ctx.Dr7 & 0xFFu)) found = true;
            }
            CloseHandle(th);
        } while (!found && Thread32Next(snap, &te));
    }
    CloseHandle(snap);
    return found;
}

// ---------------- 时序异常（取最快的一次，降低误报） ----------------
inline bool timingAnomaly(double thresholdMs) {
    LARGE_INTEGER freq{};
    if (!QueryPerformanceFrequency(&freq) || freq.QuadPart == 0) return false;
    double best = 1e18;
    for (int k = 0; k < 3; ++k) {
        LARGE_INTEGER a{}, b{};
        QueryPerformanceCounter(&a);
        volatile uint64_t x = 1;
        for (uint32_t i = 1; i < 150000u; ++i) x = x * 2654435761u + i;
        (void)x;
        QueryPerformanceCounter(&b);
        double ms = (double)(b.QuadPart - a.QuadPart) * 1000.0 / (double)freq.QuadPart;
        if (ms > 0 && ms < best) best = ms;
    }
    return best > thresholdMs;
}

// ---------------- 调试器窗口（只查窗口类名与专有标题，避免误伤 Qt 应用） ----------------
inline bool debuggerWindow() {
    static const char* kClasses[] = {
        "OLLYDBG", "ID", "WinDbgFrameClass", "ProcessHacker", "Cheat Engine", "GBDY6.80",
    };
    for (size_t i = 0; i < sizeof(kClasses) / sizeof(kClasses[0]); ++i) {
        if (FindWindowA(kClasses[i], nullptr)) return true;
    }
    static const char* kTitles[] = {
        "x64dbg", "x32dbg", "OllyDbg", "IDA", "Immunity Debugger",
        "Cheat Engine", "Process Hacker", "System Informer", "HTTP Debugger",
    };
    for (size_t i = 0; i < sizeof(kTitles) / sizeof(kTitles[0]); ++i) {
        if (FindWindowA(nullptr, kTitles[i])) return true;
    }
    return false;
}

// ---------------- 进程扫描 ----------------
// 固定用 W 版 API：tlhelp32.h 在定义了 UNICODE 的工程里会把 PROCESSENTRY32/
// Process32First 宏重定向到 W 版（并不存在 PROCESSENTRY32A），
// 这里直接用 W 版 + 手工把宽字符压成 ASCII 小写，UNICODE 开关都能编译。
// 调试器/工具进程名都是 ASCII，非 ASCII 字符映射成 '?' 不影响匹配。
inline bool processRunning(const char* const* names, size_t count) {
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snap == INVALID_HANDLE_VALUE) return false;
    PROCESSENTRY32W pe{};
    pe.dwSize = sizeof(pe);
    bool found = false;
    if (Process32FirstW(snap, &pe)) {
        do {
            char lower[64] = {};
            const wchar_t* src = pe.szExeFile;
            size_t i = 0;
            for (; src[i] && i + 1 < sizeof(lower); ++i) {
                const wchar_t c = src[i];
                lower[i] = lowerCh((c < 128) ? (char)c : '?');
            }
            for (size_t k = 0; k < count; ++k) {
                if (std::strstr(lower, names[k])) { found = true; break; }
            }
        } while (!found && Process32NextW(snap, &pe));
    }
    CloseHandle(snap);
    return found;
}

inline bool debugToolProcess() {
    static const char* kTools[] = {
        "x64dbg", "x32dbg", "ollydbg", "ida64", "idaq64", "idaq", "ida.exe",
        "windbg", "immunitydebugger", "cheatengine", "procmon", "procexp",
        "scylla", "lordpe", "pebrowse", "importrec", "frida", "httpdebugger",
        "charles", "fiddler", "wireshark", "tcpview", "apimonitor", "rohitab",
    };
    return processRunning(kTools, sizeof(kTools) / sizeof(kTools[0]));
}

inline bool vmToolProcess() {
    static const char* kTools[] = {
        "vboxservice", "vboxtray", "vboxcontrol", "vmwaretray", "vmwareuser",
        "vmtoolsd", "vmwaretray", "xenservice", "xenagent", "qemu-ga",
        "joeboxserver", "joeboxcontrol", "prl_tools", "prl_cc", "sandboxie",
    };
    return processRunning(kTools, sizeof(kTools) / sizeof(kTools[0]));
}

// ---------------- 模块（dll）扫描 ----------------
inline bool moduleLoaded(const char* name) { return GetModuleHandleA(name) != nullptr; }

inline bool sandboxModuleLoaded() {
    static const char* kMods[] = {
        "SbieDll.dll",      // Sandboxie
        "api_log.dll",      // Cuckoo / 沙箱监控
        "dir_watch.dll",
        "wpespy.dll",
        "pstorec.dll",
        "vmcheck.dll",      // VirtualBox 客户端检查
    };
    for (size_t i = 0; i < sizeof(kMods) / sizeof(kMods[0]); ++i) {
        if (moduleLoaded(kMods[i])) return true;
    }
    return false;
}

inline bool injectModuleLoaded() {
    static const char* kMods[] = {
        "frida-agent-32.dll", "frida-agent-64.dll", "frida-agent.dll",
        "frida-gadget.dll", "HookLibrary.dll", "nb_inject.dll",
    };
    for (size_t i = 0; i < sizeof(kMods) / sizeof(kMods[0]); ++i) {
        if (moduleLoaded(kMods[i])) return true;
    }
    return false;
}

// ---------------- 关键 API 首字节是否被 inline hook ----------------
inline bool apiPatched(const char* moduleName, const char* funcName) {
    HMODULE m = GetModuleHandleA(moduleName);
    if (!m) return false;
    BYTE* p = (BYTE*)(void*)GetProcAddress(m, funcName);
    if (!p) return false;
    BYTE b0 = 0, b1 = 0;
    if (!safeRead(p, b0)) return false;
    if (b0 == 0xE9 || b0 == 0xEB || b0 == 0xE8 || b0 == 0xEA || b0 == 0x68) return true;
    if (b0 == 0xFF && safeRead(p + 1, b1)) {
        if (b1 == 0x25 || b1 == 0xE0 || b1 == 0xE6) return true;
    }
    return false;
}

inline int patchedApiCount() {
    // 只查"本应由 SDK 直接调用、且正常实现不会以 jmp 开头"的接口
    struct Entry { const char* mod; const char* fn; };
    static const Entry kApis[] = {
        { "bcrypt.dll",   "BCryptEncrypt" },
        { "bcrypt.dll",   "BCryptDecrypt" },
        { "bcrypt.dll",   "BCryptVerifySignature" },
        { "bcrypt.dll",   "BCryptHashData" },
        { "winhttp.dll",  "WinHttpSendRequest" },
        { "winhttp.dll",  "WinHttpReceiveResponse" },
        { "kernel32.dll", "IsDebuggerPresent" },
        { "kernel32.dll", "GetTickCount" },
        { "ntdll.dll",    "NtQueryInformationProcess" },
    };
    int n = 0;
    for (size_t i = 0; i < sizeof(kApis) / sizeof(kApis[0]); ++i) {
        if (apiPatched(kApis[i].mod, kApis[i].fn)) ++n;
    }
    return n;
}

// ---------------- 注册表 ----------------
inline bool regKeyExists(HKEY root, const char* sub) {
    static const REGSAM kViews[2] = { KEY_WOW64_64KEY, KEY_WOW64_32KEY };
    for (int i = 0; i < 2; ++i) {
        HKEY k = nullptr;
        if (RegOpenKeyExA(root, sub, 0, KEY_READ | kViews[i], &k) == ERROR_SUCCESS) {
            RegCloseKey(k);
            return true;
        }
    }
    return false;
}

inline bool regValueContains(HKEY root, const char* sub, const char* value, const char* needle) {
    static const REGSAM kViews[2] = { KEY_WOW64_64KEY, KEY_WOW64_32KEY };
    for (int i = 0; i < 2; ++i) {
        HKEY k = nullptr;
        if (RegOpenKeyExA(root, sub, 0, KEY_READ | kViews[i], &k) != ERROR_SUCCESS) continue;
        char buf[512] = {};
        DWORD cb = sizeof(buf) - 1, type = 0;
        LONG st = RegQueryValueExA(k, value, nullptr, &type, (LPBYTE)buf, &cb);
        RegCloseKey(k);
        if (st != ERROR_SUCCESS) continue;
        for (char* p = buf; *p; ++p) *p = lowerCh(*p);
        if (std::strstr(buf, needle)) return true;
    }
    return false;
}

inline bool vmRegistryPresent() {
    if (regKeyExists(HKEY_LOCAL_MACHINE, "SOFTWARE\\VMware, Inc.\\VMware Tools")) return true;
    if (regKeyExists(HKEY_LOCAL_MACHINE, "SOFTWARE\\Oracle\\VirtualBox Guest Additions")) return true;
    static const char* kSvcs[] = {
        "SYSTEM\\CurrentControlSet\\Services\\VBoxGuest",
        "SYSTEM\\CurrentControlSet\\Services\\VBoxMouse",
        "SYSTEM\\CurrentControlSet\\Services\\VBoxSF",
        "SYSTEM\\CurrentControlSet\\Services\\vmci",
        "SYSTEM\\CurrentControlSet\\Services\\vmhgfs",
        "SYSTEM\\CurrentControlSet\\Services\\vmmouse",
        "SYSTEM\\CurrentControlSet\\Services\\xenevtchn",
        "SYSTEM\\CurrentControlSet\\Services\\qemu-ga",
    };
    for (size_t i = 0; i < sizeof(kSvcs) / sizeof(kSvcs[0]); ++i) {
        if (regKeyExists(HKEY_LOCAL_MACHINE, kSvcs[i])) return true;
    }
    return false;
}

inline bool vmBiosStrings() {
    const char* kSys = "HARDWARE\\DESCRIPTION\\System\\BIOS";
    static const char* kManu[] = {
        "vmware", "virtualbox", "innotek", "qemu", "xen", "parallels",
        "bochs", "amazon ec2", "google compute engine", "openstack", "nutanix",
    };
    for (size_t i = 0; i < sizeof(kManu) / sizeof(kManu[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSys, "SystemManufacturer", kManu[i])) return true;
    }
    static const char* kProd[] = {
        "virtual machine", "vmware virtual platform", "virtualbox", "kvm",
        "standard pc (qemu)", "xen", "virtualbox", "parallels virtual platform",
    };
    for (size_t i = 0; i < sizeof(kProd) / sizeof(kProd[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSys, "SystemProductName", kProd[i])) return true;
    }
    return false;
}

// ---------------- CPUID hypervisor 位 ----------------
inline bool cpuidHypervisor() {
#if defined(_M_IX86) || defined(_M_X64)
    int regs[4] = {0, 0, 0, 0};
    __cpuid(regs, 1);
    return ((unsigned)regs[2] & 0x80000000u) != 0;
#else
    return false;
#endif
}

// ---------------- 网卡 MAC OUI ----------------
inline bool macOuiVirtual() {
    ULONG size = 0;
    if (GetAdaptersInfo(nullptr, &size) != ERROR_BUFFER_OVERFLOW || size == 0) return false;
    std::vector<BYTE> buf(size);
    PIP_ADAPTER_INFO head = (PIP_ADAPTER_INFO)buf.data();
    if (GetAdaptersInfo(head, &size) != ERROR_SUCCESS) return false;
    for (PIP_ADAPTER_INFO p = head; p; p = p->Next) {
        if (p->AddressLength < 3) continue;
        const BYTE* m = p->Address;
        const unsigned oui = ((unsigned)m[0] << 16) | ((unsigned)m[1] << 8) | m[2];
        switch (oui) {
            case 0x000569:  // VMware
            case 0x000C29:
            case 0x001C14:
            case 0x005056:
            case 0x080027:  // VirtualBox
            case 0x00155D:  // Hyper-V
            case 0x525400:  // QEMU / KVM
            case 0x00163E:  // Xen
            case 0x001C42:  // Parallels
            case 0x005069:  // Parallels
            case 0x000FFE:  // 通用虚拟机
                return true;
            default:
                break;
        }
    }
    return false;
}

// ---------------- 驱动文件痕迹 ----------------
inline bool vmDriverFile() {
    static const char* kFiles[] = {
        "C:\\Windows\\System32\\drivers\\vmmouse.sys",
        "C:\\Windows\\System32\\drivers\\vmhgfs.sys",
        "C:\\Windows\\System32\\drivers\\VBoxMouse.sys",
        "C:\\Windows\\System32\\drivers\\VBoxGuest.sys",
        "C:\\Windows\\System32\\drivers\\VBoxSF.sys",
        "C:\\Windows\\System32\\drivers\\VBoxVideo.sys",
        "C:\\Windows\\System32\\drivers\\SbieDrv.sys",
        "C:\\Windows\\System32\\drivers\\prl_boot.sys",
    };
    for (size_t i = 0; i < sizeof(kFiles) / sizeof(kFiles[0]); ++i) {
        if (GetFileAttributesA(kFiles[i]) != INVALID_FILE_ATTRIBUTES) return true;
    }
    return false;
}

// ---------------- 低配 / 开机过短（沙箱常见特征，弱线索） ----------------
inline bool lowSpecMachine() {
    SYSTEM_INFO si{};
    GetSystemInfo(&si);
    MEMORYSTATUSEX ms{};
    ms.dwLength = sizeof(ms);
    bool memLow = false;
    if (GlobalMemoryStatusEx(&ms)) memLow = (ms.ullTotalPhys < 2ull * 1024ull * 1024ull * 1024ull);
    const int w = GetSystemMetrics(SM_CXSCREEN);
    const int h = GetSystemMetrics(SM_CYSCREEN);
    const bool screenSmall = (w > 0 && h > 0 && w <= 1024 && h <= 768);
    return (si.dwNumberOfProcessors <= 1) || memLow || screenSmall;
}

inline bool shortUptime() {
    // GetTickCount64（消除 C28159；64 位无 49 天回绕，不依赖 _WIN32_WINNT 版本宏）
    return GetTickCount64() < 300000ull;   // < 5 分钟
}

// ---------------- WDAG / 沙箱账号 ----------------
inline bool wdagAccount() {
    char buf[128] = {};
    DWORD n = GetEnvironmentVariableA("USERNAME", buf, sizeof(buf));
    if (n == 0) return false;
    std::string s(buf);
    for (size_t i = 0; i < s.size(); ++i) s[i] = lowerCh(s[i]);
    return s.find("wdagutilityaccount") != std::string::npos;
}

// ---------------- 受保护代码段补丁检测 ----------------
constexpr int kMaxRegions = 16;

struct Region {
    const void* addr = nullptr;
    size_t      len  = 0;
    uint64_t    hash = 0;
};

inline Region* regionSlots() { static Region slots[kMaxRegions]; return slots; }
inline std::atomic<int>& regionCount() { static std::atomic<int> n{0}; return n; }

inline uint64_t fnv1a(const void* p, size_t n) {
    uint64_t h = 1469598103934665603ull;
    const BYTE* b = (const BYTE*)p;
    for (size_t i = 0; i < n; ++i) {
        h ^= (uint64_t)b[i];
        h *= 1099511628211ull;
    }
    return h;
}

inline bool readRange(const void* addr, size_t len, std::vector<BYTE>& out) {
    out.clear();
    const BYTE* p = (const BYTE*)addr;
    size_t remain = len;
    while (remain > 0) {
        MEMORY_BASIC_INFORMATION mbi{};
        if (VirtualQuery(p, &mbi, sizeof(mbi)) == 0) return false;
        if (mbi.State != MEM_COMMIT) return false;
        if (mbi.Protect & (PAGE_NOACCESS | PAGE_GUARD)) return false;
        const BYTE* regEnd = (const BYTE*)mbi.BaseAddress + mbi.RegionSize;
        if (regEnd <= p) return false;
        size_t chunk = (size_t)(regEnd - p);
        if (chunk > remain) chunk = remain;
        out.insert(out.end(), p, p + chunk);
        p += chunk;
        remain -= chunk;
    }
    return true;
}

inline bool guardRegion(const void* addr, size_t len) {
    if (!addr || len == 0) return false;
    std::vector<BYTE> buf;
    if (!readRange(addr, len, buf)) return false;
    const uint64_t h = fnv1a(buf.data(), buf.size());
    const int n = regionCount().load();
    for (int i = 0; i < n; ++i) {
        Region& r = regionSlots()[i];
        if (r.addr == addr && r.len == len) { r.hash = h; return true; }
    }
    if (n >= kMaxRegions) return false;
    regionSlots()[n].addr = addr;
    regionSlots()[n].len  = len;
    regionSlots()[n].hash = h;
    regionCount().store(n + 1);
    return true;
}

inline bool verifyRegion(const void* addr, size_t len) {
    const int n = regionCount().load();
    for (int i = 0; i < n; ++i) {
        const Region& r = regionSlots()[i];
        if (r.addr != addr || r.len != len) continue;
        std::vector<BYTE> buf;
        if (!readRange(addr, len, buf)) return false;
        return fnv1a(buf.data(), buf.size()) == r.hash;
    }
    return true;   // 未登记 → 视为通过
}

inline bool anyRegionTampered() {
    const int n = regionCount().load();
    for (int i = 0; i < n; ++i) {
        if (!verifyRegion(regionSlots()[i].addr, regionSlots()[i].len)) return true;
    }
    return false;
}

} // namespace detail
#endif  // NEBULA_PROTECT_LEVEL >= 1

// ===========================================================================
//  对外 API
// ===========================================================================

// ---------------- 开关（运行时） ----------------
inline std::atomic<bool>& enabledRef() {
    static std::atomic<bool> v{ (NEBULA_PROTECT_LEVEL >= 1) ? true : false };
    return v;
}
inline bool  enabled()            { return enabledRef().load(); }
inline void  setEnabled(bool on)  { enabledRef().store(on && (NEBULA_PROTECT_LEVEL >= 1)); }

inline std::atomic<int>& levelRef() {
    static std::atomic<int> v{ NEBULA_PROTECT_LEVEL };
    return v;
}
inline int  level() { return levelRef().load(); }
inline void setLevel(int lv) {
    if (lv < 0) lv = 0;
    if (lv > (int)NEBULA_PROTECT_LEVEL) lv = (int)NEBULA_PROTECT_LEVEL;   // 不允许超过编译期上限
    levelRef().store(lv);
}

inline std::atomic<int>& actionRef() { static std::atomic<int> v{ NEBULA_PROTECT_ACTION }; return v; }
inline int  action()          { return actionRef().load(); }
inline void setAction(int a)  { actionRef().store(a); }

// 「降级」状态：action>=2 命中后置位，接入方（或 Client）据此拒绝业务请求
inline std::atomic<bool>& degradedRef() { static std::atomic<bool> v{false}; return v; }
inline bool degraded()                  { return degradedRef().load(); }
inline void resetDegraded()             { degradedRef().store(false); }

// 回调（action=1 默认策略下就是通过它上报；可接服务端、日志、自家风控）
inline std::function<void(const Report&)>& callbackRef() {
    static std::function<void(const Report&)> v;
    return v;
}
inline void setCallback(std::function<void(const Report&)> cb) { callbackRef() = std::move(cb); }

// 最近一次报告（线程安全）
inline std::mutex& reportMutex() { static std::mutex m; return m; }
inline Report& lastRef() { static Report r; return r; }

inline Report lastReport() {
    std::lock_guard<std::mutex> lk(reportMutex());
    return lastRef();
}

inline void setLast(const Report& r) {
    std::lock_guard<std::mutex> lk(reportMutex());
    lastRef() = r;
}

// ---------------- 执行一次检测 ----------------
#if NEBULA_PROTECT_LEVEL >= 1

namespace detail {

enum Kind { K_DBG_STRONG, K_DBG_WEAK, K_VM, K_SANDBOX, K_HOOK, K_ENV_WEAK };

inline void addHit(Report& r, unsigned flag, int weight, Kind kind, const char* why) {
    r.flags |= flag;
    r.score += weight;
    if (kind == K_DBG_STRONG) r.strong_hits += 1;
    else if (kind == K_DBG_WEAK) r.weak_hits += weight;
    else if (kind == K_VM) r.vm_score += weight;
    r.reasons.push_back(why);
}

inline void runChecks(int lv, Report& r) {
    // ---- 反调试：基础（等级 1）----
    if (lv >= 1) {
        if (IsDebuggerPresent())
            addHit(r, F_DBG_API, 100, K_DBG_STRONG, "IsDebuggerPresent=真");
        if (pebBeingDebugged())
            addHit(r, F_DBG_PEB, 100, K_DBG_STRONG, "PEB.BeingDebugged 已置位");
        if ((pebNtGlobalFlag() & 0x70u) != 0)
            addHit(r, F_DBG_HEAP, 45, K_DBG_WEAK, "PEB.NtGlobalFlag 呈调试堆标志");
        if (heapDebugFlags())
            addHit(r, F_DBG_HEAP, 35, K_DBG_WEAK, "进程堆标志异常（调试堆）");
    }
    // ---- 反调试：进阶（等级 2）----
    if (lv >= 2) {
        BOOL remote = FALSE;
        if (CheckRemoteDebuggerPresent(GetCurrentProcess(), &remote) && remote)
            addHit(r, F_DBG_API, 100, K_DBG_STRONG, "存在远程调试器");
        if (ntDebugPort())
            addHit(r, F_DBG_NT, 100, K_DBG_STRONG, "ProcessDebugPort 非空");
        if (ntDebugObject())
            addHit(r, F_DBG_NT, 100, K_DBG_STRONG, "ProcessDebugObjectHandle 非空");
        if (ntDebugFlags())
            addHit(r, F_DBG_NT, 80, K_DBG_STRONG, "ProcessDebugFlags=0（被调试）");
        if (hardwareBreakpoints())
            addHit(r, F_DBG_HWBP, 70, K_DBG_WEAK, "线程存在硬件断点(Dr0-Dr7)");
        if (debugToolProcess())
            addHit(r, F_DBG_TOOL, 60, K_DBG_WEAK, "运行着调试/逆向工具进程");
    }
    // ---- 反调试：严格（等级 3）----
    if (lv >= 3) {
        if (debuggerWindow())
            addHit(r, F_DBG_WINDOW, 60, K_DBG_WEAK, "检测到调试器窗口");
        if (timingAnomaly(NEBULA_TIMING_THRESHOLD_MS))
            addHit(r, F_DBG_TIMING, 30, K_DBG_WEAK, "关键代码执行时序异常（疑似单步/断点）");
    }

    // ---- 注入 / API 劫持 / 代码补丁 ----
    if (lv >= 2) {
        if (injectModuleLoaded())
            addHit(r, F_HOOK, 70, K_HOOK, "检测到注入框架模块（frida 等）");
        const int patched = patchedApiCount();
        if (patched >= 2)
            addHit(r, F_HOOK, 45, K_HOOK, "多个关键 API 入口被改写（疑似 inline hook）");
        else if (patched == 1)
            addHit(r, F_HOOK, 20, K_ENV_WEAK, "有 1 个关键 API 入口被改写（可能是杀软/监控）");
    }
    if (lv >= 2 && anyRegionTampered())
        addHit(r, F_TAMPER, 90, K_HOOK, "受保护代码段被改写（补丁/内存改动）");

    // ---- 环境：基础（等级 1）----
    if (lv >= 1) {
        if (cpuidHypervisor())
            addHit(r, F_VM_CPUID, 30, K_VM, "CPUID 报告 hypervisor 位（Hyper-V/VBS 也会置位，仅供参考）");
        if (vmRegistryPresent())
            addHit(r, F_VM_REGISTRY, 35, K_VM, "注册表存在虚拟机驱动/工具痕迹");
        if (vmBiosStrings())
            addHit(r, F_VM_BIOS, 40, K_VM, "BIOS/主板厂商字段为虚拟机");
        if (macOuiVirtual())
            addHit(r, F_VM_MAC, 45, K_VM, "网卡 MAC 属于虚拟网卡厂商（OUI）");
    }
    // ---- 环境：标准（等级 2）----
    if (lv >= 2) {
        if (vmToolProcess())
            addHit(r, F_VM_PROCESS, 30, K_VM, "运行着虚拟机增强工具进程");
        if (moduleLoaded("vmcheck.dll") || moduleLoaded("SbieDll.dll"))
            addHit(r, F_VM_MODULE, 30, K_VM, "加载了虚拟机/沙箱模块");
        if (sandboxModuleLoaded())
            addHit(r, F_SANDBOX, 60, K_SANDBOX, "检测到沙箱环境（Sandboxie/Cuckoo 等）");
        if (wdagAccount())
            addHit(r, F_SANDBOX, 60, K_SANDBOX, "运行在 WDAG 隔离环境（USERNAME=WDAGUtilityAccount）");
    }
    // ---- 环境：严格（等级 3，弱线索只计分不判定）----
    if (lv >= 3) {
        if (vmDriverFile())
            addHit(r, F_VM_FILE, 25, K_VM, "存在虚拟机驱动文件痕迹");
        if (lowSpecMachine())
            addHit(r, F_ENV_WEAK, 15, K_ENV_WEAK, "机器配置异常偏低（CPU/内存/分辨率）");
        if (shortUptime())
            addHit(r, F_ENV_WEAK, 20, K_ENV_WEAK, "系统开机时间过短（<5 分钟）");
    }
}

} // namespace detail

// 执行一次完整检测（enabled()=false 或 level=0 时直接返回"正常"）
inline Report scan() {
    Report r;
    r.level = level();
    r.at    = (int64_t)std::time(nullptr);
    if (!enabled() || r.level <= 0) return r;

    detail::runChecks(r.level, r);

    r.debugged    = (r.strong_hits > 0) || (r.weak_hits >= 90);
    r.virtualized = (r.vm_score >= 60);
    r.sandboxed   = (r.flags & F_SANDBOX) != 0;
    r.hooked      = (r.flags & F_HOOK) != 0;
    r.clean       = !(r.debugged || r.virtualized || r.sandboxed || r.hooked);
    setLast(r);
    return r;
}

#else   // NEBULA_PROTECT_LEVEL == 0：桩实现（零开销）

inline Report scan() {
    Report r;
    r.level = 0;
    r.at    = (int64_t)std::time(nullptr);
    return r;
}
inline bool guardCode(const void*, size_t) { return false; }
inline bool verifyGuardedCode() { return true; }

#endif  // NEBULA_PROTECT_LEVEL >= 1

#if NEBULA_PROTECT_LEVEL >= 1
// 登记一段核心代码做补丁检测（例：把某个校验函数的地址与长度传进来）
// 建议在程序启动时调用一次：
//   protect::guardCode((const void*)&MyVerifier::run, 256);
inline bool guardCode(const void* addr, size_t len) { return detail::guardRegion(addr, len); }
inline bool verifyGuardedCode() { return !detail::anyRegionTampered(); }
#endif

// ---------------- 命中后的处置 ----------------
// 返回 true  = 可以继续（action <= 1，仅记录/上报）
// 返回 false = 不建议继续（action >= 2，已置「降级」态）
// action == 3 时不会返回：弹窗后直接退出进程
inline std::wstring utf8ToWide(const std::string& s) {
    if (s.empty()) return {};
    int n = MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), nullptr, 0);
    if (n <= 0) return {};
    std::wstring w((size_t)n, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), &w[0], n);
    return w;
}

inline bool enforce(const Report& r) {
    if (r.clean) { setLast(r); return true; }
    setLast(r);

    // 写调试输出（DebugView 可见，便于自测排错）
    std::string line = "[Nebula] " + r.summary();
    if (!r.reasons.empty()) line += "  >>  " + r.detail();
    line += "\n";
    OutputDebugStringA(line.c_str());

    std::function<void(const Report&)> cb = callbackRef();
    if (cb) cb(r);                            // 默认策略下接入方在这里上报服务端

    const int act = action();
    if (act >= 2) {
        if (act >= 3) {
            const std::wstring msg = utf8ToWide(r.summary() + "\n\n" + r.detail());
            MessageBoxW(nullptr, msg.c_str(), L"Nebula 安全提示", MB_ICONERROR | MB_OK);
            ExitProcess(0xE0000001u);
        }
        degradedRef().store(true);
        return false;
    }
    return true;
}

// 检测 + 处置 一步到位（Client::init 内部就是调它）
inline Report scanAndEnforce(bool* may_continue = nullptr) {
    Report r = scan();
    const bool ok = enforce(r);
    if (may_continue) *may_continue = ok;
    return r;
}

// ---------------- 后台巡检 ----------------
// 命中后：优先用 cb 上报；未提供 cb 时走 enforce() 的策略
inline std::atomic<bool>& watchRef() { static std::atomic<bool> v{false}; return v; }
// 故意用裸指针：进程退出时若未 stopWatchdog()，静态 std::thread 析构会 terminate
inline std::thread*& watchThreadPtr() { static std::thread* p = nullptr; return p; }

inline void watchdogLoop(int interval_ms, std::function<void(const Report&)> cb) {
    while (watchRef().load()) {
        int slept = 0;
        while (slept < interval_ms && watchRef().load()) {
            std::this_thread::sleep_for(std::chrono::milliseconds(200));
            slept += 200;
        }
        if (!watchRef().load()) break;
        Report r = scan();
        if (r.clean) continue;
        if (cb) cb(r);
        else if (!enforce(r)) break;
    }
}

inline void startWatchdog(int interval_ms = 5000,
                          std::function<void(const Report&)> cb = nullptr) {
#if NEBULA_PROTECT_LEVEL >= 1
    if (!enabled() || level() <= 0) return;
    if (watchRef().load()) return;
    if (interval_ms < 1000) interval_ms = 1000;
    watchRef().store(true);
    std::thread*& p = watchThreadPtr();
    if (p) { delete p; p = nullptr; }          // 理论上不会走到
    p = new std::thread(watchdogLoop, interval_ms, std::move(cb));
#else
    (void)interval_ms;
    (void)cb;
#endif
}

inline void stopWatchdog() {
    if (!watchRef().load()) return;
    watchRef().store(false);
    std::thread*& p = watchThreadPtr();
    if (p) {
        if (p->joinable()) p->join();
        delete p;
        p = nullptr;
    }
}

} // namespace protect
} // namespace nebula

// ============================================================================
//  编译期提醒：只在"三项加固全关"时提示一次（定义 NEBULA_QUIET 可静音）
// ============================================================================
#if (NEBULA_PROTECT_LEVEL == 0) && (NEBULA_OBF_STRINGS == 0) && (NEBULA_SHELL_ENABLE == 0) \
    && !defined(NEBULA_QUIET)
#  ifdef _MSC_VER
#    pragma message("Nebula SDK: 客户端加固当前【未启用】(默认)。发布前建议看 sdk/SDK_PROTECTION.md，按需定义 NEBULA_HARDEN=1 或 NEBULA_PROTECT_LEVEL / NEBULA_OBF_STRINGS / NEBULA_SHELL_ENABLE。")
#  endif
#endif
