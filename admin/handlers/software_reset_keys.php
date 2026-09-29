<?php
/**
 * admin action: software_reset_keys
 * 重置某软件的 AES_KEY / SIGN_SALT（被破解后一键换钥）
 * 传 aes_key / sign_salt = 'auto'（或缺省）自动生成，也可显式指定 hex
 */

$id = Util::int($input, 'id', 0);

$aesKey  = trim((string) ($input['aes_key'] ?? 'auto'));
$signSalt = trim((string) ($input['sign_salt'] ?? 'auto'));
$mode    = Util::str($input, 'mode', 'hard'); // hard=立即重置（旧客户端失联） graceful=平滑轮换（宽限期双钥并行）

if ($mode === 'graceful') {
    if ($aesKey !== 'auto' || $signSalt !== 'auto') {
        Response::error(1001, '平滑轮换仅支持自动生成新钥（不可指定）');
    }
    $r = Software::rotateKeysGraceful($id);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }

    Audit::log($admin, 'software_reset_keys', '软件#' . $id, '平滑轮换通信密钥（宽限期 ' . $r['grace_days'] . ' 天，老客户端无感知）');

    Response::ok([
        'aes_key'    => $r['aes_key'],
        'sign_salt'  => $r['sign_salt'],
        'grace_days' => $r['grace_days'],
        'rotated_at' => $r['rotated_at'],
    ], $r['msg']);
}

$r = Software::resetKeys($id, $aesKey, $signSalt);
if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Audit::log($admin, 'software_reset_keys', '软件#' . $id, '重置通信密钥（旧客户端将失联，需换钥重新发布）');

Response::ok([
    'aes_key'   => $r['aes_key'],
    'sign_salt' => $r['sign_salt'],
], $r['msg']);
