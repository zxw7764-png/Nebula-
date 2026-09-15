<?php
/**
 * admin action: shop_setting_save
 * 保存发卡网配置（内容运营 → 发卡网配置 独立页面）
 *
 * ------------------------------------------------------------------
 * 与 setting_save.php 的关系
 * ------------------------------------------------------------------
 * 发卡配置原先塞在「系统设置 → 业务」页签里，现拆为运营分区的独立页面，
 * 保存接口也随之独立。此处只受理 shop_* 键（15 个），整表提交：
 *   · 开关/模式：shop_enable / shop_mode / shop_external_url
 *   · 支付：     shop_pay_mode / shop_epay_url / shop_epay_pid / shop_epay_key
 *   · 人工收款： shop_qrcode / shop_contact
 *   · 商店外观： shop_title / shop_notice / shop_banner / shop_theme / shop_footer
 *   · 商品陈列： shop_layout（商品展示样式 grid / list / compact / rows）
 *
 * 权限：shop_* 全部归业务档（SETTINGS_BUSINESS，仅超管），
 * 与原 setting_save.php 分档一致 —— 迁移不放宽任何权限。
 * 入口即拦截：ACTION_PERM 里 shop_setting_save 直接映射 SETTINGS_BUSINESS，
 * 操作员连 handler 都进不来（setting_save 是 SITE 入口 + 内部二次校验，
 * 本 action 收窄为 BUSINESS 入口，语义更直白）。
 */

$items = Util::get($input, 'settings', []);

if (!is_array($items) || !$items) {
    Response::error(1001, '没有需要保存的设置');
}

// 发卡网全套配置键：未登记的键一律拒绝（与 setting_save 同策略，不静默丢弃）
$allowed = [
    'shop_enable', 'shop_mode', 'shop_external_url', 'shop_pay_mode',
    'shop_epay_url', 'shop_epay_pid', 'shop_epay_key', 'shop_site_url',
    'shop_qrcode', 'shop_contact',
    'shop_card_gen_mode', 'shop_gen_prefix', 'shop_gen_expire_days',
    'shop_title', 'shop_notice', 'shop_banner', 'shop_theme', 'shop_footer', 'shop_notes',
    'shop_tab_title', 'shop_tab_alert', 'shop_tab_icon', 'shop_cats',
    'shop_logo',
    'shop_bg_url',       // 发卡网全站背景图（http(s) 或站内 /uploads/ 路径）
    'shop_ui_template',  // 发卡网界面模板（farm/mario/ink/space，空=默认深空）
    'shop_games_enabled', // 发卡网小游戏开关（模板右下角小游戏 + 排行榜）
    'shop_layout',
    'shop_popup_notice',
    'shop_contact_mode',
    'shop_detail_style',
    'shop_pay_driver',   // 当前支付驱动（epay/codepay/vmq）
    'shop_pay_cfg',      // 各驱动配置 JSON {driver:{field:value}}
    'shop_channels',     // 支付渠道展示配置（value|名称 每行一项）
    'shop_card_types',   // 卡类型配置（发卡商品 → 卡类型 子页）
    'shop_sw_filter',    // 按软件过滤商品（1=按官网/商店识别号显示对应商品）
    // 分软件分类：shop_cats_sw_<软件ID>（动态键，下方正则放行）
    'web_forever_text',  // 官网个人中心「永久会员」状态卡文案（发卡商品 → 卡类型 子页维护）
];

$normalized = [];
$unknown    = [];

foreach ($items as $k => $v) {
    // 保留数字：分软件分类键 shop_cats_sw_<软件ID> 含数字，洗掉数字会变成 shop_cats_sw_
    // （合法键仍由下方白名单 + 正则最终把关，放宽清洗不降低安全性）
    $k = preg_replace('/[^a-z0-9_]/', '', (string) $k);
    if ($k === '') {
        continue;
    }
    if (!in_array($k, $allowed, true) && !preg_match('/^shop_cats_sw_\d+$/', $k)) {
        $unknown[] = $k;
        continue;
    }
    $normalized[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
}

if ($unknown) {
    Logger::log('admin_shop_setting', 0, '发卡配置保存含未登记项: ' . implode(', ', $unknown), [
        'admin_id' => (int) $admin['id'],
    ]);
    Response::error(1001, '存在不支持的设置项：' . implode(', ', array_slice($unknown, 0, 5)));
}

if (!$normalized) {
    Response::error(1001, '没有可保存的设置项');
}

// ------------------------------------------------------------------
// 归一化校验：非法值直接拒绝（整单），避免垃圾数据进库
// ------------------------------------------------------------------
$errors = [];

if (isset($normalized['shop_mode'])
    && !in_array($normalized['shop_mode'], ['built', 'external'], true)) {
    $errors[] = '发卡模式非法';
}

if (isset($normalized['shop_pay_mode'])
    && !in_array($normalized['shop_pay_mode'], ['auto', 'manual'], true)) {
    $errors[] = '支付方式非法';
}

if (isset($normalized['shop_layout'])
    && !in_array($normalized['shop_layout'], ['grid', 'list', 'compact', 'rows', 'sidebar', 'pick'], true)) {
    $errors[] = '商品展示样式非法';
}

if (isset($normalized['shop_contact_mode'])
    && !in_array($normalized['shop_contact_mode'], ['phone', 'email', 'custom'], true)) {
    $errors[] = '下单/查询凭证方式非法';
}

if (isset($normalized['shop_ui_template'])
    && !UiTemplate::valid('shop', (string) $normalized['shop_ui_template'])) {
    $errors[] = '界面模板非法';
}

if (isset($normalized['shop_games_enabled'])
    && !in_array($normalized['shop_games_enabled'], ['0', '1'], true)) {
    $errors[] = '小游戏开关取值非法';
}

if (isset($normalized['shop_detail_style'])
    && !in_array($normalized['shop_detail_style'], ['page', 'modal'], true)) {
    $errors[] = '商品详情样式非法';
}

if (isset($normalized['shop_card_gen_mode'])
    && !in_array($normalized['shop_card_gen_mode'], ['stock', 'auto', 'gen'], true)) {
    $errors[] = '系统卡发货方式非法';
}

if (isset($normalized['shop_gen_prefix'])) {
    $normalized['shop_gen_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $normalized['shop_gen_prefix']));
}

if (isset($normalized['shop_gen_expire_days'])) {
    $d = trim((string) $normalized['shop_gen_expire_days']);
    if ($d !== '' && (!ctype_digit($d) || (int) $d > 3650)) {
        $errors[] = '卡密有效期非法（0-3650 的整数天）';
    }
}

if (isset($normalized['shop_sw_filter'])) {
    $normalized['shop_sw_filter'] = $normalized['shop_sw_filter'] === '1' ? '1' : '0';
}

foreach (['shop_external_url', 'shop_epay_url', 'shop_site_url'] as $urlKey) {
    if (!isset($normalized[$urlKey])) {
        continue;
    }
    $u = trim($normalized[$urlKey]);
    if ($u !== '' && !preg_match('#^https?://#i', $u)) {
        $errors[] = '「' . $urlKey . '」需为 http(s) 开头的完整地址';
    }
}

// 收款码：http(s) 外链或站内上传路径（/uploads/shop/xxx）
if (isset($normalized['shop_qrcode'])) {
    $q = trim((string) $normalized['shop_qrcode']);
    if ($q !== '' && !preg_match('#^https?://#i', $q) && $q[0] !== '/') {
        $errors[] = '「收款码」需为 http(s) 地址或上传后的站内路径';
    } else {
        $normalized['shop_qrcode'] = $q;
    }
}

// 背景大图：http(s) 外链或站内上传路径，铺满发卡网全站并自动叠加暗色遮罩
if (isset($normalized['shop_bg_url'])) {
    $bg = trim((string) $normalized['shop_bg_url']);
    if ($bg !== '' && !preg_match('#^https?://#i', $bg) && $bg[0] !== '/') {
        $errors[] = '「背景大图」需为 http(s) 地址或上传后的站内路径';
    } else {
        $normalized['shop_bg_url'] = $bg;
    }
}

// 横幅大图：http(s) 外链或站内上传路径（/uploads/shop/xxx.jpg，含 GIF 动图）
if (isset($normalized['shop_banner'])) {
    $b = trim((string) $normalized['shop_banner']);
    if ($b !== '' && !preg_match('#^https?://#i', $b) && $b[0] !== '/') {
        $errors[] = '「横幅大图」需为 http(s) 地址或上传后的站内路径';
    } else {
        $normalized['shop_banner'] = $b;
    }
}

if (isset($normalized['shop_theme'])) {
    $t = strtolower(trim($normalized['shop_theme']));
    if ($t !== '' && !preg_match('/^#[0-9a-f]{6}$/', $t)) {
        $errors[] = '主题色需为 #RRGGBB 格式';
    } else {
        $normalized['shop_theme'] = $t; // 大写归一化
    }
}

if (isset($normalized['shop_tab_icon'])) {
    $i = strtolower(trim($normalized['shop_tab_icon']));
    $normalized['shop_tab_icon'] = in_array($i, ['dot', 'heart', 'keep'], true) ? $i : 'dot';
}

// 支付驱动：仅允许内置 epay 或已安装插件
if (isset($normalized['shop_pay_driver'])) {
    $d = strtolower(trim($normalized['shop_pay_driver']));
    if ($d !== '' && !Pay::driverExists($d)) {
        $errors[] = '支付驱动不存在或插件未安装';
    } else {
        $normalized['shop_pay_driver'] = $d !== '' ? $d : 'epay';
    }
}

// 支付驱动配置：必须是合法 JSON 对象，键名/键值做白名单收敛
if (isset($normalized['shop_pay_cfg'])) {
    $cfg = json_decode((string) $normalized['shop_pay_cfg'], true);
    if (!is_array($cfg)) {
        $errors[] = '支付驱动配置格式错误';
    } else {
        $clean = [];
        foreach ($cfg as $drv => $fields) {
            $drv = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $drv));
            if ($drv === '' || !Pay::driverExists($drv) || !is_array($fields)) {
                continue;
            }
            $fv = [];
            foreach ($fields as $fk => $fval) {
                $fk = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $fk));
                if ($fk === '') {
                    continue;
                }
                $fv[$fk] = mb_substr(trim((string) $fval), 0, 500);
            }
            $clean[$drv] = $fv;
        }
        $normalized['shop_pay_cfg'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
}

// 插件市场地址：http(s) 完整地址
if (isset($normalized['shop_card_types'])) {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $normalized['shop_card_types']) as $line) {
        $p = array_map('trim', explode('|', (string) $line));
        if (count($p) < 3 || trim(implode('', $p)) === '') {
            continue;
        }
        if (!isset(\Shop::CARD_TYPE_DEFAULTS[(int) $p[0]])) {
            $errors[] = '卡类型配置第 ' . (count($lines) + 1) . ' 行类型 ID 非法（仅支持 1-4）';
            continue;
        }
        $p[1] = mb_substr($p[1], 0, 20);
        $p[2] = $p[2] === '1' ? '1' : '0';
        if (isset($p[3])) {
            $p[3] = mb_substr($p[3], 0, 50); // 每类型自定义提示文案（可选第 4 段）
        }
        $lines[] = implode('|', $p);
    }
    $normalized['shop_card_types'] = implode("\n", $lines);
}

if ($errors) {
    Response::error(1001, implode('；', $errors));
}

// ------------------------------------------------------------------
// 写入 + 审计
// ------------------------------------------------------------------
$oldValues = [];
$newValues = [];
$saved     = [];

foreach ($normalized as $k => $val) {
    $oldValues[$k] = (string) Setting::get($k, '');
    Setting::set($k, $val);
    $newValues[$k] = $val;
    $saved[] = $k;
}

Audit::log($admin, 'shop_setting_save', '发卡网配置',
    '修改发卡配置：' . implode(', ', $saved),
    $oldValues, $newValues);

Response::ok(['saved' => $saved], '发卡网配置已保存');
