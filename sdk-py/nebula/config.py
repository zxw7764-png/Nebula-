# -*- coding: utf-8 -*-
"""
Nebula SDK (Python) · 接入方配置区
------------------------------------------------------------------------------
这是接入方【唯一需要修改的文件】。
所有密钥均可在管理后台「软件管理」中查看。
"""

# ① API 入口地址（http:// 或 https://，强烈建议 https）
kApiUrl = "http://your-domain.com/api/index.php"

# ② 后台「软件管理」对应软件的 app_key
kAppKey = "SWXXXXXXXX"

# ③ 对应软件的 AES_KEY（32 位 hex）
kAesKey = "00000000000000000000000000000000"

# ④ 对应软件的 SIGN_SALT（48 位 hex）
kSignSalt = "000000000000000000000000000000000000000000000000"

# ⑤ 响应签名公钥（PEM；后台「系统设置 → 安全」可复制）。
#    ★ 未配置时 SDK 拒绝连接（防伪造服务器设计）。
kRespSignPubKey = ""

# ⑥ TLS 证书指纹锁定（SHA256 hex；留空 = 使用系统标准证书校验）。
#    说明：Python 标准栈不支持自定义证书指纹校验钩子，此配置仅保留占位，
#    建议通过 https + 系统证书校验 + 响应签名(sig) 达成等价防护。
kTlsCertSha256 = ""

# 请求超时（秒）
kTimeoutSeconds = 15

# 客户端版本号（服务端据此判断强制更新）
kClientVersion = "1.0.3"
