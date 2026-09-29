# 更新日志（CHANGELOG）

本文件记录 Nebula 网络验证系统各版本的变更。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循语义化版本（`主.次.修订`）。每次发版请在本文件顶部追加条目，并同步
`lib/bootstrap.php` 的 `NB_VERSION`；发布到版本更新系统时，把对应条目整理为 `release_notes`。

## [2.65.18] - 2026-09-30

### 修复（登录链路安全审查 P1）

- 风险自动冻结被评分缓存跳过：RiskScore::evaluate 命中缓存时无视 persist 参数直接返回，
  登录失败触发的自动冻结最长延迟 10 分钟，且攻击者停手等缓存过期后永远不触发；
  改为缓存命中同样执行 maybeFreeze（幂等，status 条件更新防重复冻结）
- 登录失败仍扣点：点数卡 per_login 扣点原在设备校验/异地拦截之前执行，
  设备数超限或异地被拦的用户点数照样被扣；扣点移至全部校验通过后、建会话前执行
- 跨软件用户名枚举预言机：账号归属其他软件时返回独立错误码 2005，
  攻击者可凭 2005/2001 差异枚举全站用户名（uk_username 全库唯一）；
  改为与「用户名或密码错误」完全一致的 2001，真实原因仅记服务端日志

### 修复（登录链路安全审查 P2）

- 单点登录并发窗口：kickUser 与 Session::create 分离执行，两机同时登录会各活一个会话；
  改为同一事务内先踢旧后建新
- 心跳旁路设备校验：不带 machine_id 的心跳请求会跳过设备解绑检查，
  管理端强制解绑后旧会话仍可心跳到 TTL；缺 machine_id 的心跳按 need_relogin 拒绝
- init 会话密钥表灌水：sign_keys 每次签发插一行（7 天 TTL），随机机器码+代理池可无限写表；
  新增机器码维度签发限流（10 次/分钟），cron 增加过期密钥兜底清理（1b 节）
- 客户端可控字符串超长致 9999：machine_id / device_name / os_info / client_ver
  按入库列宽钳制（128/128/128/32），超长不再触发严格模式报错

### 其他

- .gitignore 增加 tests/_certs/（测试证书由测试脚本运行时自生成，勿入库）

## [2.65.16] - 2026-09-30

### 修复（风险评分）

- 用户管理「风险评估」弹窗底部「关闭」按钮无效：按钮参数误写 `class`（组件识别 `cls`）且未绑定 `act`，点击无反应；已修复并绑定 closeModal
- 风险权重读取修正：RiskScore 原读 config 文件 security.risk_*（无出厂值，实际恒为 0），改为后台设置优先、出厂默认（30/20/20/30/80）兜底

### 新增（权重可视化配置）

- 系统设置 → 安全策略新增「风险评分模型」区：四维权重 + 自动冻结阈值（0~200 钳制；阈值 0 = 关闭自动冻结），仅超管可改，保存后下轮评分生效

## [2.65.15] - 2026-09-30

### 新增（风险评分模型）

- 新增 lib/RiskScore.php：四维加权评分——IP 异常 +30 / 账号失败 +20 / 设备异常 +20 / 代理异常 +30（权重 security.risk_* 可配），评分缓存 10 分钟
- 自动冻结：总分 ≥ security.risk_freeze_score（默认 80）且账号正常时置 status=2 并写日志 action=risk_freeze；每次客户端登录失败后自动重评
- 后台：用户列表新增「风险」列（≥80 高危红 / ≥40 关注黄，点击查看四维命中明细弹窗）；新增 user_risk 接口（user.read）；管理员改动状态后自动刷新该用户评分缓存

## [2.65.14] - 2026-09-30

### 修复（安全巡检重复落盘）

- SecReport::run 增加 persist 参数：后台实时查看只读不写报告文件/日志，修复每次刷新向 sec_report_*.txt 重复追加同一条异常的问题；cron 每日持久化路径不受影响

## [2.65.13] - 2026-09-30

### 调整（安全巡检独立入口）

- 侧边栏「系统」组新增独立菜单「安全巡检」页：实时巡检 + 立即巡检按钮 + 历史报告文件视图；审计日志页恢复原样

## [2.65.12] - 2026-09-30

### 新增（后台安全报告入口）

- 审计日志页顶部新增「🩺 安全巡检报告」面板：进入页面即实时巡检一次，展示近 24h 异常明细（级别/类型/明细行）与历史报告折叠视图
- 新增 `sec_report` 接口（`audit.read` 权限，自定义权限清单同步）

## [2.65.11] - 2026-09-30

### 新增（工程化 / 下一阶段基建）
- **密钥平滑轮换**：软件通信密钥支持「宽限期双钥并行」轮换（默认 7 天，
  `security.key_grace_days` 可调，0=关闭）。老客户端在宽限期内自动回落旧钥验签，
  在线用户无感知；宽限期外旧钥自动失效。与既有「立即重置」（旧客户端即时失联）并存，
  应急掐断用重置、例行轮换用平滑轮换。
  - 数据库：`nb_softwares` 新增 `aes_key_prev` / `sign_salt_prev` / `keys_rotated_at`
    （老库执行 `install/migrate_key_rotation.php`，新装 schema 已含）
- **多租户（基于代理商体系）**：软件可归属代理商（`softwares.owner_agent_id`），
  管理员可绑定为租户管理员（`admins.agent_id`）——登录总后台仅可见归属软件及其
  用户 / 卡密 / 设备数据，单条写操作越权直接拒绝（4031）。
  隔离逻辑集中在 `lib/Tenant.php`，默认拒绝：无法确定归属的数据不可见。
  （老库执行 `install/migrate_tenant.php`）
- **每日安全审计报告**（`lib/SecReport.php`，cron 第 11 节自动执行，每日一次）：
  暴力破解嫌疑（同 IP 登录失败 ≥10）、撞库嫌疑（同账号）、密钥重置追踪、
  代理商卡密突增；异常写入 `logs/sec_report_<date>.txt` 并记日志，
  `php cron.php --force-sec` 可立即执行
- **发布工具**（仅开发站，不入分发）：`deploy/make_release.php`——
  `pack` 打空白发布包（含逐文件 md5 的 MANIFEST），`diff` 对比两版本生成增量更新包
  （含 DELETED.txt 待删除清单）

## [2.65.3] - 2026-09-29

### 新增（Python SDK）
- **官方 Python SDK（`sdk-py/`）**：协议与 C++ 版同规格——加密信封通信、ES256|RS256 响应验签
  （未配置公钥拒绝连接）、password / username_code / code 三种登录自动适配、心跳接管（被踢 /
  顶号自动回登录）、完整性自校验、自动更新（仅 https 更新地址，hash+size 双校验）、弹窗/立即
  公告（已读记录 30 天清理）、功能密钥 NF1（`feature.seal` / `open_pack`）。
  内置 Pygame 桌面登录界面（440×420 无边框登录窗 + 800×500 主窗），接入方零 UI 代码；
  `nebula/config.py` 为唯一配置文件。接入文档见 [sdk-py/README.md](sdk-py/README.md)。

### 新增（功能密钥）
- **功能密钥（Feature Key）**：「下发解密密钥，不下发验证结果」的数据防破解能力。
  后台「软件管理」可为每个软件配置一把随机密钥（可一键生成，≤128 字符），**只在 login
  成功响应中下发**（`data.feature_key`，init 是免验证接口刻意不下发）；登录失败 /
  被踢 / 会话过期后密钥不出现。接入方用它加解密随程序分发的核心数据包 ——
  patch 掉登录判定分支也拿不到密钥，核心数据永远停留在密文状态。
  - 数据库：`nb_softwares.feature_key` 列（全新安装走 `install/schema.sql`；
    老库升级执行 `install/migrate_feature_key.php`）；
  - 数据包格式 `NF1`：`NF1.<b64(iv+AES-256-CBC)>.<hex(HMAC-SHA256)>`，
    encrypt-then-MAC 先验签后解密，密钥域分离派生（`|nebula-feature-aes` / `|nebula-feature-mac`）；
  - SDK：新增 `nebula/client/feature.hpp`（`seal()` 开发期加密 / `open()` 运行期解密），
    `LoginResult` 新增 `feature_key` 字段，login 解析自动填充；
  - 后台：「软件管理」编辑表单新增功能密钥输入框 + 随机生成按钮，列表返回该字段；
  - 文档：`docs/API.md` 新增「2.18 功能密钥协议」章节（NF1 格式 / PHP 侧制作示例 / 安全边界）。

### 修复（接口）
- `login.php` 成功响应中 `$sw` 未定义（软件识别后未保存引用），功能密钥等按软件
  下发的字段会被 `?? ''` 静默吞成空串 —— 已在入口处捕获 `Software::current()` 修复。


### 修复（支付）
- **微信支付 V3 回调按官方规范完全重写**（`lib/Pay.php` `wechatVerifyNotify`、`shop/wechat_notify.php`）。
  旧实现对标准 V3 通知 100% 无法工作，本次修复：
  - `AEAD_AES_256_GCM` 解密：`base64(ciphertext)` 解码后**末 16 字节为 tag**，
    `associated_data` 作 AAD、12 字节 nonce 作 IV、APIv3 密钥（32 字节）作密钥；
  - 从**解密后的 transaction** 取 `out_trade_no / amount.total / trade_state / transaction_id`
    （外层通知体并无这些字段）；
  - `Wechatpay-*` 请求头验签（验签串 `{ts}\n{nonce}\n{raw}\n`，平台证书 SHA256）；
    CGI 模式 fallback 取头时正确还原连字符（`HTTP_WECHATPAY_*` 下划线 → 连字符）；
  - `Authorization` 请求头改为官方格式
    `WECHATPAY2-SHA256-RSA2048 mchid="..",nonce_str="..",signature="..",timestamp="..",serial_no=".."`；
  - 新增 `trade_state == SUCCESS` 校验、±300 秒重放防护、APIv3 密钥长度校验；
  - 应答规范：成功 `200 + {"code":"SUCCESS"}`，失败 **5xx + FAIL JSON**（触发微信重试，
    旧实现返回 200 会被微信视为应答成功、永不重试）；`trade_no` 改记微信 `transaction_id`。

### 修复（SDK）
- `downloadUpdate()` 的 TLS 指纹锁定只对 **API 同 host** 继承（`tls_cert_sha256`）；
  跨域更新包（文件床 / CDN）不再误继承 API 指纹导致 100% 被拦截，
  仍有 WinHTTP 标准证书链校验 + 下载后强制 hash / 大小校验兜底。
- `Client::options()` 新增运行期访问器（`auto_update_optional` 等策略可在 init 前调整）。
- **内置弹窗标题改用 init 下发的软件名**（`alertTitle()`）：公告 / 版本更新 / 维护 /
  下线通知 / 安全校验共 8 处统一为「软件名 - 主题」，未取到软件名时回退「Nebula 主题」。

### 文档
- `docs/API.md` 新增「支付回调（异步通知）」章节（微信 V3 验签 / 解密 / 应答规范）。
- 新增本更新日志。

## [2.65.1] - 2026-09（服务器先行热修复）

- 仅部署于生产服务器的过渡版本（`lib/bootstrap.php` 版本号已步进，改动未回填本仓库）。
  以服务器部署记录为准；本仓库自 2.65.2 起恢复「代码-版本-日志」同步。

## [2.65.0] - 2026-09

### 新增
- **响应签名（ES256/RS256 双重验签）**：业务响应附带签名，客户端可验证响应来源与完整性。
- **双向自更新模块**：SDK `init` 下发版本信息（`version.{need_update,force_update,latest,update_url,file_hash,file_size,self_file_hash...}`），
  `autoUpdate()` 完成下载 → SHA256/大小校验 → 替换脚本 → 重启；`enforceSelfIntegrity()` 自身完整性校验。
- 数据库迁移 `install/migrations/2.sql`（对应版本 2.65.0，前置 >= 2.64.4）。
- 后台「系统更新」页：对接版本更新系统（update-system），一键下载 → SHA256 校验 → 备份 → 解压覆盖 → 版本号步进。

[2.65.3]: https://gitee.com/xinia/online-verification/commits/master
[2.65.2]: https://gitee.com/xinia/online-verification/commits/master
[2.65.1]: https://gitee.com/xinia/online-verification/commits/master
[2.65.0]: https://gitee.com/xinia/online-verification/commits/master
