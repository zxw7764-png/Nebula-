<?php
/**
 * admin action: version_save
 * 新增 / 编辑 / 删除版本
 */

$op = Util::str($input, 'op', 'save');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    Database::exec('DELETE FROM ' . Database::t('versions') . ' WHERE id = ?', [$id]);
    Audit::log($admin, 'version_delete', "版本#{$id}", '删除版本记录');
    Response::ok(null, '版本已删除');
}

$id      = Util::int($input, 'id', 0);
$version = Util::str($input, 'version', '');
$channel = Util::str($input, 'channel', 'stable');
$url     = Util::str($input, 'download_url', '');
$hash    = Util::str($input, 'file_hash', '');
$size    = max(0, Util::int($input, 'file_size', 0));
$log     = (string) Util::get($input, 'changelog', '');
$force   = Util::int($input, 'force_update', 0);
$status  = Util::int($input, 'status', 1);

// 文件哈希：填写时必须是 32 位（MD5）或 64 位（SHA-256）十六进制
if ($hash !== '' && !preg_match('/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{64})$/', $hash)) {
    Response::error(1001, '文件哈希格式错误：应为 32 位（MD5）或 64 位（SHA-256）十六进制');
}

if ($version === '' || !preg_match('/^\d+(\.\d+){0,3}$/', $version)) {
    Response::error(1001, '版本号格式错误，示例 1.2.3');
}
if (!in_array($channel, ['stable', 'beta'], true)) {
    $channel = 'stable';
}

$softwareId = Util::int($input, 'software_id', 0);
if ($softwareId <= 0 || !Software::find($softwareId)) {
    $softwareId = (int) Database::value('SELECT id FROM ' . Database::t('softwares') . ' WHERE status = 1 ORDER BY id ASC LIMIT 1');
}

$data = [
    'software_id'  => $softwareId,
    'version'      => $version,
    'channel'      => $channel,
    'download_url' => $url,
    'file_hash'    => $hash,
    'file_size'    => $size,
    'changelog'    => $log,
    'force_update' => $force,
    'status'       => $status,
];

if ($id > 0) {
    Database::update('versions', $data, 'id = :id', ['id' => $id]);
    Audit::log($admin, 'version_save', "版本#{$id} {$version}", '编辑版本');
    Response::ok(['id' => $id], '版本已更新');
}

$data['created_at'] = time();
try {
    $newId = Database::insert('versions', $data);
} catch (Throwable $e) {
    Response::error(1001, '该版本号已存在');
}
Audit::log($admin, 'version_save', "版本#{$newId} {$version}", '发布版本');
Response::ok(['id' => $newId], '版本已发布');
