<?php
/**
 * admin action: bigscreen
 * ------------------------------------------------------------------
 * 数据大屏：实时在线、今日概览、在线曲线、激活/新增曲线、代理销量排行、
 *           缓存与心跳聚合的运行状态。
 *
 * 参数：
 *   op      data（默认）| flush_cache
 *   days    曲线天数，默认 30，范围 7~90
 *   hours   在线曲线回溯小时数，默认 24，范围 6~72
 *
 * 性能注意：
 *   大屏会定时自动刷新，聚合类查询一律走 Cache::remember 缓存（TTL 20~30s），
 *   避免多个管理员同时开着大屏把数据库打满。
 */

$op = Util::str($input, 'op', 'data');

// ------------------------------------------------------------------
// 手动清空缓存（排障用：怀疑缓存脏了但不想重启 Redis）
// ------------------------------------------------------------------
if ($op === 'flush_cache') {
    if ((int) $admin['role'] !== 1) {
        Response::error(1004, '仅超级管理员可执行此操作');
    }
    $n = Cache::flushPrefix();
    Audit::log($admin, 'cache_flush', '缓存', "清空缓存 {$n} 个键");
    Response::ok(['count' => $n], "已清空 {$n} 个缓存键");
}

$days  = Util::int($input, 'days', 30);
$days  = max(7, min(90, $days));
$hours = Util::int($input, 'hours', 24);
$hours = max(6, min(72, $hours));

$now     = time();
$today   = strtotime('today');
$timeout = Policy::heartbeatTimeout();

// ------------------------------------------------------------------
// 先落库：让 DB 里的数据尽量新鲜，大屏数字才不会是"上一分钟"的
// ------------------------------------------------------------------
if (Heartbeat::enabled()) {
    Heartbeat::flush((int) Config::get('heartbeat.flush_batch', 200));
}
if (Config::get('log.stat_buffer', false) && Cache::available()) {
    Logger::flushStats();
}

// ------------------------------------------------------------------
// 实时区
// ------------------------------------------------------------------
$realtime = [
    'online'         => Session::onlineCount($timeout),
    'online_devices' => (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE status = 1 AND last_seen > ?',
        [$now - $timeout]
    ),
    'online_users'   => (int) Database::value(
        'SELECT COUNT(DISTINCT user_id) FROM ' . Database::t('sessions') . ' WHERE status = 1 AND last_active > ?',
        [$now - $timeout]
    ),
    'timeout'        => $timeout,
];

// ------------------------------------------------------------------
// 今日 / 总量（缓存 20s，抗大屏自动刷新）
// ------------------------------------------------------------------
$brief = Cache::remember('bigscreen:brief', 20, static function () use ($today) {
    return [
        'today' => [
            'new_users'    => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE created_at >= ?', [$today]),
            'activations'  => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE status = 1 AND used_at >= ?', [$today]),
            'logins'       => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('logs') . " WHERE action = 'login' AND result = 1 AND created_at >= ?", [$today]),
            'api_calls'    => (int) Database::value('SELECT IFNULL(SUM(call_count),0) FROM ' . Database::t('api_stats') . ' WHERE stat_date = ?', [date('Y-m-d')]),
            'api_fails'    => (int) Database::value('SELECT IFNULL(SUM(fail_count),0) FROM ' . Database::t('api_stats') . ' WHERE stat_date = ?', [date('Y-m-d')]),
            'recharge'     => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('agent_logs') . " WHERE action = 'recharge' AND created_at >= ?", [$today]),
            'new_agents'   => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('agents') . ' WHERE created_at >= ?', [$today]),
        ],
        'total' => [
            'users'        => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users')),
            'users_active' => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE status = 1 AND vip_expire != 0'),
            'cards'        => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards')),
            'cards_unused' => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE status = 0'),
            'cards_used'   => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE status = 1'),
            'agents'       => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('agents')),
            'devices'      => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE status = 1'),
        ],
    ];
});

// ------------------------------------------------------------------
// 在线曲线（近 N 小时，5 分钟一个点；数据来自 cron 写的 nb_online_stats）
// ------------------------------------------------------------------
$onlineCurve = Cache::remember('bigscreen:online:' . $hours, 30, static function () use ($hours, $now) {
    try {
        $rows = Database::all(
            'SELECT (stat_time - stat_time % 300) AS bucket,
                    ROUND(AVG(online)) AS online,
                    ROUND(AVG(devices)) AS devices
             FROM ' . Database::t('online_stats') . '
             WHERE stat_time >= ?
             GROUP BY bucket ORDER BY bucket ASC',
            [$now - $hours * 3600]
        );
    } catch (Throwable $e) {
        // 未执行迁移时优雅降级：大屏显示"暂无数据"而不是报错
        return [];
    }
    return array_map(function ($r) { return [
        't'       => (int; }) $r['bucket'],
        'label'   => date('H:i', (int) $r['bucket']),
        'online'  => (int) $r['online'],
        'devices' => (int) $r['devices'],
    ], $rows);
});

// ------------------------------------------------------------------
// 近 N 天的激活量 / 新增用户 / 接口调用（三条曲线）
// ------------------------------------------------------------------
$curves = Cache::remember('bigscreen:curves:' . $days, 30, static function () use ($days) {
    $from = strtotime('today') - ($days - 1) * 86400;

    $activations = [];
    foreach (Database::all(
        'SELECT FROM_UNIXTIME(used_at, "%Y-%m-%d") AS d, COUNT(*) AS c
         FROM ' . Database::t('cards') . ' WHERE status = 1 AND used_at >= ? GROUP BY d',
        [$from]
    ) as $r) {
        $activations[$r['d']] = (int) $r['c'];
    }

    $newUsers = [];
    foreach (Database::all(
        'SELECT FROM_UNIXTIME(created_at, "%Y-%m-%d") AS d, COUNT(*) AS c
         FROM ' . Database::t('users') . ' WHERE created_at >= ? GROUP BY d',
        [$from]
    ) as $r) {
        $newUsers[$r['d']] = (int) $r['c'];
    }

    $api = [];
    foreach (Database::all(
        'SELECT stat_date, SUM(call_count) AS c, SUM(fail_count) AS f
         FROM ' . Database::t('api_stats') . ' WHERE stat_date >= ? GROUP BY stat_date',
        [date('Y-m-d', $from)]
    ) as $r) {
        $api[$r['stat_date']] = ['calls' => (int) $r['c'], 'fails' => (int) $r['f']];
    }

    // 补齐没有数据的日期，曲线才不会断
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $out[] = [
            'date'        => $d,
            'label'       => date('m-d', strtotime($d)),
            'activations' => $activations[$d] ?? 0,
            'new_users'   => $newUsers[$d] ?? 0,
            'api_calls'   => $api[$d]['calls'] ?? 0,
            'api_fails'   => $api[$d]['fails'] ?? 0,
        ];
    }
    return $out;
});

// ------------------------------------------------------------------
// 代理销量排行（近 N 天生成的卡密数 / 已使用数 / 作废数）
// ------------------------------------------------------------------
$agentRank = Cache::remember('bigscreen:agents:' . $days, 30, static function () use ($days) {
    $from = strtotime('today') - ($days - 1) * 86400;
    $rows = Database::all(
        // 注意：别名不要用 generated / used 这类词，MySQL 8.0 里 GENERATED 是保留字
        'SELECT c.agent_id,
                a.username, a.nickname,
                COUNT(*) AS gen_count,
                SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) AS used_count,
                SUM(CASE WHEN c.status = 2 THEN 1 ELSE 0 END) AS void_count,
                SUM(CASE WHEN c.status = 0 THEN 1 ELSE 0 END) AS unused_count
         FROM ' . Database::t('cards') . ' c
         LEFT JOIN ' . Database::t('agents') . ' a ON a.id = c.agent_id
         WHERE c.agent_id > 0 AND c.created_at >= ?
         GROUP BY c.agent_id, a.username, a.nickname
         ORDER BY gen_count DESC LIMIT 10',
        [$from]
    );
    return array_map(function ($r) { return [
        'agent_id'  => (int; }) $r['agent_id'],
        'name'      => $r['nickname'] ?: ($r['username'] ?: ('代理#' . $r['agent_id'])),
        'generated' => (int) $r['gen_count'],
        'used'      => (int) $r['used_count'],
        'unused'    => (int) $r['unused_count'],
        'voided'    => (int) $r['void_count'],
    ], $rows);
});

// ------------------------------------------------------------------
// 卡密类型分布
// ------------------------------------------------------------------
$typeDist = Cache::remember('bigscreen:types', 30, static function () {
    $rows = Database::all(
        'SELECT type,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS used
         FROM ' . Database::t('cards') . ' GROUP BY type ORDER BY total DESC'
    );
    return array_map(function ($r) { return [
        'type'  => (int; }) $r['type'],
        'name'  => Card::typeName((int) $r['type']),
        'total' => (int) $r['total'],
        'used'  => (int) $r['used'],
    ], $rows);
});

// ------------------------------------------------------------------
// 最近动态
// ------------------------------------------------------------------
$recentLogs = Database::all(
    'SELECT action, result, message, username, ip, created_at
     FROM ' . Database::t('logs') . ' ORDER BY id DESC LIMIT 12'
);
foreach ($recentLogs as &$l) {
    $l['time_text'] = Util::date((int) $l['created_at']);
}
unset($l);

Response::ok([
    'server' => [
        'time'        => date('Y-m-d H:i:s'),
        'ts'          => $now,
        'php_version' => PHP_VERSION,
        'site_name'   => Setting::get('site_name', 'Nebula 网络验证'),
    ],
    'realtime'  => $realtime,
    'today'     => $brief['today'],
    'total'     => $brief['total'],
    'online_curve'     => $onlineCurve,
    'curves'           => $curves,
    'agent_rank'       => $agentRank,
    'type_dist'        => $typeDist,
    'recent_logs'      => $recentLogs,
    'range'     => ['days' => $days, 'hours' => $hours],
    // 缓存与心跳聚合的运行状态：出问题时先看这里
    'runtime'   => [
        'cache'       => Cache::info(),
        'heartbeat'   => Heartbeat::stats(),
        'stat_buffer' => Logger::statBufferSize(),
        'cache_files' => Cache::info()['driver'] === 'file' ? Cache::fileUsage() : null,
    ],
]);
