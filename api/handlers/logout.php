<?php
/**
 * action: logout
 * 参数: token
 */

$token = Util::str($requestData, 'token', '');

if ($token !== '') {
    $v = Session::validate($token, Util::str($requestData, 'machine_id', '') ?: null, true);
    if ($v['ok']) {
        Session::destroy($token);
        Logger::log('logout', 1, '退出登录', ['user_id' => (int) $v['session']['user_id']]);
    }
}

Response::ok(['logout' => true], '已退出登录');
