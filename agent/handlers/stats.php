<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: stats
 * 代理商自己的数据分析：生成 / 激活趋势、卡类型占比、今日与本月概况
 * 数据口径：cards 表 agent_id = 本代理（只看自己的卡密，不含他人）
 */

$id  = (int) $agent['id'];
$now = time();

// ------------------------------------------------------------------
// 近 14 天趋势：按天统计 生成量（created_at）与 激活量（used_at）
// ------------------------------------------------------------------
$days = 14;
$from = strtotime('today') - ($days - 1) * 86400;

$genRows = Database::all(
    'SELECT FROM_UNIXTIME(created_at, "%Y-%m-%d") AS d, COUNT(*) AS c
     FROM ' . Database::t('cards') . '
     WHERE agent_id = ? AND created_at >= ?
     GROUP BY d',
    [$id, $from]
);
$actRows = Database::all(
    'SELECT FROM_UNIXTIME(used_at, "%Y-%m-%d") AS d, COUNT(*) AS c
     FROM ' . Database::t('cards') . '
     WHERE agent_id = ? AND used_at > 0 AND used_at >= ?
     GROUP BY d',
    [$id, $from]
);
$genMap = [];
foreach ($genRows as $r) { $genMap[$r['d']] = (int) $r['c']; }
$actMap = [];
foreach ($actRows as $r) { $actMap[$r['d']] = (int) $r['c']; }

$trend = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $trend[] = [
        'date'       => $d,
        'generated'  => $genMap[$d] ?? 0,
        'activated'  => $actMap[$d] ?? 0,
    ];
}

// ------------------------------------------------------------------
// 各卡类型生成占比（全部历史）
// ------------------------------------------------------------------
$typeBreakdown = [];
foreach (Database::all(
    'SELECT type, COUNT(*) AS total,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS used,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS unused,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS voided
     FROM ' . Database::t('cards') . '
     WHERE agent_id = ?
     GROUP BY type',
    [$id]
) as $r) {
    $typeBreakdown[] = [
        'type'       => (int) $r['type'],
        'name'       => Card::typeName((int) $r['type']),
        'total'      => (int) $r['total'],
        'used'       => (int) $r['used'],
        'unused'     => (int) $r['unused'],
        'voided'     => (int) $r['voided'],
        'used_rate'  => (int) $r['total'] > 0 ? round((int) $r['used'] * 100 / (int) $r['total']) : 0,
    ];
}

// ------------------------------------------------------------------
// 概况数字：今日 / 昨日 / 本月生成，激活率，最高产日
// ------------------------------------------------------------------
$today     = strtotime('today');
$yesterday = $today - 86400;
$monthStart = strtotime(date('Y-m-01'));

$summary = [
    'today'      => $genMap[date('Y-m-d')] ?? 0,
    'yesterday'  => Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('cards') . '
         WHERE agent_id = ? AND created_at >= ? AND created_at < ?',
        [$id, $yesterday, $today]
    ),
    'month'      => Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('cards') . '
         WHERE agent_id = ? AND created_at >= ?',
        [$id, $monthStart]
    ),
];

$allStats = Agent::stats($id);
$summary['activate_rate'] = $allStats['total'] > 0
    ? round($allStats['used'] * 100 / $allStats['total'])
    : 0;

$peak = ['date' => '', 'count' => 0];
foreach ($trend as $t) {
    if ($t['generated'] > $peak['count']) {
        $peak = ['date' => $t['date'], 'count' => $t['generated']];
    }
}

Response::ok([
    'trend'          => $trend,
    'types'          => $typeBreakdown,
    'summary'        => $summary,
    'peak'           => $peak,
    'stats'          => $allStats,
    'agent'          => Agent::publicInfo($agent),
]);
