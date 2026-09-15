<?php
/**
 * action: register
 * 参数: username, password, email(可选)
 */

$r = Auth::register($requestData, Util::ip(), Software::current());

Logger::log('register', $r['ok'] ? 1 : 0, $r['msg'], [
    'username' => Util::str($requestData, 'username', ''),
    'user_id'  => $r['data']['user_id'] ?? 0,
]);

if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Response::ok($r['data'], $r['msg']);
