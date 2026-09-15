<?php
/**
 * admin action: software_save
 * 新增 / 编辑软件（密钥不在此修改，必须走 software_reset_keys）
 */

$id = Util::int($input, 'id', 0);

if ($id > 0) {
    $r = Software::update($id, $input);
} else {
    $r = Software::create($input);
}

if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Response::ok(['id' => $r['id'] ?? $id], $r['msg']);
