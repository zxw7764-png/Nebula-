<?php
/**
 * admin action: shop_goods_upload
 * 发卡商品图片上传（multipart/form-data，字段名 file）
 * ------------------------------------------------------------------
 * 存储：Web 根 uploads/shop/，返回站点相对 URL（/uploads/shop/xxx.jpg），
 * 发卡商品「商品图片」输入框可直接使用，也支持手填任意 http(s) 链接。
 * 权限：settings.business（与 shop_goods_save 同档，仅超管）。
 * 安全：getimagesize 内容校验 + 扩展名白名单（jpg/png/gif/webp）+ 5MB 上限，
 *       随机文件名杜绝路径拼接，目录拒绝脚本执行由 Web 规则兜底。
 */

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    Response::error(1001, '缺少上传文件');
}
$f = $_FILES['file'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    Response::error(1001, '上传失败（错误码 ' . (int) $f['error'] . '）');
}
if ((int) $f['size'] <= 0 || (int) $f['size'] > 5 * 1024 * 1024) {
    Response::error(1001, '图片需在 5MB 以内');
}

// 内容级校验：伪造扩展名的非图片文件直接拒绝
$info = @getimagesize((string) $f['tmp_name']);
$extMap = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];
if (!$info || !isset($extMap[$info[2]])) {
    Response::error(1001, '仅支持 jpg / png / gif / webp 图片');
}

$dir = NB_ROOT . '/uploads/shop';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    Response::error(5000, '创建上传目录失败');
}

$name = date('ymdHis') . bin2hex(random_bytes(4)) . '.' . $extMap[$info[2]];
if (!@move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $name)) {
    Response::error(5000, '保存图片失败');
}

Audit::log($admin, 'shop_goods_upload', $name, '上传发卡商品图片', [], ['url' => '/uploads/shop/' . $name]);

Response::ok(['url' => '/uploads/shop/' . $name], '图片已上传');
