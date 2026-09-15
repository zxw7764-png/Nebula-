<?php
/**
 * admin action: template_list
 * 界面模板管理：返回自动识别的模板清单（统一开发目录 web/Template/<id>/，
 * 兼容各端 assets 模板文件夹旧位置）与两端当前生效模板。
 */

Response::ok([
    'templates' => [
        'web'  => array_values(UiTemplate::detect('web')),
        'shop' => array_values(UiTemplate::detect('shop')),
    ],
    'current' => [
        'web'  => UiTemplate::normalize('web', (string) Setting::get('web_ui_template', '')),
        'shop' => UiTemplate::normalize('shop', (string) Setting::get('shop_ui_template', '')),
    ],
]);
