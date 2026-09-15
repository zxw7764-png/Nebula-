<?php
/**
 * action: heartbeat
 * 心跳上报，客户端按 heartbeat_interval 定时调用
 * 参数: token, machine_id
 * 返回: 剩余时长、在线状态、是否有新公告、是否强制下线、离线宽限票据
 *
 * 性能说明（相对旧版）：
 *   旧版每次心跳要 2 次 SELECT + 3 次写库（sessions、devices、logs、api_stats）。
 *   现在：
 *     · 设备活跃改成缓存缓冲 + 批量落库（Heartbeat），请求内不再写 devices；
 *     · 「心跳正常」不再写 nb_logs（可用 log.heartbeat 打开）；
 *     · 公告计数走缓存；
 *     · 设备查询从「先 UPDATE 再 SELECT」合并成 1 次 SELECT。
 *   最终常见的稳态路径只剩 sessions 的一次 SELECT / UPDATE + users 一次 SELECT。
 */

$token     = Util::str($requestData, 'token', '');
$machineId = Util::str($requestData, 'machine_id', '');

$v = Session::validate($token, $machineId);
if (!$v['ok']) {
    Logger::log('heartbeat', 0, $v['msg'], ['machine_id' => $machineId]);
    Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
}

$session = $v['session'];
$userId  = (int) $session['user_id'];

$user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1002, '账号不存在', ['need_relogin' => true]);
}

// 账号状态实时检查（限时封禁到期自动解封）
if ((int) $user['status'] !== 1) {
    if ((int) $user['status'] === 0 && Auth::autoUnbanIfExpired($user)) {
        $user['status']    = 1;
        $user['ban_expire'] = 0;
    } else {
        Session::kickUser($userId);
        Logger::log('heartbeat', 0, '账号状态异常: ' . $user['status'], ['user_id' => $userId]);
        $msg = (int) $user['status'] === 0 ? Auth::bannedText($user) : '账号已被冻结';
        Response::send(2002, $msg, ['kick' => true, 'need_relogin' => true]);
    }
}

// 会员有效性实时检查
$vip = Auth::checkVip($user);
if (!$vip['valid']) {
    Session::kickUser($userId);
    Logger::log('heartbeat', 0, $vip['msg'], ['user_id' => $userId]);
    Response::send($vip['code'], $vip['msg'], ['kick' => true, 'need_activate' => true]);
}

// 刷新会话（单行按唯一索引更新，这条保留：踢人/封号必须实时生效）
$ttl = (int) Config::get('policy.session_ttl', 3600);
Session::touch($token, $ttl);

// 设备活跃 + 解绑检查（合并成一次查询，顺带把心跳时间记入缓冲）
if ($machineId !== '') {
    $devRow = Database::one(
        'SELECT id, status FROM ' . Database::t('devices') . ' WHERE user_id = ? AND machine_id = ?',
        [$userId, $machineId]
    );

    if ($devRow && (int) $devRow['status'] === 0) {
        Session::destroy($token);
        Heartbeat::dropDevice($userId, $machineId);
        Logger::log('heartbeat', 0, '设备已被解绑', ['user_id' => $userId, 'machine_id' => $machineId]);
        Response::send(4002, '当前设备已被解绑', ['kick' => true, 'need_relogin' => true]);
    }

    if (Heartbeat::enabled()) {
        // 只进缓冲，由 cron 或机会式批量落库
        Heartbeat::mark($userId, $machineId);
    } else {
        Database::exec(
            'UPDATE ' . Database::t('devices') . ' SET last_seen = ? WHERE user_id = ? AND machine_id = ? AND status = 1',
            [time(), $userId, $machineId]
        );
    }
}

// 计算剩余时间
$expire  = (int) $user['vip_expire'];
$remain  = $expire === -1 ? -1 : ($expire > 0 ? max(0, $expire - time()) : 0);

// 心跳正常记录默认不落库（见 config log.heartbeat 说明）；失败记录始终会写
if (Config::get('log.heartbeat', false)) {
    Logger::log('heartbeat', 1, '心跳正常', ['user_id' => $userId, 'machine_id' => $machineId]);
}

// 新公告标记：走缓存，避免每个心跳都去 COUNT 一次 notices
$hasNotice = (int) Cache::remember('notice:push_count', 30, static function () {
    return (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('notices') . ' WHERE status = 1 AND type >= 2'
    );
}) > 0;

// 机会式落库：没配 cron 也不会让缓冲无限积压
Heartbeat::maybeFlush();

// 离线宽限票据：服务端抖动时客户端凭这张票据本地验签后继续离线运行
$grace = Grace::issue($user, $token, $machineId);

$data = [
    'online'        => true,
    'server_time'   => time(),
    'next_interval' => Policy::heartbeatInterval(),
    'remain'        => $remain,
    'remain_text'   => $remain === -1 ? '永久' : Util::duration($remain),
    'vip_expire'    => $expire,
    'points'        => (int) $user['points'],
    'session_ttl'   => $ttl,
    'force_offline' => false,
    'has_notice'    => $hasNotice,
];

// 立即公告（type=3）随心跳下发：客户端 SDK 用本地已读记录过滤后弹出，看过即不再显示
// 高频接口：按软件 ID 缓存 30 秒（与 has_notice 同策略），避免每个心跳都实时查库
$flashSwId = (int) ($user['software_id'] ?? 0);
$data['flash_notices'] = Cache::remember('notice:flash_sw_' . $flashSwId, 30, static function () use ($flashSwId) {
    return Database::all(
        'SELECT id, title, content FROM ' . Database::t('notices') . '
         WHERE status = 1 AND type = 3
           AND (software_id = 0 OR software_id = ?)
           AND (start_at = 0 OR start_at <= ?)
           AND (end_at = 0 OR end_at >= ?)
         ORDER BY sort DESC, id DESC LIMIT 10',
        [$flashSwId, time(), time()]
    );
});

if ($grace !== null) {
    $data['grace'] = $grace;
}

Response::ok($data);
