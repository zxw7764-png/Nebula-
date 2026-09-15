<?php
/**
 * admin action: tpl_sections_get
 * 模板布局与自定义区块读取（side=web 官网 / side=shop 发卡网）：
 * 返回某模板的区块顺序（该端入口 css 头注释 Layout: 行，未声明返回内置默认）
 * 与自定义区块内容，供后台「界面模板 → 布局与自定义区块」编辑。
 *   官网：模板目录 sections/*.html；内置区块 hero/features/... 文案在「官网内容」页配置
 *   发卡网：模板目录 shop-sections/*.html；内置区块 notice（公告横幅）/ notes（购买须知）/ goods（商品区）
 * 权限：settings.site（官网 / 发卡网外观类）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_SITE);

$side = Util::str($input, 'side', 'web') === 'shop' ? 'shop' : 'web';
$tpl  = strtolower(trim(Util::str($input, 'template', '')));
if ($tpl === '' || !UiTemplate::valid($side, $tpl)) {
    Response::error(1001, '模板不存在或未注册');
}
$t = UiTemplate::detect($side)[$tpl] ?? null;
if (!$t || $t['src'] !== 'dev') {
    Response::error(1001, '仅支持 web/Template 文件夹型模板');
}

$builtins = $side === 'shop' ? UiTemplate::BUILTIN_SHOP_SECTIONS : UiTemplate::BUILTIN_SECTIONS;
$declared = UiTemplate::layout($side, $tpl);          // css 声明（null = 未声明）
$layout   = $declared ?: $builtins;                   // 实际生效顺序
$dir      = UiTemplate::devDir() . '/' . $tpl . ($side === 'shop' ? '/shop-sections' : '/sections');

// 自定义区块集合：Layout 声明的非内置 id ∪ 区块目录已有文件
$ids = [];
foreach ($layout as $id) {
    if (!in_array($id, $builtins, true) && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}
foreach (is_dir($dir) ? (glob($dir . '/*.html') ?: []) : [] as $f) {
    $id = basename((string) $f, '.html');
    if (preg_match('/^[a-z0-9_]{1,32}$/', $id) && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}

$sections = [];
foreach ($ids as $id) {
    $file = $dir . '/' . $id . '.html';
    $sections[] = [
        'id'        => $id,
        'in_layout' => in_array($id, $layout, true),
        'content'   => is_file($file) ? (string) file_get_contents($file) : '',
    ];
}

Response::ok([
    'template'        => $tpl,
    'side'            => $side,
    'layout'          => $layout,
    'layout_declared' => $declared,   // null = css 未声明（用内置默认顺序）
    'builtins'        => $builtins,
    'sections'        => $sections,
]);
