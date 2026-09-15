<?php
/**
 * admin action: shop_sw_get
 * 读取某软件的发卡网覆盖（当前仅模板；key: shop_sw_<id>）
 * 返回覆盖 JSON 的原始字段；空 = 未覆盖，发卡网回落全局配置
 */

$id = Util::int($input, 'id', 0);
$sw = $id > 0 ? Software::find($id) : null;
if (!$sw) {
    Response::error(1001, '软件不存在');
}

$raw = trim((string) Setting::get('shop_sw_' . $id, ''));
$ov  = [];
if ($raw !== '') {
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $ov = $data;
    }
}

Response::ok([
    'id'        => $id,
    'name'      => (string) $sw['name'],
    'overrides' => $ov,
]);
