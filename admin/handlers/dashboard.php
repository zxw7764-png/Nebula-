<?php
/**
 * admin action: dashboard
 * 后台首页统计数据
 */

$now      = time();
$today    = strtotime('today');
$timeout  = Policy::heartbeatTimeout();

$stat = [
    'user_total'      => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users')),
    'user_today'      => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE created_at >= ?', [$today]),
    'user_active'     => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE status = 1 AND vip_expire != 0'),
    'user_banned'     => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE status != 1'),

    'card_total'      => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards')),
    'card_unused'     => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE status = 0'),
    'card_used'       => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE status = 1'),
    'card_used_today' => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE used_at >= ?', [$today]),

    'device_total'    => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE status = 1'),
    'online_count'    => Session::onlineCount($timeout),

    'api_today'       => (int) Database::value(
        'SELECT IFNULL(SUM(call_count),0) FROM ' . Database::t('api_stats') . ' WHERE stat_date = ?',
        [date('Y-m-d')]
    ),
    'api_fail_today'  => (int) Database::value(
        'SELECT IFNULL(SUM(fail_count),0) FROM ' . Database::t('api_stats') . ' WHERE stat_date = ?',
        [date('Y-m-d')]
    ),
    'login_today'     => (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('logs') . ' WHERE action = ? AND result = 1 AND created_at >= ?',
        ['login', $today]
    ),
];

// 近 7 天趋势
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $trend[] = [
        'date'   => $d,
        'api'    => (int) Database::value(
            'SELECT IFNULL(SUM(call_count),0) FROM ' . Database::t('api_stats') . ' WHERE stat_date = ?',
            [$d]
        ),
        'login'  => (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('logs') . ' WHERE action = ? AND result = 1 AND created_at >= ? AND created_at < ?',
            ['login', strtotime($d), strtotime($d) + 86400]
        ),
        'new_user' => (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE created_at >= ? AND created_at < ?',
            [strtotime($d), strtotime($d) + 86400]
        ),
    ];
}

// 最近日志
$recentLogs = Database::all(
    'SELECT action, result, message, username, ip, created_at
     FROM ' . Database::t('logs') . ' ORDER BY id DESC LIMIT 15'
);
foreach ($recentLogs as &$l) {
    $l['time_text'] = Util::date((int) $l['created_at']);
}
unset($l);

Response::ok([
    'stat'       => $stat,
    'trend'      => $trend,
    'recent_logs'=> $recentLogs,
    'admin'      => AdminAuth::publicInfo($admin),
    'server'     => [
        'php_version' => PHP_VERSION,
        'time'        => date('Y-m-d H:i:s'),
        'os'          => PHP_OS,
    ],
]);
