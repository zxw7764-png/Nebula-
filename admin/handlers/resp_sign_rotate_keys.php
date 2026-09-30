<?php
/**
 * admin action: resp_sign_rotate_keys
 * 轮换响应签名密钥（ES256 EC P-256），独立于离线宽限密钥。
 */

$r = RespSign::rotateKeys();

Audit::log($admin, 'resp_sign_rotate_keys', '', '轮换响应签名密钥（kid=' . $r['kid'] . '，algo=' . $r['algo'] . '）');

Response::ok([
    'kid'        => $r['kid'],
    'algo'       => $r['algo'],
    'public_key' => $r['public_key'],
], '响应签名密钥已轮换，旧签名立即失效');
