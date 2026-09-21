# Nebula 网络验证 · 接口文档

所有客户端接口统一入口：

```
POST http://<域名>/api/index.php?action=<接口名>
```

（配置了 `.htaccess` 后也可用 `POST http://<域名>/api/<接口名>`）

> ### 不想手写协议？
>
> `sdk/` 目录提供**零依赖的 C++ SDK**（header-only），把 `nebula_sdk.hpp` 拖进项目即可，  
> 无需 OpenSSL / libcurl（用系统自带的 `bcrypt.dll` / `winhttp.dll`，仅 Windows + MSVC）。
>
> ```cpp
> #include "nebula_sdk.hpp"
>
> nebula::Client c("http://你的服务器/api/index.php", AES_KEY, SIGN_SALT, "machine_id");
> c.init();
> auto lr = c.login("用户名", "密码");
> c.startHeartbeat(lr.token, /* 心跳回调 */, 60000);
> ```
>
> 本文档描述的是**协议层**细节，适合需要自行实现客户端（其他语言 / 特殊需求）的场景。  



---

## 一、通信协议

### 1.1 请求格式

所有请求体为 JSON，统一信封结构：

```json
{
  "data": "<base64(iv[16] + AES-256-CBC密文)>",
  "sign": "<HMAC-SHA256 hex>",
  "t":    1726000000,
  "n":    "a1b2c3d4e5f6a7b8",
  "k":    "0f1e2d3c4b5a6978",
  "app_key": "SWxxxx"
}
```

| 字段      | 说明                                                           |
| ------ | ------------------------------------------------------------ |
| `data` | 业务参数 JSON 序列化后加密：`base64(iv[16] + 密文)`（见 1.2 节）              |
| `sign` | `HMAC-SHA256(data + "\|" + t + "\|" + n, SIGN_SALT)` 的小写 hex |
| `t`    | 秒级 Unix 时间戳，与服务端时差不得超过 `time_window`（默认 300 秒）               |
| `n`    | 随机字符串（≥8 位），一次性使用，服务端做重放校验                                   |
| `k`    | **会话密钥 ID（init 之后必填）**，见 1.2.1 节                             |
| `app_key` | **软件标识（必填）**，后台「软件管理」下发；服务端用它选定软件并使用该软件独立的 AES_KEY / SIGN_SALT，账号/卡密/设备等数据均按软件隔离。未携带或无效 → `1004`，**不再回落默认软件** |

### 1.2 加密参数

传输加密统一为 **AES-256-CBC（机密性）+ HMAC-SHA256 签名（完整性）**：

| 项目   | 值                                           |
| ---- | ------------------------------------------- |
| 算法   | AES-256-CBC + PKCS#7 填充                     |
| 密文格式 | `base64(iv[16] + 密文)`                       |
| 密钥   | `SHA256(AES_KEY)` 前 32 字节                   |
| IV   | `MD5(AES_KEY)` 前 16 字节（固定）                  |
| 完整性  | 外层 `sign` 签名（HMAC-SHA256，先验签后解密，篡改密文必然验签失败） |

> 完整性由 encrypt-then-MAC 保证：客户端先对密文签名，服务端**验签通过才解密**。

### 1.2.1 会话级签名密钥（k 字段）

为防止编译进客户端的 `SIGN_SALT` 被逆向后伪造任意请求，服务端采用**会话级密钥**：

1. 客户端调用 `init`（主盐签名，不带 `k`）→ 响应 `data` 解密后含：

```json
"session": { "k": "<16位hex密钥ID>", "s": "<48位hex会话盐>" }
```

1. 之后**所有非白名单接口**（register/login/heartbeat/activate/unbind/devices/userinfo/logout）：
   - 请求信封必须携带 `k` 字段
   - `sign` 改用会话盐 `s` 计算
   - 缺 `k`、`k` 无效或过期 → `5002`
2. 服务端**响应用「验请求所用的同一把盐」签名**：init 响应用主盐，业务响应用会话盐
3. 密钥管理：每次 `init` 重新下发（同一 `machine_id` 仅保留最新一把），7 天未续自动过期；init 时可携带旧 `k` 平滑轮换
4. 安全效果：主盐只保 `init/notice/version` 三个只读接口可用；即使主盐被 dump，也无法伪造业务请求，且换一次 init 旧密钥即作废
5. 服务端开关：`config.php` → `security.session_key_required`（默认 `true`）

### 1.3 响应格式

响应同样使用加密信封，外层额外附带明文 `code` 便于快速判断：

```json
{
  "data": "<base64(iv + 密文)>",
  "sign": "<hmac>",
  "t":    1726000000,
  "n":    "xxxx",
  "code": 0
}
```

解密 `data` 后得到业务响应：

```json
{
  "code": 0,
  "msg":  "登录成功",
  "time": 1726000000,
  "data": { }
}
```

### 1.3.1 明文白名单接口

以下接口**无需登录、无敏感数据**，支持明文直接调用（不带 `data/sign/t/n` 信封）：

| 接口        | 说明                 |
| --------- | ------------------ |
| `init`    | 客户端初始化（拉取配置/公告/版本） |
| `notice`  | 获取公告列表             |
| `version` | 版本校验               |
| `online`  | 在线人数               |

这一组接口同时还满足：允许 GET 请求、不要求会话密钥 `k`、不受最低版本强制更新拦截、  
不计入每日调用配额、维护模式下照常放行。

**响应一律加密**（不论请求是明文还是加密信封）：

- 所有接口（含白名单接口、错误响应）都返回加密信封 `{data, sign, t, n, code}`
- 白名单接口只是允许**请求**侧不带信封，响应侧始终加密，防止响应被中间人直接读取

客户端解析响应时无需区分请求方式，统一按 1.3 节的信封流程验签 + 解密即可。

> 白名单在 `config/config.php` 的 `security.plain_whitelist` 中配置。  
> 其余接口在 `enforce_crypto = true`（默认）时**必须**携带完整加密信封，  
> 否则返回 `1001 缺少必要字段 data/sign/t/n`。

### 1.4 业务状态码

| code | 含义                                |
| ---- | --------------------------------- |
| 0    | 成功                                |
| 1001 | 参数错误                              |
| 1002 | 未登录 / 令牌无效                        |
| 1003 | 令牌过期                              |
| 1004 | app_key 无效或软件已停用（卡密直登模式下调用注册也返回此码） |
| 2001 | 用户名或密码错误                          |
| 2002 | 账号已被封禁                            |
| 2003 | 账号被锁定                             |
| 2004 | 账号已过期，需激活                         |
| 3001 | 卡密不存在                             |
| 3002 | 卡密已被使用                            |
| 3003 | 卡密已作废                             |
| 3004 | 卡密已过期                             |
| 3005 | 激活码尚未绑定账号                         |
| 3006 | 该激活码已绑定其他账号（用户名与绑定账号不一致）          |
| 3007 | 该用户名已被注册，不能再绑定此激活码                |
| 4001 | 设备数量超限                            |
| 4002 | 设备未绑定 / 已被解绑                      |
| 4003 | 缺少机器码                             |
| 4004 | 设备已被拉黑                            |
| 4005 | 设备指纹异常，已拒绝登录（疑似伪造机器码 / 模拟器虚拟机）    |
| 4006 | 异地登录已拦截（已绑定设备换了 IP，需后台开启「异地登录拦截」） |
| 5001 | 请求过于频繁                            |
| 5002 | 签名校验失败                            |
| 5003 | 请求已过期                             |
| 5004 | 重复请求                              |
| 6001 | 版本过低需强制更新                         |
| 6002 | 服务器维护中                            |
| 9999 | 服务器内部错误                           |

---

## 二、客户端接口


### 2.1 init · 初始化

拉取服务器配置、公告、版本信息。**建议客户端启动第一步调用。**

**请求参数**

| 参数         | 类型     | 必填 | 说明                |
| ---------- | ------ | -- | ----------------- |
| client_ver | string | 否  | 客户端当前版本，如 `1.0.0` |
| machine_id | string | 否  | 机器码               |

**响应示例**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "server_time": 1726000000,
    "site_name": "Nebula 网络验证",
    "heartbeat_interval": 60,
    "session_ttl": 3600,
    "register_enable": true,
    "maintain_mode": false,
    "grace": {
      "enable": true,
      "seconds": 3600,
      "max_seconds": 7200,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----",
      "ticket_prefix": "G1",
      "usage": "心跳失败时，本地用 public_key 验签后可在 until 前离线运行"
    },
    "crypto": { "enforce": true, "algo": "AES-256-CBC", "sign": "HMAC-SHA256" },
    "version": {
      "client_ver": "1.0.0",
      "latest": "1.1.0",
      "min": "1.0.0",
      "need_update": true,
      "force_update": false,
      "update_url": "https://example.com/app.exe",
      "update_note": "修复若干问题"
    },
    "notices": [
      { "id": 1, "title": "欢迎使用", "content": "系统已上线", "type": 4 }
    ]
  }
}
```

> `notices` 仅下发 **列表公告**（type=4，公告栏展示用，按归属软件过滤）；弹窗公告（type=2）与立即公告（type=3）由客户端经 `notice` 接口配合 SDK `popupNotices()` / `flashNotices()` 处理。

| `data.grace` 字段           | 说明                             |
| ------------------------- | ------------------------------ |
| `enable`                  | 服务端是否开启离线宽限                    |
| `seconds` / `max_seconds` | 单次宽限时长 / 硬上限（秒）                |
| `algorithm`               | 票据签名算法，固定 `ES256`（ECDSA P-256） |
| `kid`                     | 密钥标识，轮换后变化；客户端可据此发现需要重新拉取公钥    |
| `public_key`              | **验签公钥**（PEM），客户端缓存后本地验签，无需联网  |
| `ticket_prefix`           | 票据固定前缀（`G1`），用于快速识别            |
| `usage`                   | 用法提示文案                         |

> 离线宽限票据的完整使用方式见 [2.16 离线宽限协议](#216-离线宽限协议)。

---

### 2.2 register · 注册

> 登录方式为 `code`（卡密直登）时本接口**自动关闭**，返回 `1004 当前仅支持激活码登录，无需注册`。  
> 官网侧（`/web/api.php?action=register`）行为一致。

**请求参数**

| 参数       | 类型     | 必填 | 说明                 |
| -------- | ------ | -- | ------------------ |
| username | string | 是  | 3-32 位字母/数字/下划线/中文 |
| password | string | 是  | 6-64 位             |
| email    | string | 否  | 邮箱                 |

**响应**

```json
{ "code": 0, "msg": "注册成功", "data": { "user_id": 10, "username": "testuser" } }
```

---


### 2.3 login · 登录

> **登录方式由后台「系统设置 → 登录方式」单选决定**，客户端按 `init` 下发的  
> `data.login` 规格（`method` / `fields`）直接提交对应字段。  
> 三种方式**互斥**，不存在「一次请求塞多个字段、服务端猜」的兼容逻辑：  
> 字段与该方式不匹配时直接返回 `1001`。

`init` 下发的登录规格：

```json
"login": {
  "method": "password",
  "label": "用户名 + 密码",
  "need_username": true,
  "need_password": true,
  "need_code": false,
  "fields": ["username", "password"]
}
```

| method          | 含义        | 必填字段                   |
| --------------- | --------- | ---------------------- |
| `password`      | 用户名 + 密码  | `username`, `password` |
| `username_code` | 用户名 + 激活码 | `username`, `code`     |
| `code`          | 激活码（卡密直登） | `code`                 |

**公共请求参数**

| 参数          | 类型     | 必填    | 说明                                               |
| ----------- | ------ | ----- | ------------------------------------------------ |
| machine_id  | string | **是** | 机器码，用于设备绑定                                       |
| device_name | string | 否     | 设备名称                                             |
| os_info     | string | 否     | 系统信息                                             |
| client_ver  | string | 否     | 客户端版本                                            |
| device_fp   | object | 否     | 设备指纹组件（见 [2.14 设备指纹](#214-设备指纹可选)）。不上报时行为与旧版完全一致 |

**各登录方式的字段**

| 参数       | 类型     | password | username_code | code | 说明         |
| -------- | ------ | :------: | :-----------: | :--: | ---------- |
| username | string |     ✅    |       ✅       |   —  | 用户名        |
| password | string |     ✅    |       —       |   —  | 密码         |
| code     | string |     —    |       ✅       |   ✅  | 激活码，不区分大小写 |

**激活码登录的语义（`username_code` / `code` 共用）**

| 卡密状态            | 行为                                                                         |
| --------------- | -------------------------------------------------------------------------- |
| 已绑定账号           | 直接登录该账号。`username_code` 方式下若填写的用户名与绑定账号不一致 → `3006`                        |
| 未绑定             | **建号并激活**后登录：`code` 方式自动生成用户名（`card_` + 卡密派生）；`username_code` 方式使用用户填写的用户名 |
| 未绑定 + 用户名已被注册   | 拒绝 `3007`（防止持卡人借未绑定的卡密登录进他人账号）                                             |
| 已作废 / 已过期 / 不存在 | `3003` / `3004` / `3001`                                                   |

> 注意：`code`（卡密直登）方式下注册功能会**自动关闭**（`register` 返回 `1004`），  
> 因为该方式不依赖账号体系，注册出来的账号没有可用密码。

**成功响应**

```json
{
  "code": 0,
  "msg": "登录成功",
  "data": {
    "token": "a1b2c3...",
    "expire_at": 1726003600,
    "ttl": 3600,
    "login_method": "password",
    "account_created": false,
    "user": {
      "user_id": 10,
      "username": "testuser",
      "nickname": "testuser",
      "vip_expire": 1728592000,
      "vip_text": "2026-10-11 12:00:00",
      "points": 0,
      "max_devices": 2,
      "status": 1,
      "group_id": 1
    },
    "vip": { "valid": true, "code": 0, "msg": "ok", "expire_at": 1728592000, "points": 0 },
    "device": {
      "machine_id": "a1b2c3d4",
      "auto_bound": true,
      "max_devices": 2,
      "bound_count": 1
    },
    "grace": {
      "ticket": "G1.eyJ2IjoxLCJ1IjoxMCwibSI6ImExYjJjM2Q0...<base64url>.<sig-base64url>",
      "until": 1726007200,
      "seconds": 3600,
      "issued_at": 1726003600,
      "server_time": 1726003600,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "machine_bind": "a1b2c3d4e5f6a7b8",
      "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----"
    }
  }
}
```

| 新增字段              | 说明                                                           |
| ----------------- | ------------------------------------------------------------ |
| `login_method`    | 本次登录实际生效的方式                                                  |
| `account_created` | 是否由本次登录自动建号（激活码方式首次登录为 `true`）                               |
| `grace`           | 离线宽限票据（见 [2.16 离线宽限协议](#216-离线宽限协议)）。服务端关闭该能力或账号未激活时为 `null` |

**设备超限响应（code 4001）**

```json
{
  "code": 4001,
  "msg": "设备数量已达上限",
  "data": {
    "max_devices": 1,
    "bound_count": 1,
    "devices": [ { "machine_id": "...", "device_name": "...", "last_seen": "..." } ]
  }
}
```

---


### 2.4 heartbeat · 心跳

客户端需按 `heartbeat_interval`（默认 60 秒）定时上报。

**请求参数**

| 参数         | 类型     | 必填 | 说明   |
| ---------- | ------ | -- | ---- |
| token      | string | 是  | 登录令牌 |
| machine_id | string | 是  | 机器码  |

**正常响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "online": true,
    "server_time": 1726000060,
    "next_interval": 60,
    "remain": 2591960,
    "remain_text": "30天",
    "vip_expire": 1728592000,
    "points": 0,
    "session_ttl": 3600,
    "force_offline": false,
    "has_notice": false,
    "grace": {
      "ticket": "G1.eyJ2IjoxLCJ1IjoxMCwibSI6ImExYjJjM2Q0...<base64url>.<sig-base64url>",
      "until": 1726007260,
      "seconds": 3600,
      "issued_at": 1726003660,
      "server_time": 1726003660,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "machine_bind": "a1b2c3d4e5f6a7b8"
    }
  }
}
```

**离线宽限票据字段**

| 字段                          | 类型     | 说明                                                   |
| --------------------------- | ------ | ---------------------------------------------------- |
| `ticket`                    | string | 完整票据，形如 `G1.<payload-b64url>.<sig-b64url>`；客户端原样缓存   |
| `until`                     | int    | **宽限截止时间**（Unix 秒），到此时间必须重新联网校验                      |
| `seconds`                   | int    | 本次宽限时长（秒），等于 `until - issued_at`                     |
| `issued_at` / `server_time` | int    | 签发时间 / 服务端当前时间（可用于校正本地时钟）                            |
| `algorithm`                 | string | 固定 `ES256`                                           |
| `kid`                       | string | 密钥标识，与 `init` 下发的 `kid` 对比可判断是否需重新拉公钥                |
| `machine_bind`              | string | **机器码摘要**（`sha256(machine_id)` 前 16 字节），客户端须用本地机器码核对 |
| `public_key`                | string | 仅 `login` 附带；`heartbeat` 不重复下发，客户端用缓存的那份即可           |

> `heartbeat` 每次都会**刷新**票据，客户端成功收到即覆盖旧票据；票据本身绑定当前会话令牌，  
> 因此一旦被踢下线，旧票据在最迟 `until` 之后即彻底失效（详见 [2.16](#216-离线宽限协议)）。

**被踢下线（需立即处理）**

```json
{ "code": 1002, "msg": "账号已在其他设备登录", "data": { "need_relogin": true } }
```

```json
{ "code": 1002, "msg": "账号已被强制下线", "data": { "need_relogin": true } }
```

> `1002` 按会话失效原因区分文案：`账号已在其他设备登录`＝同账号在其他设备登录顶号；  
> `账号已被强制下线`＝管理员强制下线 / 封禁 / 设备解绑等系统操作；`会话已退出`＝主动登出。

```json
{ "code": 4002, "msg": "当前设备已被解绑", "data": { "kick": true, "need_relogin": true } }
```

```json
{ "code": 2004, "msg": "账号已过期", "data": { "kick": true, "need_activate": true } }
```

> 客户端收到带 `kick` 或 `need_relogin` 的响应时，应停止业务功能并回到登录界面。

---

### 2.5 activate · 激活卡密

**请求参数**

| 参数         | 类型     | 必填 | 说明   |
| ---------- | ------ | -- | ---- |
| token      | string | 是  | 登录令牌 |
| machine_id | string | 否  | 机器码  |
| code       | string | 是  | 激活码  |

**响应**

```json
{
  "code": 0,
  "msg": "激活成功：增加时长 30天",
  "data": {
    "card_type": 1,
    "detail": "增加时长 30天",
    "user": { "user_id": 10, "vip_expire": 1731196800, "vip_text": "2026-11-10 12:00:00", "points": 0 }
  }
}
```

---

### 2.6 unbind · 解绑设备

**请求参数**

| 参数         | 类型     | 必填 | 说明                |
| ---------- | ------ | -- | ----------------- |
| token      | string | 是  | 登录令牌              |
| machine_id | string | 否  | 目标机器码，不传则解绑当前设备   |
| password   | string | 否  | 账号密码，用于二次验证       |
| all        | bool   | 否  | `true` 则解绑该账号全部设备 |

> 每账号每日最多解绑 3 次，超出需联系管理员。

**响应**

```json
{
  "code": 0,
  "msg": "设备解绑成功",
  "data": { "machine_id": "a1b2c3d4", "bound_count": 0, "devices": [] }
}
```

---

### 2.7 devices · 设备列表

**请求参数**：`token`

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "max_devices": 2,
    "bound_count": 1,
    "current": "a1b2c3d4",
    "devices": [
      {
        "id": 5,
        "machine_id": "a1b2c3d4",
        "device_name": "Windows PC",
        "os_info": "Windows 10 x64",
        "ip": "1.2.3.4",
        "status": 1,
        "status_text": "正常",
        "bind_at": "2026-09-01 10:00:00",
        "last_seen": "2026-09-10 08:30:00",
        "online": true
      }
    ]
  }
}
```

---

### 2.8 userinfo · 用户信息

**请求参数**：`token`

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "user": { },
    "group_name": "默认用户组",
    "vip": { "valid": true },
    "remain": 2591960,
    "remain_text": "30天",
    "register_time": "2026-09-01 10:00:00",
    "last_login": "2026-09-10 08:00:00",
    "device": { "max_devices": 2, "bound_count": 1 }
  }
}
```

---

### 2.9 notice · 公告

无需登录，可用 `GET`。

**请求参数**：`id`（可选，查单条）

> 仅下发「客户端公告」：`type=2` 弹窗公告（客户端弹出窗口展示）、`type=3` 立即公告（弹出展示，客户端确认后本地记为已读、不再显示）、`type=4` 列表公告（客户端公告栏展示）。
> 官网门户公告（type=1）只在官网首页展示，不下发给客户端。归属软件为 0 时全部软件通用，否则仅归属软件的客户端可见。

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "title": "系统公告",
        "content": "内容...",
        "type": 4,
        "type_text": "列表公告",
        "created_at_text": "2026-09-10 08:00:00"
      }
    ],
    "total": 1
  }
}
```

---

### 2.10 version · 版本校验

**请求参数**

| 参数      | 类型     | 必填 | 说明                            |
| ------- | ------ | -- | ----------------------------- |
| version | string | 是  | 客户端当前版本                       |
| channel | string | 否  | `stable` / `beta`，默认 `stable` |

**响应**

```json
{
  "code": 0,
  "msg": "发现新版本",
  "data": {
    "current": "1.0.0",
    "latest": "1.1.0",
    "min": "1.0.0",
    "channel": "stable",
    "need_update": true,
    "force_update": false,
    "download_url": "https://example.com/app.exe",
    "file_hash": "sha256...",
    "file_size": 10485760,
    "changelog": "修复若干问题"
  }
}
```

**完整性比对（客户端必做）**：下载更新包完成后，本地计算文件哈希/大小并与响应比对，不一致即丢弃文件并提示重新下载，不得执行：

- 文件哈希：`file_hash` 为 32 位 hex（MD5）或 64 位 hex（SHA256，按长度识别算法）
- 文件大小：`file_size` 字节（`0` = 后台未填写，跳过该项比对）

C++ SDK 已内置工具：`Bcrypt::fileHashHex(路径, 是否SHA256)` 与 `Bcrypt::fileSizeBytes(路径)`，且 `init()` 的 `version` 对象已带出 `file_hash` / `file_size`（`InitResult` 同名字段）。

**客户端完整性自校验（防篡改）**：响应中的 `self_file_hash` / `self_file_size` 是**客户端上报版本号**在「版本管理」里登记的哈希与字节数（服务端按 `software_id + client_ver + channel` 查已发布记录）。客户端启动时计算**自身 exe** 的哈希/大小与之比对，不一致即判定文件被篡改，应弹窗并拒绝运行。该版本未登记、未发布或未填哈希时，两字段为 `''` / `0`，客户端跳过校验。SDK 已内置 `nebula::verifySelfIntegrity(InitResult&)` 一键校验。

> ⚠️ 发布版本时填写的 `file_hash` / `file_size` 必须是**该版本客户端 exe 的真实值**——一旦登记并发布，所有自报该版本号的客户端都会被强制比对，值填错会把正常客户端一并拦截。

---

### 2.11 online · 在线人数

公开接口，**无需登录**，也无需会话密钥 `k`，可直接 GET 或明文调用。

**请求参数**：无

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "online": 128,
    "timeout": 180,
    "server_time": 1789024346
  }
}
```

| 字段          | 类型  | 说明                      |
| ----------- | --- | ----------------------- |
| online      | int | 当前在线会话数                 |
| timeout     | int | 在线判定窗口（秒），超过该时长未心跳即视为离线 |
| server_time | int | 服务端时间戳                  |

**统计口径**

统计的是 `nb_sessions` 中 `status = 1` 且 `last_active` 在心跳超时  
（`policy.heartbeat_timeout`，默认 180 秒）内的**会话数**，与后台首页  
「在线会话」完全一致。

> 同一账号多设备登录会分别计数。若要「去重用户数」或「在线设备数」，  
> 属于另一套口径，需要单独加接口。

**轮询建议**：客户端/官网展示用的话 30~60 秒拉一次即可，受 IP 限流保护  
（客户端 120 次/分，官网 240 次/分）。

**官网入口**

官网侧对应 `GET /web/api.php?action=online`，返回**明文 JSON**（结构同上，  
无加密信封），同样无需登录、不校验 CSRF：

```json
{"code":0,"msg":"ok","time":1789024346,"data":{"online":128,"timeout":180,"server_time":1789024346}}
```

---


### 2.12 download · 客户端下载地址（官网）

> 该接口**仅在官网侧提供**（`GET /web/api.php?action=download`）。  
> 客户端不需要单独调用它 —— 客户端从 `init` / `version` 响应的  
> `download_url` / `file_hash` / `file_size` 字段获取同一份信息。

公开接口，**无需登录**、不校验 CSRF、维护模式下照常放行，返回**明文 JSON**。

**请求参数**：无

**响应（已配置下载地址）**

```json
{
  "code": 0,
  "msg": "ok",
  "time": 1789024865,
  "data": {
    "configured": true,
    "version": "1.0.0",
    "download_url": "https://dl.example.com/nebula/NebulaMenu_1.0.0.zip",
    "file_name": "NebulaMenu_1.0.0.zip",
    "file_size": 19000000,
    "file_hash": "68fe07199e140572ed7d7e681109a5edf52d52faa98a9c96c116556aa21d2180",
    "changelog": ""
  }
}
```

| 字段           | 类型     | 说明                     |
| ------------ | ------ | ---------------------- |
| configured   | bool   | 是否已配置**可用**的下载地址       |
| version      | string | 最新发布版本号                |
| download_url | string | 下载地址；未配置（或被安全校验拒绝）时为空串 |
| file_name    | string | 从地址末段解析出的文件名，供前端另存展示   |
| file_size    | int    | 安装包字节数，`0` 表示未填写       |
| file_hash    | string | 安装包 SHA256，供客户端校验完整性   |
| changelog    | string | 更新说明                   |

**数据来源**：`nb_versions` 表 stable 渠道最新已发布（`status = 1`）记录；  
该表无记录时回落 `config.php` 的 `version.update_url`。

**安全约束**：只回传 `http://` / `https://` 开头的地址。若后台误填  
`javascript:` 等伪协议，一律按「未配置」处理（`configured = false`、  
`download_url` 置空），避免前端直接跳转执行。

> 下载地址在后台「版本管理」页填写（字段：下载地址 / 文件哈希 / 文件大小）。

> **与其他入口同源**：官网首页展示的「最新版本」与首页在线人数条上的版本号，  
> 服务端渲染与前端刷新都取自同一份数据（`Version::latest('stable')`）。  
> 因此官网首页、下载按钮文案、客户端 `version` 接口三者不会出现版本号不一致的情况。

---

### 2.13 logout · 退出

**请求参数**：`token`

---


### 2.14 设备指纹（可选）

`machine_id` 是客户端自己算的字符串，伪造成本极低：改一个字节就是"新设备"。  
`device_fp` 让客户端再上报**多个硬件组件**的特征串，服务端据此做加权指纹校验，  
用于识别机器码伪造、模拟器/虚拟机和一机多号。

> C++ SDK（`sdk/nebula_sdk.hpp`）**已内置自动采集**（WMI 硬件序列号取哈希 + 主网卡裸 MAC，
> `device_name` 自动取真实电脑主机名），使用 SDK 时无需手动构造本字段。

**上报格式**（`login` 请求中的可选字段，对象或 JSON 字符串均可）：

```json
"device_fp": {
  "board": "9f2c…",        // 主板
  "cpu":   "1a7b…",        // CPU
  "disk":  "c3d4…",        // 系统盘序列号
  "bios":  "55ee…",        // BIOS/UEFI
  "mac":   "001122334455", // 主网卡（给裸 MAC 时可用于识别虚拟机网卡）
  "gpu":   "77aa…"         // 显卡
}
```

> 每个组件填**该硬件的哈希或特征串**（服务端只做等值比较与相似度计算，  
> 不关心具体算法）。`mac` 若直接给 12 位裸 MAC，会额外匹配常见虚拟机网卡 OUI。

**组件权重与判定规则**

| 组件         | 权重 | 换掉后是否容忍             |
| ---------- | -: | ------------------- |
| `board` 主板 | 30 | ❌ 核心组件，变化即判定疑似伪造机器码 |
| `cpu` CPU  | 25 | ❌ 核心组件，变化即判定疑似伪造机器码 |
| `disk` 系统盘 | 20 | ✅ 容忍（升级 SSD 属正常）    |
| `bios`     | 15 | ✅ 容忍                |
| `mac` 网卡   | 10 | ✅ 容忍（换网卡/虚拟网卡最常见）   |
| `gpu` 显卡   | 10 | ✅ 容忍                |

- 核心组件（`board` / `cpu`，可通过 `device_fp.core_components` 调整）双方都上报时，  
  只要有一个不一致 → 记 `fp_changed`。
- 核心组件缺失时，回落到**加权相似度**与 `device_fp.drift_threshold` 比较。

**风险标记**

| 标记               | 含义        | 触发条件                                                 |
| ---------------- | --------- | ---------------------------------------------------- |
| `vm`             | 疑似模拟器/虚拟机 | 设备名/系统信息命中虚拟化关键词，或网卡 OUI 命中 VMware/VirtualBox/QEMU 等 |
| `low_entropy`    | 硬件信息过少    | 上报组件数少于 `device_fp.min_components`                   |
| `same_value`     | 组件值雷同     | 多个组件值完全相同（批量伪造特征）                                    |
| `fp_changed`     | 机器码疑似伪造   | 同一 machine_id 下核心组件发生变化                              |
| `multi_fp`       | 同账号多机器指纹  | 同一账号窗口内出现的不同指纹数超过 `max_fp_per_user`                  |
| `shared_machine` | 同一机器多账号   | 同一指纹关联的账号数超过 `max_user_per_fp`                       |

命中标记会随 `login` 响应的 `data.device.risk` 数组返回，并写入 `nb_logs`  
（`action = device_risk`）。**默认仅记录不拦截**；需要硬拦截时在 `config/config.php` 打开：

```php
'device_fp' => [
    'block_on_drift' => true,  // 疑似伪造机器码时拒绝登录（返回 4005）
    'block_vm'       => true,  // 命中模拟器/虚拟机时拒绝登录（返回 4005）
],
```

`init` 响应的 `data.device_fp` 会下发组件清单与权重，客户端可据此决定采集哪些项。

> 未上报 `device_fp` 的客户端**不受任何影响**：服务端跳过全部指纹逻辑，  
> 行为与升级前逐字节一致，因此各对接方可以按自己的节奏升级。

---

### 2.15 限流与枚举防护

除全局「单 IP 每分钟请求数」外，卡密相关接口另有三层防护：

| 维度                         | 作用                     | 配置项                                                       |
| -------------------------- | ---------------------- | --------------------------------------------------------- |
| 单 IP（`activate` / `login`） | 防止单个来源高频请求             | `rate_limit_per_min` / `login_attempt_per_min`            |
| **单卡号**                    | 防止同一张卡被反复试探、多账号轮番尝试同一卡 | `card_try_limit`（默认 8 次）  
`card_try_window`（默认 600 秒）    |
| **来源枚举**                   | 防止「每次换一个卡号」的随机枚举       | `card_miss_limit`（默认 30 次）  
`card_miss_window`（默认 600 秒） |

- 单卡限流键内只存卡密的 **SHA-256 摘要**，不落明文，限流表泄露也不会泄露卡密。
- 枚举计数只累计「卡密不存在」（`3001`）的失败，不影响正常业务。
- 生效范围：`activate`、`login`（`username_code` / `code` 两种激活码方式）、  
  `/agent/` 的激活码注册。
- 超限统一返回 `5001`。

---


### 2.16 离线宽限协议

**目的**：服务器抖动、网络波动或临时宕机时，已登录的客户端不必立刻掉线，  
可凭一张**服务端私钥签名的离线宽限票据**在本地验签后继续运行一段时间，  
避免「服务端一抖，全体用户掉线」引发投诉。

#### 票据结构

```
G1.<payload-b64url>.<signature-b64url>
```

| 段         | 说明                                                                                 |
| --------- | ---------------------------------------------------------------------------------- |
| `G1`      | 固定前缀（`ticket_prefix`），同时是签名数据的开头                                                   |
| payload   | 载荷 JSON 的 **base64url**（无填充）编码                                                     |
| signature | 对字符串 `G1.<payload-b64url>` 做 **ES256（ECDSA P-256 + SHA-256）** 签名，DER 编码后 base64url |

#### 载荷字段

| 字段  | 说明                                                     |
| --- | ------------------------------------------------------ |
| `v` | 载荷版本，当前为 `1`                                           |
| `u` | 用户 ID                                                  |
| `m` | **机器码摘要** = `sha256(machine_id)` 的十六进制前 16 位；未绑机器码时为空串 |
| `k` | **会话令牌摘要** = `sha256(token)` 的十六进制前 16 位；票据只对本次登录有效    |
| `e` | 会员到期时间（Unix 秒），`-1` 表示永久                               |
| `i` | 签发时间（Unix 秒）                                           |
| `g` | **宽限截止时间**（Unix 秒），到点即失效（可容忍 `clock_skew` 秒偏差）         |
| `d` | 宽限时长（秒）= `g - i`                                       |
| `n` | 随机数，防止票据字节完全可预测                                        |

> 载荷**不含**用户名、密码等任何敏感信息；机器码与令牌只以摘要形式出现，  
> 即使票据泄露也无法反推原始值。

#### 客户端本地验签流程

1. 从 `init`（或 `login`）拿到 `public_key`（PEM）与 `ticket_prefix`，**缓存到本地**。
2. 每次成功调用 `login` / `heartbeat` 后，用响应里的 `grace.ticket` **覆盖**本地票据。
3. 当 `heartbeat` 请求**失败**（超时、连接被拒、返回 5xx 且非业务错误）时：
   1. 校验 `grace` 处于开启状态，且本地存在票据；
   2. 按 `.` 切分得到 3 段，第一段必须等于 `G1`；
   3. 用缓存的 `public_key` 对 `G1.<payload-b64url>` 做 ES256 验签，失败 → 判定被篡改，回普通模式；
   4. base64url 解码载荷，校验 `m == sha256(本地机器码)前16位`、`k == sha256(当前token)前16位`；
   5. 用 **服务端时间** 为准（可借 `server_time` 校正本地时钟），若 `now > g + clock_skew` → 宽限已到期；
   6. 全部通过 → **允许继续离线运行**，直到 `until`；期间持续重试心跳，一旦恢复立即用新票据刷新。

**参考判断逻辑（伪代码）**

```
ok = verify_es256(pubkey, "G1." + payload_b64, sig)
     && payload.m == sha16(machine_id)
     && payload.k == sha16(token)
     && now <= payload.g + clock_skew
if ok: run_offline_until(payload.g)
else:  return_to_login()
```

#### 安全边界

| 场景                | 结果                                                                                           |
| ----------------- | -------------------------------------------------------------------------------------------- |
| 票据被篡改 / 伪造        | 验签失败，本地不采纳                                                                                   |
| 换机器               | `m` 不匹配，本地不采纳                                                                                |
| 换会话（被踢后重新登录，令牌变化） | `k` 不匹配，旧票据立即失效                                                                              |
| 账号到期              | 签发时 `until` 已被账号有效期**钳制**（`clamp_to_vip`），最迟随会员到期同时失效                                        |
| 封号 / 强制下线         | 宽限**只延长离线运行**，一旦联网即被服务端拒绝；封禁的最终生效延迟上限为 `grace.seconds`                                       |
| 服务端关闭该能力          | `grace.enable=false` 或 `grace.seconds=0`，`login` / `heartbeat` 的 `grace` 为 `null`，客户端按普通模式处理 |

#### 配置项（`config.php` → `grace`）

| 配置             | 默认                      | 说明                                     |
| -------------- | ----------------------- | -------------------------------------- |
| `enable`       | `true`                  | 总开关                                    |
| `seconds`      | `3600`                  | 单次宽限时长（秒），建议为心跳间隔的 15~30 倍；`0` = 关闭    |
| `max_seconds`  | `7200`                  | 硬上限，防止 `seconds` 配得过大；`0` = 不限制        |
| `clamp_to_vip` | `true`                  | 宽限截止是否被账号到期时间钳制（强烈建议保持 `true`）         |
| `clock_skew`   | `120`                   | 客户端时钟允许偏差（秒）                           |
| `key_file`     | `config/grace_keys.php` | 私钥文件；删除后自动重新生成（等价于**轮换密钥**，此前所有票据立即失效） |

> **密钥轮换**：只要更换了 `key_file`（或删除让其重新生成），`kid` 随之改变，  
> 客户端下次 `init` 时比对 `kid` 不一致即会重新拉取公钥；旧票据在此期间自然全部失效。

> **兼容性**：本协议为**纯增量**能力。客户端完全不处理 `grace` 字段时，  
> 行为与升级前一致（网络失败即掉线）；服务端未启用时也不会下发该字段。

---


### 2.17 官网互动接口

> 入口：`POST /web/api.php?action=<接口名>`，**明文 JSON**。  
> 认证方式为浏览器会话 Cookie（`NBWEBSID`），与后台管理会话互不干扰。  
> 写接口需带 `X-CSRF` 请求头（值取自页面注入的 `window.__NB_WEB__.csrf`）。

**接口一览**

| action     | 说明                                   |  登录 | CSRF |
| ---------- | ------------------------------------ | :-: | :--: |
| `plan`     | 价格套餐列表（仅 `status=1`，按 `sort` 倒序）     |  —  |   免  |
| `shot`     | 客户端截图列表（仅 `status=1`，只放行 http/https） |  —  |   免  |
| `msg_list` | 留言板列表（仅已审核通过的；分页，每页 10 条主楼）          |  —  |   免  |
| `msg_post` | 发布留言 / 回复                            |  ✔  |   ✔  |
| `msg_like` | 点赞 / 取消点赞（切换语义）                      |  ✔  |   ✔  |
| `fb_types` | 反馈类型选项                               |  —  |   免  |
| `fb_list`  | 我的反馈列表（仅返回本人）                        |  ✔  |   免  |
| `fb_post`  | 提交反馈                                 |  ✔  |   ✔  |

**`msg_list` 响应结构**（主楼 + 两层回复，一次取回避免 N+1）

```json
{"code":0,"data":{"list":[{"id":12,"username":"星尘","content":"…","likes":3,
  "reply_to":"","mine":false,"liked":false,"created_at":"2026-09-10 20:00:00",
  "replies":[{"id":13,"username":"官方客服","content":"…","reply_to":"星尘",
              "likes":0,"mine":false,"liked":false,"created_at":"…"}]}],
  "total":1,"page":1,"pages":1}}
```

- `mine` / `liked` 仅在登录时有意义；匿名访问恒为 `false`
- 分页作用于**主楼**条数，回复随主楼一并返回

**`msg_post` 参数与规则**

| 参数          | 说明                               |
| ----------- | -------------------------------- |
| `content`   | 必填，按**字符**计数（`mb_strlen`），上限 500 |
| `parent_id` | 选填，`0` 为主楼；回复时必须是**已审核通过**的主楼 ID |

- 落库 `status = 0`（待审核），响应 `{"id":12,"pending":true}`
- 只允许两层：`parent_id` 指向的留言其 `parent_id` 必须为 `0`，否则报「只支持两层回复」
- 限流：单账号 **3 条 / 分钟**

**`msg_like` 参数**：`id`（留言 ID，必须是已审核通过的）

- 响应 `{"liked":true,"likes":4}`；重复调用即取消点赞
- 靠 `nb_message_likes(message_id,user_id)` 唯一键兜底并发；  
  取消时用 `GREATEST(likes,1)-1`，计数永不为负
- 限流：单账号 **30 次 / 分钟**

**`fb_post` 参数与规则**

| 参数        | 说明                                                        |
| --------- | --------------------------------------------------------- |
| `type`    | `1` 功能建议 / `2` 问题反馈 / `3` 卡密订单 / `4` 其他；非法值**回落为 4** 而非报错 |
| `title`   | 必填，≤ 60 字                                                 |
| `content` | 必填，≤ 2000 字                                               |
| `contact` | 选填，≤ 100 字，便于客服回访                                         |

- 限流：单账号 **5 条 / 小时**
- 响应附带最新反馈列表，前端可直接重绘

**`fb_list` 响应结构**（状态与回复可见性）

```json
{"code":0,"data":{"list":[{"id":3,"type":2,"type_text":"问题反馈",
  "title":"…","content":"…","contact":"","status":2,"status_text":"已回复",
  "reply":"…","reply_admin":"客服A","replied_at":"2026-09-10 20:10:00",
  "created_at":"2026-09-10 20:00:00"}],"types":{"1":"功能建议","2":"问题反馈",
  "3":"卡密/订单","4":"其他"}}}
```

- 状态：`0` 待处理 / `1` 处理中 / `2` 已回复 / `3` 已关闭
- **隐私规则**：只有状态为 `2` 或 `3` 时才下发 `reply` / `reply_admin`，  
  其余状态返回空串 —— 避免前端显示「空回复框」，也避免回复草稿外泄

**审核策略：先审后显示**

留言与回复提交后均为「待审核」，只有后台通过（`status = 1`）才会出现在 `msg_list`  
与官网首屏。前端收到 `pending:true` 后会提示「已提交，通过审核后展示」，  
避免用户误以为发送失败而重复提交。

**优雅降级**：若站点尚未执行 `install/migrate_web_interact.php`，  
上述接口一律返回空列表（`list: []`），官网区块显示「暂无内容」，**不会整页报错**。  
`WebInteract::tableReady()` 按请求缓存 `SHOW TABLES` 结果，避免重复查询。

---

## 三、安全说明

1. **固定 IV 的取舍**：为便于客户端实现，IV 由密钥派生而非随机。这降低了语义安全性，但配合 HMAC 签名 + 时间戳 + nonce 防重放，已能抵御常见的抓包篡改与重放攻击。若需更高强度，可改为随机 IV 前置到密文（需同步修改客户端）。
2. **签名保护范围**：签名覆盖 `data` 与时间戳、nonce，任何篡改都会导致校验失败。
3. **时间同步**：客户端需保证系统时间准确，与服务端时差超过 `time_window` 会被拒绝（默认 300 秒）。
4. **限流策略**：
   - 单 IP 每分钟 120 次通用接口调用
   - 单 IP 每分钟 10 次登录尝试
   - 单 IP 每分钟 20 次激活尝试
   - 单账号每日 3 次设备解绑
5. **密码存储**：bcrypt（cost 10），兼容历史 md5 哈希自动升级。改密后该账号所有旧会话立即失效。
6. **离线宽限票据**：
    - 用 **ECDSA P-256（ES256）** 非对称签名，服务端只持有**私钥**，客户端只拿到**公钥**——  
      公钥泄露无法伪造票据，与「对称密钥下发到客户端」的方案有本质区别。
    - 私钥落盘在 `config/grace_keys.php`，位于部署模板已 deny 的 `config/` 目录内；  
      切勿把该文件泄露给客户端或提交到公开仓库。
    - 票面绑定 `user_id + 机器码摘要 + 会话令牌摘要 + 会员到期`，换机器 / 换会话 / 篡改任一字段均验签失败。
    - 宽限只延长**离线**运行时间，联网后立即以服务端判定为准，不会成为「永久绕过封号」的后门。
    - 需要**立即使所有已下发票据失效**时：删除 `config/grace_keys.php`（服务端会自动重新生成密钥），  
      或把 `grace.seconds` 设为 `0` 关闭该能力。
7. **缓存与聚合**：
    - 缓存只存**派生数据与计数**（在线缓冲、心跳缓冲、统计缓冲、热点配置），不缓存明文密码等敏感字段。
    - Redis 未设密码时只监听内网 / 本机（`127.0.0.1`），切勿把无密码 Redis 暴露到公网。
    - 文件缓存落在 `logs/cache`，依赖部署模板对 `logs/` 目录的整目录 deny；  
      若自行改动缓存目录，务必同步补充 deny 规则。
    - 心跳缓冲 / 统计缓冲**不落业务数据**，即使缓存整体丢失，也只会造成少量在线时长统计偏差，  
      不影响登录、激活、计费等核心链路（每个请求的判定仍实时读库）。
