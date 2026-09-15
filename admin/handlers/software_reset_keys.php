<?php
/**
 * admin action: software_reset_keys
 * 重置某软件的 AES_KEY / SIGN_SALT（被破解后一键换钥）
 * 传 aes_key / sign_salt = 'auto'（或缺省）自动生成，也可显式指定 hex
 */

$id = Util::int($input, 'id', 0);

$aesKey  = trim((string) ($input['aes_key'] ?? 'auto'));
$signSalt = trim((string) ($input['sign_salt'] ?? 'auto'));

$r = Software::resetKeys($id, $aesKey, $signSalt);
if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Audit::log($admin, 'software_reset_keys', '软件#' . $id, '重置通信密钥（旧客户端将失联，需换钥重新发布）');

Response::ok([
    'aes_key'   => $r['aes_key'],
    'sign_salt' => $r['sign_salt'],
], $r['msg']);
