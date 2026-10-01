<?php
/**
 * action: handshake
 * Nebula 3.1 会话握手（明文 JSON，无需加密信封）
 * 参数: app_key, eph_pub(65B点b64), nc(16B b64), ts, mhash(sha256(machine_id) hex, 可选)
 * 返回: { proto:31, sid, eph_pub, ns, ts_s, sign(ES256), sig_kid, sig_algo }
 * 协议细节见 lib/Handshake.php 头注释。
 */

// 握手响应是明文 JSON（本身就是协商材料，无敏感数据）；
// 完整性由响应里的 ES256 签名保证，不走 3.0 的对称信封。
Response::setEncrypt(false);

$result = Handshake::create($input);
Response::ok($result);
