<?php
/**
 * admin action: web_upload
 * 官网图片上传（multipart/form-data，字段名 file）
 * ------------------------------------------------------------------
 * 用途：官网背景图（总站 / 分软件），后续 Logo / Favicon 等官网图片可复用。
 * 存储：Web 根 uploads/web/，返回站点相对 URL（/uploads/web/xxx.jpg），
 *       填入「内容运营 → 官网内容」的背景图地址即可，也支持手填任意 http(s) 链接。
 * 权限：settings.site（官网外观类，与官网内容保存同档）。
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

$dir = NB_ROOT . '/uploads/web';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    Response::error(5000, '创建上传目录失败');
}

$name = date('ymdHis') . bin2hex(random_bytes(4)) . '.' . $extMap[$info[2]];
if (!@move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $name)) {
    Response::error(5000, '保存图片失败');
}

Audit::log($admin, 'web_upload', $name, '上传官网图片', [], ['url' => '/uploads/web/' . $name]);

Response::ok(['url' => '/uploads/web/' . $name], '图片已上传');
