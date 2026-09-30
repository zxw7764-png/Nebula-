# Nebula C# SDK 完整文档

> **版本**：SDK 1.0.3  |  **目标框架**：.NET 10 (net10.0-windows)  |  **平台**：Windows x64

---

## 目录

- [1. 概述](#1-概述)
- [2. 快速开始](#2-快速开始)
- [3. 配置文件详解 (SdkConfig.cs)](#3-配置文件详解-sdkconfigcs)
- [4. 核心接口](#4-核心接口)
  - [4.1 Client 主类](#41-client-主类)
  - [4.2 初始化 (Init)](#42-初始化-init)
  - [4.3 登录 (Login)](#43-登录-login)
  - [4.4 心跳保活 (Heartbeat)](#44-心跳保活-heartbeat)
  - [4.5 登出 (Logout)](#45-登出-logout)
  - [4.6 设备管理](#46-设备管理)
  - [4.7 用户信息](#47-用户信息)
  - [4.8 公告系统](#48-公告系统)
  - [4.9 版本检查与自动更新](#49-版本检查与自动更新)
  - [4.10 离线宽限 (Grace)](#410-离线宽限-grace)
  - [4.11 功能密钥数据包 (NF1)](#411-功能密钥数据包-nf1)
  - [4.12 自身完整性校验](#412-自身完整性校验)
- [5. 完整接入示例](#5-完整接入示例)
- [6. 错误码参考](#6-错误码参考)
- [7. 文件清单](#7-文件清单)

---

## 1. 概述

Nebula SDK 是一套面向 .NET / WinForms 应用的软件授权验证客户端 SDK，提供：

| 能力 | 说明 |
|------|------|
| 加密通信 | AES-256-CBC + HMAC-SHA256 + 服务端非对称验签（ES256/RS256）三重保护 |
| 多种登录方式 | 账密、卡密直登、用户名+激活码（服务端下发 `login.method`） |
| 心跳保活 | 后台线程自动心跳，支持踢下线、强制下线、闪现公告 |
| 设备绑定 | 机器码 + 设备指纹（board/cpu/disk/bios/mac/gpu）多维识别 |
| 离线宽限 | 断网后凭签名票据继续运行，到时自动退出 |
| 自动更新 | 检测 → 下载 → SHA256 校验 → 批处理自替换 → 重启 |
| 公告系统 | 四种类型：列表、弹窗、立即闪现、列表查询 |
| 完整性自校验 | 程序文件 SHA256/MD5 + 大小校验，防二进制篡改 |
| 功能密钥包 | NF1 格式数据包，encrypt-then-MAC，用于核心数据保护 |

---

## 2. 快速开始

### 最简三行接入

```csharp
using Nebula.Sdk;

// ① 用 SdkConfig 常量创建客户端
var client = NebulaFactory.CreateDefaultClient(
    machineId: "",          // 留空自动生成（建议持久化后传入）
    osInfo: "Windows",
    clientVersion: "1.0.0"
);

// ② 初始化（init）
var init = client.Init();
if (!init.Ok) { /* 初始化失败处理 */ }

// ③ 登录
var login = client.Login("用户名", "密码");
if (login.Ok) {
    // 登录成功，token 可用
    client.StartHeartbeat(login.Token, (code, msg, hb) => {
        // 心跳回调
    });
}
```

### 前置条件

1. 在 `SdkConfig.cs` 中填入从后台获取的 6 项配置（见 [§3](#3-配置文件详解-sdkconfigcs)）
2. 项目文件 `.csproj` 引用 `System.Management` NuGet 包（反 VM WMI 查询依赖）
3. 目标框架 `net10.0-windows`（WinForms + Windows 专用 API）

---

## 3. 配置文件详解 (SdkConfig.cs)

`SdkConfig.cs` 是接入方**唯一需要修改的文件**。所有常量来自后台「软件管理」页面。

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `ApiUrl` | `const string` | ✅ | API 入口地址，必须以 `/api/index.php` 结尾 |
| `AppKey` | `const string` | ✅ | 软件标识（字母数字组合），每个软件独立 |
| `AesKey` | `const string` | ✅ | 通信密钥（32 位十六进制），用于 AES 加解密 |
| `SignSalt` | `const string` | ✅ | 签名盐值（48 位十六进制），用于 HMAC |
| `RespSignPubKey` | `const string` | ✅ | 响应签名公钥（PEM 格式），验签服务端响应 |
| `TlsCertSha256` | `const string` | ❌ | TLS 证书指纹（64 位 hex），仅 HTTPS 生效，防中间人 |
| `DebugLog` | `const bool` | ❌ | 调试日志开关（发布时置 `false`） |

### 安全建议

- ⚠ `AesKey` 等同于软件"身份证"，泄露后可解密所有通信，务必妥善保管
- ⚠ `RespSignPubKey` 为空时所有请求会返回配置错误，拒绝连接
- ⚠ `SignSalt` 泄露后可伪造请求签名，务必定期更换
- 生产环境务必配置 `TlsCertSha256`，防止中间人攻击
- 不要将含真实密钥的代码提交到公开仓库
- 密钥泄露后立即在后台重新生成并同步本文件

---

## 4. 核心接口

### 4.1 Client 主类

```csharp
public sealed class Client : IDisposable
```

**构造函数**：

```csharp
var client = new Client(new ClientOptions {
    ApiUrl = "https://example.com/api/index.php",
    AesKey = "eb32f8087805a06cf8e45e306e7a8d5f",
    SignSalt = "147ea3cc63530253a1617df4da45d7b0cc1a345f67fe2db3",
    AppKey = "SWBFE6879E94DD",
    MachineId = "",              // 留空自动生成随机值
    OsInfo = "Windows",
    ClientVersion = "1.0.0",
    ResponseSignPublicKey = "...", // PEM 公钥
    TlsCertSha256 = "...",        // 可选
    RequireResponseSignature = true,
    ConnectTimeoutMs = 8000,
    ReceiveTimeoutMs = 15000,
    UseSystemProxy = false,
    AutoUpdateEnable = true,
    AllowInsecureUpdate = false,
    AutoUpdateOptional = false,
});
```

**ClientOptions 完整字段**：

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `ApiUrl` | `string` | `""` | API 入口地址 |
| `AesKey` | `string` | `""` | 32 位 hex 通信密钥 |
| `SignSalt` | `string` | `""` | 48 位 hex 签名盐 |
| `AppKey` | `string` | `""` | 软件标识 |
| `MachineId` | `string` | `""` | 机器码，留空自动生成 |
| `OsInfo` | `string` | `"Windows"` | 操作系统信息 |
| `ClientVersion` | `string` | `"1.0.0"` | 客户端版本号 |
| `ResponseSignPublicKey` | `string` | `SdkConfig.RespSignPubKey` | 响应验签公钥 |
| `TlsCertSha256` | `string` | `SdkConfig.TlsCertSha256` | TLS 证书指纹 |
| `RequireResponseSignature` | `bool` | `true` | 是否强制验证响应签名 |
| `ConnectTimeoutMs` | `int` | `8000` | 连接超时（毫秒） |
| `ReceiveTimeoutMs` | `int` | `15000` | 接收超时（毫秒） |
| `UseSystemProxy` | `bool` | `false` | 是否使用系统代理（默认关闭，防本地代理劫持） |
| `AutoUpdateEnable` | `bool` | `true` | 是否启用自动更新 |
| `AllowInsecureUpdate` | `bool` | `false` | 是否允许 HTTP 更新（默认仅 HTTPS） |
| `AutoUpdateOptional` | `bool` | `false` | 非强制更新是否自动应用 |

**Client 属性与方法**：

| 成员 | 类型 | 说明 |
|------|------|------|
| `ConfigValid` | `bool` | 配置是否完整 |
| `ConfigError` | `string` | 配置错误描述 |
| `MachineId` | `string` | 当前机器码 |
| `DeviceName` | `string` | 主机名 |
| `ClientVersion` | `string` | 客户端版本 |
| `LoginMethod` | `string` | 服务端下发的登录方式 |
| `Options` | `ClientOptions` | 原始配置 |
| `SetUiHandler(UiHandler)` | `void` | 设置 UI 提示处理器 |
| `DefaultAlert` | `Action<string,string>?` | 全局默认弹窗（静态，未设 UiHandler 时使用） |
| `GraceTicket` | `string` | 当前离线宽限票据 |
| `GraceUntil` | `long` | 宽限到期 Unix 秒 |
| `NeedRelogin` | `bool` | 是否需要重新登录 |
| `HeartbeatRunning` | `bool` | 心跳线程是否运行中 |
| `Dispose()` | `void` | 停止心跳 |

### 4.2 初始化 (Init)

```csharp
public InitResult Init();
```

初始化是登录的前置步骤。SDK 会向服务端发送 `client_ver` 和 `machine_id`，获取：
- 服务端时间、站点名称、心跳间隔
- 注册开关、维护模式
- 登录方式（password / code / username_code）
- 版本检查信息（是否需更新、强制更新）
- 离线宽限配置（公钥、前缀、算法）
- 设备指纹开关与组件列表
- 公告列表

**InitResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否成功 |
| `Msg` | `string` | 错误消息 |
| `ServerTime` | `long` | 服务端 Unix 秒 |
| `SiteName` | `string` | 站点名称 |
| `HeartbeatInterval` | `int` | 心跳间隔（秒，默认 60） |
| `SessionTtl` | `long` | 会话 TTL |
| `RegisterEnable` | `bool` | 是否允许注册 |
| `MaintainMode` | `bool` | 是否维护中 |
| `LoginMethod` | `string` | 登录方式 |
| `NeedUpdate` | `bool` | 是否需要更新 |
| `ForceUpdate` | `bool` | 是否强制更新 |
| `Latest` | `string` | 最新版本号 |
| `MinVer` | `string` | 最低版本 |
| `UpdateUrl` | `string` | 更新包下载地址 |
| `UpdateNote` | `string` | 更新说明 |
| `FileHash` | `string` | 更新包哈希 |
| `FileSize` | `long` | 更新包大小 |
| `SelfFileHash` | `string` | 自身文件哈希（完整性校验用） |
| `SelfFileSize` | `long` | 自身文件大小 |
| `GraceEnable` | `bool` | 离线宽限开关 |
| `GraceSeconds` | `int` | 宽限秒数 |
| `GracePublicKey` | `string` | 宽限验签公钥 |
| `GraceAlgorithm` | `string` | 宽限算法 |
| `GraceKid` | `string` | 宽限密钥 ID |
| `GracePrefix` | `string` | 票据前缀（默认 G1） |
| `AppKey` | `string` | 服务端确认的 AppKey |
| `SoftwareId` | `int` | 软件 ID |
| `SoftwareName` | `string` | 软件名称 |
| `DeviceFpEnable` | `bool` | 设备指纹开关 |
| `DeviceFpComponents` | `List<string>` | 指纹组件列表 |
| `Notices` | `List<Notice>` | 公告列表 |

### 4.3 登录 (Login)

```csharp
public LoginResult Login(string account, string secret);
```

根据 `Init` 下发的 `LoginMethod` 自动适配请求格式：

| LoginMethod | account 参数 | secret 参数 | 说明 |
|-------------|-------------|-------------|------|
| `password` | 用户名 | 密码 | 账密登录（默认） |
| `code` | 卡密 | （不用） | 卡密直登，单框输入 |
| `username_code` | 用户名 | 激活码 | 用户名+激活码 |

登录成功后 SDK 会自动采集设备指纹（board/cpu/disk/bios/mac/gpu）并随请求提交。

**LoginResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否成功 |
| `Code` | `int` | 业务码（0=成功，负值=本地错误） |
| `Msg` | `string` | 消息 |
| `Token` | `string` | 会话令牌 |
| `ExpireAt` | `long` | 到期 Unix 秒 |
| `Ttl` | `long` | 有效期秒数 |
| `LoginMethod` | `string` | 实际使用的登录方式 |
| `AccountCreated` | `bool` | 是否自动创建了账号 |
| `User` | `UserInfo` | 用户信息 |
| `DeviceRisk` | `List<string>` | 设备风险标签 |
| `GraceTicket` | `string` | 离线宽限票据 |
| `GraceUntil` | `long` | 宽限到期 Unix 秒 |
| `FeatureKey` | `string` | 功能密钥（用于 NF1 数据包解密） |
| `NeedRelogin` | `bool` | 是否需要重新登录 |

**UserInfo 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `UserId` | `int` | 用户 ID |
| `Username` | `string` | 用户名 |
| `Nickname` | `string` | 昵称 |
| `VipExpire` | `long` | VIP 到期 Unix 秒（-1=永久） |
| `VipText` | `string` | VIP 文本描述 |
| `Points` | `int` | 积分 |
| `MaxDevices` | `int` | 最大设备数 |
| `Status` | `int` | 状态（1=正常） |
| `GroupId` | `int` | 用户组 ID |

### 4.4 心跳保活 (Heartbeat)

```csharp
// 手动心跳
public Response Heartbeat(string token);

// 自动心跳（后台线程）
public void StartHeartbeat(string token, HeartbeatCb callback, int intervalMs = 0);
public void StopHeartbeat();
```

**心跳回调委托**：

```csharp
public delegate void HeartbeatCb(int code, string msg, HeartbeatInfo hb);
```

**HeartbeatInfo 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Remain` | `int` | 剩余秒数（-1=未知） |
| `Online` | `bool` | 是否在线 |
| `ForceOffline` | `bool` | 是否被强制下线 |
| `HasNotice` | `bool` | 是否有新公告 |
| `NeedRelogin` | `bool` | 是否需要重新登录 |
| `Kick` | `bool` | 是否被踢下线 |
| `NeedActivate` | `bool` | 是否需要激活 |
| `NextInterval` | `int` | 下次心跳间隔（秒） |
| `GraceTicket` | `string` | 宽限票据（心跳续期） |
| `GraceUntil` | `long` | 宽限到期 |
| `FlashNotices` | `List<Notice>` | 闪现公告列表 |

**心跳线程特性**：
- 后台线程（`IsBackground = true`），不阻止进程退出
- 支持 `NextInterval` 动态调整间隔
- 自动处理闪现公告（`AutoFlash` 开关）
- 回调在心跳线程执行，UI 操作需自行 `BeginInvoke`
- `StopHeartbeat()` 等待最多 3 秒确保线程退出

**典型用法**：

```csharp
client.StartHeartbeat(login.Token, (code, msg, hb) => {
    if (hb.Kick || hb.NeedRelogin || hb.ForceOffline) {
        // 被踢下线，回登录界面
        BeginInvoke(() => { /* 处理踢下线 */ });
    }
});
```

### 4.5 登出 (Logout)

```csharp
public Response Logout(string token);
```

登出后销毁服务端会话，本地状态重置为 `Ready`。

### 4.6 设备管理

```csharp
// 查看当前账号绑定的设备列表
public Response Devices(string token);

// 解绑设备
public Response UnbindDevice(string token, string machineId = "", 
                             string password = "", bool all = false);

// 激活码激活
public Response Activate(string token, string code);
```

**UnbindDevice 参数**：

| 参数 | 说明 |
|------|------|
| `machineId` | 指定要解绑的机器码（空=解绑当前设备） |
| `password` | 解绑需要的密码验证（服务端策略） |
| `all` | `true` = 解绑所有设备 |

### 4.7 用户信息

```csharp
public Response Userinfo(string token);
```

登录后可随时查询最新用户信息。

### 4.8 公告系统

```csharp
// 查询公告
public Response GetNotices(int id = 0);
public static List<Notice> ParseNoticeList(string decrypted);

// 闪现公告（type=3，确认后不再显示）
public List<Notice> FetchFlashNotices();
public List<Notice> FlashNotices();       // 获取 + 自动弹窗 + 标记已读
public void MarkNoticeRead(long id);
public bool IsNoticeRead(long id);
public void ClearNoticeReads();
public void SetAutoFlash(bool on);

// 弹窗公告（type=2，每次登录提示）
public List<Notice> PopupNotices();
```

**公告类型**：

| Type | 说明 |
|------|------|
| 1 | 普通公告（列表展示） |
| 2 | 弹窗公告（每次登录提示） |
| 3 | 立即公告（闪现，确认后不再显示） |
| 4 | 列表公告（公告栏展示） |

**已读记录存储路径**：`%APPDATA%\NebulaSDK\notices_<app_key>.txt`，自动保留 30 天。

### 4.9 版本检查与自动更新

```csharp
// 手动版本检查
public Response CheckVersion(string version, string channel = "stable");

// 自动更新：检测 → 下载 → SHA256/大小校验 → 替换重启
public UpdateResult AutoUpdate(bool exitWhenApplied = true);

// 只下载不替换
public UpdateResult DownloadUpdate();
```

**UpdateResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `State` | `UpdateState` | 更新状态 |
| `Msg` | `string` | 消息 |
| `Version` | `string` | 新版本号 |
| `NewFile` | `string` | 下载的文件路径 |
| `VerifyHash` | `string` | 校验哈希 |
| `VerifySize` | `long` | 校验大小 |
| `Force` | `bool` | 是否强制更新 |

**UpdateState 枚举**：

| 值 | 说明 |
|----|------|
| `NoUpdate` | 已是最新 |
| `Downloaded` | 已下载待安装 |
| `NeedConfirm` | 需用户确认 |
| `Applied` | 已替换并重启 |
| `Failed` | 失败 |
| `Disabled` | 自动更新未启用 |

**安全机制**：
- 更新包必须通过 SHA256 和大小双校验，不匹配自动删除
- 默认仅允许 HTTPS 更新地址（`AllowInsecureUpdate = false`）
- 替换通过批处理脚本实现：等进程退出 → `move` → `start` → 自删
- 同 host 的 HTTPS 更新地址复用证书指纹锁定

### 4.10 离线宽限 (Grace)

```csharp
// 离线宽限票据校验
public GraceResult CheckOffline(string ticket, string token);

// 获取当前宽限信息
public string GraceTicket { get; }
public long GraceUntil { get; }
public string GetGracePublicKey();
```

**GraceResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否通过 |
| `Code` | `OfflineError` | 错误码 |
| `Msg` | `string` | 消息 |
| `RemainSec` | `int` | 剩余秒数 |
| `UntilTs` | `long` | 到期 Unix 秒 |
| `PayloadUserid` | `int` | 票据载荷用户 ID |
| `PayloadVipExpire` | `long` | 票据载荷 VIP 到期 |

**票据格式**：`G1.<payload-b64url>.<signature-b64url>`

**校验流程**（`Offline.VerifyGraceTicket`）：
1. 解析票据分段与载荷
2. 验签（ES256/RS256，签名对象 = `G1.<payload>`）
3. 机器码摘要校验（SHA256 前 16 位）
4. 会话令牌摘要校验
5. 有效期校验（容忍 ±120 秒时钟偏差）

### 4.11 功能密钥数据包 (NF1)

```csharp
// 加密（开发期使用）
string pack = Feature.Seal("核心数据明文", featureKey);

// 解密（登录拿到 feature_key 后调用）
bool ok = Feature.Open(pack, featureKey, out string data, out string error);
```

**NF1 格式**：`NF1.<base64(IV+AES-256-CBC密文)>.<HMAC-SHA256>`

**安全机制**：
- 密钥派生：AES Key = `SHA256(featureKey + "|nebula-feature-aes")`，MAC Key = `SHA256(featureKey + "|nebula-feature-mac")`
- Encrypt-then-MAC：先验签后解密，防篡改
- IV 随机生成（`RandomNumberGenerator`）
- 恒定时间比较（`ConstantTimeEquals`），防时序侧信道

### 4.12 自身完整性校验

```csharp
// 一站式完整性校验（init 成功后调用）
public bool EnforceSelfIntegrity();
```

校验当前 exe 文件的 SHA256/MD5 哈希和大小是否与服务端 `init` 下发的一致。失败时 SDK 自动弹窗提示并返回 `false`。

---


## 5. 完整接入示例

以下是从零到完整运行的接入流程（WinForms 登录窗口）：

```csharp
using Nebula.Sdk;

// ════════════════════════════════════════════════════════════════════
// 第 1 步：配置 SdkConfig.cs（唯一需修改的文件）
//   填入 ApiUrl, AppKey, AesKey, SignSalt, RespSignPubKey, TlsCertSha256
// ════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════
// 第 2 步：创建客户端（建议持久化 machineId）
// ════════════════════════════════════════════════════════════════════
var client = NebulaFactory.CreateDefaultClient(
    machineId: GetStableMachineId(),  // 注册表 MachineGuid 摘要
    osInfo: "Windows",
    clientVersion: "1.0.3"            // 与服务端版本检查对齐
);

// 设置 UI 提示处理器（可选；不设则用 DefaultAlert）
client.SetUiHandler((kind, msg) =>
{
    // kind: "integrity" | "version" | "maintain" | "kick" | "flash" | "popup"
    MessageBox.Show(msg, kind, MessageBoxButtons.OK, MessageBoxIcon.Information);
});

// ════════════════════════════════════════════════════════════════════
// 第 3 步：init → 自校验 → 版本检查
// ════════════════════════════════════════════════════════════════════
// 第 4 步：初始化（建议后台线程）
// ════════════════════════════════════════════════════════════════════
var init = client.Init();
if (!init.Ok) { /* 处理初始化失败 */ return; }

// 完整性自校验
if (!client.EnforceSelfIntegrity()) { /* 程序被篡改 */ return; }

// 维护模式提示
if (init.MaintainMode) client.MaintainAlert();

// 版本检查
if (init.ForceUpdate) client.AutoUpdate();  // 强制更新：自动下载+替换+重启
if (!client.VersionAlert()) { /* 版本过低 */ return; }

// 公告
client.PopupNotices();
client.FlashNotices();

// ════════════════════════════════════════════════════════════════════
// 第 5 步：登录
// ════════════════════════════════════════════════════════════════════
var login = client.Login(account, secret);
if (!login.Ok) { /* 处理登录失败 */ return; }

// ════════════════════════════════════════════════════════════════════
// 第 6 步：启动心跳保活
// ════════════════════════════════════════════════════════════════════
client.StartHeartbeat(login.Token, (code, msg, hb) =>
{
    if (hb.Kick || hb.NeedRelogin || hb.ForceOffline)
    {
        // 被踢下线：回登录界面
    }
});

// ════════════════════════════════════════════════════════════════════
// 第 7 步：使用功能密钥数据包（可选）
// ════════════════════════════════════════════════════════════════════
if (login.FeatureKey.Length > 0)
{
    // 用 feature_key 解密服务端下发的加密数据
    if (Feature.Open(encryptedPack, login.FeatureKey, out var data, out var err))
    {
        // data 为解密后的明文
    }
}

// ════════════════════════════════════════════════════════════════════
// 第 8 步：退出时清理
// ════════════════════════════════════════════════════════════════════
client.StopHeartbeat();
client.Logout(login.Token);
client.Dispose();
```

---

## 6. 错误码参考

### 本地错误码（`Error` 枚举，负值）

| 值 | 枚举 | 说明 |
|----|------|------|
| 0 | `Ok` | 成功 |
| -1 | `Network` | 连接失败/超时/证书指纹不匹配 |
| -2 | `Envelope` | 信封校验失败（HMAC/签名/解密） |
| -3 | `HttpStatus` | HTTP 状态码非 200 |
| -4 | `Config` | 配置缺失 |
| -5 | `Crypto` | 本地密码学操作失败 |

### 离线宽限错误码（`OfflineError` 枚举）

| 值 | 枚举 | 说明 |
|----|------|------|
| 0 | `Ok` | 通过 |
| -1 | `Signature` | 票据验签失败 |
| -2 | `Format` | 票据格式错误 |
| -3 | `Binding` | 票据与机器/会话不匹配 |
| -4 | `Expired` | 离线宽限已到期 |
| -5 | `Disabled` | 服务端未开启离线宽限 |

### 服务端业务码（正值）

| 码 | 说明 |
|----|------|
| 0 | 成功 |
| 1001 | 参数错误 |
| 1002 | 未登录或令牌无效 |
| 1003 | 令牌已过期 |
| 1004 | 权限不足 |
| 2001 | 用户名或密码错误 |
| 2002 | 账号已被封禁 |
| 2003 | 账号被锁定 |
| 2004 | 账号已过期，需激活 |
| 3001 | 卡密不存在 |
| 3002 | 卡密已被使用 |
| 3003 | 卡密已作废 |
| 3004 | 卡密已过期 |
| 3005 | 激活码尚未绑定账号 |
| 3006 | 激活码已绑定其他账号 |
| 3007 | 用户名已被注册 |
| 4001 | 设备数量已达上限 |
| 4002 | 设备未绑定 |
| 4003 | 缺少机器码 |
| 4004 | 设备已被拉黑 |
| 4005 | 设备指纹异常 |
| 4006 | 异地登录已拦截 |
| 5001 | 请求过于频繁 |
| 5002 | 签名校验失败 |
| 5003 | 请求已过期 |
| 5004 | 重复请求 |
| 6001 | 版本过低，需强制更新 |
| 6002 | 服务器维护中 |
| 9999 | 服务器内部错误 |

---

## 7. 文件清单

| 文件 | 职责 |
|------|------|
| `SdkConfig.cs` | 接入方配置区（唯一需修改的文件） |
| `NebulaClient.cs` | 客户端主类（`Client`、`ClientOptions`、`NebulaFactory`） |
| `NebulaTypes.cs` | 公共结果类型与错误码 |
| `NebulaEnvelope.cs` | 通信信封（请求加密 + 响应验签解密） |
| `NebulaCrypto.cs` | 密码学（AES/HMAC/SHA256/MD5/非对称验签） |
| `NebulaHttp.cs` | HTTP 传输（POST + TLS 证书指纹锁定） |
| `NebulaDevice.cs` | 设备身份（机器码 + 指纹采集） |
| `NebulaJson.cs` | 极简 JSON 解析器 |
| `NebulaLog.cs` | 调试日志 |
| `NebulaOffline.cs` | 离线宽限票据（验签 + 绑定校验） |
| `NebulaUpdate.cs` | 自动更新（下载 + 校验 + 自替换重启） |
| `NebulaStoreFeatureIntegrity.cs` | 已读记录 + 完整性自校验 + 功能密钥包 (NF1) |

---

> 📖 文档版本：2026-09-30 | SDK 版本：1.0.3 | 适配 .NET 10 (net10.0-windows)