<?php
/**
 * admin action: analytics
 * ------------------------------------------------------------------
 * 留存 / 复购分析。
 *
 * 数据来源与口径（都在现有表上算，不新增埋点）：
 *   留存    —— 以 nb_users.created_at 为「注册日」（第 0 天），
 *              以 nb_sessions.login_at 判断该用户是否在第 N 天回来登录过。
 *              用 sessions 而不是 logs：sessions 不会被保留期清理，
 *              能算更长的时间跨度；logs 默认只留 30 天。
 *   复购(用户) —— 同一 user 激活过 ≥2 张卡（nb_cards.used_by）。
 *   复购(代理) —— 同一代理兑换过 ≥2 次充值卡密（nb_agent_logs.action = recharge）。
 *   活跃    —— 近 N 天有登录记录的去重用户数（DAU/WAU/MAU 口径）。
 *
 * 参数：
 *   days  统计天数，默认 30，范围 7~90
 */

$days = Util::int($input, 'days', 30);
$days = max(7, min(90, $days));

$now   = time();
$today = strtotime('today');

// 留存队列最多回溯 21 天（再早的队列算不出 D7）
$cohortDays = min($days, 21);

$payload = Cache::remember('analytics:' . $days, 60, static function () use ($days, $cohortDays, $now, $today) {

    // ==============================================================
    // 1. 留存队列（注册日 -> D1 / D3 / D7）
    // ==============================================================
    $from = strtotime('today') - ($cohortDays - 1) * 86400;

    $cohorts = [];
    if ($cohortDays > 0) {
        $rows = Database::all(
            'SELECT DATE(FROM_UNIXTIME(u.created_at)) AS cohort,
                    COUNT(DISTINCT u.id) AS total,
                    COUNT(DISTINCT CASE
                        WHEN s.login_at >= UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 1 * 86400
                         AND s.login_at <  UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 2 * 86400
                        THEN u.id END) AS d1,
                    COUNT(DISTINCT CASE
                        WHEN s.login_at >= UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 3 * 86400
                         AND s.login_at <  UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 4 * 86400
                        THEN u.id END) AS d3,
                    COUNT(DISTINCT CASE
                        WHEN s.login_at >= UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 7 * 86400
                         AND s.login_at <  UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(u.created_at))) + 8 * 86400
                        THEN u.id END) AS d7
             FROM ' . Database::t('users') . ' u
             LEFT JOIN ' . Database::t('sessions') . ' s
                    ON s.user_id = u.id
                   AND s.login_at >= u.created_at
                   AND s.login_at <  u.created_at + 9 * 86400
             WHERE u.created_at >= ?
             GROUP BY cohort ORDER BY cohort DESC',
            [$from]
        );

        $sumTotal = 0;
        $sumD1 = 0;
        $sumD3 = 0;
        $sumD7 = 0;
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $age   = (int) floor(($now - strtotime((string) $r['cohort'])) / 86400); // 队列已存在多少天
            $cohorts[] = [
                'date'  => (string) $r['cohort'],
                'label' => date('m-d', strtotime((string) $r['cohort'])),
                'total' => $total,
                'age'   => $age,
                // 未满 N 天的队列算不出对应留存，用 null 让前端显示 "—"
                'd1'    => $age >= 1 ? (int) $r['d1'] : null,
                'd3'    => $age >= 3 ? (int) $r['d3'] : null,
                'd7'    => $age >= 7 ? (int) $r['d7'] : null,
                'd1_pct'=> $age >= 1 && $total > 0 ? round((int) $r['d1'] / $total * 100, 1) : null,
                'd3_pct'=> $age >= 3 && $total > 0 ? round((int) $r['d3'] / $total * 100, 1) : null,
                'd7_pct'=> $age >= 7 && $total > 0 ? round((int) $r['d7'] / $total * 100, 1) : null,
            ];
            if ($age >= 7) {
                $sumTotal += $total;
                $sumD1 += (int) $r['d1'];
                $sumD3 += (int) $r['d3'];
                $sumD7 += (int) $r['d7'];
            }
        }
        $retentionSummary = [
            'cohorts'  => $sumTotal,
            'd1'       => $sumTotal > 0 ? round($sumD1 / $sumTotal * 100, 1) : 0,
            'd3'       => $sumTotal > 0 ? round($sumD3 / $sumTotal * 100, 1) : 0,
            'd7'       => $sumTotal > 0 ? round($sumD7 / $sumTotal * 100, 1) : 0,
        ];
    } else {
        $retentionSummary = ['cohorts' => 0, 'd1' => 0, 'd3' => 0, 'd7' => 0];
    }

    // ==============================================================
    // 2. 复购（用户按激活张数 / 代理按兑换次数）
    // ==============================================================
    $userRp = Database::one(
        'SELECT COUNT(*) AS buyers,
                SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) AS repeat_buyers,
                SUM(CASE WHEN cnt >= 3 THEN 1 ELSE 0 END) AS v3,
                SUM(CASE WHEN cnt >= 6 THEN 1 ELSE 0 END) AS v6
         FROM (SELECT used_by, COUNT(*) AS cnt
               FROM ' . Database::t('cards') . '
               WHERE status = 1 AND used_by > 0
               GROUP BY used_by) t'
    );

    $agentRp = Database::one(
        'SELECT COUNT(*) AS agents,
                SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) AS repeat_agents,
                SUM(CASE WHEN cnt >= 5 THEN 1 ELSE 0 END) AS v5
         FROM (SELECT agent_id, COUNT(*) AS cnt
               FROM ' . Database::t('agent_logs') . "
               WHERE action = 'recharge'
               GROUP BY agent_id) t"
    );

    $userBuyers  = (int) ($userRp['buyers'] ?? 0);
    $userRepeat  = (int) ($userRp['repeat_buyers'] ?? 0);
    $agentCount  = (int) ($agentRp['agents'] ?? 0);
    $agentRepeat = (int) ($agentRp['repeat_agents'] ?? 0);

    $repurchase = [
        'user' => [
            'buyers'     => $userBuyers,
            'repeat'     => $userRepeat,
            'rate'       => $userBuyers > 0 ? round($userRepeat / $userBuyers * 100, 1) : 0,
            'once'       => max(0, $userBuyers - $userRepeat),
            'two_to_five'=> max(0, $userRepeat - (int) ($userRp['v6'] ?? 0)),
            'six_plus'   => (int) ($userRp['v6'] ?? 0),
            'three_plus' => (int) ($userRp['v3'] ?? 0),
        ],
        'agent' => [
            'agents'     => $agentCount,
            'repeat'     => $agentRepeat,
            'rate'       => $agentCount > 0 ? round($agentRepeat / $agentCount * 100, 1) : 0,
            'once'       => max(0, $agentCount - $agentRepeat),
            'five_plus'  => (int) ($agentRp['v5'] ?? 0),
        ],
    ];

    // ==============================================================
    // 3. 活跃分层 + 逐日活跃用户（DAU）
    // ==============================================================
    $tier = [
        'today'   => (int) Database::value(
            'SELECT COUNT(DISTINCT user_id) FROM ' . Database::t('sessions') . ' WHERE login_at >= ?',
            [$today]
        ),
        'd7'      => (int) Database::value(
            'SELECT COUNT(DISTINCT user_id) FROM ' . Database::t('sessions') . ' WHERE login_at >= ?',
            [$now - 7 * 86400]
        ),
        'd30'     => (int) Database::value(
            'SELECT COUNT(DISTINCT user_id) FROM ' . Database::t('sessions') . ' WHERE login_at >= ?',
            [$now - 30 * 86400]
        ),
        'total'   => (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users')),
    ];
    $tier['silent'] = max(0, $tier['total'] - $tier['d30']); // 30 天没登录过

    // 逐日活跃用户曲线（用登录行为去重统计，与留存口径一致）
    $dauFrom = $today - ($days - 1) * 86400;
    $dauMap  = [];
    foreach (Database::all(
        'SELECT DATE(FROM_UNIXTIME(login_at)) AS d, COUNT(DISTINCT user_id) AS c
         FROM ' . Database::t('sessions') . ' WHERE login_at >= ? GROUP BY d',
        [$dauFrom]
    ) as $r) {
        $dauMap[$r['d']] = (int) $r['c'];
    }

    $dau = [];
    $loginFrom = $today - ($days - 1) * 86400;
    $loginMap  = [];
    foreach (Database::all(
        'SELECT DATE(FROM_UNIXTIME(created_at)) AS d, COUNT(*) AS c
         FROM ' . Database::t('logs') . " WHERE action = 'login' AND result = 1 AND created_at >= ? GROUP BY d",
        [$loginFrom]
    ) as $r) {
        $loginMap[$r['d']] = (int) $r['c'];
    }

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', $today - $i * 86400);
        $dau[] = [
            'date'    => $d,
            'label'   => date('m-d', strtotime($d)),
            'users'   => $dauMap[$d] ?? 0,
            'logins'  => $loginMap[$d] ?? 0,
        ];
    }

    // ==============================================================
    // 4. 代理充值（金额/次数）走势 —— 代理侧"复购"的量化体现
    // ==============================================================
    $rechargeMap = [];
    foreach (Database::all(
        'SELECT DATE(FROM_UNIXTIME(created_at)) AS d, COUNT(*) AS c
         FROM ' . Database::t('agent_logs') . "
         WHERE action = 'recharge' AND created_at >= ? GROUP BY d",
        [$dauFrom]
    ) as $r) {
        $rechargeMap[$r['d']] = (int) $r['c'];
    }
    $rechargeTrend = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', $today - $i * 86400);
        $rechargeTrend[] = [
            'date'  => $d,
            'label' => date('m-d', strtotime($d)),
            'count' => $rechargeMap[$d] ?? 0,
        ];
    }

    return [
        'retention'      => ['list' => $cohorts, 'summary' => $retentionSummary, 'days' => $cohortDays],
        'repurchase'     => $repurchase,
        'tier'           => $tier,
        'dau'            => $dau,
        'recharge_trend' => $rechargeTrend,
    ];
});

$payload['days'] = $days;
$payload['explain'] = [
    'retention'  => 'D1/D3/D7 = 注册日的第 1/3/7 天回访登录的用户占比（自然日口径，队列未满 N 天显示 —）',
    'repurchase' => '用户复购 = 激活过 ≥2 张卡的用户占比；代理复购 = 兑换过 ≥2 次充值卡密的代理占比',
    'tier'       => '活跃口径为「有登录记录的去重用户数」；不活跃 = 总用户数 − 30 天活跃',
];

Response::ok($payload);
