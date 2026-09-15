<?php
/**
 * admin action: shop_sw_save
 * 保存某软件的发卡网覆盖（当前仅模板字段 shop_ui_template；key: shop_sw_<id>）
 * 整体覆盖语义：保存前必须先读旧覆盖一并回传（前台模板管理已处理）。
 * 访客识别：发卡网 ?app= → Cookie nb_web_app → 单软件兜底（Shop::visitorSoftwareId）。
 */

$id = Util::int($input, 'id', 0);
$sw = $id > 0 ? Software::find($id) : null;
if (!$sw) {
    Response::error(1001, '软件不存在');
}

// 可覆盖字段白名单（与 shop/index.php 的读取键一致）
$keys = ['shop_ui_template'];

// 合并语义：先读旧覆盖；input 未出现的字段保留旧值，出现的空值 = 清除该覆盖
$old = [];
$rawOld = trim((string) Setting::get('shop_sw_' . $id, ''));
if ($rawOld !== '' && is_array($decodedOld = json_decode($rawOld, true))) {
    $old = $decodedOld;
}
$ov = $old;
foreach ($keys as $k) {
    if (!array_key_exists($k, $input)) {
        continue; // 未提交的字段保留旧覆盖
    }
    $v = is_string($input[$k]) ? strtolower(trim($input[$k])) : '';
    if ($v === '') {
        unset($ov[$k]); // 提交空值 = 清除覆盖（回落全局）
        continue;
    }
    $ov[$k] = $v;
}

// 界面模板值校验（走 UiTemplate 接口，与前台回落口径一致）
if (isset($ov['shop_ui_template']) && !UiTemplate::valid('shop', (string) $ov['shop_ui_template'])) {
    Response::error(1001, '界面模板非法');
}

if ($ov) {
    Setting::set('shop_sw_' . $id, json_encode($ov, JSON_UNESCAPED_UNICODE), '软件发卡网覆盖');
} else {
    Setting::set('shop_sw_' . $id, '', '软件发卡网覆盖（清空）');
}

Logger::log('admin_shop_sw_save', 1, '软件#' . $id . ' 发卡网覆盖已更新（' . count($ov) . ' 项）');

Response::ok(['id' => $id, 'overrides' => $ov], '该软件发卡网设置已保存');
