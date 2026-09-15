# Nebula 网络验证

一套基于 PHP + MySQL 的网络验证（授权）系统后端，提供客户端 API 与管理后台 API，适用于 C++ / C# / Python / 易语言等任意客户端对接。

## 📚 文档中心

全部功能文档统一放在 **`docs/`** 文件夹（C++ SDK 的接入与加固文档随 SDK 放在 **`sdk/`**），点标题跳转：

| 文档 | 说明 |
| --- | --- |
| [docs/API.md](docs/API.md) | 客户端 API 完整接口文档（协议、加签、各接口字段、离线宽限协议） |
| [docs/API_RAW_EXAMPLES.md](docs/API_RAW_EXAMPLES.md) | 请求 / 响应原始报文示例（手写协议对接逐字节参照） |
| [sdk/SDK.md](sdk/SDK.md) | C++ SDK 接入文档（初始化 / 登录 / 心跳 / 内置提示 / 完整性自校验） |
| [sdk/SDK_PROTECTION.md](sdk/SDK_PROTECTION.md) | C++ SDK 客户端加固指南（壳标记 / 代码混淆 / 反调试 / 反虚拟机，**默认关闭，按需开启**） |
| [docs/TEMPLATE.md](docs/TEMPLATE.md) | 界面模板开发文档（目录规范 / 小游戏 / 交互音效 / 布局与自定义区块） |
| [docs/TEMPLATE_OLD.md](docs/TEMPLATE_OLD.md) | 模板开发文档旧版（历史归档，仅供对照） |

> 界面模板使用与后台可视化编辑（换肤 / 布局与自定义区块 / 小游戏参数）的操作入口在管理后台
> 「官网运营 → 界面模板 / 官网内容 / 小游戏与排行榜」，开发规范见 docs/TEMPLATE.md。


## 功能一览

| 模块        | 说明                                             |
| --------- | ---------------------------------------------- |
| 🔐 登录验证   | 账号密码登录，bcrypt 加密，登录失败锁定，防爆破                    |
| 💓 心跳机制   | 客户端定时上报，服务端实时下发剩余时长、踢下线指令；高频写入经缓存聚合后批量落库 |
| 🛰️ 离线宽限  | 服务端签发 ECDSA 离线票据，客户端本地验签，服务器抖动时允许短暂离线运行 |
| ⚡ 缓存层     | 统一缓存抽象（扩展 Redis / 内置 RESP 套接字 / 文件三级降级），热点数据与统计缓冲外置 |
| 🎫 激活码    | 时长卡 / 点数卡 / 次数卡 / 永久卡，批量生成、导出、作废               |
| 🖥️ 设备绑定  | 机器码绑定，设备数上限，自动绑定，用户自助解绑，管理端强制解绑；可选多硬件加权指纹（漂移容忍 / 模拟器虚拟机识别 / 一机多号）                |
| 👤 用户管理   | 增删改查、封禁冻结、重置密码、强制下线、清空设备                       |
| 🤝 代理商分销  | 独立后台 + 激活码自助注册，按卡类型配置额度/单价与激活用户组；充值卡密自助续费，卡密归属可对账 |
| 📢 公告系统   | 普通 / 弹窗 / 重要三级，支持定时生效                          |
| 🔄 版本验证   | 最低版本强制更新，多渠道（stable/beta），更新包哈希校验              |
| 📊 API 统计 | 按天 / 按接口 / 按 IP 聚合，调用量、失败率、平均耗时                |
| 📉 数据大屏   | 实时在线数、激活曲线、代理销量排行、卡密类型分布，纯 SVG 零依赖图表，30s 自动刷新 |
| 📈 留存复购   | D1/D3/D7 队列留存、用户与代理复购率、DAU/WAU/MAU 活跃分层、充值趋势   |
| 📝 日志风控   | 全量操作日志，异常 IP 识别，请求限流，单卡尝试限流与卡密枚举防护                           |
| 👥 用户组    | 分组管理，差异化设备数上限；可按卡类型指定激活后归属                           |
| 🛒 发卡商城   | 内置发卡网 `/shop/`（易支付 / 码支付 / V免签自动发货 / 人工收款），商品化发卡（系统卡密 / 外部卡密池导入）、6 种商品展示样式、分类页签、订单批量处理；支持跳转外链模式 |
| 🌐 官网门户   | `/web/` 独立官网：注册登录 / 激活 / 设备管理 / 留言板 / 用户反馈 / 价格套餐 / 购买商家 / 效果展示，文案与主题色、背景图后台可配 |
| 🧩 多软件分站 | 多软件各自独立官网（`?app=` 识别），分站文案 / Logo / 主题色 / 背景图 / 模板可单独覆盖总站；总站白页模式严格分流 |
| 🎨 界面模板   | 官网与发卡网各内置 7 套 UI 模板（小清新 / 极简留白 / 樱花 / 午夜蓝金 / 素雅纸感 / 动漫霓虹 / 可爱马卡龙），一键换肤，优先于主题色 |
| 🛡️ IP 黑名单 | 单 IP / CIDR 段（IPv4/IPv6）黑名单，命中后全站所有页面与接口以自定义错误页拦截 |
| 🗂️ 文件管理   | 文件完整性基准校验（sha256 全站比对）+ Webshell 特征挂马扫描 + 受保护文件查看/删除 |
| 🔒 通信加密   | AES-256-CBC + HMAC-SHA256 签名 + 时间戳 + nonce 防重放 |

---


## 目录结构

```
yanzheng/
├── api/                    客户端 API
│   ├── index.php           统一入口（路由 + 加密解析 + 限流）
│   └── handlers/           各接口实现
│       ├── init.php        初始化
│       ├── register.php    注册
│       ├── login.php       登录
│       ├── heartbeat.php   心跳
│       ├── activate.php    激活卡密
│       ├── unbind.php      解绑设备
│       ├── devices.php     设备列表
│       ├── userinfo.php    用户信息
│       ├── notice.php      公告
│       ├── version.php     版本校验
│       └── logout.php      退出
├── admin/                  管理后台
│   ├── home.php            页面入口（输出 HTML 骨架 + 注入运行时参数）
│   ├── index.php           API 入口（路由 + 认证 + CSRF + 限流）
│   ├── AdminAuth.php       管理员认证类
│   ├── assets/             前端资源（按模块拆分）
│   │   ├── css/main.css    样式表
│   │   └── js/
│   │       ├── app.js          应用入口（登录/启动）
│   │       ├── core/
│   │       │   ├── api.js      请求封装（含 CSRF、下载）
│   │       │   ├── state.js    全局状态与运行时配置
│   │       │   ├── ui.js       提示/模态框/分页/批量选择
│   │       │   ├── util.js     通用工具函数
│   │       │   ├── chart.js    纯 SVG 图表（面积/折线/排行/环形，零依赖）
│   │       │   └── router.js   菜单/路由/页面注册
│   │       └── pages/          各功能页面（一页一文件）
│   │           ├── dashboard.js  数据概览
│   │           ├── bigscreen.js  数据大屏（实时在线/曲线/代理排行）
│   │           ├── analytics.js  留存复购分析（D1/D3/D7、复购、活跃分层）
│   │           ├── stat.js       API 统计
│   │           ├── user.js       用户管理（批量/导入导出）
│   │           ├── card.js       卡密管理（批量/编辑/详情/关联商品生成）
│   │           ├── batch.js      卡密批次
│   │           ├── device.js     设备管理（批量/拉黑）
│   │           ├── device_ban.js 设备拉黑名单
│   │           ├── session.js    在线会话（批量踢出）
│   │           ├── agent.js      代理商管理（按类型额度/单价、启停、充值）
│   │           ├── agent_code.js 代理商激活码（生成/编辑/启停，含按类型预设）
│   │           ├── software.js   软件管理（多软件分站）
│   │           ├── notice.js     公告管理
│   │           ├── version.js    版本管理
│   │           ├── group.js      用户组
│   │           ├── portal_web.js 官网内容（总站 + 分软件覆盖：文案/主题色/背景/模板）
│   │           ├── message.js    留言板审核
│   │           ├── feedback.js   用户反馈处理
│   │           ├── plan.js       价格套餐 / 发卡商品陈列
│   │           ├── screenshot.js 客户端截图
│   │           ├── seller.js     购买商家
│   │           ├── shop.js       发卡订单（批量发货/关闭/删除）
│   │           ├── shop_goods.js 发卡商品（分类归属/外部卡密导入/批量）
│   │           ├── shop_setting.js 发卡网配置（开关/支付/外观/界面模板）
│   │           ├── files.js      文件管理（完整性校验/Webshell 扫描）
│   │           ├── log.js        操作日志
│   │           ├── audit.js      审计日志（变更明细）
│   │           ├── setting.js    系统设置（页签式）
│   │           └── profile.js    个人中心
│   └── handlers/           各管理接口
├── agent/                  代理商后台（独立入口，与 admin/ 互不可见）
│   ├── index.php           页面入口（登录页 + 激活码注册页 + 主界面骨架）
│   ├── api.php             API 入口（路由 + 认证 + CSRF + 限流）
│   ├── inc/session.php     会话引导（NBAGSID，页面与接口共用）
│   ├── assets/             前端资源（css/main.css + js/agent.js，自包含）
│   └── handlers/           各代理接口（登录/注册/生成/卡密/批次/改密）
├── web/                    官网门户（注册/激活/留言板/反馈/套餐/截图）
│   ├── index.php           官网首页（多软件 ?app= 分站识别 + 总站白页）
│   ├── api.php             官网 API 入口
│   ├── inc/portal.php      引导文件（站点信息 / webSetting 分软件覆盖取值）
│   └── assets/             前端资源（site.css 深空样式 + ui-templates.css 界面模板）
├── shop/                   发卡网前台（内置商店 / 外链 302 跳转）
│   ├── index.php           商店页（6 种商品展示样式 + 界面模板换肤）
│   ├── api.php             商店 API 入口（下单/查询/登录）
│   ├── notify.php          支付异步回调（易支付等）
│   └── assets/             前端资源（shop.css + ui-templates.css 界面模板）
├── lib/                    核心库
│   ├── bootstrap.php       引导文件（所有入口必须先加载）
│   ├── Config.php          配置读取
│   ├── Database.php        PDO 封装
│   ├── Crypto.php          AES 加解密 + HMAC 签名 + 防重放
│   ├── Response.php        统一响应
│   ├── Logger.php          业务日志与统计（含统计缓冲）
│   ├── Cache.php           缓存层（Redis / 内置 RESP / 文件三级降级）
│   ├── Heartbeat.php       心跳聚合（缓冲 + 批量落库）
│   ├── Grace.php           离线宽限票据（ECDSA ES256 签发/验签）
│   ├── Audit.php           管理端审计日志（变更前后对比）
│   ├── RateLimit.php       限流器
│   ├── Session.php         会话管理
│   ├── Device.php          设备绑定
│   ├── Auth.php            账号认证
│   ├── Card.php            激活码
│   ├── Agent.php           代理商（认证/计费/统计）
│   ├── LoginMethod.php     登录方式规格（客户端与官网同源）
│   ├── Version.php         版本取值（init/version/官网下载同源）
│   ├── Setting.php         系统设置
│   └── Util.php            工具函数
├── config/
│   └── config.php          全局配置（数据库、密钥、策略、后台保护）
├── install/
│   ├── install.php         网页安装向导
│   ├── install.lock        安装锁（存在则禁止重装）
│   ├── schema.sql          数据库结构（26 张表）
│   ├── migrate_agent.php   升级脚本：新增代理商体系（可重复执行）
│   ├── migrate_agent_types.php 升级脚本：代理商激活码 + 按卡类型计费（可重复执行）
│   ├── migrate_type_groups.php 升级脚本：按卡类型指定激活用户组（可重复执行）
│   ├── migrate_card_prefix.php 升级脚本：代理生成卡密的固定前缀（可重复执行）
│   ├── migrate_init_balance.php 升级脚本：余额计费下激活码的注册初始余额（可重复执行）
│   ├── migrate_agent_recharge.php 升级脚本：代理商充值卡密（余额/张数额度，可重复执行）
│   ├── migrate_recharge_quota_map.php 升级脚本：充值卡密「张数额度」支持多卡类型（可重复执行）
│   ├── migrate_device_fp.php 升级脚本：设备指纹（多硬件组件加权 + 模拟器/虚拟机识别，可重复执行）
│   ├── migrate_online_stats.php 升级脚本：数据大屏在线快照表 nb_online_stats（可重复执行）
│   ├── clear_logs.php      日志清理工具（--dry-run 预演 / --yes 执行）
│   └── nginx.conf.example  Nginx 部署配置示例
├── examples/
│   └── client_demo.cpp     C++ 客户端完整示例（手写协议版，依赖 OpenSSL + libcurl）
├── sdk/                    开箱即用的 C++ 接入 SDK（header-only，零第三方依赖）
│   ├── nebula_sdk.hpp      主头文件（include 即用，无需编译）
│   ├── nebula_protect.hpp  可选加固组件（壳标记/混淆/反调试，默认全关，见 SDK_PROTECTION.md）
│   ├── SDK.md              C++ SDK 接入文档（初始化 / 登录 / 心跳 / 内置提示 / 完整性自校验）
│   └── SDK_PROTECTION.md   C++ SDK 客户端加固指南（默认关闭，按需开启）
├── docs/
│   ├── API.md              完整接口文档
│   ├── API_RAW_EXAMPLES.md 请求/响应原始报文示例
│   ├── TEMPLATE.md         界面模板开发文档
│   └── TEMPLATE_OLD.md     模板开发文档旧版（历史归档）
├── logs/                   日志目录
├── cron.php                定时清理任务
└── .htaccess               安全规则与路由重写
```

---

## 环境要求

| 项目      | 要求                                      |
| ------- | --------------------------------------- |
| PHP     | ≥ 7.4（推荐 8.0+）                          |
| 扩展      | `pdo_mysql`、`openssl`、`json`、`mbstring` |
| 数据库     | MySQL 5.7+ / MariaDB 10.3+              |
| 缓存（可选）  | Redis 5.0+（无 `redis` 扩展也能用，见「缓存与心跳聚合」）；不部署则自动走文件缓存 |
| Web 服务器 | Apache（含 mod_rewrite）或 Nginx            |

> `openssl` 扩展同时用于通信加密与**离线宽限票据签名**（ES256），必须启用。
> 缓存与 Redis 均为**可选**：不部署时心跳 / 统计自动回落逐次写库，功能不受影响。



---

## 安装部署

### 方式一：网页安装向导（推荐）

1. 将整个项目上传到 Web 根目录
2. 确保 `config/` 与 `logs/` 目录**可写**
3. 浏览器访问 `http://你的域名/install/install.php`
4. 按向导填写数据库信息与管理员账号
5. 安装完成后**立即删除 `install` 目录**
6. 记录向导第 3 步显示的 `AES_KEY` 与 `SIGN_SALT`，客户端需要用到

### 方式二：手动安装

1. 导入数据库结构：

```bash
mysql -u root -p < install/schema.sql
```

1. 编辑 `config/config.php`，填写数据库信息：

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'nebula_auth',
    'user' => 'root',
    'pass' => '你的密码',
],
```

1. 生成通信密钥并填入配置：

```bash
php -r "echo 'aes_key   = ' . bin2hex(random_bytes(16)) . PHP_EOL;
        echo 'sign_salt = ' . bin2hex(random_bytes(24)) . PHP_EOL;"
```

1. 手动创建管理员（把 `<bcrypt哈希>` 换成实际值）：

```bash
php -r "echo password_hash('admin888', PASSWORD_BCRYPT), PHP_EOL;"
```

```sql
INSERT INTO nb_admins (username, password, nickname, role, status, created_at)
VALUES ('admin', '<bcrypt哈希>', 'admin', 1, 1, UNIX_TIMESTAMP());
```

1. 删除 `install` 目录

### 安装后自检

用浏览器打开后台首页，以管理员账号登录，逐页确认数据正常加载即可。
如需排查，可查看 `logs/` 目录下的运行日志。

---

## 安全配置（重要）

后台涉及敏感操作，建议按下面几项加固。

### 1. 修改后台目录名

把 `admin/` 改成别的名字（如 `manage_x9k2/`），可大幅降低被扫描器命中的概率。

```bash
mv admin manage_x9k2
```

同时更新 `config/config.php` 中的 `admin.path`。

### 2. 启用入口密钥

配置后，访问后台页面必须带 `?k=密钥`，首次访问成功后写入 cookie，后续免带。

```php
// config/config.php
'admin' => [
    'entry_key' => '换成一串足够长的随机字符',
    // ...
],
```

生成方式：`php -r "echo bin2hex(random_bytes(24));"`

访问方式：`https://你的域名/admin/home.php?k=你的密钥`

> 密钥错误时返回 **404**，不暴露后台存在。

### 2.1 代理商后台（可选功能）

代理商使用**独立入口** `/agent/`。账号有两种来源：

1. 主管理员在「业务管理 → 代理商」直接创建；
2. 主管理员在「业务管理 → 代理商激活码」生成激活码，
   代理商打开 `/agent/#reg` 填码自助注册。

代理商只能看到自己名下的卡密，无法触达后台任何其他数据。

**激活码决定了代理商的全部发货规格**（注册后代理不可自改）：

- **按卡类型分别指定激活后进入的用户组** —— 永久卡、时长卡、点数卡、次数卡可各进不同用户组
  （如永久卡进 VIP 组、时长卡进普通组）；某类型不指定时使用激活码上的「兜底用户组」
- 卡密**设备上限**、是否允许代理作废卡密、控量模式
- **代理生成卡密的固定前缀** —— 填了以后该代理生成的卡密强制带此前缀（代理端不可修改），
  便于按渠道 / 代理做码段识别与对账；留空则仍由代理在 `/agent/` 自行填写
- **每种卡类型的额度（配额模式）或单价（余额模式）** —— 永久卡可以单独定价、单独配额，时长卡/点数卡/次数卡同理
- **注册后赠予余额（仅余额计费模式）** —— 代理商注册到手即有此余额，可直接发货；
  代理端「发货规格」会按余额显示每种卡还能生成多少张
- 可用注册次数（1 = 一次性；大于 1 时同一个码可注册多个代理）与激活码有效期

其他要点：

- 不需要代理商时，在「系统设置 → 代理商设置」把「开放代理商后台」关掉即可（接口一并拒绝）；
  只想禁止自助注册就关「开放代理商自助注册」
- 与主后台共用目录名策略的思路，也可给代理商入口加一层密钥：
  「系统设置 → 代理商设置 → 代理商入口密钥」填值后，必须先访问
  `https://你的域名/agent/?k=密钥`，否则返回 404
- 另有独立的限流（单 IP 240 次/分钟；单代理生成 10 次/分钟、100 次/天；注册尝试 5 次/10 分钟）

### 3. 开启 IP 白名单（可选）

只允许特定 IP 访问后台，适合有固定出口 IP 的场景：

```php
'admin' => [
    'ip_whitelist_enable' => true,
    'ip_whitelist' => ['1.2.3.4', '::1'],
],
```

### 4. 敏感操作二次密码确认

删除用户、批量删除等操作会要求重新输入管理密码，防止令牌被盗后直接删库：

```php
'admin' => [
    'require_password_confirm' => true,   // 默认开启
],
```

### 5. 跨域白名单

同域部署时保持为空数组（默认），完全关闭 CORS：

```php
'security' => [
    'cors_origins' => [],   // 需要跨域时填具体域名，不要用 '*'
],
```

### 6. 修改默认密码

首次登录后立刻在「个人中心」修改密码。新密码要求：**至少 8 位，且同时包含字母和数字**。

### 内建的安全机制

| 机制      | 说明                                                |
| ------- | ------------------------------------------------- |
| CSRF 防护 | 所有写操作校验令牌，只读接口豁免                                  |
| 登录防爆破   | 连续失败 N 次锁定账号（可配），失败日志记录来源 IP                      |
| 会话隔离    | 管理端令牌存独立表，改密后所有旧会话立即失效                            |
| 接口限流    | 按 IP 限制请求频率，防止刷接口                                 |
| 审计追溯    | 所有写操作记录操作人、IP、时间、字段变更前后值                          |
| 输出转义    | 前端所有数据渲染前做 HTML 转义，防 XSS                          |
| 安全响应头   | `nosniff` / `X-Frame-Options` / `Referrer-Policy` |
| 密钥不落地   | AES 密钥只存在服务端，前端页面仅注入随机化的会话标识                      |
| 离线票据防伪  | 离线宽限票据由服务端私钥签名，客户端公钥验签；票面绑定账号 / 机器码 / 会话 / 有效期，篡改即失效 |

---

### Nginx 配置参考

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /www/nebula;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 客户端 API 美化路由
    location ~ ^/api/([a-z_]+)$ {
        rewrite ^/api/([a-z_]+)$ /api/index.php?action=$1 last;
    }

    # 管理 API 美化路由
    location ~ ^/admin/([a-z_]+)$ {
        rewrite ^/admin/([a-z_]+)$ /admin/index.php?action=$1 last;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 保护敏感目录
    location ~ ^/(config|lib|logs|install)/ {
        deny all;
    }
    location ~ \.(sql|log|lock|md)$ {
        deny all;
    }
}
```

---

## 快速验证

安装完成后，可用以下命令测试接口是否正常：

```bash
# 1. 测试初始化接口（明文白名单内，无需加密）
curl "http://127.0.0.1/api/index.php?action=init"

# 2. 测试管理端登录
curl -X POST "http://127.0.0.1/admin/index.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin888"}'

# 3. 用返回的 token 查看统计
curl -X POST "http://127.0.0.1/admin/index.php?action=dashboard" \
  -H "X-Token: 上一步返回的token"
```

---

## 客户端对接

### 方式一：用现成 SDK（推荐）

`sdk/nebula_sdk.hpp` 提供 header-only 的 C++ SDK，**一个头文件拖进项目即可**。
📘 完整接入文档见 **[sdk/SDK.md](sdk/SDK.md)**（初始化/登录/心跳/内置提示/完整性自校验/离线宽限全说明）。
🛡️ 需要防破解时再看 **[sdk/SDK_PROTECTION.md](sdk/SDK_PROTECTION.md)**：同目录的
`sdk/nebula_protect.hpp` 提供壳标记（VMProtect/Themida）、代码混淆、反调试/反虚拟机检测，
**默认全部关闭**，加一行 `NEBULA_HARDEN=1` 即可全开。

```cpp
#include "nebula_sdk.hpp"

nebula::Client c("http://你的服务器/api/index.php", AES_KEY, SIGN_SALT,
                 "SW你的软件标识", "", "Windows", "1.0.1");   // app_key 必填（后台软件管理获取）
auto ir = c.init();                    // 初始化：下发会话密钥、心跳间隔、登录方式、版本策略
if (!c.enforceSelfIntegrity()) return 1;   // 完整性自校验：exe 被篡改 → SDK 弹窗，退出
if (!c.versionAlert())          return 1;  // 版本过期提示（强制更新中止 / 可选更新提醒）
c.maintainAlert();                         // 维护模式提示（全部内置弹窗，也可 setUiHandler 自定义）
auto lr = c.login("用户名", "密码");        // 登录（按服务器登录方式自动组装）
c.startHeartbeat(lr.token, [&c](int code, const std::string& msg,
                                 const nebula::HeartbeatInfo& hb) {
    if (hb.kick || hb.need_relogin) c.kickAlert(msg);   // 被踢/顶号 → SDK 弹窗
}, 0);                                     // 0 = 用 init 下发的心跳间隔
c.stopHeartbeat();
c.logout(lr.token);
```

- 内部已封装 AES-256-CBC、HMAC-SHA256 签名、时间戳/nonce 防重放、会话密钥协商
- 内置**提示体系**：版本过期 / 可选更新 / 维护中 / 被踢顶号 / 完整性校验失败全部 SDK 自动弹窗，
  `setUiHandler(kind, msg)` 一行接管为自定义 UI；提示中文走宽字符窗口不乱码
- 内置**完整性自校验**：后台版本管理登记当前版本哈希/大小后，客户端启动自动比对自身 exe，被篡改即拒绝运行
- 内置**硬件指纹自动采集**：登录时自动上报 `device_fp`（主板/CPU/系统盘/BIOS/显卡取哈希、
  主网卡裸 MAC 供虚拟机 OUI 识别，OEM 占位值自动过滤），**无需调用方处理**；
  `device_name` 也自动取真实电脑主机名
- 仅 Windows（VS/MSVC 工具链），系统自带 `bcrypt` / `WinHTTP` 已 `#pragma comment` 自动链接，**不需要 OpenSSL 或 libcurl**
- 另提供 `activate` / `devices` / `unbindDevice` / `userinfo` / `getNotices` / `checkVersion` / `online` / `checkOffline`（离线票据本地校验）等接口

### 方式二：手写协议

参考 `examples/client_demo.cpp`，包含完整的：

- AES-256-CBC 加解密（OpenSSL EVP 接口）
- HMAC-SHA256 签名
- Base64 编解码
- libcurl HTTP 请求封装
- 自动加密签名的 `post()` 函数
- 登录 / 心跳 / 激活 / 解绑 / 版本校验的业务封装

### 对接要点

1. **密钥必须一致**：客户端的 `AES_KEY`、`SIGN_SALT` 要与 `config/config.php` 完全相同
2. **机器码**：建议采集主板序列号 + CPU ID + 硬盘序列号，做 SHA256 后取前 16-32 字节
3. **时间同步**：客户端系统时间需准确，与服务器时差不超过 300 秒
4. **心跳线程**：登录成功后启动独立线程，按 `heartbeat_interval` 上报，收到 `kick` 标志立即停止业务
5. **nonce 唯一**：每次请求都要生成新的随机 nonce
6. **离线宽限**（可选）：保存 `login` / `heartbeat` 响应中的 `grace` 票据，连同 `init` 下发的公钥一起缓存；
   网络失败时本地验签，未过期即可继续运行，成功后用新票据覆盖即可（详见 `docs/API.md` 的「离线宽限协议」）

---

## 定时任务

`cron.php` 负责清理过期会话、僵尸设备、过期日志与统计，并承担两项聚合落库工作：

1. **心跳缓冲落库**——把缓存中累积的 `devices.last_seen` 批量写回（必须跑在「清理僵尸设备」之前）
2. **统计缓冲落库**——把缓存中累积的 `api_stats` 调用计数批量落库
3. 其余：清理过期会话 / 僵尸设备 / 限流记录 / 日志 / API 统计 / nonce / 旧文件日志，写入在线快照表 `nb_online_stats`，缓存维护

> 没有配置 cron 也不会积压：心跳与统计都会在累计到阈值时「机会式」自动落库；
> 但**强烈建议配置 cron**，以保证在线曲线完整、清理及时。

**Linux（crontab）**

```
* * * * * /usr/bin/php /path/to/yanzheng/cron.php >> /path/to/yanzheng/logs/cron.log 2>&1
```

**Windows（计划任务）**

- 程序：`C:\php\php.exe`
- 参数：`D:\xiangmu\yanzheng\cron.php`
- 触发器：每 1 分钟

**或用外部服务定时访问**

```
https://你的域名/cron.php?key=<config.php 中的 sign_salt>
```

---

## 数据大屏与留存复购

后台侧边栏新增两页：

- **📉 数据大屏**：实时在线数、今日激活/新增、在线曲线、代理销量排行、卡密类型分布、运行时状态（缓存驱动 / 心跳缓冲积压），默认 30s 自动刷新，可一键清理缓存
- **📈 留存复购**：D1 / D3 / D7 队列留存、用户与代理复购率、DAU / WAU / MAU 活跃分层、充值趋势

曲线数据来自 `nb_online_stats` 快照表——由 `cron.php` 每分钟写入一行；未配 cron 时曲线会有缺口，但实时数值仍准确。

---

## 配置说明

编辑 `config/config.php`：

### 数据库

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'nebula_auth',
    'user' => 'root',
    'pass' => '',
    'prefix' => 'nb_',      // 表前缀
],
```

### 缓存与心跳聚合

```php
'cache' => [
    // auto  = 扩展 redis → 内置 RESP 套接字 → 文件 → 关闭（推荐）
    // redis = 只用 Redis（连不上会继续降级到文件，不中断业务）
    // file  = 只用文件缓存（仅适合单机）
    // none  = 关闭缓存（心跳/统计自动回落逐次写库）
    'driver'      => 'auto',
    'prefix'      => 'nb:',
    'default_ttl' => 300,
    'redis' => [
        'host' => '127.0.0.1', 'port' => 6379,
        'password' => '', 'database' => 0,
        'timeout' => 1.0,              // 连接超时（秒）
        'reprobe_seconds' => 60,       // 连接失败负缓存（秒）
    ],
    // 文件缓存落在 logs/cache：部署模板已整目录 deny，无需改服务器配置
    'file' => ['dir' => __DIR__ . '/../logs/cache'],
],

'heartbeat' => [
    'aggregate'       => true,   // 心跳聚合总开关（缓存不可用时自动失效）
    'refresh_seconds' => 120,    // 同设备 last_seen 最少间隔多少秒才落库
    'flush_batch'     => 200,    // 单次最多刷多少台
    'flush_every'     => 30,     // 每多少次心跳机会式落库
    'buffer_ttl'      => 86400,  // 缓冲区保留时长（秒）
    'stale_seconds'   => 86400,  // 超过该时长未心跳的项丢弃
],
```

> - `heartbeat.refresh_seconds` **必须小于** `policy.heartbeat_timeout`，否则设备会被误判离线。
> - **多机部署必须用 Redis**（或共享存储），否则各机器缓存不共享，在线数与心跳缓冲会不一致。
> - 无 PHP `redis` 扩展也能用——内置 RESP 套接字客户端直连 Redis 协议，零依赖。

### 离线宽限期

```php
'grace' => [
    'enable'       => true,      // 总开关
    'seconds'      => 3600,      // 单次下发的离线宽限时长（秒），0 = 关闭
    'max_seconds'  => 7200,      // 硬上限（秒），0 = 不限制
    'clamp_to_vip' => true,      // 宽限截止被账号到期时间钳制（强烈建议 true）
    'clock_skew'   => 120,       // 客户端时钟允许偏差（秒）
    'key_file'     => __DIR__ . '/grace_keys.php',  // 私钥文件，可删除以轮换
    'openssl_config' => '',      // openssl.cnf 路径，留空自动探测
],
```

> - 私钥首次使用时**自动生成**到 `config/grace_keys.php`（Web 不可访问，切勿外泄）。
> - 删除该文件会**自动重新生成**一套密钥，此前签发的所有离线票据立即失效。
> - 客户端内置公钥（经 `init`/`login` 下发）本地验签，无需联网即可放行。
> - 票面绑定 `user_id + 机器码摘要 + 会话令牌摘要 + 会员到期时间 + 宽限截止`，任何一项被篡改都会验签失败。

### 通信安全

```php
'security' => [
    'aes_key'        => '...',   // 32 字节，安装时自动生成
    'sign_salt'      => '...',   // 签名盐
    'time_window'    => 300,     // 时间戳容差（秒）
    'enforce_crypto' => true,    // 是否强制加密（调试时可设 false）
    'plain_whitelist'=> ['init', 'notice'],  // 允许明文的接口
],
```

### 业务策略

```php
'policy' => [
    'default_max_devices' => 1,      // 默认设备上限
    'heartbeat_interval'  => 60,     // 心跳间隔（秒）
    'heartbeat_timeout'   => 180,    // 离线判定（秒）
    'session_ttl'         => 3600,   // 会话有效期（秒）
    'rate_limit_per_min'  => 120,    // 单 IP 每分钟限流
    'login_attempt_per_min' => 10,   // 登录尝试限流
    'login_fail_threshold'  => 5,    // 失败几次锁定
    'login_lock_seconds'    => 900,  // 锁定时长（秒）
    'trial_seconds'         => 0,    // 未激活试用时长，0=关闭
],
```

### 版本控制

```php
'version' => [
    'min_client_version'    => '1.0.0',  // 低于此版本强制更新
    'latest_client_version' => '1.0.0',
    'force_update'          => false,
    'update_url'            => '',
    'update_note'           => '',
],
```

> 也可通过后台「版本管理」动态配置，优先级高于配置文件。

---

## 安全建议

| 项目         | 建议                                         |
| ---------- | ------------------------------------------ |
| 传输         | 生产环境务必启用 HTTPS                             |
| 加密         | 保持 `enforce_crypto = true`                 |
| 后台入口       | 修改 `config.php` 中 `admin.path`，改为不易猜测的名称   |
| IP 白名单     | 后台仅允许固定 IP 访问（`admin.ip_whitelist_enable`） |
| 调试开关       | 生产环境关闭 `debug` 和 `log.record_raw`          |
| install 目录 | 安装完成后立即删除                                  |
| 数据库        | 使用独立低权限账号，不要用 root                         |
| 备份         | 定期备份数据库与 `config/config.php`               |

---

## 常见问题

**Q: 返回「签名校验失败」**

检查客户端与服务端的 `AES_KEY`、`SIGN_SALT` 是否完全一致；确认签名格式为 `data + "|" + t + "|" + n`；确认 hex 为小写。

**Q: 返回「请求已过期」**

客户端系统时间不准，与服务器时差超过 `time_window`。同步系统时间，或适当调大该值。

**Q: 返回「重复请求」**

nonce 被重复使用。确保每次请求生成新的随机 nonce（≥8 位）。

**Q: 数据库连接失败**

检查 `config/config.php` 中的数据库配置；确认 PHP 已安装 `pdo_mysql` 扩展（`php -m | grep pdo_mysql`）。

**Q: 心跳接口返回 1002 / 4002**

令牌失效或设备被解绑。客户端应捕获 `need_relogin` 标志并回到登录界面。

**Q: 卡密生成很慢**

生成 10000 张约需数秒，属正常（每张都要做唯一性检查）。如需更快可增大卡密长度或减少段数。

---

## 更新日志

### v2.63.15（发卡网体验迭代 · 后台增强 · SDK 重构）

> v2.63.1 → v2.63.15 的累计更新。

- **商品详情支持 HTML（2.63.3 ~ 2.63.6）**
  - 商品详情支持 HTML 代码，**贴整份 HTML 文档也能正常渲染**：`<style>` 自动限定作用域
    （body 样式映射到详情容器、其余选择器自动加详情前缀，不会污染页面），文档壳
    （DOCTYPE / head / html / body 等）自动剥离
  - 点击商品图开灯箱查看原图
- **详情页布局改版（2.63.8 / 2.63.10）**
  - 改为「上大图 + 下方左详情右购买」：商品图整行放大展示（contain 完整显示不裁切）
  - 详情矩形框向两侧加宽；内容过长时限高滚动（约屏高 62%，细滚动条）
- **弹窗公告统一内容框渲染（2.63.9）**
  - 公告 HTML 直接在弹窗公告框内展示（含 `<style>` 自动限定作用域、整份文档自动剥壳），
    移除原「整页自定义」iframe 模式；内容过长框内滚动
- **分类页签分段连体式（2.63.12 ~ 2.63.14）**
  - 分类按钮由独立方块改为整排连体分段条，选中段主色高亮
- **后台软件列表复制按钮（2.63.15）**
  - app_key / AES_KEY / SIGN_SALT 后一键复制（execCommand 兜底兼容 http 本地环境）
- **浅色模板与细节修正（2.63.1 / 2.63.2）**
  - 浅色模板下激活提示条、Toast 等深底硬编码元素可读性修正；公告空态文案精简
- **C++ SDK 重构为 header-only**
  - 新版 `sdk/nebula_sdk.hpp` 单头文件即用（WinHTTP + bcrypt 系统库，无需 OpenSSL/libcurl），
    README 与 `docs/API.md` 对接章节同步更新

### v2.63.0（官网门户与发卡网持续迭代 · 界面模板）

> v2.32.1 → v2.63.0 的累计更新，按功能域归并。

- **界面模板系统（v2.63.0）**
  - 官网与发卡网各内置 **7 套 UI 模板**：小清新（薄荷绿）/ 极简留白（黑白灰 404 风，去装饰直角）/
    樱花粉 / 午夜蓝金（深色）/ 素雅纸感（米白衬线标题）/ 动漫霓虹（高饱和粉青）/ 可爱马卡龙（香芋紫胶囊按钮）
  - 实现：两端样式全量语义变量化后，模板 = `body.ui-<key>` 作用域变量覆盖 + 浅色共享修正块，
    模板自带整套配色，**选中后优先于后台主题色 / 背景图**（原配置保留，回落默认深空即恢复）
  - 入口：官网运营 → 官网内容（总站 + 分软件可单独覆盖）、发卡网配置 → 商店外观
- **多软件分站与官网门户**
  - 软件管理：多软件各自独立官网（URL `?app=` 识别），分站可覆盖文案 / Logo / Favicon /
    主题色 / 背景图 / 界面模板 / 留言板开关 / 客服 / 商店入口，留空回落总站
  - 总站白页模式（`web_total_blank`）：开启后根路径只渲染「选择软件」页，严格分流
  - 官网内容统一在「官网运营 → 官网内容」维护（首屏文案 / 功能卡 / 流程 / FAQ / 导航页脚 / 主题色 / 背景图上传）
  - 官网互动：留言板（两层回复 + 点赞 + 审核）、用户反馈（四类型 + 状态流转）、
    价格套餐 / 购买商家 / 客户端截图，全部后台可运营
- **发卡网迭代**
  - 商品化发卡：发卡商品子页（分类归属弹窗 + 归属筛选 + 搜索、批量删除、图标站内 `/uploads/` 路径放行）、
    卡类型配置、**外部卡密商品**（卡密池 TXT 导入 / 查看 / 复制未售 / 批量删除，与本系统卡密并列上架）
  - 生成卡密可直接**关联上架商品**，自动锁定卡类型 / 时长 / 设备数 / 用户组规格
  - 商品展示 6 种样式：网格卡片 / 紧凑小卡 / 橱窗列表 / 分类侧栏 / 选择式（+经典横列），后台点选切换
  - 支付体系扩展：易支付 / 码支付 / V免签多驱动（`shop_pay_driver` + `shop_pay_cfg`），
    支付渠道展示可自定义（勾选 + 改名）
  - 商店装修增强：弹窗公告（支持完全自定义 HTML）、下单/查询凭证方式（手机号 / 邮箱 / 自定义）、
    商品详情样式（整页 / 弹窗）、全站背景图、标签页离开提醒（文案 / 图标样式可配）、购买须知
  - 发卡订单：批量发货（逐单事务、失败明细）/ 批量关闭 / 批量删除（审计快照）；
    多软件场景按官网识别号过滤商品（`shop_sw_filter`）
- **后台体验**
  - 界面重构：深色模式（跟随系统）、Bootstrap Icons 图标体系、侧边栏固定框架、设置页页签化
  - 全站批量操作统一为卡密管理形态（工具栏常驻按钮，选中浮现/隐藏常规动作），
    用户 / 设备 / 会话 / 留言 / 反馈 / 批次 / 拉黑 / 订单 / 商品 八处收口
  - 科幻风 404 页（`web/404.html`）接入 `.htaccess` ErrorDocument

### v2.32.0（发卡系统与安全增强）

- **发卡商城全链路**：
  - 套餐表 `nb_plans` 扩展发卡字段（价格 / 卡类型 / 时长 / 设备数 / 激活用户组），
    新增 `nb_shop_orders` 订单表与 `shop_*` 系列设置键
  - `lib/Shop.php`：易支付 MD5 验签（`hash_equals` 防时序）、本地订单金额比对、
    事务 `FOR UPDATE` 取卡原子发货、幂等回调、缺货自动转人工、管理端手工补发
  - 独立发卡网前台 `/shop/`：built（内置商店）与 external（302 跳外链）双模式，
    商店装修（标题 / 公告 / 横幅本地上传 / 主题色 / 页脚）后台可配
  - 后台新增发卡商品 / 分类（图标上传、有商品分类禁删）/ 订单 / 卡密 / 设置页签
  - 支付回跳 `?o=订单号` 自动查询并分状态展示（成功 / 人工处理 / 已关闭 / 确认中），
    待支付自动轮询 30 秒兜底同步回跳早于异步回调的场景
  - 下单回跳 / 回调地址优先取后台配置的站点地址，防 Host 头伪造；订单号随机段 5 字节
- **登录体验统一**：发卡网登录方式跟随后台配置（密码 / 密码+验证码 / 验证码直登），
  激活码类账号自动建号；登录图形验证码；激活码找回密码（官网 / 发卡网双入口）
- **IP 黑名单**（系统设置 → 安全）：单 IP 与 CIDR 段（IPv4/IPv6），
  命中后全站（后台 / 官网 / 发卡网 / 代理端 / 客户端 API）返回自定义错误页
  并提示「你已被封禁，请联系管理员」（HTTP 403）
- **文件管理**（主后台 → 系统）：sha256 全站完整性基准 / 校验（改动、新增、缺失）、
  14 条特征评分 Webshell 扫描、文件查看与删除（Deleter 密码确认、运行必需文件硬保护），
  支持勾选批量删除
- **统一自定义错误页**：400/401/403/404/405/429/500/502/503 全部渲染
  「信号丢失」深空页（`lib/error_page.php`），页内数字随状态码变化
- **代理后台（`/agent/`）UI 与主后台同源**：同一套 CSS 设计语言 + 站点 Logo 复用 +
  Bootstrap Icons + 深色模式（独立 `nb_agent_theme`，跟随系统）+ 登录注册验证码
- **安全审计修复**：`preg_split('/[|｜]/u')` 多字节截断、portal 正则分隔符、
  批量删除缺 ID、分类删除越权防护等；限流基于 `Util::ip()`（受信代理模型），
  套 CDN 时请在安全配置中填写受信代理地址

### v2.30.0

- **安全加固：管理端 RBAC 权限矩阵（P0-01）**
  - **修复的问题**：管理端此前只在入口判断「只读角色 role=3 不能写」，
    `role=2`（操作员）与超管在业务上完全等同 —— 操作员账号可以直接调用
    `setting_save` 改安全配置、`card_generate` 印卡、`agent_recharge_save`
    发代理充值卡，属于垂直权限提升。
  - **新增 `lib/AdminPermission.php` 权限内核**：
    - 22 个权限点（`user.read/edit/delete/import`、`card.read/export/generate/void`、
      `agent.read/edit/grant/recharge_code`、`device.read/manage/ban`、
      `session.kick`、`settings.site/business/security/infra`、`audit.read`、
      `admin.manage`、`content.manage`）
    - **58 个 handler 全量映射**到 `action → 权限点` 表，入口 `index.php` 统一校验，
      handler 无需各自判断
    - **默认拒绝**：未登记权限点的 action 一律拒绝，避免新增接口忘记配权限
    - **超管短路**：`role=1` 直接放行，保证矩阵漏配也不会把超管锁在系统外
  - **操作员（role=2）可做**：看用户/卡密/代理/设备，改用户资料，内容运营
    （留言/反馈/公告/版本/套餐/截图），踢用户会话，改站点展示设置
  - **操作员不可做**：生成/作废卡密、导出卡密、改代理档案与额度、发充值卡、
    改业务/安全/基础设施设置、看审计日志、删用户、封设备、批量导入
  - 角色矩阵经测试：`role 2` 对 `setting_save / card_generate / agent_recharge_save /
    user_delete / agent_save / device_ban / user_unbind / user_import / audit_list /
    card_export` 全部拒绝
- **安全加固：设置项四档分级（P1-10）**
  - 原先 `setting_save.php` 是一张扁平白名单，28 个键一视同仁 —— 能改站名
    就等于能改安全策略与 Redis 口令。现按危险程度分四档：
    - `settings.site`：站点展示信息（站名/公告/客服联系方式/留言板开关/维护提示语）
      —— 操作员可改
    - `settings.business`：业务规则（注册开关/赠送天数/默认设备数/代理商开关与单价）
      —— 仅超管
    - `settings.security`：能削弱防线的开关（登录方式/单点登录/异地拦截/心跳/限流/
      最低客户端版本）—— 仅超管
    - `settings.infra`：密钥与缓存基础设施（代理商入口密钥/缓存驱动/Redis 连接与口令）
      —— 仅超管
  - **整单拒绝语义**：混合提交若含任何越权档位的键，整单拒绝并明确提示，
    避免出现「提示成功但只改了一半」
  - 28 个设置键与原白名单**逐项比对：丢失 0、新增 0**
  - 「系统设置」前端按档隐藏无权编辑的卡片 / 禁用保存按钮，并给出红色提示条
    （仅体验优化，真正的拦截在服务端）
- **安全加固：管理端会话密钥绑定（P0-02）**
  - **修复的问题**：管理端认证此前只有一个不透明随机 `token`，一旦泄露
    （XSS 窃取 localStorage / 内网抓包 / 共享终端残留 / 运维截图），
    攻击者把它塞进 `X-Token` 头即可完整接管该管理员，服务端不做任何二次校验。
    且 token 有效期 2 小时、每次请求自动续期，持续活动则永不自然过期。
  - **修复方案**：采用「token + session_key」双因子
    - 登录时额外签发 `session_key`，**只在本次响应里下发一次**
    - 服务端只落库它的 `SHA-256` 摘要，库泄露也无法反推明文
    - 客户端后续请求必须同时带 `X-Token` 与 `X-Session-Key`
    - **密钥错误时直接吊销整个会话**（而非只拒绝本次），避免攻击者反复试错
  - **UA 绑定**：记录登录时 UA 摘要，UA 变化即判定会话被搬运，拒绝并吊销
  - **IP 软绑定（默认）**：IP 变化只记审计日志不打断使用 —— 管理后台常在
    动态 IP / 多出口 / 移动办公场景使用，硬绑会把真实管理员锁在门外。
    固定出口的内网环境可开 `admin.strict_ip_bind` 收紧为硬拦
  - **向后兼容（硬要求）**：`sk_hash` 为 `NULL` 的历史会话仍按旧模型放行，
    升级瞬间不踢任何在线管理员；未跑迁移的站点自动降级并记日志，不会打挂登录
  - **代理商后台同步加固**：`nb_agent_sessions` 与管理端是两套独立会话表，
    采用完全相同的方案（代理账号被接管同样能印卡，风险等价）
  - **数据层**：`install/migrate_admin_session_bind.php`（幂等，覆盖两张会话表）
    + `install/schema.sql` 同步新增 `sk_hash` / `ua_hash` 两列
- **安全加固：受信代理模型（P1-01）**
  - **修复的问题**：`Util::ip()` 无条件采信 `X-Forwarded-For` / `X-Real-IP` /
    `CF-Connecting-IP`，任何人手工加一个请求头即可伪造来源 IP，
    绕过 IP 白名单、限流与失败计数（等于凭空多出无数个「新来源」）。
  - **修复方案**：仅在 `REMOTE_ADDR` 命中 `security.trusted_proxies`
    （支持单 IP 与 CIDR，IPv4/IPv6）时才解析转发头；逐跳跳过受信地址、
    剥离端口、归一化 `::ffff:` 映射地址。取值顺序
    `CF-Connecting-IP → X-Real-IP → X-Forwarded-For`。
  - **行为变更**：`trusted_proxies` 默认为空数组，即**默认不信任任何转发头**。
    套了 CDN / Nginx 反代的部署需要显式填入反代地址，否则拿到的会是反代 IP。
- **安全加固：登录失败计数原子化（P1-04）**
  - **修复的问题**：原实现「读 `login_fail_cnt` → +1 → 写回」在并发爆破下会
    丢失计数（两个请求都读到 4，都写 5），锁定阈值形同虚设。
    实测 12 并发失败登录，旧实现只累计到 10。
  - **修复方案**：改为单条 `SET login_fail_cnt = login_fail_cnt + 1`，
    由数据库保证原子；触发锁定用条件更新收口（`WHERE ... AND login_fail_cnt >= ?`），
    只有结算时计数仍达阈值的那一个请求能写 `lock_until` 并归零。
    复测 12 并发得到 12，无丢更新。
- **安全加固：卡密作废原子化（P1-06）**
  - **修复的问题**：`Card::void()` 先 `SELECT` 查状态再 `UPDATE`，
    两个请求可同时通过检查、同时作废一张卡，产生两笔退款 / 两条日志（TOCTOU）。
  - **修复方案**：改为条件更新 `WHERE id = ? AND status = ?`，
    以 `rowCount() === 1` 判定是否抢到状态迁移，没抢到的直接返回失败。
    `voidByAgent()` 同样处理，并按当前状态返回准确错误码（3002/3003/1004）。
  - 10 进程并发作废同一张卡：成功 1 / 失败 9 / `card_logs` 仅 1 条 / 状态正确。
- **安全加固：数据库事务支持嵌套（P1-05）**
  - **修复的问题**：`Database::begin()/commit()` 直接透传给 PDO，而 **PDO 不支持嵌套事务** ——
    内层 `beginTransaction()` 会被静默忽略（不报错、不生效），内层 `commit()` 却会
    **把整个外层事务一并提交**。这意味着「代理商开卡」这类多步业务一旦在内部复用
    了带事务的方法，中途失败时前面的扣款已经落库，回滚失效，
    只能靠事后补偿（原实现是 catch 里调 `Agent::refund()` 手工退钱），
    补偿本身再失败就是资金错账。
  - **修复方案**：把事务改为 **引用计数**（`private static int $txDepth`）
    - `begin()` —— 仅当深度为 0 时才真正 `beginTransaction()`，然后深度 +1
    - `commit()` —— 深度 -1，**只有归零时才真正 `COMMIT`**（内层 commit 不再误提交外层）
    - `rollback()` —— 无条件把深度清零并整体回滚（异常传播时语义最直观）
    - `inTransaction()` 改为读深度而非 PDO 状态，内外一致
    - 多余的 `commit()`（无活动事务）安全忽略，保持与旧实现一致的容错
  - **业务侧落地**：`agent/handlers/card_generate.php` 把「扣额度 → 生成卡密 → 写日志」
    收进**同一事务**，失败即整笔回滚 —— 不再依赖 `Agent::refund()` 事后补偿。
    `refund()` 保留给无外层事务的历史路径
  - **验证**：`test_tx_boundary.php` **20/20**，覆盖嵌套深度计数、内层 commit 不提前提交外层、
    内层 rollback 全量回滚、多余 commit 安全忽略；真实业务路径实测
    「生成失败后余额回到 50000」，无需任何补偿动作
- **安全加固：设备数量上限原子化（P1-07）**
  - **修复的问题**：绑定设备是典型 TOCTOU —— 先 `activeCount()` 查在线设备数、
    再插入新设备。多客户端同时登录时（一台电脑多开、或用户故意并发），
    所有请求都会读到「还没超额」，然后一起插入，上限形同虚设。
  - **修复方案**：删除两处预检（全新设备分支 + 重新激活分支），
    改为在事务内对 `nb_devices` 中该用户的行加 **`SELECT ... FOR UPDATE` 行锁**，
    在临界区内完成「计数 → 判断 → 插入」，并发请求被迫串行。
    新增私有方法 `insertWithLimit()` / `reactivateWithLimit()` 承载该逻辑
  - **对照组实验（证明漏洞真实存在）**：在测试脚本里原样复现旧逻辑（不改生产代码），
    同压力下**上限 2 却绑定了 5 台**；修复后 10 进程并发连续 3 次稳定
    「成功 2 / 上限拒绝 8 / 库内在线 2」
- **安全加固：会话令牌改用 HttpOnly Cookie（P1-09）**
  - **修复的问题**：会话令牌存 `localStorage` 并通过 `X-Token` 头提交 ——
    任何一处 XSS（被注入的留言内容、浏览器扩展、第三方脚本）都能一句
    `localStorage.getItem(key)` 把令牌读走，然后离线重放到任意机器。
  - **修复方案**：新增 `lib/SessionCookie.php`，令牌改写进 **HttpOnly Cookie**
    （浏览器层面的硬约束，JS 无论如何读不到）；`SameSite=Strict` 防跨站携带。
  - **与 P0-02 互补，不是替代**：Cookie 会被浏览器自动携带，单靠它反而引入 CSRF 面。
    因此**保留 `X-Session-Key` 第二因子**（JS 持有、Cookie 里没有）——
    攻击者诱导浏览器发请求时带不上它。两层各司其职：
    Cookie 防「XSS 窃取令牌」，会话密钥防「CSRF 冒用会话」
  - **取值优先级**：`X-Token 头 > body.token > Cookie`，旧客户端与调试路径继续可用；
    `admin.cookie_session=false` 可整体关闭 Cookie 模式退回旧行为
  - **CLI 兼容**：`setcookie()` 在 CLI SAPI 下会因 headers already sent 直接终止进程
    （exit 255，会把 `cron.php`、迁移脚本、测试脚本打死）。
    实现里用 `PHP_SAPI !== 'cli' && !headers_sent()` 守卫，CLI 下只更新内部状态
  - **前端配合**：`state.js` 新增 `USE_COOKIE` / `useCookieSession()` / `setSessionKey()`，
    Cookie 模式下令牌不落 localStorage；`api.js` 抽出 `authHeaders()` 按模式组装请求头
  - **验证**：`test_cookie_session.php` **21/21** + `test_refresh_session.php` **8/8**
    （含 HttpOnly/SameSite 属性断言、三级取值优先级、端到端「仅凭 Cookie 恢复会话」、
    以及「只有 Cookie 无会话密钥 → 拒绝」的 CSRF 负例）
- **安全加固：传输加密统一为 AES-256-CBC + HMAC-SHA256（移除 GCM 双轨）**
  - **决策**：传输加密回归单一方案 —— **AES-256-CBC（机密性）+ HMAC-SHA256 签名（完整性）**
    的 encrypt-then-MAC 组合。此前短暂引入的 AES-256-GCM 双轨（v2 实验线）整体移除：
    双轨要求客户端全部具备算法协商能力（`X-NB-Enc` 头 / `crypto.cipher` 字段 /
    `nebula_set_cipher()` API）才能安全切档，灰度成本与「老客户端解不开」的失联风险
    与收益不成比例 —— 而现有 CBC + HMAC 路径的完整性由签名保证，
    签名校验先于解密执行，篡改密文必然验签失败（无 padding oracle）。
  - **移除范围**：`lib/Crypto.php` 删除 `encryptGcm/decryptGcm`、`CIPHER_GCM`、
    `cipher()/setCipher()/isGcm()`、`X-NB-Enc` 响应头；`config/config.php` 删除
    `security.cipher` 配置；后台设置删除 `security_cipher` 项（含其取值域校验）；
    `init` 响应的 `crypto` 段简化为固定 `algo='AES-256-CBC'` + `sign='HMAC-SHA256'`
  - **SDK 同步**：删除 `AesGcmCrypt()` / `AesEncryptGcmB64()` / `AesDecryptGcmB64()`
    与双轨分发，`nebula_set_cipher()` API 与 `cfg.cipher` 协商一并移除；
    传输层固定 AES-256-CBC，密钥派生 / 签名格式不变
  - **协议不变**：信封 `data/sign/t/n/k` 结构、会话密钥机制、签名格式（`data|t|n`）
    全部与 v2.29 及之前完全一致 —— **任何版本的客户端无需任何改动即可继续工作**
- **修复：Python 参考客户端缺失会话密钥协商**
  - **修复的问题**：`examples/nebula_client.py` 三处协议实现与 v2.29 之后的
    会话密钥机制不一致，**直接运行必然被服务端拒绝**，作为「参考实现」是误导性的：
    - `init` 返回的 `session: {k, s}` 被丢弃，业务请求**不带 `k` 字段** ——
      服务端要求会话密钥，结果是所有业务接口一律 `bad_sign`
    - `_sign()` 恒用主盐：一旦带上 `k`，服务端改用会话盐验签 → `bad_sign`
    - **响应验签用主盐**：服务端 `buildResponse()` 用的是「验请求所用盐」
      （即会话盐），客户端拿主盐去比对 → **永远验不过**
  - **修复方案**：新增 `session_kid` / `session_skey` 成员与 `_current_salt()`
    （会话盐优先、无会话回退主盐）；`_post()` 补 `k` 字段、响应验签改用会话盐；
    `init()` 保存 `session.{k,s}`
  - **验证方式（零第三方依赖）**：本机不引入 `pycryptodome` / `requests`，
    改以 **PHP 侧作对照 oracle**：
    - `examples/verify_oracle.php` 产出密钥派生 / IV / CBC / HMAC 向量
    - `examples/verify_nebula_client.py` 用 Python 标准库逐字节复算
    - `examples/e2e_oracle.php` 直接调用真实的 `Crypto::parseRequest` /
      `buildResponse` 做信封收发，`examples/verify_e2e.py` 端到端联调
- **修复：后台刷新页面后掉登录（v2.30.1）**
  - **现象**：后台登录后，只要刷新页面（F5）就回到登录页，点侧边栏切换菜单却正常
  - **根因**：前端 `app.js` 的启动流程用 `if (S.token)` 判断「是否已登录」，
    但 Cookie 模式下 `S.token` **恒为空字符串**（令牌在 HttpOnly Cookie 里，
    JS 读不到也不该读）。于是每次刷新都跳过「恢复会话」分支，直接显示登录页 ——
    而服务端的 Cookie 与会话其实完全有效。纯前端误判，后端链路自始至终正常
  - **修复**：判据改为 `useCookieSession() || !!S.token`，
    真实有效性交给服务端 `profile` 接口裁决（失效时才跳登录）
  - **验证**：`test_refresh_session.php` 8/8（模拟两次请求：
    登录下发 Cookie → 刷新仅凭 Cookie 恢复会话）
  - **教训**：会话 Cookie 化之后，「用 token 是否存在来判断登录态」的写法
    全部需要重审（已全量 `grep` 复查，仅此一处）
- **测试**：RBAC 完整性 4/4（58 handler 全登记、权限点全合法、矩阵合法）、
  设置分档 13/13（含混合提交整单拒绝）、会话绑定 10/10 + 代理端 4/4、
  受信代理模型 20/20（含 CIDR 边界、多级 XFF、IPv6、`::ffff:` 归一化）、
  事务嵌套 20/20、设备上限 6/6 + 并发对照实验、
  Cookie 会话 21/21 + 刷新恢复 8/8、
  Python 客户端对齐 + 端到端联调全绿、
  并发用例（12 进程登录计数、10 进程卡密作废、10 进程设备绑定）全部通过

### v2.27.0

- **新增：官网互动模块——把「只读展示页」补齐成可运营的门户**
  - 此前官网只有「首页展示 + 注册登录 + 激活卡密 + 设备解绑」，缺少用户与站点之间的
    互动入口，页面内容偏单薄。本次一次补齐四块，并配齐后台管理。
- **首页新增三个区块**
  - **留言板**：登录用户可发布留言、点赞、回复（两层结构）；未登录可浏览，
    点「登录 / 注册」直接唤起登录弹窗
  - **价格套餐**：后台可配置任意条套餐（名称 / 价格 / 单位 / 时长 / 要点 / 角标 /
    高亮 / 排序 / 启停），点「立即购买」弹出客服联系方式
  - **效果展示**：后台可配置客户端截图，点缩略图进灯箱放大查看
- **个人中心新增「我的反馈」**
  - 用户提交功能建议 / 问题反馈 / 卡密订单 / 其他四类反馈（标题 + 详述 + 选填联系方式）
  - 列表展示处理状态（待处理 / 处理中 / 已回复 / 已关闭）与客服回复
- **后台新增「官网运营」菜单组**（4 个页面）
  - **留言板**：按状态 / 关键词筛选，单条与批量通过、驳回（可填理由）、删除；
    删除主楼时**级联清理其回复与点赞记录**，不留孤儿数据
  - **用户反馈**：按状态 / 类型 / 关键词筛选，状态页签带计数；回复（自动记录
    回复人与时间并置为「已回复」）、关闭、重新打开、删除、批量关闭
  - **价格套餐**：增删改查 + 启停 + 排序，价格字段为字符串，支持「面议」这类非数字文案
  - **客户端截图**：增删改查 + 启停 + 排序，保存时**强制校验 URL 必须是 http/https**
    （挡掉 `javascript:` / `data:` 伪协议），编辑弹窗内提供实时预览
- **「系统设置」新增「官网互动」卡片**
  - 客服联系方式（购买咨询弹窗展示；邮箱 / 网址渲染成可点链接，其余提供一键复制）
  - 首页留言板总开关
- **审核策略：先审后显示**
  - 留言提交后状态为「待审核」，只有后台通过后才出现在官网；
    前端提交成功会明确提示「已提交，通过审核后展示」，避免用户以为没发出去
  - 回复同样需要审核；且**只允许回复已审核通过的主楼**，防止通过接口探测未审核内容
- **隐私与安全**
  - 反馈内容仅本人与管理员可见；**客服回复在状态达到「已回复 / 已关闭」之前不下发**，
    前端不显示空回复框
  - 截图 URL 在**保存侧与读取侧双重校验**，即使有人直接往库里塞非法协议也不会被渲染
  - 点赞用 `nb_message_likes(message_id, user_id)` 唯一键防重复，
    计数用 `GREATEST(likes,1)-1` 保证永不为负
  - 各写接口均接入限流：发留言 3 次/分钟、点赞 30 次/分钟、提交反馈 5 次/小时（均按账号）
  - 只读接口（含需登录的 `fb_list`、`devices`）免除 CSRF，避免刷新页面时
    会话令牌迟到导致误报「页面校验已失效」；写接口仍强制校验 CSRF
- **优雅降级**
  - 新增 `lib/WebInteract.php` 承载官网互动的共享领域逻辑，被官网入口与后台入口共用
    （`web/inc/portal.php` 只在官网加载，后台拿不到它，因此不能把逻辑写在那里）
  - `WebInteract::tableReady()` 缓存 `SHOW TABLES` 结果：**未跑迁移的老站**
    官网新区块显示「暂无内容」而非整页 500，向后兼容
- **数据层**：`install/migrate_web_interact.php`（幂等）+ `install/schema.sql` 同步新增
  `nb_messages` / `nb_message_likes` / `nb_feedbacks` / `nb_plans` / `nb_screenshots` 五张表
- **测试**：官网前端整合测试 64/64 通过、后台互动逻辑测试 25/25 通过
  （含级联删除无残留、回复可见性、伪协议过滤、降级行为等负向用例）

### v2.26.0

- **新增：C++ 接入 SDK（`sdk/`）—— 让第三方软件接入验证只需两个文件**
  - 交付形态为「单头文件 + 单源文件」：`nebula.h` + `nebula.cpp`（含 `src/` 内部模块），
    拖进项目即可编译，**没有 .lib 文件、没有 MT/MD 或 VS 版本兼容问题**
  - **零第三方依赖**：不使用 OpenSSL / libcurl，改为系统自带的
    `bcrypt.dll`（密码学）与 `winhttp.dll`（网络）；MySQL/HTTP 层与宿主进程隔离，
    不会因宿主设置了 IE 代理而静默改道
  - 协议细节全部封装：AES-256-CBC 加解密、HMAC-SHA256 签名、时间戳窗口与 nonce 防重放、
    `init` 会话密钥协商（信封 `k` 字段）、响应签名校验
  - 业务接口：`init` / `login`（三种登录方式自动适配）/ `heartbeat` / `activate` /
    `unbind` / `devices` / `userinfo` / `notice` / `version` / `logout`
  - **后台心跳线程**：间隔跟随服务端下发值，心跳失败时区分「服务端要求下线」
    与「纯网络错误」；后者在离线票据有效期内自动进入离线模式继续运行
  - **被踢下线回调**：`session` / `banned` / `vip` / `unbind` / `single` / `heartbeat`
    六种原因分类回调，便于业务层区分处理
  - 内置**机器码生成**（系统盘卷序列号 + 计算机名 + CPU + 主板，SHA-256 取前 32 位）
    与**硬件指纹采集**（主板/CPU/系统盘/BIOS/网卡/显卡，与 `DeviceFp::WEIGHTS` 完全对应）
  - 内置**离线宽限票据验签**：完整实现 ES256（ECDSA P-256），
    含 `X.509 SubjectPublicKeyInfo → CNG BCRYPT_ECCKEY_BLOB` 格式转换与
    `DER(SEQUENCE{r,s}) → 裸 r||s` 转换；公钥由 `init` 下发并缓存
- SDK 附带的测试（均为真实环境联调）
  - **密码学层与 PHP 服务端逐字节对齐：39/39 通过** ——
    含「解密 PHP 侧 `openssl_encrypt` 产生的密文」反向验证、`\uXXXX` 中文解码、
    `data|t|nonce` 签名串格式等
  - **ES256 验签：7/7 通过** —— 含篡改 body、篡改签名必须失败的负向用例
  - 完整流程（init → 登录 → 心跳 → 退出）、硬件指纹六项采集、真实封禁触发踢号回调 均通过
- 新增 `sdk/docs/SDK.md` 完整接入文档：API 速查、错误码对照与处理建议、
  机器码原理、硬件指纹说明、离线宽限机制、10 条常见问题排查、安全加固建议
- README 新增「客户端对接」双方案说明（推荐用 SDK / 手写协议参考 `client_demo.cpp`）

### v2.25.2

- **修复：后台「安全设置」保存后不生效**（改了同账号单点登录 / 异地登录拦截 / 心跳间隔 /
  离线判定 / 每日解绑次数上限 / 接口限流，全都像没改一样）
  - 根因：这些策略项的业务代码**只读 `config/config.php`，从不读数据库**。
    后台 `setting_save` 确实把值写进了 `nb_settings` 表，但运行时无人消费；
    其中 `single_login` / `geo_block` / `unbind_per_day` 更**连一次引用都没有**（功能未实现）
  - 新增 `lib/Policy.php` 作为策略项**统一取值入口**，按
    `nb_settings（后台改的） → config/config.php（出厂默认）`
    优先级读取；业务代码改调 `Policy::xxx()`，不再散落各处直接读 Config
  - **配置文件回退**：`Setting::isSet()` 只查库判断「后台是否配过」，
    未配过则沿用 config 出厂值 —— **未保存过任何设置的站点行为与升级前完全一致**；
    后台一保存即立即以库中值为准
  - 修复「值为 `0` 被误判成未设置」的经典陷阱（必须用 `array_key_exists` 而非返回值判断）
  - 各策略项补边界兜底：心跳间隔/超时/限流为 0 或非数字时回落默认值，
    每日解绑上限为负时归零（不限制）
- **补齐三项从未实现的功能**
  - **同账号单点登录**：开启后同账号仅保留 1 个在线会话，后登录踢掉先登录
    （在 `Session::create()` **之前** `kickUser()`，避免把新会话一起踢掉）
  - **异地登录拦截**：已绑定设备换 IP 登录时拒绝，返回 `4006` 并回传当前/已知 IP（已打码）。
    **首次绑定的新机器不做判定**，避免用户第一次从新网络登录被误伤
  - **每日解绑次数上限**：此前硬编码为 3，现按设置项生效，`0` = 不限制
- 后台「安全设置」新增**「当前生效值」面板**：逐项显示实际生效值与来源
  （`后台设置` / `配置文件`），保存后自动刷新 —— 一眼确认改动是否已落到运行时
- 新增 `Util::maskIp()`（IP 打码，IPv4/IPv6 均支持），用于日志与响应中脱敏
- 前端保存前增加数值范围校验（心跳 1~3600、离线判定 1~86400、解绑上限 0~9999、限流 1~100000）

### v2.25.1

- 修复：客户端已上报的**六项硬件信息后台看不到**——`nb_devices.fp_json` 一直有存，但接口从未下发，
  设备管理页只能看到「风险」标签
  - 设备列表新增**「硬件指纹」列**：显示已上报组件数 + 组件名简写（如 `6项 主板·CPU·系统盘·BIOS·网卡·显卡`），
    未上报的老客户端显示「未上报」
  - 新增**「指纹详情」弹窗**（或点列表中的指纹列）：逐项列出客户端上报的主板 / CPU / 系统盘 / BIOS /
    网卡 / 显卡原始特征串，并标注**权重**与**组件完整度进度条**（如 `100%（权重 110/110）`）
  - 弹窗内一并展示加权指纹 `fp_hash`（可一键复制）、设备名 / 系统信息 / 绑定时间 / IP / 风险标记中文说明
  - 网卡为**裸 MAC** 且命中虚拟机 OUI 时，明细里额外标红「虚拟机网卡」
  - 新增**「指纹 → 未上报指纹」筛选**，并在列表顶部提示未上报设备总数，
    便于评估还有多少老客户端未接入 `device_fp`
  - 弹窗直接复用列表已取的数据，**不额外发起请求**
  - 未执行 `migrate_device_fp.php` 或数据损坏时自动降级为「未上报」，不影响列表打开

### v2.25.0

- 新增**离线宽限期**（应对服务器抖动 / 网络波动导致的全体掉线）
  - 服务端用 **ECDSA P-256（ES256）** 私钥签发一张**离线宽限票据**，随 `login` / `heartbeat` 下发；
    客户端用内置公钥**本地验签**，通过后可在票据到期前继续离线运行
  - 票面绑定 `user_id + 机器码摘要 + 会话令牌摘要 + 会员到期时间 + 宽限截止`；
    到期时间可被账号有效期钳制（`clamp_to_vip`），封号 / 踢下线不会被长期绕过
  - 私钥首次使用时**自动生成**到 `config/grace_keys.php`（Web 不可访问）；删除该文件即可**轮换密钥**
  - 公钥经 `init` / `login` 下发，客户端无需预置；`clock_skew` 容忍客户端时钟偏差
  - 无 sodium 扩展也能用（走 openssl，集成环境可显式指定 `openssl_config`）
- 新增**统一缓存层** `lib/Cache.php`——把高频写库动作挪出 MySQL 热路径
  - 驱动优先级 `auto → ext-redis → 内置 RESP 套接字 → file → none`，**连不上自动降级，不中断业务**
  - **内置 RESP 客户端**：无 `redis` 扩展时用 TCP 套接字直连 Redis 协议，零依赖
  - 统一接口 `get/set/del/incr/expire/hSet/hGet/hIncrBy/lock/unlock/flushPrefix/remember`；
    内置类型信封（JSON）避免 Redis 只能存字符串导致的类型退化
  - 文件缓存落在 `logs/cache`（部署模板已整目录 deny，无需改服务器配置）
- 新增**心跳聚合**：`devices.last_seen` 由「每次心跳写一次库」改为**缓存缓冲 + 批量落库**
  - `heartbeat.refresh_seconds` 去重（同一设备间隔内不重复落库）；`maybeFlush` 机会式落库 + cron 兜底
  - 清理僵尸设备的阈值同步放宽，避免聚合期间误判在线设备离线
  - **未启用或缓存不可用时自动回落逐次写库**，功能与统计口径完全一致
- 新增**接口统计缓冲**：`api_stats` 的每次 upsert 改为 `HINCRBY` 缓冲，cron / 机会式批量落库；
  心跳成功日志默认不再入库（失败记录始终保留）
- 新增**数据大屏**（后台「数据大屏」）：实时在线、今日激活 / 新增、在线曲线、
  代理销量排行、卡密类型分布、运行时状态（缓存驱动 / 心跳积压），30s 自动刷新 + 一键清缓存
  - 曲线数据来自新表 `nb_online_stats`（cron 每分钟写快照）
  - 图表为**纯 SVG 零依赖**（`assets/js/core/chart.js`），不引入任何第三方库
- 新增**留存复购分析**（后台「留存复购」）：D1 / D3 / D7 队列留存、用户与代理复购率、
  DAU / WAU / MAU 活跃分层、充值趋势
- 修复：引导文件 `set_error_handler` 会把 `@` 抑制的错误也升级为异常，
  导致 `@unlink` / `@fopen` 等容错写法在 PHP 8 下抛错（缓存与清理分支全部失灵）；
  现遵循 `error_reporting()` 语义，被 `@` 抑制的错误不再抛出
- 升级脚本：`php install/migrate_online_stats.php`（可重复执行，仅新增快照表）

### v2.24.0

- 新增**设备指纹**（多硬件组件加权）：客户端可在 `login` 上报 `device_fp`
  （`board` / `cpu` / `disk` / `bios` / `mac` / `gpu` 六项硬件特征串），服务端按稳定性加权合成
  指纹，解决「`machine_id` 是客户端自算、改一个字节就是新设备」的老问题
  - **组件漂移容忍**：换硬盘（升级 SSD）、换网卡、换显卡、刷 BIOS 都不会被误判为换机；
    只有**核心组件**（主板 / CPU）变化才判定为「疑似伪造机器码」
  - **模拟器 / 虚拟机识别**：设备名与系统信息关键词 + 虚拟机网卡 OUI 双重信号
  - **一机多号 / 一号多机统计**：同一指纹关联账号数、同一账号出现的指纹数超阈值即打标
  - 风险标记：`vm` / `low_entropy` / `same_value` / `fp_changed` / `multi_fp` / `shared_machine`，
    随 `login` 响应返回并写入日志；**默认只记录不拦截**，可在 config 打开硬拦截
  - 主后台「设备管理」新增**风险列 + 风险筛选**，并在顶部提示风险设备总数
  - **完全向后兼容**：客户端不上报 `device_fp` 时跳过全部指纹逻辑，行为与升级前一致；
    即使站点忘了跑迁移，接口也会自动降级（只丢指纹、不阻断登录）
  - **C++ SDK 已内置自动采集**（`nebula_sdk.hpp`）：登录时自动上报指纹与真实主机名，对接方无需手动处理
  - 升级脚本：`php install/migrate_device_fp.php`（可重复执行）
- 新增**卡密尝试限流 / 枚举防护**（此前只有单 IP 维度，挡不住针对卡密的爆破与撞库）
  - **单卡维度**：同一张卡密在窗口内被反复尝试即暂时拒绝（默认 8 次 / 10 分钟），
    限流键只存卡密的 SHA-256 摘要，不落明文
  - **枚举检测**：同一来源累计「卡密不存在」超过阈值即临时封禁（默认 30 次 / 10 分钟），
    专门对付「每次换一个卡号」的随机枚举
  - 生效范围：`activate`、激活码登录（`username_code` / `code`）、`/agent/` 激活码注册
  - 阈值见 `config/config.php` 的 `policy.card_try_*` / `policy.card_miss_*`

### v2.23.1

- 「张数额度」充值卡密升级为**多卡类型**：一张卡密可同时给多种卡类型分别设置增加张数
  - 生成时按卡类型逐行填写：填 `0` = 该类型不充值，填 `-1` = 该类型设为「不限量」；至少填一种
  - 代理商兑换**一次**即让所有填了张数的类型同时到账（明细如「时长卡 +10 张、点数卡 设为不限量」）
  - 新列 `nb_agent_recharge_codes.quota_map`（JSON，如 `{"1":10,"2":-1}`）；为空时回退旧字段
    `card_type` + `quota`，**存量单类型卡密行为完全不变**
  - 已是「不限量」的类型再充值仍保持不限量
  - 升级脚本：`php install/migrate_recharge_quota_map.php`（可重复执行）
- 修复：主后台**删除代理商**时只删了 `nb_agents`，遗留 `nb_agent_types` / `nb_agent_logs` 孤儿行；
  现在会连同会话、类型配置、操作日志一并清理（有卡密的代理依旧禁止删除）

### v2.22.0

- 新增**代理商充值卡密**：主后台「代理商激活码」页新增「充值卡密」页签，可批量生成两类卡密，
  代理商登录 `/agent/` → 「充值卡密」输入卡密即可**自助兑换**，无需管理员在线操作
  - **余额充值卡**：兑换后给代理余额加钱（如 ¥100），仅「余额计费」模式的代理有意义
  - **张数额度卡**：兑换后给指定卡类型加可生成张数（`-1` 表示设为「不限量」），仅「张数额度」模式的代理有意义
  - 支持卡密前缀（默认 `RCG`）、可兑换次数（>1 可当通用充值码）、有效期、启停与追溯（记录最近兑换代理）
  - 新表 `nb_agent_recharge_codes`；升级脚本：`php install/migrate_agent_recharge.php`（可重复执行）
  - 兑换走事务：条件 UPDATE 扣次数 + 入账，防并发重复兑换；已兑换卡密不可删除只能停用

### v2.21.0

- 生成代理商激活码时，若控量模式为**「余额计费」**，可额外设置**「注册后赠予余额」**：
  凭该码注册的代理到手即有此余额，可直接发货，不必再等管理员充值
  - 新列 `nb_agent_codes.init_balance`（单位：分）；仅余额计费模式生效，其它模式注册时写 0
  - 注册日志会记下赠予金额；激活码列表 / 详情均展示赠予额
  - 升级脚本：`php install/migrate_init_balance.php`（可重复执行；存量激活码默认 0，行为不变）
- 代理端**「发货规格」在余额计费下改为显示每种卡类型「可生成多少张」**（= 当前余额 ÷ 该类型单价），
  生成页与「我的发货规格」同步展示；主后台代理商列表 / 详情也按类型标出可生成张数

### v2.20.0

- 代理商激活码新增**「代理生成卡密的固定前缀」**：主管理员在生成/编辑激活码时填写（也可在「代理商 → 编辑」里改），
  该码注册出的代理在 `/agent/` 生成卡密时前缀被**强制锁定**，代理端不可修改 —— 便于按渠道 / 代理做码段识别与对账
  - 新列 `nb_agent_codes.card_prefix` 与 `nb_agents.card_prefix`（`NULL`/空 = 不限制，代理仍可自填，保持旧行为）
  - 代理端生成页在固定前缀时输入框变为只读并给出提示；后台激活码列表/详情、代理详情均展示该前缀
  - 升级脚本：`php install/migrate_card_prefix.php`（可重复执行；存量数据默认空，行为不变）

### v2.19.2

- 主后台侧边栏**默认收起**：首次进入时「业务管理 / 运营 / 系统」等分组全部折叠，
  点击分组标题即可展开；当前页所在分组始终展开，保证高亮项可见
  - 折叠状态改用 `nb_nav_collapsed_v2` 存储，与旧版（默认全展开）隔离，新老浏览器都生效
- 每次进入后台**默认落到「数据概览」**，不再沿用上次停留的页面（URL 会同步为 `#dashboard`）

### v2.19.1

- 代理商「激活用户组」升级为**按卡类型分别指定**：可为永久卡 / 时长卡 / 点数卡 / 次数卡
  各选一个用户组（如永久卡进 VIP、时长卡进普通），实现按卡分级发放
  - 激活码生成/编辑界面：发货规格表每种卡类型各带一个「激活用户组」下拉；
    原「激活用户组」降级为**兜底组**（某类型选「跟随默认」时生效）
  - 代理编辑界面同样可按类型指定；代理端「生成卡密」实时显示所选类型激活后进入的组
  - 新列 `nb_agent_types.group_id`（`0` = 跟随代理兜底组，两级都为 0 则不换组）；
    激活码 `preset` 的每个类型新增 `group_id` 字段，注册时复制到代理档案
  - 升级脚本：`php install/migrate_type_groups.php`（可重复执行；存量数据默认 `0`，行为不变）
- 主后台侧边栏支持**每个板块收起 / 展开**：点击分组标题即可折叠，状态本地记忆，
  当前页所在分组自动展开（小屏图标模式下始终展开）

### v2.19.0

- 新增**代理商激活码 + 自助注册**：主管理员在「代理商激活码」生成专属码，
  代理商打开 `/agent/#reg` 填码注册（可用次数 / 有效期 / 一键复制发放）
  - 激活码携带全部发货规格：**激活用户组 / 设备上限 / 是否可作废 / 控量模式 / 各卡类型额度单价**，
    注册时复制到代理档案，**代理商无法自改** —— 卡密激活后进入哪个用户组由主管理员决定
  - 注册次数用条件 UPDATE 消费，不会超发；已注册过的码禁止删除（保留追溯）
  - `nb_agents.reg_code` 记录来源激活码，后台激活码列表直接显示「已注册哪些代理」
  - 新设置项 `agent_register_enable`（可单独关闭自助注册）
  - 升级脚本：`php install/migrate_agent_types.php`（可重复执行）
- 代理商额度与单价改为**按卡类型分别配置**（新表 `nb_agent_types`）
  - 配额模式：永久卡 / 时长卡 / 点数卡 / 次数卡 各有各自的可生成张数（`-1` = 该类型不限，`enabled=0` = 不开放）
  - 余额模式：每种卡类型各有各自单价，生成时按**该类型的单价 × 张数**扣余额
  - 主后台「编辑代理商 / 生成激活码」提供类型矩阵；充值支持按类型加张数（`types: {"4": 2}`）
  - `nb_agents.quota_total / unit_price` 降级为历史字段，不再参与计费

### v2.18.0

- 新增**代理商（分销）体系**：独立后台 `/agent/`，代理商自助生成卡密
  - 三种控量模式：张数额度（`-1` 不限）/ 余额计费（单价 × 张数）/ 不限量
  - 卡密与批次写入 `agent_id` 归属，主后台「卡密管理」可按来源筛选、可对账
  - 设备上限与激活用户组由主管理员在代理档案固定，代理商无法越权发放高权限卡密
  - 独立账号表 / 会话表 / Cookie 名（`NBAGSID`），与主管理后台完全隔离
  - 可选入口密钥（`agent_entry_key`），未携带时返回仿真 404
  - 升级脚本：`php install/migrate_agent.php`（可重复执行）
- 官网首页「最新版本」改为与 `download` 接口 / 客户端 `version` 接口**同源**
  （`Version::latest()`：版本发布表优先，config 兜底），前端亦通过接口动态刷新
- 卡密列表 / 导出新增**来源筛选**（官方直发 / 指定代理商），列表新增「来源」列
- 修复 `card_export` 在同时使用 `ids` 与状态筛选时混用 `?` 与命名占位符导致 SQL 报错的问题

### v1.0.0

- 初始版本
- 客户端 API：init / register / login / heartbeat / activate / unbind / devices / userinfo / notice / version / logout
- 管理 API：用户、卡密、设备、会话、日志、统计、公告、版本、用户组、设置
- AES-256-CBC + HMAC-SHA256 通信加密
- 网页安装向导

---

## 许可

本项目仅供学习与自用，请勿用于非法用途。使用本系统进行软件授权时，请确保符合当地法律法规。
