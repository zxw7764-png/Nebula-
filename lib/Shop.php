<?php
/**
 * 发卡网业务（官网 /shop/）
 * ------------------------------------------------------------------
 * 三种玩法并存，后台可切换（nb_settings.shop_*）：
 *   · shop_mode=built + shop_pay_mode=auto    易支付自动发卡
 *     下单 → 跳易支付收银台 → 异步回调验签 → 官方直发卡库自动取卡 → 前台展示卡密
 *   · shop_mode=built + shop_pay_mode=manual  人工确认兜底
 *     下单 → 前台展示收款码与订单号 → 管理员后台确认收款并发卡（自动取卡或手动补码）
 *   · shop_mode=external                      跳转外部独立发卡站
 *     官网购买入口直接跳 shop_external_url，本模块仅提供配置读取
 *
 * 卡源与库存口径：
 *   商品（套餐）挂卡规格 = card_type + card_duration + card_max_devices + card_group_id，
 *   库存 = nb_cards 中该精确规格、status=0（未使用）、agent_id=0（官方直发）、
 *   未过期的卡数。发卡即把取到的卡标为 status=3（已售出未激活）并绑定订单；
 *   买家激活时 Card::activate() 对 status=3 的卡天然放行（只拒 VOID/USED/过期），
 *   激活后变 status=1，链路闭环。
 *   外部卡密商品（card_source=1，非本验证系统的卡）：不挂规格，从 nb_shop_cards
 *   导入卡密池发货——买家付款后取池中一条未售内容直接交付，人工补发任意文本直发。
 *
 * 本文件由 lib/bootstrap.php 加载，官网 /shop/、管理后台、cron 三处共用。
 */
class Shop
{
    /** 订单状态 */
    const ORDER_PENDING   = 0; // 待支付
    const ORDER_DELIVERED = 1; // 已发卡
    const ORDER_CLOSED    = 2; // 已关闭（人工关闭 / 超时）
    const ORDER_MANUAL    = 3; // 已收款待人工处理（缺货补发等）

    /** 支付方式 */
    const PAY_EPAY   = 1; // 易支付自动
    const PAY_MANUAL = 2; // 人工确认

    /** 待支付订单保留时长（人工单超时自动关闭；自动单等回调，不自动关） */
    const MANUAL_ORDER_TTL = 86400;

    /** 卡密来源 */
    const SOURCE_SYSTEM = 0; // 本系统卡密：按挂卡规格从 nb_cards 自动取卡
    const SOURCE_EXT    = 1; // 外部卡密：从 nb_shop_cards 导入池发货（非本验证系统商品）

    // --------------------------------------------------------------
    // 配置读取
    // --------------------------------------------------------------

    /** 发卡网总开关 */
    public static function enabled(): bool
    {
        return Setting::bool('shop_enable', false);
    }

    /** 发卡模式：built=内置发卡 external=跳转外部发卡站 */
    public static function mode(): string
    {
        $m = strtolower(trim((string) Setting::get('shop_mode', 'built')));
        return in_array($m, ['built', 'external'], true) ? $m : 'built';
    }

    /** 外部发卡站地址（仅 http/https） */
    public static function externalUrl(): string
    {
        $url = trim((string) Setting::get('shop_external_url', ''));
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /** 内置发卡支付方式：auto=易支付自动发卡 manual=人工确认 */
    public static function payMode(): string
    {
        $m = strtolower(trim((string) Setting::get('shop_pay_mode', 'auto')));
        return in_array($m, ['auto', 'manual'], true) ? $m : 'auto';
    }

    /** 易支付三项配置是否齐全（url / pid / key） */
    public static function epayReady(): bool
    {
        return Setting::get('shop_epay_url', '') !== ''
            && Setting::get('shop_epay_pid', '') !== ''
            && Setting::get('shop_epay_key', '') !== '';
    }

    /** 人工模式展示信息 */
    public static function manualInfo(): array
    {
        return [
            'qrcode'  => preg_match('#^https?://#i', (string) Setting::get('shop_qrcode', ''))
                ? (string) Setting::get('shop_qrcode') : '',
            'contact' => (string) Setting::get('shop_contact', ''),
        ];
    }

    // --------------------------------------------------------------
    // 商店外观（后台可视化自定义，类似 AGC 发卡网的商店装修）
    // --------------------------------------------------------------

    /**
     * 商店外观配置：标题 / 公告 / 横幅 / 主题色 / 页脚。
     * 全部可空 —— 空值回退内置默认，商店开箱即用。
     */
    public static function styleConfig(): array
    {
        return [
            'title'  => trim((string) Setting::get('shop_title', '')),
            // 浏览器标签页：自定义标题（留空用商店标题）+ 离开页面时的闪动提醒文案
            'tab_title' => trim((string) Setting::get('shop_tab_title', '')),
            'tab_alert' => trim((string) Setting::get('shop_tab_alert', '')),
            'tab_icon'  => self::tabIcon((string) Setting::get('shop_tab_icon', '')),
            'cats'     => self::catConfig((string) Setting::get('shop_cats', '')),
            'notice' => trim((string) Setting::get('shop_notice', '')),
            'popup'  => trim((string) Setting::get('shop_popup_notice', '')),
            // 横幅：http(s) 外链或站内上传路径（/uploads/shop/...），与保存侧同口径
            'banner' => preg_match('#^(https?://|/)#i', (string) Setting::get('shop_banner', ''))
                ? (string) Setting::get('shop_banner') : '',
            'theme'  => self::themeBase((string) Setting::get('shop_theme', '')),
            'layout' => self::goodsLayout((string) Setting::get('shop_layout', '')),
            'detail' => self::goodsDetailStyle((string) Setting::get('shop_detail_style', '')),
            'footer' => trim((string) Setting::get('shop_footer', '')),
            'notes'  => trim((string) Setting::get('shop_notes', '')),
            'logo'   => self::logoUrl((string) Setting::get('shop_logo', '')),
        ];
    }

    /** 校验 Logo 地址：接受 http(s) 或 / 开头的站内路径，非法/为空返回空串（回退默认 logo.png） */
    public static function logoUrl(string $url): string
    {
        $url = trim($url);
        return ($url !== '' && (preg_match('#^https?://#i', $url) || $url[0] === '/')) ? $url : '';
    }

    /** 校验离开页面标签图标：dot 主题色圆点 / heart 爱心圆点 / keep 保留原图标，非法回 dot */
    public static function tabIcon(string $v): string
    {
        $v = strtolower(trim($v));
        return in_array($v, ['dot', 'heart', 'keep'], true) ? $v : 'dot';
    }

    /**
     * 分类配置：每行「名称|图标URL」（图标可省略）。
     * 返回 [ ['name'=>.., 'icon'=>..], ... ]；配置中的分类在商品分类页签中优先展示。
     */
    public static function catConfig(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = array_map('trim', explode('|', $line, 2));
            $name = mb_substr($p[0], 0, 60);
            if ($name === '') continue;
            $icon = isset($p[1]) ? trim($p[1]) : '';
            if ($icon !== '' && !preg_match('#^(https?://|/)#i', $icon)) {
                $icon = '';
            }
            $out[] = ['name' => $name, 'icon' => $icon];
        }
        return $out;
    }

    /** 校验主题色：只接受 #RRGGBB，非法返回空串（回退默认） */
    public static function themeBase(string $hex): string
    {        $hex = strtolower(trim($hex));
        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '';
    }

    /** 校验商品展示样式：grid 网格卡片 / list 横向列表 / compact 紧凑小卡 / rows 橱窗列表，非法回退 grid */
    public static function goodsLayout(string $v): string
    {
        $v = strtolower(trim($v));
        return in_array($v, ['grid', 'compact', 'rows', 'sidebar', 'pick'], true) ? $v : 'grid';
    }

    /** 校验商品详情样式：page 整页详情（左图右购买面板）/ modal 弹窗详情，非法回退 page */
    public static function goodsDetailStyle(string $v): string
    {
        $v = strtolower(trim($v));
        return in_array($v, ['page', 'modal'], true) ? $v : 'page';
    }

    /**
     * 下单/查询凭证形态（后台发卡网配置）：phone 手机号 / email 邮箱 / custom 自定义内容。
     * 三种形态均配套查询密码，买家凭「凭证+密码」查询订单。
     */
    public static function contactMode(): string
    {
        $v = strtolower(trim((string) Setting::get('shop_contact_mode', 'phone')));
        return in_array($v, ['phone', 'email', 'custom'], true) ? $v : 'phone';
    }

    /** 凭证输入框文案（前端 label / placeholder 用） */
    public static function contactModeLabel(string $mode = ''): array
    {
        $mode = $mode !== '' ? $mode : self::contactMode();
        return match ($mode) {
            'email'  => ['label' => '邮箱', 'placeholder' => '用于查询订单，如 you@example.com'],
            'custom' => ['label' => '联系凭证', 'placeholder' => '自定义内容（账号/昵称等），用于查询订单'],
            default  => ['label' => '手机号', 'placeholder' => '用于查询订单，如 13800000000'],
        };
    }

    /** 凭证格式校验：合法返回 true */
    public static function contactValid(string $mode, string $contact): bool
    {
        return match ($mode) {
            'email' => (bool) filter_var($contact, FILTER_VALIDATE_EMAIL) && strlen($contact) <= 100,
            'phone' => (bool) preg_match('/^1[3-9][0-9]{9}$/', $contact),
            default => mb_strlen($contact, 'UTF-8') >= 2 && mb_strlen($contact, 'UTF-8') <= 50,
        };
    }

    /**
     * 主题色衍生：主色 + 提亮 25% 的辅助色（hover / 文字强调用）。
     * 返回 ['base' => '#7c5cff', 'light' => '#967dff']，非法输入返回空数组。
     */
    public static function themeColors(string $hex): array
    {
        if (!preg_match('/^#([0-9a-f]{6})$/', $hex, $m)) {
            return [];
        }
        $r = hexdec(substr($m[1], 0, 2));
        $g = hexdec(substr($m[1], 2, 2));
        $b = hexdec(substr($m[1], 4, 2));
        $lift = static fn (int $c): int => (int) round($c + (255 - $c) * 0.25);
        return [
            'base'  => $hex,
            'light' => sprintf('#%02x%02x%02x', $lift($r), $lift($g), $lift($b)),
        ];
    }

    // --------------------------------------------------------------
    // 商品与库存
    // --------------------------------------------------------------

    /** 卡规格的可读文案 */
    public static function specText(int $type, int $duration, int $maxDevices): string
    {
        switch ($type) {
            case Card::TYPE_FOREVER:
                $d = '永久';
                break;
            case Card::TYPE_DURATION:
                $d = Util::duration(max(1, $duration));
                break;
            case Card::TYPE_POINTS:
                $d = $duration . ' 点数';
                break;
            case Card::TYPE_TIMES:
                $d = $duration . ' 次';
                break;
            default:
                $d = '未知规格';
        }
        return $d . ' / ' . $maxDevices . ' 台设备';
    }

    /**
     * 有效售价文案（与下单金额同口径）。
     * ------------------------------------------------------------------
     * 售价的唯一权威来源是「挂卡类型」上的 price；商品级 shop_price 只是
     * 规格未单独定价时的兜底值。此前列表页直接读 shop_price，出现
     * 「规格里填了 50，商品列表却显示 0 / 免费」这种前后不一致。
     *
     * @param mixed $defaultPrice 商品级兜底售价（元）
     * @param array $cards        挂卡规格（每项含 price）
     * @return string             '免费' | '30' | '10 ~ 100'
     */
    public static function priceText($defaultPrice, array $cards): string
    {
        $base = (float) $defaultPrice;
        $list = [];
        foreach ($cards as $c) {
            $v = (float) ($c['price'] ?? 0);
            $list[] = $v > 0 ? $v : $base;
        }
        if (!$list) {
            $list = [$base];
        }
        $min = min($list);
        $max = max($list);
        if ($max <= 0) {
            return '免费';
        }
        $fmt = static function (float $v): string {
            return $v == floor($v) ? (string) (int) $v : number_format($v, 2, '.', '');
        };
        return $min === $max ? $fmt($min) : $fmt($min) . ' ~ ' . $fmt($max);
    }

    /** 系统固定卡类型默认名 */
    public const CARD_TYPE_DEFAULTS = [
        Card::TYPE_DURATION => '时长卡',
        Card::TYPE_POINTS   => '点数卡',
        Card::TYPE_TIMES    => '次数卡',
        Card::TYPE_FOREVER  => '永久卡',
    ];

    /**
     * 卡类型显示名：后台「发卡商品 → 卡类型」可自定义名称（shop_card_types，
     * 每行「类型ID|显示名|启用」）。发卡前台规格说明、代理发货规格等展示统一走这里。
     */
    public static function cardTypeText(int $type): string
    {
        static $names = null;
        if ($names === null) {
            $names = self::CARD_TYPE_DEFAULTS;
            foreach (preg_split('/\r\n|\r|\n/', trim((string) Setting::get('shop_card_types', ''))) as $line) {
                $p = array_map('trim', explode('|', (string) $line));
                if (count($p) >= 2 && isset($names[(int) $p[0]]) && $p[1] !== '') {
                    $names[(int) $p[0]] = mb_substr($p[1], 0, 20);
                }
            }
        }
        return $names[$type] ?? '未知';
    }

    /**
     * 卡类型配置全集（固定 4 类）：['id','name','on','hint']
     * 未配置的类型视为启用 + 默认名。
     */
    public static function cardTypeConfig(): array
    {
        $cfg = [];
        foreach (preg_split('/\r\n|\r|\n/', trim((string) Setting::get('shop_card_types', ''))) as $line) {
            $p = array_map('trim', explode('|', (string) $line));
            if (count($p) >= 3 && isset(self::CARD_TYPE_DEFAULTS[(int) $p[0]])) {
                $cfg[(int) $p[0]] = $p;
            }
        }
        $hints = [
            Card::TYPE_DURATION => '卡面 = 秒数',
            Card::TYPE_POINTS   => '卡面 = 点数',
            Card::TYPE_TIMES    => '卡面 = 次数',
            Card::TYPE_FOREVER  => '激活后永久有效（vip_expire=-1）',
        ];
        $out = [];
        foreach (self::CARD_TYPE_DEFAULTS as $id => $def) {
            $out[] = [
                'id'   => $id,
                'name' => isset($cfg[$id]) && $cfg[$id][1] !== '' ? mb_substr($cfg[$id][1], 0, 20) : $def,
                'on'   => !isset($cfg[$id]) || $cfg[$id][2] === '1',
                'hint' => isset($cfg[$id][3]) && $cfg[$id][3] !== '' ? mb_substr($cfg[$id][3], 0, 50) : $hints[$id],
            ];
        }
        return $out;
    }

    /**
     * 某精确规格的官方直发库存（status=0、未过期）
     * group_id 参与匹配：套餐挂卡指定了激活用户组，取到不同组的卡会货不对板。
     */
    public static function stock(int $type, int $duration, int $maxDevices, int $groupId, int $softwareId = 0): int
    {
        $swSql = $softwareId > 0 ? ' AND software_id = ?' : '';
        $params = $softwareId > 0
            ? [$type, $duration, $maxDevices, $groupId, Card::STATUS_UNUSED, time(), $softwareId]
            : [$type, $duration, $maxDevices, $groupId, Card::STATUS_UNUSED, time()];
        return (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('cards') . "
             WHERE type = ? AND duration = ? AND max_devices = ? AND group_id = ?
               AND status = ? AND agent_id = 0
               AND (expire_at = 0 OR expire_at > ?){$swSql}",
            $params
        );
    }

    /** 外部卡密池库存（未售条数）；池表未建（未跑迁移）时视为 0 */
    public static function extStock(int $planId): int
    {
        try {
            return (int) Database::value(
                'SELECT COUNT(*) FROM ' . Database::t('shop_cards') . '
                 WHERE plan_id = ? AND status = 0',
                [$planId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** 外部卡密池按「商品 + 挂卡类型」的未售条数（多规格下每种类型独立库存） */
    public static function extStockByType(int $planId, int $cardType): int
    {
        try {
            return (int) Database::value(
                'SELECT COUNT(*) FROM ' . Database::t('shop_cards') . '
                 WHERE plan_id = ? AND card_type = ? AND status = 0',
                [$planId, $cardType]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** 外部卡密池按「商品」分挂卡类型的未售条数（返回 [card_type => count]） */
    public static function extStockByTypes(int $planId): array
    {
        try {
            $rows = Database::all(
                'SELECT card_type, COUNT(*) AS cnt FROM ' . Database::t('shop_cards') . '
                 WHERE plan_id = ? AND status = 0 GROUP BY card_type',
                [$planId]
            );
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r['card_type']] = (int) $r['cnt'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * nb_shop_plans 是否已有 shop_software_id 列（静态缓存，进程内只查一次）。
     * 未跑 migrate_shop_sw.php 的老库：显示过滤整体降级为不生效，避免 SQL 报错。
     */
    public static function hasShopSwCol(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $has = (bool) Database::one(
                'SHOW COLUMNS FROM ' . Database::t('shop_plans') . " LIKE 'shop_software_id'"
            );
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /** 未跑 migrate_shop_notice.php 的老库：shop_notice 列不存在时降级为无提示，避免 SQL 报错。 */
    public static function hasShopNoticeCol(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $has = (bool) Database::one(
                'SHOW COLUMNS FROM ' . Database::t('shop_plans') . " LIKE 'shop_notice'"
            );
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * 取商品「查单自定义提示」：商品级单条文案，买家查到该商品已支付订单时展示。
     * @param int $planId     商品 ID（shop_plans.id）
     * @param int $softwareId 订单归属软件 ID（保留参数，当前未用于区分）
     */
    public static function planNotice(int $planId, int $softwareId): string
    {
        if (!self::hasShopNoticeCol() || $planId <= 0) {
            return '';
        }
        $row = Database::one(
            'SELECT shop_notice FROM ' . Database::t('shop_plans') . ' WHERE id = ?',
            [$planId]
        );
        if (!$row || empty($row['shop_notice'])) {
            return '';
        }
        return trim((string) $row['shop_notice']);
    }

    /**
     * 当前访客命中的软件 ID（按软件过滤商品用），未识别返回 0。
     * 解析顺序与官网 web_current_software 一致：
     *   URL ?app=<app_key> → Cookie nb_web_app（官网带识别号进商店会记住）
     *   → 恰好只有一个启用软件时直接用它。
     * 只读不写 Cookie / 不 setCurrent（写记忆由官网 portal.php 完成）。
     */
    public static function visitorSoftwareId(): int
    {
        $byKey = static function (string $key): int {
            $key = trim($key);
            if ($key === '') {
                return 0;
            }
            try {
                $sw = Software::byAppKey($key);
                return $sw ? (int) $sw['id'] : 0;
            } catch (Throwable $e) {
                return 0;
            }
        };

        $app = isset($_GET['app']) && is_string($_GET['app']) ? $_GET['app'] : '';
        $id  = $byKey($app);
        if ($id > 0) {
            return $id;
        }

        $id = $byKey((string) ($_COOKIE['nb_web_app'] ?? ''));
        if ($id > 0) {
            return $id;
        }

        try {
            $all = Software::all(true);
            if (count($all) === 1) {
                return (int) $all[0]['id'];
            }
        } catch (Throwable $e) {
            // ignore
        }
        return 0;
    }

    /**
     * 上架发卡的商品列表（前台商品页用，附带实时库存）
     * 独立发卡商品表 nb_shop_plans（2.32.23 起与官网价格套餐 nb_plans 完全分离），
     * 只看 shop_status=1；删除 / 编辑互不影响官网套餐。
     *
     * 按软件显示（后台 shop_sw_filter 开启时）：
     *   识别到访客软件 → 只出「通用(0) + 该软件」的商品；
     *   未识别（多软件且没带识别号）→ 只出通用商品。
     *   老库缺列（未跑迁移）时忽略过滤，行为与开关关闭一致。
     */
    public static function shopPlans(): array
    {
        if (!WebInteract::tableReady('shop_plans')) {
            return [];
        }
        $swWhere  = '';
        $swBind   = [];
        $hasSwCol = self::hasShopSwCol();
        if (Setting::bool('shop_sw_filter') && $hasSwCol) {
            $swId = self::visitorSoftwareId();
            if ($swId > 0) {
                $swWhere = ' AND shop_software_id IN (0, :swid)';
                $swBind  = [':swid' => $swId];
            } else {
                $swWhere = ' AND shop_software_id = 0';
            }
        }
        // 显示归属软件列一并取出：发货时它同时决定自动生成 / 取卡的卡密归属软件
        $swSelect = $hasSwCol ? ', shop_software_id' : ', 0 AS shop_software_id';
        try {
            $rows = Database::all(
                'SELECT id, name, duration, `desc`, shop_category, shop_icon, shop_intro, shop_detail,
                        shop_name, shop_badge, shop_highlight,
                        shop_status, shop_price, card_source,
                        software_id, card_type, card_duration, card_max_devices, card_group_id'
                        . $swSelect . '
                 FROM ' . Database::t('shop_plans') . '
                 WHERE shop_status = 1' . $swWhere . '
                 ORDER BY sort DESC, id ASC',
                $swBind
            );
        } catch (Throwable $e) {
            // 老站未跑 migrate_shop.php：缺列时降级为无商品，不整页报错
            return [];
        }

        $out = [];
        foreach ($rows as $p) {
            $src    = (int) ($p['card_source'] ?? self::SOURCE_SYSTEM);
            $type   = (int) $p['card_type'];
            $dur    = (int) $p['card_duration'];
            $maxDev = max(1, (int) $p['card_max_devices']);
            $gid    = (int) $p['card_group_id'];
            // 发货归属软件（下单快照进订单 → 取卡 / 自动生成卡密都按它）：库存展示也按它统计，
            // 否则会显示「其他软件有货」而实际发不出卡
            $deliverSw = self::deliverSoftwareId($p);
            // 多规格挂卡类型：取全部卡片，并用首张卡片覆盖主展示字段（兼容旧读卡逻辑）
            $cards = self::planCards((int) $p['id']);
            if ($cards) {
                $c0     = $cards[0];
                $type   = $c0['card_type'];
                $dur    = $c0['card_duration'];
                $maxDev = $c0['card_max_devices'];
                $gid    = $c0['card_group_id'];
                foreach ($cards as &$c) {
                    $c['stock'] = $src === self::SOURCE_EXT
                        ? self::extStockByType((int) $p['id'], $c['card_type'])
                        : self::stock($c['card_type'], $c['card_duration'], $c['card_max_devices'], $c['card_group_id'], $deliverSw);
                }
                unset($c);
            }
            $points = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $p['desc'])),
                static fn ($s) => $s !== ''
            ));

            $shopName = trim((string) ($p['shop_name'] ?? ''));
            $out[] = [
                'id'             => (int) $p['id'],
                'name'           => $shopName !== '' ? $shopName : (string) $p['name'],
                'price'          => (string) $p['shop_price'],  // 兼容旧输出（展示文案）
                'unit'           => '元',
                'duration'       => (string) $p['duration'],
                'points'         => $points,
                'badge'          => (string) ($p['shop_badge'] ?? ''),
                'highlight'      => (int) ($p['shop_highlight'] ?? 0) === 1,
                'category'       => mb_substr(trim((string) ($p['shop_category'] ?? '')), 0, 60),
                'icon'           => preg_match('#^(https?://|/)#i', (string) ($p['shop_icon'] ?? ''))
                                    ? trim((string) $p['shop_icon']) : '',
                'intro'          => mb_substr(trim((string) ($p['shop_intro'] ?? '')), 0, 200),
                'detail'         => trim((string) ($p['shop_detail'] ?? '')),
                'shop_price'     => number_format((float) $p['shop_price'], 2, '.', ''), // 实售价（元）
                'card_type'      => $type,
                'card_type_text' => self::cardTypeText($type),
                'card_duration'  => $dur,
                'card_max_devices' => $maxDev,
                'card_group_id'  => $gid,
                'card_source'    => $src,
                // 发货归属软件（解析结果）：下单时快照进订单，取卡 / 自动生成卡密都按它。
                // 旧逻辑只读挂卡归属列（商品从不设置，恒为默认 1）→ 订单 software_id 恒为 1，
                // 商品「所属软件」形同虚设，自动生成的卡密全归软件 1。
                'software_id'      => $deliverSw,
                'shop_software_id' => (int) ($p['shop_software_id'] ?? 0),
                'is_ext'         => $src === self::SOURCE_EXT,
                'spec_text'      => $src === self::SOURCE_EXT ? '卡密商品' : self::specText($type, $dur, $maxDev),
                // 有效售价文案：与下单金额同口径（规格价优先，回落默认售价）
                'price_text'     => self::priceText($p['shop_price'], $cards),
                'cards'          => $cards,
                'stock'          => $src === self::SOURCE_EXT
                    ? self::extStock((int) $p['id'])
                    : self::stock($type, $dur, $maxDev, $gid, $deliverSw),
            ];
        }
        return $out;
    }

    /** plan_cards 多规格表是否存在（未跑迁移的老库降级为无多规格） */
    public static function hasPlanCardsTable(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            // 注意：Database::t() 会给表名加反引号，再被 quote() 包成单引号后，
            // SHOW TABLES LIKE '`nb_shop_plan_cards`' 会去匹配名字含反引号的表，
            // 永远查不到。这里取原始表名（prefix + name）做 quote。
            $rawName = Config::get('db.prefix', '') . 'shop_plan_cards';
            $has = (bool) Database::one(
                'SHOW TABLES LIKE ' . Database::pdo()->quote($rawName)
            );
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /** 取商品的全部挂卡类型（多规格），按 sort、id 升序；空数组表示未配置（回退主表规格）。 */
    public static function planCards(int $planId): array
    {
        if (!self::hasPlanCardsTable()) {
            return [];
        }
        try {
            $rows = Database::all(
                'SELECT id, card_type, card_duration, price, card_max_devices, card_group_id, sort, status
                 FROM ' . Database::t('shop_plan_cards') . '
                 WHERE plan_id = ? ORDER BY sort ASC, id ASC',
                [$planId]
            );
        } catch (Throwable $e) {
            // 查询失败记录日志便于排查（仍降级为空，不影响页面）
            if (class_exists('Logger')) {
                Logger::log('shop', 2, 'planCards 查询失败', ['err' => $e->getMessage(), 'plan_id' => $planId]);
            }
            return [];
        }
        return array_map(static function ($r) {
            $type = (int) $r['card_type'];
            return [
                'id'               => (int) $r['id'],
                'card_type'        => $type,
                'card_type_text'   => self::cardTypeText($type),
                'card_duration'    => (int) $r['card_duration'],
                'price'            => number_format((float) $r['price'], 2, '.', ''),
                'card_max_devices' => max(1, (int) $r['card_max_devices']),
                'card_group_id'    => (int) $r['card_group_id'],
                'sort'             => (int) $r['sort'],
                'status'           => (int) $r['status'],
            ];
        }, $rows);
    }

    /** 同步某商品的挂卡类型（多规格）：清空旧记录后按数组重写。cards 每项含 card_type/card_duration/price/card_max_devices/card_group_id。 */
    public static function syncPlanCards(int $planId, array $cards): void
    {
        if (!self::hasPlanCardsTable() || $planId <= 0) {
            return;
        }
        try {
            Database::exec(
                'DELETE FROM ' . Database::t('shop_plan_cards') . ' WHERE plan_id = ?',
                [$planId]
            );
            foreach ($cards as $i => $c) {
                Database::insert('shop_plan_cards', [
                    'plan_id'          => $planId,
                    'card_type'        => (int) $c['card_type'],
                    'card_duration'    => (int) $c['card_duration'],
                    'price'            => (string) $c['price'],
                    'card_max_devices' => (int) $c['card_max_devices'],
                    'card_group_id'    => (int) $c['card_group_id'],
                    'sort'             => $i,
                    'status'           => 1,
                    'created_at'       => time(),
                ]);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::log('shop', 0, 'syncPlanCards 失败: ' . $e->getMessage(), ['plan_id' => $planId]);
            }
        }
    }

    /** 单个上架商品（下单前复核用），不存在或未上架返回 null */
    public static function shopPlan(int $planId): ?array
    {
        foreach (self::shopPlans() as $p) {
            if ($p['id'] === $planId) {
                return $p;
            }
        }
        return null;
    }

    // --------------------------------------------------------------
    // 下单
    // --------------------------------------------------------------

    /** 订单号：S + 12位时间(ymdHis) + 6位随机十六进制，共 19 位，不可枚举 */
    public static function genOrderNo(): string
    {
        return 'S' . date('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * 创建订单
     * @param int    $planId     套餐 ID
     * @param string $contact    买家联系方式（人工模式必填）
     * @param string $channel    支付渠道 alipay/wxpay/qqpay（auto 模式用）
     * @param string $notifyBase 异步回调基地址，如 https://site.com（auto 模式用）
     * @param string $returnBase 支付完成跳转基地址（auto 模式用）
     * @param string $ip         下单 IP
     * @return array{ok:bool, code:int, msg:string, data:?array}
     *         data: order_no / amount(分) / pay_type / pay_url(auto) / qrcode / contact(人工)
     */
    public static function createOrder(
        int $planId,
        string $contact,
        string $queryPwd,
        string $channel,
        string $notifyBase,
        string $returnBase,
        string $ip,
        int $qty = 1,
        int $userId = 0,
        int $specIndex = -1
    ): array {
        if (!self::enabled() || self::mode() !== 'built') {
            return ['ok' => false, 'code' => 4001, 'msg' => '发卡网未开启', 'data' => null];
        }

        $plan = self::shopPlan($planId);
        if (!$plan) {
            return ['ok' => false, 'code' => 4002, 'msg' => '商品不存在或已下架', 'data' => null];
        }

        // 多规格：选中某个挂卡类型时，用该规格的卡类型/时长/设备/组/售价快照，覆盖商品默认规格
        $specs = is_array($plan['cards'] ?? null) ? $plan['cards'] : [];
        $spec  = null;
        if ($specIndex >= 0 && isset($specs[$specIndex])) {
            $spec = $specs[$specIndex];
            $plan['card_type']        = (int) $spec['card_type'];
            $plan['card_duration']    = (int) $spec['card_duration'];
            $plan['card_max_devices'] = (int) $spec['card_max_devices'];
            $plan['card_group_id']    = (int) $spec['card_group_id'];
            if ((float) $spec['price'] > 0) {
                $plan['shop_price'] = $spec['price'];
            }
        }

        $qty     = min(99, max(1, $qty));
        $amount  = (int) round(((float) $plan['shop_price']) * 100) * $qty; // 元 → 分 × 数量
        if ($amount < 0) {
            return ['ok' => false, 'code' => 4003, 'msg' => '该商品售价配置异常', 'data' => null];
        }

        // 0 元免费商品同样走下单 → 易支付 → 回调发卡的完整流程（money=0.00，
        // 回调金额校验 0===0 通过后自动发卡）；决定自动还是人工与付费商品一致
        $payType = (self::payMode() === 'auto' && (self::epayReady() || Pay::ready(Pay::active()))) ? self::PAY_EPAY : self::PAY_MANUAL;

        // 下单凭证（手机号/邮箱/自定义内容）+ 查询密码：买家后续凭「凭证+密码」查单
        $mode    = self::contactMode();
        $contact = trim(mb_substr($contact, 0, 100, 'UTF-8'));
        $queryPwd = trim($queryPwd);

        // 已登录买家：免填凭证，订单自动关联账号（个人中心可见）；
        // 免填路径不做凭证格式校验（uid:N 不是手机号/邮箱，属正常内部形态）
        $skipContact = $userId > 0 && $contact === '';
        if ($skipContact) {
            $contact = 'uid:' . $userId;
        }
        if ($contact === '') {
            return ['ok' => false, 'code' => 1001, 'msg' => '请填写' . self::contactModeLabel($mode)['label'], 'data' => null];
        }
        if (!$skipContact && !self::contactValid($mode, $contact)) {
            $tip = $mode === 'phone' ? '手机号格式不正确' : ($mode === 'email' ? '邮箱格式不正确' : '联系凭证需 2-50 个字符');
            return ['ok' => false, 'code' => 1001, 'msg' => $tip, 'data' => null];
        }
        if ($queryPwd !== '' || $userId <= 0) {
            if (strlen($queryPwd) < 4 || strlen($queryPwd) > 32) {
                return ['ok' => false, 'code' => 1001, 'msg' => '查询密码需 4-32 位，用于日后查询订单', 'data' => null];
            }
        }

        $orderNo = self::genOrderNo();
        $now     = time();

        $orderId = (int) Database::insert('shop_orders', [
            'order_no'    => $orderNo,
            'plan_id'     => $plan['id'],
            'plan_name'   => $plan['name'],
            'software_id' => self::deliverSoftwareId($plan),
            'card_source' => (int) ($plan['card_source'] ?? self::SOURCE_SYSTEM),
            'card_type'   => $plan['card_type'],
            'duration'    => $plan['card_duration'],
            'max_devices' => $plan['card_max_devices'],
            'group_id'    => $plan['card_group_id'],
            'amount'      => $amount,
            'qty'         => $qty,
            'pay_type'    => $payType,
            'channel'     => $payType === self::PAY_EPAY ? preg_replace('/[^a-z]/', '', strtolower($channel)) : '',
            'status'      => self::ORDER_PENDING,
            'contact'     => $contact,
            'query_pwd'   => $queryPwd !== '' ? password_hash($queryPwd, PASSWORD_DEFAULT) : '',
            'user_id'     => $userId,
            'client_ip'   => $ip,
            'created_at'  => $now,
        ]);

        Logger::log('shop_order', 1, '发卡网下单', [
            'raw' => [
                'order_no' => $orderNo,
                'plan_id'  => $plan['id'],
                'amount'   => $amount,
                'pay_type' => $payType,
            ],
            'ip' => $ip,
        ]);

        $data = [
            'order_no'  => $orderNo,
            'plan_name' => $plan['name'],
            'amount'    => $amount,
            'pay_type'  => $payType,
        ];

        if ($payType === self::PAY_EPAY) {
            // 支付驱动统一走 Pay 层：内置 epay 或已安装插件（支付宝/微信/聚合等）
            $payUrl = Pay::payUrl([
                'order_no' => $orderNo,
                'product'  => $plan['name'],
                'amount'   => $amount,
                'channel'  => $channel,
            ], rtrim($notifyBase, '/') . '/shop/notify.php', rtrim($returnBase, '/') . '/shop/?o=' . urlencode($orderNo));
            if ($payUrl === '') {
                // 理论上 epayReady 已挡住，这里兜底降级人工
                Database::update('shop_orders', ['pay_type' => self::PAY_MANUAL], 'order_no = :ono', ['ono' => $orderNo]);
                $data['pay_type'] = self::PAY_MANUAL;
                $data += self::manualInfo();
            } else {
                $data['pay_url'] = $payUrl;
            }
        } else {
            $data += self::manualInfo();
        }

        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    /**
     * 待支付订单重新拉起支付：仅 待支付 + 自动支付单 + 当前驱动配置齐全 时返回收银台地址。
     * 人工单 / 已支付 / 已关闭 / 驱动未配置 均返回空串（前端不显示按钮）。
     */
    public static function repayUrl(string $orderNo, string $base): string
    {
        $o = Database::one(
            'SELECT order_no, plan_name, amount, pay_type, status, channel FROM ' . Database::t('shop_orders') . ' WHERE order_no = ?',
            [strtoupper(trim($orderNo))]
        );
        if (!$o || (int) $o['status'] !== self::ORDER_PENDING || (int) $o['pay_type'] !== self::PAY_EPAY) {
            return '';
        }
        if (!Pay::ready(Pay::active())) {
            return '';
        }
        return Pay::payUrl([
            'order_no' => (string) $o['order_no'],
            'product'  => (string) $o['plan_name'],
            'amount'   => (int) $o['amount'],
            'channel'  => (string) $o['channel'] ?: 'alipay',
        ], rtrim($base, '/') . '/shop/notify.php', rtrim($base, '/') . '/shop/?o=' . urlencode((string) $o['order_no']));
    }

    // --------------------------------------------------------------
    // 取卡与发卡（事务）
    // --------------------------------------------------------------

    /**
     * 按精确规格取一张官方直发未使用卡（事务内 FOR UPDATE），标为已售出。
     * 调用方必须已处于 Database::begin() 事务中。
     * @return array{id:int, code:string}|null 缺货返回 null
     */
    /**
     * 激活卡密后补绑发卡订单：游客下单时 user_id=0，激活卡密的账号即是买家，
     * 凭卡密 remark 里的订单号把订单挂到该账号（个人中心即可见），只绑未归属订单。
     */
    public static function bindOrderByCard(string $code, int $uid): void
    {
        $code = strtoupper(trim($code));
        if ($uid <= 0 || $code === '') {
            return;
        }
        $card = Database::one(
            'SELECT remark FROM ' . Database::t('cards') . ' WHERE code = ?',
            [$code]
        );
        if (!$card || (string) $card['remark'] === '') {
            return;
        }
        if (!preg_match('/^发卡网(?:售出|自动生成):([A-Z0-9]+)$/u', (string) $card['remark'], $m)) {
            return; // 非发卡网来源（代理售出等）不处理
        }
        Database::update(
            'shop_orders',
            ['user_id' => $uid],
            'order_no = :o AND user_id = 0',
            ['o' => $m[1]]
        );
    }

    public static function pickCard(int $type, int $duration, int $maxDevices, int $groupId, string $orderNo, int $softwareId = 0): ?array
    {
        $tbl = Database::t('cards');
        $now = time();

        $swSql  = $softwareId > 0 ? ' AND software_id = ?' : '';
        $params = $softwareId > 0
            ? [$type, $duration, $maxDevices, $groupId, Card::STATUS_UNUSED, $now, $softwareId]
            : [$type, $duration, $maxDevices, $groupId, Card::STATUS_UNUSED, $now];

        $card = Database::one(
            "SELECT id, code FROM {$tbl}
             WHERE type = ? AND duration = ? AND max_devices = ? AND group_id = ?
               AND status = ? AND agent_id = 0
               AND (expire_at = 0 OR expire_at > ?){$swSql}
             ORDER BY id ASC LIMIT 1 FOR UPDATE",
            $params
        );
        if (!$card) {
            return null;
        }

        // 原子标记已售出（双重保险：即使锁竞争异常也不会把已用/已作废的卡卖出去）
        $n = Database::update(
            'cards',
            ['status' => 3, 'remark' => '发卡网售出:' . $orderNo],
            'id = :id AND status = :st',
            ['id' => (int) $card['id'], 'st' => Card::STATUS_UNUSED]
        );
        if ($n !== 1) {
            return null;
        }

        return ['id' => (int) $card['id'], 'code' => (string) $card['code']];
    }

    /** 支付渠道配置：value|名称 每行一项（value 限 alipay/wxpay/qqpay），返回 [{value,label}] */
    public static function channels(): array
    {
        $defLabels = ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包'];
        $out = [];
        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) Setting::get('shop_channels', '')) as $line) {
            $p = array_map('trim', explode('|', (string) $line));
            $v = strtolower(preg_replace('/[^a-z]/', '', (string) ($p[0] ?? '')));
            if ($v === '' || !isset($defLabels[$v]) || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = ['value' => $v, 'label' => mb_substr($p[1] ?? '', 0, 12) ?: $defLabels[$v]];
        }
        if (!$out) {
            $out = [
                ['value' => 'alipay', 'label' => $defLabels['alipay']],
                ['value' => 'wxpay',  'label' => $defLabels['wxpay']],
                ['value' => 'qqpay',  'label' => $defLabels['qqpay']],
            ];
        }
        return $out;
    }

    /** 订单支付方式展示文案：当前驱动简称 + 渠道自定义名称（如「V免签 · 微信」） */
    public static function payLabel(string $channel): string
    {
        $drv = Pay::active();
        $drvNames = ['epay' => '易支付', 'codepay' => '码支付', 'vmq' => 'V免签'];
        $base = $drvNames[$drv] ?? '易支付';
        $ch = strtolower(trim($channel));
        if ($ch === '') {
            return $base;
        }
        foreach (self::channels() as $c) {
            if ($c['value'] === $ch) {
                return $base . ' · ' . $c['label'];
            }
        }
        return $base;
    }

    /**
     * 商品自身的发货归属软件（不含访客识别，后台展示用）。
     *   1) 挂卡归属 software_id > 1 —— 后台显式指定了非默认软件（老数据默认 1 视为未设置）；
     *   2) 商品「所属软件」shop_software_id > 0 —— 显示归属同时作为发货归属，
     *      保证「商品设置了所属软件 → 取卡 / 自动生成的卡密就归该软件」，不再一律落到软件 1；
     *   3) 兜底 1。
     */
    public static function planOwnedSoftwareId(array $plan): int
    {
        $sw = (int) ($plan['software_id'] ?? 0);
        if ($sw > 1) {
            return $sw;
        }
        $disp = (int) ($plan['shop_software_id'] ?? 0);
        if ($disp > 0) {
            return $disp;
        }
        return $sw > 0 ? $sw : 1;
    }

    /**
     * 下单时快照进订单的 software_id（发货归属软件）。
     * 商品未指定归属的「通用商品」按访客识别号归属，避免跨软件发卡导致买家激活失败。
     */
    public static function deliverSoftwareId(array $plan): int
    {
        $own = self::planOwnedSoftwareId($plan);
        if ($own > 1) {
            return $own;
        }
        $visitor = self::visitorSoftwareId();
        return $visitor > 0 ? $visitor : $own;
    }

    /** 系统卡发货方式：stock 仅库存 / auto 先用库存缺货自动生成 / gen 每单自动生成，非法回退 stock */
    public static function cardGenMode(): string
    {
        $v = strtolower(trim((string) Setting::get('shop_card_gen_mode', 'stock')));
        return in_array($v, ['stock', 'auto', 'gen'], true) ? $v : 'stock';
    }

    /**
     * 下单自动生成一张系统卡（事务内调用）：直接建为已售出状态并绑定订单，买家激活即用。
     * 规格沿用订单快照（类型/时长/设备数/用户组），前缀与卡密有效期取后台「支付设置」配置。
     * @return array{id:int, code:string}
     */
    public static function genCardForOrder(int $type, int $duration, int $maxDevices, int $groupId, string $orderNo, int $softwareId = 0): array
    {
        $softwareId = $softwareId > 0 ? $softwareId : 1;
        $prefix    = preg_replace('/[^A-Za-z0-9]/', '', (string) Setting::get('shop_gen_prefix', ''));
        $expireDay = max(0, min(3650, (int) Setting::get('shop_gen_expire_days', 0)));
        $now       = time();

        for ($i = 0; $i < 5; $i++) {
            $code = Util::cardCode($prefix, 4, 4);
            if (Database::value('SELECT id FROM ' . Database::t('cards') . ' WHERE code = ?', [$code])) {
                continue;
            }
            $id = Database::insert('cards', [
                'code'        => $code,
                'software_id' => $softwareId,
                'type'        => $type,
                'duration'    => $duration,
                'max_devices' => $maxDevices,
                'group_id'    => $groupId,
                'status'      => 3, // 已售出：激活逻辑对 status=3 放行（同库存售卡口径）
                'agent_id'    => 0, // 显式归属官方直发：与代理商库存(agent_id>0)彻底区隔，绝不占用/混入代理卡池
                'expire_at'   => $expireDay > 0 ? $now + $expireDay * 86400 : 0,
                'remark'      => '发卡网自动生成:' . $orderNo,
                'created_at'  => $now,
            ]);
            return ['id' => (int) $id, 'code' => $code];
        }
        throw new RuntimeException('自动生成卡密失败（卡号冲突）');
    }

    /**
     * 从外部卡密池取一条未售卡密（事务内 FOR UPDATE），标记已售并绑定订单。
     * 调用方必须已处于 Database::begin() 事务中。
     * @return array{id:int, code:string}|null 缺货返回 null
     */
    public static function pickExtCard(int $planId, int $orderId, string $orderNo, int $cardType = 0): ?array
    {
        $tbl = Database::t('shop_cards');

        // 多规格：优先按订单所选卡类型取卡；未分类（card_type=0）的旧数据兜底可用
        $hasType = false;
        try {
            $hasType = (bool) Database::one("SHOW COLUMNS FROM {$tbl} LIKE 'card_type'");
        } catch (Throwable $e) {
            $hasType = false;
        }
        if ($hasType && $cardType > 0) {
            $row = Database::one(
                "SELECT id, batch_id, content FROM {$tbl}
                 WHERE plan_id = ? AND status = 0 AND (card_type = ? OR card_type = 0)
                 ORDER BY (card_type = 0) ASC, id ASC LIMIT 1 FOR UPDATE",
                [$planId, $cardType]
            );
        } else {
            $row = Database::one(
                "SELECT id, batch_id, content FROM {$tbl}
                 WHERE plan_id = ? AND status = 0
                 ORDER BY id ASC LIMIT 1 FOR UPDATE",
                [$planId]
            );
        }
        if (!$row) {
            return null;
        }

        $n = Database::update(
            'shop_cards',
            ['status' => 1, 'order_id' => $orderId, 'sold_at' => time()],
            'id = :id AND status = 0',
            ['id' => (int) $row['id']]
        );
        if ($n !== 1) {
            return null;
        }

        // 批次已售计数同步递增，卡密批次页能看到外部导入批次的售出进度
        if ((int) ($row['batch_id'] ?? 0) > 0) {
            Database::exec(
                'UPDATE ' . Database::t('card_batches') . ' SET used_count = used_count + 1 WHERE id = ?',
                [(int) $row['batch_id']]
            );
        }

        return ['id' => (int) $row['id'], 'code' => (string) $row['content']];
    }

    /**
     * 给订单发卡（幂等）
     * 前置：收款已确认（auto 回调验签通过 / 管理员后台确认）。
     * 自动取卡失败（缺货）时订单转 ORDER_MANUAL 待人工补发，返回 shortage=true。
     *
     * @return array{ok:bool, code:int, msg:string, data:?array}
     *         data: card_code / shortage(bool)
     */
    public static function deliver(int $orderId): array
    {
        Database::begin();
        try {
            $order = Database::one(
                'SELECT * FROM ' . Database::t('shop_orders') . ' WHERE id = ? FOR UPDATE',
                [$orderId]
            );
            if (!$order) {
                Database::rollback();
                return ['ok' => false, 'code' => 4004, 'msg' => '订单不存在', 'data' => null];
            }

            $status = (int) $order['status'];
            if ($status === self::ORDER_DELIVERED) {
                // 幂等：重复回调 / 重复点击直接返回已发卡结果
                Database::commit();
                return ['ok' => true, 'code' => 0, 'msg' => '订单已发卡', 'data' => ['card_code' => (string) $order['card_code'], 'shortage' => false]];
            }
            if ($status === self::ORDER_CLOSED) {
                Database::rollback();
                return ['ok' => false, 'code' => 4005, 'msg' => '订单已关闭，无法发卡', 'data' => null];
            }

            // 按购买数量逐张取卡（事务内 FOR UPDATE，单张失败整单回滚防拆单）
            // 系统卡发货方式（后台「支付设置→系统卡发货方式」）：
            //   stock 仅库存取卡（缺货转人工）/ auto 先用库存、缺货自动生成 / gen 每单自动生成
            $qty   = max(1, (int) ($order['qty'] ?? 1));
            $cards = [];
            for ($i = 0; $i < $qty; $i++) {
                if ((int) ($order['card_source'] ?? self::SOURCE_SYSTEM) === self::SOURCE_EXT) {
                    $card = self::pickExtCard((int) $order['plan_id'], $orderId, (string) $order['order_no'], (int) ($order['card_type'] ?? 0));
                } else {
                    $mode = self::cardGenMode();
                    $card = null;
                    if ($mode !== 'gen') {
                        $card = self::pickCard(
                            (int) $order['card_type'],
                            (int) $order['duration'],
                            (int) $order['max_devices'],
                            (int) $order['group_id'],
                            (string) $order['order_no'],
                            (int) ($order['software_id'] ?? 1)
                        );
                    }
                    if (!$card && $mode !== 'stock') {
                        $card = self::genCardForOrder(
                            (int) $order['card_type'],
                            (int) $order['duration'],
                            (int) $order['max_devices'],
                            (int) $order['group_id'],
                            (string) $order['order_no'],
                            (int) ($order['software_id'] ?? 1)
                        );
                    }
                }
                if (!$card) {
                    break;
                }
                $cards[] = $card;
            }

            if (count($cards) === $qty) {
                $now   = time();
                $codes = array_column($cards, 'code');
                Database::update('shop_orders', [
                    'status'       => self::ORDER_DELIVERED,
                    'card_id'      => $cards[0]['id'],
                    'card_code'    => implode("\n", $codes),
                    'delivered_at' => $now,
                    'remark'       => '',
                ], 'id = :id', ['id' => $orderId]);

                // 卡密审计：与激活/作废记录同表，便于追溯这张卡卖给了哪个订单
                // 外部卡密不属于 nb_cards，card_id 记 0，detail 注明来源
                $isExt = (int) ($order['card_source'] ?? 0) === self::SOURCE_EXT;
                foreach ($cards as $card) {
                    Database::insert('card_logs', [
                        'card_id'    => $isExt ? 0 : $card['id'],
                        'code'       => mb_substr($card['code'], 0, 500),
                        'user_id'    => 0,
                        'action'     => 'shop_sell',
                        'detail'     => '发卡网售出 订单:' . $order['order_no'] . ($isExt ? '（外部卡密）' : ''),
                        'ip'         => (string) $order['client_ip'],
                        'created_at' => $now,
                    ]);
                }

                Database::commit();
                return ['ok' => true, 'code' => 0, 'msg' => '发卡成功', 'data' => ['card_code' => implode("\n", $codes), 'shortage' => false]];
            }

            // 缺货（含部分取到）：整单回滚后转人工待处理，管理员补发
            Database::rollback();
            Database::begin();
            Database::update('shop_orders', [
                'status' => self::ORDER_MANUAL,
                'remark' => '已收款但库存不足，待人工补发',
            ], 'id = :id', ['id' => $orderId]);
            Database::commit();

            Logger::log('shop_deliver', 0, '发卡缺货，转人工处理', [
                'raw' => ['order_no' => $order['order_no'], 'plan' => $order['plan_name']],
            ]);
            return ['ok' => false, 'code' => 4006, 'msg' => '暂时缺货，已转人工处理', 'data' => ['shortage' => true]];
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('shop_deliver', 0, '发卡异常: ' . $e->getMessage(), [
                'raw' => ['order_id' => $orderId],
            ]);
            return ['ok' => false, 'code' => 5000, 'msg' => '发卡失败，请稍后重试', 'data' => null];
        }
    }

    // --------------------------------------------------------------
    // 易支付对接（彩虹易支付 md5 协议）
    // --------------------------------------------------------------

    /**
     * 易支付签名：参数按键名 ASCII 升序拼接 a=v&b=v（跳过空值/sign/sign_type），
     * 末尾直接接商户密钥后取 md5。
     */
    public static function epaySign(array $params, string $key): string
    {
        ksort($params);
        $buf = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) {
                continue;
            }
            $buf[] = $k . '=' . $v;
        }
        return md5(implode('&', $buf) . $key);
    }

    /** 生成易支付收银台跳转地址（GET 参数携带签名） */
    public static function epayPayUrl(
        string $orderNo,
        string $productName,
        int $amountFen,
        string $channel,
        string $notifyUrl,
        string $returnUrl
    ): string {
        $gateway = rtrim((string) Setting::get('shop_epay_url', ''), '/');
        $pid     = (string) Setting::get('shop_epay_pid', '');
        $key     = (string) Setting::get('shop_epay_key', '');
        if ($gateway === '' || $pid === '' || $key === '') {
            return '';
        }

        $channel = in_array($channel, ['alipay', 'wxpay', 'qqpay'], true) ? $channel : 'alipay';

        $params = [
            'pid'          => $pid,
            'type'         => $channel,
            'out_trade_no' => $orderNo,
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => mb_substr($productName, 0, 60, 'UTF-8'),
            'money'        => number_format($amountFen / 100, 2, '.', ''),
        ];
        $params['sign']      = self::epaySign($params, $key);
        $params['sign_type'] = 'MD5';

        return $gateway . '/submit.php?' . http_build_query($params);
    }

    /**
     * 校验易支付异步通知：
     *   1. md5 验签（hash_equals 防时序）
     *   2. trade_status 必须为 TRADE_SUCCESS
     * @param array $params $_GET（易支付 GET 通知）
     */
    public static function epayVerifyNotify(array $params): bool
    {
        $key = (string) Setting::get('shop_epay_key', '');
        if ($key === '' || empty($params['sign'])) {
            return false;
        }
        $expect = self::epaySign($params, $key);
        return hash_equals($expect, (string) $params['sign'])
            && ($params['trade_status'] ?? '') === 'TRADE_SUCCESS';
    }

    /**
     * 处理易支付异步回调（幂等）
     * 验签 → 锁订单 → 校验金额 → 标记已支付 → 发卡。
     * @return array{ok:bool, msg:string}
     */
    public static function handleNotify(array $params): array
    {
        $orderNo = (string) ($params['out_trade_no'] ?? '');
        [$signOk, $payOrderNo, $payMoneyFen] = Pay::verifyNotify($params);
        // 插件驱动的订单号 / 金额字段可能与易支付不同，以驱动返回为准
        if ($orderNo === '') {
            $orderNo = $payOrderNo;
        }
        if ($orderNo === '' || !$signOk) {
            return ['ok' => false, 'msg' => '验签失败'];
        }

        $order = Database::one(
            'SELECT * FROM ' . Database::t('shop_orders') . ' WHERE order_no = ?',
            [$orderNo]
        );
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        // 金额一致性校验：以本地订单金额为准，防止篡改金额的低额支付
        $payMoney = $payMoneyFen >= 0 ? $payMoneyFen : (int) round(((float) ($params['money'] ?? 0)) * 100);
        if ($payMoney !== (int) $order['amount']) {
            Logger::log('shop_notify', 0, '回调金额与订单不一致', [
                'raw' => ['order_no' => $orderNo, 'pay_money' => $payMoney, 'order_amount' => (int) $order['amount']],
            ]);
            return ['ok' => false, 'msg' => '金额不一致'];
        }

        $status = (int) $order['status'];
        if ($status === self::ORDER_DELIVERED) {
            return ['ok' => true, 'msg' => 'success']; // 幂等：重复通知直接确认
        }
        if ($status === self::ORDER_CLOSED) {
            // 极小概率：人工关单后买家才完成支付。不给自动复活（防止对账混乱），记日志人工处理。
            Logger::log('shop_notify', 0, '已关闭订单收到支付回调', [
                'raw' => ['order_no' => $orderNo, 'trade_no' => (string) ($params['trade_no'] ?? '')],
            ]);
            return ['ok' => false, 'msg' => '订单已关闭'];
        }

        // 标记支付信息（已支付状态由 deliver 落成已发卡；先落 trade_no 便于对账）
        Database::update('shop_orders', [
            'trade_no' => (string) ($params['trade_no'] ?? ''),
            'paid_at'  => time(),
        ], 'order_no = :ono AND status = :st', ['ono' => $orderNo, 'st' => $status]);

        $r = self::deliver((int) $order['id']);
        if ($r['ok']) {
            Logger::log('shop_notify', 1, '支付回调发卡成功', [
                'raw' => ['order_no' => $orderNo, 'trade_no' => (string) ($params['trade_no'] ?? '')],
            ]);
            return ['ok' => true, 'msg' => 'success'];
        }
        // 缺货已转 ORDER_MANUAL：对易支付仍应答 success（钱已收，不再重试通知）
        return ['ok' => $r['data']['shortage'] ?? false, 'msg' => $r['msg']];
    }

    // --------------------------------------------------------------
    // 查询与超时清理
    // --------------------------------------------------------------

    /**
     * 买家凭订单号查询（订单号本身随机不可枚举，调用方需另行限流）。
     * 已发卡订单返回卡密；其余状态只返回状态描述，不泄露联系方式等信息。
     */
    public static function queryByOrderNo(string $orderNo): ?array
    {
        $orderNo = strtoupper(trim($orderNo));
        if (!preg_match('/^S[0-9]{12}[0-9A-F]{10}$/', $orderNo)) {
            return null;
        }
        $o = Database::one(
            'SELECT order_no, plan_id, software_id, plan_name, amount, pay_type, status, card_code, created_at, paid_at, delivered_at
             FROM ' . Database::t('shop_orders') . ' WHERE order_no = ?',
            [$orderNo]
        );
        if (!$o) {
            return null;
        }
        $cardsTable = Database::t('cards');
        $codes = [];
        if ((int) $o['status'] === self::ORDER_DELIVERED) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $o['card_code']) as $c) {
                $c = trim($c);
                if ($c === '') { continue; }
                $card = Database::one(
                    "SELECT status FROM {$cardsTable} WHERE code = ? LIMIT 1",
                    [$c]
                );
                $codes[] = ['code' => $c, 'activated' => $card && (int) $card['status'] === 1];
            }
        }
        $notice = '';
        if ((int) $o['status'] === self::ORDER_DELIVERED || (int) $o['status'] === self::ORDER_MANUAL) {
            $notice = self::planNotice((int) $o['plan_id'], (int) $o['software_id']);
        }
        return [
            'order_no'    => (string) $o['order_no'],
            'plan_name'   => (string) $o['plan_name'],
            'amount'      => number_format(((int) $o['amount']) / 100, 2, '.', ''),
            'pay_type'    => (int) $o['pay_type'],
            'status'      => (int) $o['status'],
            'status_text' => self::orderStatusText((int) $o['status']),
            'card_code'   => (int) $o['status'] === self::ORDER_DELIVERED ? (string) $o['card_code'] : '',
            'codes'       => $codes,
            'created_at'  => Util::date((int) $o['created_at']),
            'notice'      => $notice,
        ];
    }

    /**
     * 买家凭「下单凭证 + 查询密码」查询订单列表（最近 20 单）。
     * 凭证精确匹配 + 逐单密码校验，防手机号/邮箱被枚举撞库。
     * 已发卡订单返回卡密。
     */
    public static function queryByContact(string $contact, string $pwd): array
    {
        $contact = trim(mb_substr($contact, 0, 100, 'UTF-8'));
        $pwd     = trim($pwd);
        if ($contact === '' || $pwd === '') {
            return [];
        }
        $rows = Database::all(
            'SELECT order_no, plan_id, software_id, plan_name, amount, pay_type, status, card_code, query_pwd, created_at
             FROM ' . Database::t('shop_orders') . '
             WHERE contact = ? AND query_pwd <> ?
             ORDER BY id DESC LIMIT 20',
            [$contact, '']
        );
        $out = [];
        $cardsTable = Database::t('cards');
        foreach ($rows as $o) {
            if (!password_verify($pwd, (string) $o['query_pwd'])) {
                continue;
            }
            $codes = [];
            if ((int) $o['status'] === self::ORDER_DELIVERED) {
                foreach (preg_split('/\r\n|\r|\n/', (string) $o['card_code']) as $c) {
                    $c = trim($c);
                    if ($c === '') { continue; }
                    $card = Database::one(
                        "SELECT status FROM {$cardsTable} WHERE code = ? LIMIT 1",
                        [$c]
                    );
                    $codes[] = ['code' => $c, 'activated' => $card && (int) $card['status'] === 1];
                }
            }
            $notice = '';
            if ((int) $o['status'] === self::ORDER_DELIVERED || (int) $o['status'] === self::ORDER_MANUAL) {
                $notice = self::planNotice((int) $o['plan_id'], (int) $o['software_id']);
            }
            $out[] = [
                'order_no'    => (string) $o['order_no'],
                'plan_name'   => (string) $o['plan_name'],
                'amount'      => number_format(((int) $o['amount']) / 100, 2, '.', ''),
                'pay_type'    => (int) $o['pay_type'],
                'status'      => (int) $o['status'],
                'status_text' => self::orderStatusText((int) $o['status']),
                'card_code'   => (int) $o['status'] === self::ORDER_DELIVERED ? (string) $o['card_code'] : '',
                'codes'       => $codes,
                'notice'      => $notice,
                'created_at'  => Util::date((int) $o['created_at']),
            ];
        }
        return $out;
    }

    public static function orderStatusText(int $status): string
    {
        return [
            self::ORDER_PENDING   => '待支付',
            self::ORDER_DELIVERED => '已发卡',
            self::ORDER_CLOSED    => '已关闭',
            self::ORDER_MANUAL    => '人工处理中',
        ][$status] ?? '未知';
    }

    /**
     * 关闭超时未支付的人工单（cron 调用）。
     * 自动单不自动关：等回调对账（关单后回调会被拒绝，容易扯皮），由管理员手动处理。
     * @return int 关闭的订单数
     */
    public static function closeExpiredManualOrders(): int
    {
        return Database::exec(
            'UPDATE ' . Database::t('shop_orders') . '
             SET status = ?, remark = "超时未支付，自动关闭"
             WHERE pay_type = ? AND status = ? AND created_at < ?',
            [self::ORDER_CLOSED, self::PAY_MANUAL, self::ORDER_PENDING, time() - self::MANUAL_ORDER_TTL]
        );
    }

    // --------------------------------------------------------------
    // 管理端
    // --------------------------------------------------------------

    /** 后台订单统计（列表页顶部小卡） */
    public static function adminStats(): array
    {
        $t   = Database::t('shop_orders');
        $day = strtotime('today');

        return [
            'today_count' => (int) Database::value("SELECT COUNT(*) FROM {$t} WHERE created_at >= ?", [$day]),
            'today_paid'  => (int) Database::value(
                "SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE paid_at >= ? AND status IN (?, ?, ?)",
                [$day, self::ORDER_DELIVERED, self::ORDER_MANUAL, self::ORDER_PENDING]
            ),
            'pending'     => (int) Database::value("SELECT COUNT(*) FROM {$t} WHERE status = ?", [self::ORDER_PENDING]),
            'manual'      => (int) Database::value("SELECT COUNT(*) FROM {$t} WHERE status = ?", [self::ORDER_MANUAL]),
        ];
    }

    /**
     * 管理员确认收款并发卡（人工单 / 缺货补发通用）。
     * @param string|null $manualCode 手动补发时填一张官方直发未使用卡密；null=自动取卡
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function adminDeliver(int $orderId, ?string $manualCode = null): array
    {
        if ($manualCode !== null && trim($manualCode) !== '') {
            return self::adminDeliverManual($orderId, strtoupper(trim($manualCode)));
        }
        return self::deliver($orderId);
    }

    /** 手动补发：把管理员指定的卡绑定到订单（校验可用性与规格一致） */
    private static function adminDeliverManual(int $orderId, string $code): array
    {
        Database::begin();
        try {
            $order = Database::one(
                'SELECT * FROM ' . Database::t('shop_orders') . ' WHERE id = ? FOR UPDATE',
                [$orderId]
            );
            if (!$order) {
                Database::rollback();
                return ['ok' => false, 'code' => 4004, 'msg' => '订单不存在', 'data' => null];
            }
            if ((int) $order['status'] === self::ORDER_DELIVERED) {
                Database::rollback();
                return ['ok' => false, 'code' => 4005, 'msg' => '订单已发卡，请勿重复操作', 'data' => null];
            }
            if ((int) $order['status'] === self::ORDER_CLOSED) {
                Database::rollback();
                return ['ok' => false, 'code' => 4005, 'msg' => '订单已关闭', 'data' => null];
            }

            if ((int) ($order['card_source'] ?? self::SOURCE_SYSTEM) === self::SOURCE_EXT) {
                // 外部卡密商品：人工补发不做规格校验，任意文本直接发货；
                // 池中若存在相同内容的未售卡则标记一条，保持库存与批次已售计数准确
                $now = time();
                $pool = Database::one(
                    'SELECT id, batch_id FROM ' . Database::t('shop_cards')
                    . ' WHERE plan_id = ? AND content = ? AND status = 0 LIMIT 1 FOR UPDATE',
                    [(int) $order['plan_id'], $code]
                );
                if ($pool) {
                    Database::update('shop_cards',
                        ['status' => 1, 'order_id' => $orderId, 'sold_at' => $now],
                        'id = :id AND status = 0',
                        ['id' => (int) $pool['id']]);
                    if ((int) ($pool['batch_id'] ?? 0) > 0) {
                        Database::exec(
                            'UPDATE ' . Database::t('card_batches') . ' SET used_count = used_count + 1 WHERE id = ?',
                            [(int) $pool['batch_id']]
                        );
                    }
                }
                Database::update('shop_orders', [
                    'status'       => self::ORDER_DELIVERED,
                    'card_id'      => 0,
                    'card_code'    => $code,
                    'delivered_at' => $now,
                    'remark'       => '人工补发(外部卡密)',
                ], 'id = :id', ['id' => $orderId]);
                Database::insert('card_logs', [
                    'card_id'    => 0,
                    'code'       => mb_substr($code, 0, 500),
                    'user_id'    => 0,
                    'action'     => 'shop_sell',
                    'detail'     => '发卡网人工补发(外部卡密) 订单:' . $order['order_no'],
                    'ip'         => (string) $order['client_ip'],
                    'created_at' => $now,
                ]);
                Database::commit();
                return ['ok' => true, 'code' => 0, 'msg' => '补发成功', 'data' => ['card_code' => $code]];
            }

            $card = Database::one(
                'SELECT * FROM ' . Database::t('cards') . ' WHERE code = ? FOR UPDATE',
                [$code]
            );
            if (!$card) {
                Database::rollback();
                return ['ok' => false, 'code' => 4006, 'msg' => '卡密不存在', 'data' => null];
            }
            if ((int) $card['status'] !== Card::STATUS_UNUSED) {
                Database::rollback();
                return ['ok' => false, 'code' => 4006, 'msg' => '该卡不是未使用状态，无法补发', 'data' => null];
            }
            if ((int) $card['agent_id'] !== 0) {
                Database::rollback();
                return ['ok' => false, 'code' => 4006, 'msg' => '该卡归属代理商，不能用于官方发卡', 'data' => null];
            }
            if ((int) $card['expire_at'] > 0 && (int) $card['expire_at'] < time()) {
                Database::rollback();
                return ['ok' => false, 'code' => 4006, 'msg' => '该卡已过期', 'data' => null];
            }
            // 规格必须与套餐挂卡一致：发错规格等于货不对板，直接拒绝
            if ((int) $card['type'] !== (int) $order['card_type']
                || (int) $card['duration'] !== (int) $order['duration']
                || (int) $card['max_devices'] !== (int) $order['max_devices']
                || (int) $card['group_id'] !== (int) $order['group_id']) {
                Database::rollback();
                return ['ok' => false, 'code' => 4006, 'msg' => '卡密规格与订单商品不一致（类型/规格/设备数/用户组）', 'data' => null];
            }

            $now = time();
            Database::update('cards', ['status' => 3, 'remark' => '发卡网人工补发:' . $order['order_no']],
                'id = :id AND status = :st', ['id' => (int) $card['id'], 'st' => Card::STATUS_UNUSED]);
            Database::update('shop_orders', [
                'status'       => self::ORDER_DELIVERED,
                'card_id'      => (int) $card['id'],
                'card_code'    => (string) $card['code'],
                'delivered_at' => $now,
                'remark'       => '人工补发',
            ], 'id = :id', ['id' => $orderId]);
            Database::insert('card_logs', [
                'card_id'    => (int) $card['id'],
                'code'       => (string) $card['code'],
                'user_id'    => 0,
                'action'     => 'shop_sell',
                'detail'     => '发卡网人工补发 订单:' . $order['order_no'],
                'ip'         => (string) $order['client_ip'],
                'created_at' => $now,
            ]);

            Database::commit();
            return ['ok' => true, 'code' => 0, 'msg' => '补发成功', 'data' => ['card_code' => (string) $card['code']]];
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('shop_deliver', 0, '人工补发异常: ' . $e->getMessage(), ['raw' => ['order_id' => $orderId]]);
            return ['ok' => false, 'code' => 5000, 'msg' => '补发失败，请稍后重试', 'data' => null];
        }
    }

    /** 管理员关闭订单（仅待支付/人工待处理可关） */
    public static function adminClose(int $orderId, string $remark = ''): array
    {
        $n = Database::update(
            'shop_orders',
            ['status' => self::ORDER_CLOSED, 'remark' => trim($remark) !== '' ? mb_substr($remark, 0, 200, 'UTF-8') : '管理员关闭'],
            'id = :id AND status IN (:s0, :s3)',
            ['id' => $orderId, 's0' => self::ORDER_PENDING, 's3' => self::ORDER_MANUAL]
        );
        return $n === 1
            ? ['ok' => true, 'code' => 0, 'msg' => '订单已关闭', 'data' => null]
            : ['ok' => false, 'code' => 4005, 'msg' => '订单不存在或状态不允许关闭（已发卡订单不可关闭）', 'data' => null];
    }
}
