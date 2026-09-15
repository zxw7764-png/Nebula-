<?php
/**
 * admin action: stat_overview
 * API 调用统计总览
 */

$startDate = Util::str($input, 'start_date', date('Y-m-d', strtotime('-6 day')));
$endDate   = Util::str($input, 'end_date', date('Y-m-d'));

// 总览
$summary = Database::one(
    'SELECT IFNULL(SUM(call_count),0) AS calls,
            IFNULL(SUM(fail_count),0) AS fails,
            IFNULL(ROUND(AVG(NULLIF(avg_ms,0))),0) AS avg_ms
     FROM ' . Database::t('api_stats') . '
     WHERE stat_date BETWEEN ? AND ?',
    [$startDate, $endDate]
);

// 按接口聚合
$byEndpoint = Database::all(
    'SELECT endpoint,
            SUM(call_count) AS calls,
            SUM(fail_count) AS fails,
            ROUND(AVG(NULLIF(avg_ms,0))) AS avg_ms
     FROM ' . Database::t('api_stats') . '
     WHERE stat_date BETWEEN ? AND ?
     GROUP BY endpoint ORDER BY calls DESC',
    [$startDate, $endDate]
);

// 按日期聚合
$byDate = Database::all(
    'SELECT stat_date, SUM(call_count) AS calls, SUM(fail_count) AS fails
     FROM ' . Database::t('api_stats') . '
     WHERE stat_date BETWEEN ? AND ?
     GROUP BY stat_date ORDER BY stat_date ASC',
    [$startDate, $endDate]
);

// Top IP
$topIp = Database::all(
    'SELECT ip, SUM(call_count) AS calls, SUM(fail_count) AS fails
     FROM ' . Database::t('api_stats') . '
     WHERE stat_date BETWEEN ? AND ?
     GROUP BY ip ORDER BY calls DESC LIMIT 20',
    [$startDate, $endDate]
);

// 异常 IP（失败率高）
$abnormal = Database::all(
    'SELECT ip,
            SUM(call_count) AS calls,
            SUM(fail_count) AS fails,
            ROUND(SUM(fail_count) / GREATEST(SUM(call_count),1) * 100, 2) AS fail_rate
     FROM ' . Database::t('api_stats') . '
     WHERE stat_date BETWEEN ? AND ?
     GROUP BY ip
     HAVING fails >= 10
     ORDER BY fail_rate DESC, fails DESC LIMIT 20',
    [$startDate, $endDate]
);

Response::ok([
    'range'       => ['start' => $startDate, 'end' => $endDate],
    'summary'     => [
        'calls'    => (int) ($summary['calls'] ?? 0),
        'fails'    => (int) ($summary['fails'] ?? 0),
        'avg_ms'   => (int) ($summary['avg_ms'] ?? 0),
        'fail_rate'=> ($summary['calls'] ?? 0) > 0
            ? round($summary['fails'] / $summary['calls'] * 100, 2) : 0,
    ],
    'by_endpoint' => $byEndpoint,
    'by_date'     => $byDate,
    'top_ip'      => $topIp,
    'abnormal_ip' => $abnormal,
]);
