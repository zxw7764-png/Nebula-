<?php
/**
 * UiTemplate — 界面模板注册 / 识别接口（单一事实来源）
 *
 * 模板统一放在 web/Template/<id>/（官网 + 发卡网共用这一个目录）：
 *   web.css    = 官网样式（有则官网端识别该模板）
 *   shop.css   = 发卡网样式（有则发卡端识别该模板）
 *   game.html  = 自带小游戏（两端通用，前台右下角 🎮 iframe 加载）
 *   game.js / 图片等其余文件 = 小游戏资源，game.html 相对路径直接引用
 * 前台直接从 web/Template 加载，改完即生效，无需同步。
 *
 * 兼容：各端 assets 模板文件夹（官网 web/assets/css/templates/、
 * 发卡 shop/assets/templates/）里的旧式模板继续有效——单文件 <id>.css
 * 或文件夹 <id>/<id>.css；同名时 web/Template 优先。
 * 下划线开头（如 _shared.css）是共享样式，不算模板。
 * 本类扫描以上位置自动识别可用模板；前台校验、后台模板管理、
 * 各保存处理器统一走这里，不再各自维护硬编码白名单。
 *
 * 新增模板只需：
 *   1. web/Template/<id>/ 放 web.css（官网）和/或 shop.css（发卡）；
 *   2. 本类 $meta 补中文名 / 简介 / 主色（不补也能被识别，显示通用名）。
 */
class UiTemplate
{
    /** 官网内置区块 id（由 index.php 直接渲染，不可被 sections 文件覆盖同名） */
    public const BUILTIN_SECTIONS = ['hero', 'features', 'shots', 'flow', 'pricing', 'sellers', 'notice', 'board', 'faq'];

    /** 发卡网内置区块 id（shop/index.php 直接渲染；自定义区块放 shop-sections/ 目录） */
    public const BUILTIN_SHOP_SECTIONS = ['notice', 'notes', 'goods'];

    /** 模板元数据：显示名 / 简介 / 主色（后备；模板 css 头注释里的 Template Name/Description/Color 优先） */
    private static array $meta = [
        'farm'  => ['name' => '云上农场',    'sub' => '像素种田 · 木牌草地', 'color' => '#5fa83a'],
        'mario' => ['name' => '马里奥跳跳',  'sub' => '像素闯关 · 砖地水管', 'color' => '#e52521'],
        'ink'   => ['name' => '仙门水墨',    'sub' => '宣纸国风 · 楷体朱砂', 'color' => '#2d5a4a'],
        'space' => ['name' => 'STAR RAIDER', 'sub' => '太空射击 · 赛博霓虹', 'color' => '#00e5ff'],
    ];

    /** @var array<string, array<string, array>> 请求级缓存 [side => [id => info]] */
    private static array $cache = [];

    /** 统一模板开发目录（官网 + 发卡网共用） */
    public static function devDir(): string
    {
        return __DIR__ . '/../web/Template';
    }

    /** assets 兼容位置（旧式模板）：某端模板文件夹路径 */
    public static function dir(string $side): string
    {
        return $side === 'shop'
            ? __DIR__ . '/../shop/assets/templates'
            : __DIR__ . '/../web/assets/css/templates';
    }

    /**
     * 识别某端全部模板。
     * ① web/Template/<id>/（web.css=官网 / shop.css=发卡，game.html=自带小游戏，两端通用）
     * ② 各端 assets 兼容位置（单文件 <id>.css 或文件夹 <id>/<id>.css；同名单 id 被 web/Template 覆盖）
     * @return array<string, array{id:string,name:string,sub:string,color:string,known:bool,dir:bool,has_game:bool,src:string}>
     */
    public static function detect(string $side): array
    {
        $side = $side === 'shop' ? 'shop' : 'web';
        if (isset(self::$cache[$side])) {
            return self::$cache[$side];
        }

        $out = [];

        // ① 统一开发目录：web/Template/<id>/
        foreach (glob(self::devDir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $id = basename($d);
            if ($id === '' || $id[0] === '_' || !preg_match('/^[a-z0-9_]+$/', $id)) {
                continue; // _ 开头 = 不参与；id 字符集同 CSS 类名
            }
            $entryCss = $side === 'web' ? 'web.css' : 'shop.css';
            if (!is_file($d . '/' . $entryCss)) {
                continue; // 该端没有对应样式文件，不算这端的模板
            }
            $out[$id] = [
                'dir'      => true,
                'has_game' => is_file($d . '/game.html'),
                'has_interact' => is_file($d . '/interact.js'),
                'src'      => 'dev',
                'css'      => $entryCss,
            ] + self::info($id, $d . '/' . $entryCss);
        }

        // ② assets 兼容位置：单文件 <id>.css 或文件夹 <id>/<id>.css（同名被 ① 覆盖）
        foreach (glob(self::dir($side) . '/*.css') ?: [] as $file) {
            $id = basename($file, '.css');
            if ($id === '' || $id[0] === '_' || isset($out[$id])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $id)) {
                continue;
            }
            $out[$id] = [
                'dir' => false,
                'has_game' => false,
                'has_interact' => is_file(self::dir($side) . '/' . $id . '/interact.js'),   // 单文件模板允许同名文件夹只放 interact.js
                'src' => 'assets',
                'css' => $id . '.css',
            ] + self::info($id, $file);
        }
        foreach (glob(self::dir($side) . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $id = basename($d);
            if ($id === '' || $id[0] === '_' || isset($out[$id])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $id) || !is_file($d . '/' . $id . '.css')) {
                continue; // 文件夹型必须有同名入口 css
            }
            $out[$id] = [
                'dir'      => true,
                'has_game' => is_file($d . '/game.html'),
                'has_interact' => is_file($d . '/interact.js'),
                'src'      => 'assets',
                'css'      => $id . '/' . $id . '.css',
            ] + self::info($id, $d . '/' . $id . '.css');
        }

        ksort($out);
        self::$cache[$side] = $out;
        return $out;
    }

    /**
     * 模板显示信息。元数据来源优先级：
     *   ① 入口 css 文件头块注释（Template Name / Description / Color，兼容中文 名称/简介/主色）
     *   ② $meta 硬编码（内置四款）
     *   ③ 兜底「模板 <id>」+ 默认紫色
     */
    private static function info(string $id, string $cssFile): array
    {
        $cssMeta = self::parseCssMeta($cssFile);
        $mt = self::$meta[$id] ?? null;
        return [
            'id'     => $id,
            'name'   => $cssMeta['name']   ?? ($mt['name']   ?? ('模板 ' . $id)),
            'sub'    => $cssMeta['sub']    ?? ($mt['sub']    ?? '自动识别的模板'),
            'color'  => $cssMeta['color']  ?? ($mt['color']  ?? '#7c5cff'),
            'known'  => $mt !== null || $cssMeta !== null,
            'layout' => $cssMeta['layout'] ?? null,
        ];
    }

    /** 解析 css 文件头部块注释里的元数据（WordPress 主题风格），无则 null */
    private static function parseCssMeta(string $file): ?array
    {
        $head = (string) @file_get_contents($file, false, null, 0, 2048);
        if ($head === '' || !preg_match('/\/\*(.*?)\*\//s', $head, $m)) {
            return null;
        }
        $pick = static function (array $keys) use ($m): ?string {
            foreach ($keys as $k) {
                // \*? 兼容块注释星号前缀行（ * Layout: ...）
                if (preg_match('/^\s*\*?\s*' . $k . '\s*:\s*(.+)$/mi', $m[1], $mm)) {
                    $v = trim($mm[1]);
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
            return null;
        };
        $name  = $pick(['Template Name', '模板名', '名称']);
        $sub   = $pick(['Description', '描述', '简介']);
        $color = $pick(['Color', '主色', '颜色']);
        $layout = $pick(['Layout', '布局']);
        if ($name === null && $sub === null && $color === null && $layout === null) {
            return null;
        }
        $out = [];
        if ($name !== null) {
            $out['name'] = mb_substr($name, 0, 30);
        }
        if ($sub !== null) {
            $out['sub'] = mb_substr($sub, 0, 60);
        }
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $out['color'] = strtolower($color);
        }
        if ($layout !== null) {
            $ids = [];
            foreach (explode(',', $layout) as $lid) {
                $lid = trim(strtolower($lid));
                if ($lid !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $lid) && !in_array($lid, $ids, true)) {
                    $ids[] = $lid;
                    if (count($ids) >= 24) {
                        break;
                    }
                }
            }
            if ($ids) {
                $out['layout'] = $ids;
            }
        }
        return $out ?: null;
    }

    /** 某端布局声明：该端入口 css 头注释 Layout: 行（区块 id 顺序表，省略的区块不显示），无声明返回 null（用默认顺序） */
    public static function layout(string $side, string $id): ?array
    {
        $t = self::detect($side)[$id] ?? null;
        if (!$t || empty($t['layout']) || !is_array($t['layout'])) {
            return null;
        }
        return $t['layout'];
    }

    /**
     * 写回模板入口 css 头注释 Layout: 行（后台布局保存 / 删除区块联动用）。
     * $ids 空数组 = 删除声明行（恢复内置默认顺序）。无变化时不动文件也返回 true。
     */
    public static function writeLayoutLine(string $cssFile, array $ids): bool
    {
        $css = (string) @file_get_contents($cssFile);
        if ($css === '') {
            return false;
        }
        $line   = $ids ? ('Layout: ' . implode(', ', $ids)) : '';
        $newCss = $css;

        if (preg_match('/\/\*[\s\S]*?\*\//', $css, $m, PREG_OFFSET_CAPTURE)) {
            $head    = $m[0][0];
            $headOff = $m[0][1];
            $newHead = $head;
            if (preg_match('/^\s*\*?\s*(?:Layout|布局)\s*:.*$/mi', $head)) {
                // 已有 Layout 行：替换 / 删除
                if ($line === '') {
                    $newHead = (string) preg_replace('/^\s*\*?\s*(?:Layout|布局)\s*:.*\r?\n?/mi', '', $head);
                } else {
                    $newHead = (string) @preg_replace('/^(\s*\*?\s*)(?:Layout|布局)\s*:.*$/mi', $line, $head, 1);
                }
            } elseif ($line !== '') {
                // 头注释里没有 Layout 行 → 收尾 */ 前插入
                $newHead = (string) @preg_replace('/\*\/\s*$/', $line . "\n */", rtrim($head));
            }
            if ($newHead !== $head) {
                $newCss = substr($css, 0, $headOff) . $newHead . substr($css, $headOff + strlen($head));
            }
        } elseif ($line !== '') {
            // 无头注释 → 文件头新建声明块
            $newCss = "/*\n" . $line . "\n */\n" . $css;
        }

        if ($newCss === $css) {
            return true;    // 内容无变化，无需写盘
        }
        return @file_put_contents($cssFile, $newCss) !== false;
    }

    /** 某端可用模板 id 列表 */
    public static function ids(string $side): array
    {
        return array_keys(self::detect($side));
    }

    /** 模板值是否合法（'' = 默认深空，恒合法；未知 id 视为非法，前台回落默认） */
    public static function valid(string $side, string $id): bool
    {
        $id = strtolower(trim($id));
        return $id === '' || array_key_exists($id, self::detect($side));
    }

    /** 规范化：非法值一律回落 ''（默认深空） */
    public static function normalize(string $side, string $id): string
    {
        $id = strtolower(trim($id));
        return self::valid($side, $id) ? $id : '';
    }

    /**
     * 模板 css 的加载地址（相对该端入口 index.php 的 URL，前台拼 link 用）。
     * web/Template 来源：官网 Template/<id>/web.css、发卡 ../web/Template/<id>/shop.css
     * （发卡在 /shop/ 子目录，../Template 会指向站点根导致 404，必须回到 /web/Template）
     * assets 来源：官网 assets/css/templates/…、发卡 assets/templates/…
     */
    public static function cssRel(string $side, string $id): string
    {
        $side = $side === 'shop' ? 'shop' : 'web';
        $id   = strtolower(trim($id));
        $t    = self::detect($side)[$id] ?? null;
        if ($t && $t['src'] === 'dev') {
            return ($side === 'shop' ? '../web/Template/' : 'Template/') . $id . '/' . $t['css'];
        }
        $rel = ($t['css'] ?? $id . '.css');
        return $side === 'shop' ? 'assets/templates/' . $rel : 'assets/css/templates/' . $rel;
    }

    /** 模板自带小游戏页面加载地址（相对该端入口），无则 null */
    public static function gameRel(string $side, string $id): ?string
    {
        $side = $side === 'shop' ? 'shop' : 'web';
        $id   = strtolower(trim($id));
        $t    = self::detect($side)[$id] ?? null;
        if (!$t || !$t['has_game']) {
            return null;
        }
        return ($t['src'] === 'dev')
            ? ($side === 'shop' ? '../web/Template/' : 'Template/') . $id . '/game.html'
            : ($side === 'shop' ? 'assets/templates/' : 'assets/css/templates/') . $id . '/game.html';
    }

    /**
     * 模板自带官网交互音效脚本（interact.js）加载地址（相对该端入口），无则 null。
     * 脚本里自行绑定 hover / click 等交互音效（规范见 docs/TEMPLATE.md）。
     */
    public static function interactRel(string $side, string $id): ?string
    {
        $side = $side === 'shop' ? 'shop' : 'web';
        $id   = strtolower(trim($id));
        $t    = self::detect($side)[$id] ?? null;
        if (!$t || empty($t['has_interact'])) {
            return null;
        }
        return ($t['src'] === 'dev')
            ? ($side === 'shop' ? '../web/Template/' : 'Template/') . $id . '/interact.js'
            : ($side === 'shop' ? 'assets/templates/' : 'assets/css/templates/') . $id . '/interact.js';
    }

    /**
     * 模板资源版本号（前台 link/script 的 ?v= 用，改文件即失效浏览器缓存）。
     * 取入口 css 的 mtime；同一模板的文件改动都会反映到图标上，
     * 避免"模板改了但版本号没变、浏览器一直用旧缓存"导致的显示默认模板。
     */
    public static function ver(string $side, string $id): string
    {
        $side = $side === 'shop' ? 'shop' : 'web';
        $id   = strtolower(trim($id));
        $t    = self::detect($side)[$id] ?? null;
        if (!$t) {
            return (string) (defined('NB_VERSION') ? NB_VERSION : '1');
        }
        $base = $t['src'] === 'dev' ? self::devDir() . '/' . $id : self::dir($side);
        $file = $base . '/' . $t['css'];
        $mt   = @filemtime($file) ?: 0;
        return (string) (defined('NB_VERSION') ? NB_VERSION : '1') . '.' . $mt;
    }
}
