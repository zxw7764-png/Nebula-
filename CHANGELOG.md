# 更新日志（CHANGELOG）

本文件记录 Nebula 网络验证系统各版本的变更。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循语义化版本（`主.次.修订`）。每次发版请在本文件顶部追加条目，并同步
`lib/bootstrap.php` 的 `NB_VERSION`；发布到版本更新系统时，把对应条目整理为 `release_notes`。

## [2.65.2] - 2026-09-29

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

[2.65.2]: https://gitee.com/xinia/online-verification/commits/master
[2.65.1]: https://gitee.com/xinia/online-verification/commits/master
[2.65.0]: https://gitee.com/xinia/online-verification/commits/master
