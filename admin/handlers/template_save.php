<?php
/**
 * admin action: template_save
 * 界面模板管理：切换官网 / 发卡网当前生效模板。
 *   side     web | shop
 *   template 模板 id（'' = 默认深空）；必须已被 UiTemplate::detect 识别
 * 模板值经注册接口校验，非法直接拒绝（不会把未知 id 写库导致前台回落）。
 */

$side = strtolower(trim(Util::str($input, 'side', '')));
$tpl  = strtolower(trim(Util::str($input, 'template', '')));

if (!in_array($side, ['web', 'shop'], true)) {
    Response::error(1001, '未知端（web / shop）');
}
if (!UiTemplate::valid($side, $tpl)) {
    Response::error(1001, '模板不存在或未在模板 CSS 中注册');
}

$key = $side === 'web' ? 'web_ui_template' : 'shop_ui_template';
Setting::set($key, $tpl);

$sideName = $side === 'web' ? '官网' : '发卡网';
Audit::log($admin, 'template_save', '界面模板',
    ($sideName) . '模板' . ($tpl === '' ? '恢复默认深空' : '切换为「' . (UiTemplate::detect($side)[$tpl]['name'] ?? $tpl) . '」'));

Response::ok([], $tpl === '' ? '已恢复默认深空' : '模板已切换为「' . (UiTemplate::detect($side)[$tpl]['name'] ?? $tpl) . '」');
