<?php
/**
 * 定时任务
 *
 * Linux crontab 示例（每分钟执行一次）:
 *   * * * * * /usr/bin/php /path/to/nebula/cron.php >> /path/to/nebula/logs/cron.log 2>&1
 *
 * Windows 计划任务:
 *   程序: C:\php\php.exe
 *   参数: D:\path\to\nebula\cron.php
 *   触发器: 每 1 分钟
 *
 * 也可用外部服务定时访问: https://你的域名/cron.php?key=你的密钥
 */

require_once __DIR__ . '/lib/bootstrap.php';

// 若通过 HTTP 触发，需带 key 校验
if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    $expect = Config::get('security.sign_salt');
    if ($key === '' || !hash_equals((string) $expect, (string) $key)) {
        require_once __DIR__ . '/lib/error_page.php';
        nb_error_page(403);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$start = microtime(true);
$out   = [];

// ------------------------------------------------------------------
// 0. 心跳缓冲落库（必须在「清理僵尸设备」之前，否则在线设备会被误判离线）
// ------------------------------------------------------------------
if (Heartbeat::enabled()) {
    $r = Heartbeat::flush((int) Config::get('heartbeat.flush_batch', 200));
    $out[] = "心跳缓冲落库: {$r['devices']} 台（跳过 {$r['skipped']}）"
        . ($r['reason'] !== '' ? " — {$r['reason']}" : '');
} else {
    $out[] = '心跳缓冲落库: 未启用（直接写库模式）';
}

// ------------------------------------------------------------------
// 0b. 接口调用统计缓冲落库
// ------------------------------------------------------------------
if (Config::get('log.stat_buffer', false) && Cache::available()) {
    $r = Logger::flushStats();
    $out[] = "调用统计落库: {$r['rows']} 条"
        . ($r['reason'] !== '' ? " — {$r['reason']}" : '');
}

// ------------------------------------------------------------------
// 1. 清理过期会话
// ------------------------------------------------------------------
$n = Session::gc();
$out[] = "清理过期会话: {$n} 个";

// ------------------------------------------------------------------
// 2. 清理僵尸设备（心跳超时）
//    Heartbeat::gcTimeout() 会把阈值放宽一个缓冲延迟，避免聚合模式下误判
// ------------------------------------------------------------------
$timeout = Policy::heartbeatTimeout();
$n = Device::gcOffline(Heartbeat::gcTimeout($timeout));
$out[] = "清理离线设备: {$n} 台（阈值 {$timeout}s）";

// ------------------------------------------------------------------
// 3. 清理限流记录
// ------------------------------------------------------------------
$n = Database::exec('DELETE FROM ' . Database::t('rate_limit') . ' WHERE window_at < ?', [time() - 600]);
$out[] = "清理限流记录: {$n} 条";

// ------------------------------------------------------------------
// 4. 清理过期日志
// ------------------------------------------------------------------
$keepDays = (int) Config::get('log.keep_days', 30);
$n = Database::exec('DELETE FROM ' . Database::t('logs') . ' WHERE created_at < ?', [time() - $keepDays * 86400]);
$out[] = "清理过期日志: {$n} 条";

// ------------------------------------------------------------------
// 5. 清理过期 API 统计
// ------------------------------------------------------------------
$n = Database::exec('DELETE FROM ' . Database::t('api_stats') . ' WHERE stat_date < ?', [date('Y-m-d', strtotime('-90 day'))]);
$out[] = "清理过期统计: {$n} 条";

// ------------------------------------------------------------------
// 5b. 在线数快照（数据大屏的在线曲线）
//     nb_sessions 只保存当前状态，没有历史，所以需要按分钟留点。
//     口径与 /api/online、后台首页完全一致（status=1 且 last_active 在超时内）。
// ------------------------------------------------------------------
try {
    $online    = Session::onlineCount($timeout);
    $devOnline = (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE status = 1 AND last_seen > ?',
        [time() - $timeout]
    );
    $userTotal = (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users'));
    $bucket    = time() - (time() % 60);

    Database::exec(
        'INSERT INTO ' . Database::t('online_stats') . ' (stat_time, online, devices, users, created_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE online = VALUES(online), devices = VALUES(devices), users = VALUES(users)',
        [$bucket, $online, $devOnline, $userTotal, time()]
    );
    $out[] = "在线快照: {$online} 会话 / {$devOnline} 设备";

    // 快照保留 90 天（大屏最长看 30 天，留足余量）
    $n = Database::exec('DELETE FROM ' . Database::t('online_stats') . ' WHERE stat_time < ?', [time() - 90 * 86400]);
    if ($n > 0) {
        $out[] = "清理过期快照: {$n} 条";
    }
} catch (Throwable $e) {
    $out[] = '在线快照: 跳过（未执行迁移或表不存在）';
}

// ------------------------------------------------------------------
// 6. 清理过期 nonce
// ------------------------------------------------------------------
Crypto::gcNonce();
$out[] = "清理重放缓存: 完成";

// ------------------------------------------------------------------
// 7. 清理旧文件日志
// ------------------------------------------------------------------
$logDir = Config::get('log.dir');
if ($logDir && is_dir($logDir)) {
    $deadline = time() - $keepDays * 86400;
    $cnt = 0;
    foreach ((array) glob(rtrim($logDir, '/\\') . '/*.log') as $f) {
        if (is_file($f) && @filemtime($f) < $deadline) {
            @unlink($f);
            $cnt++;
        }
    }
    $out[] = "清理文件日志: {$cnt} 个";
}

// ------------------------------------------------------------------
// 8. 缓存维护
// ------------------------------------------------------------------
if (Cache::available()) {
    $info = Cache::info();
    if ($info['driver'] === 'file') {
        // 文件驱动的过期项：被读到时会自删，但再也没人读的会残留，
        // 这里按 mtime 兜底清理（保留 7 天）。
        $deadline = time() - 7 * 86400;
        $cnt = 0;
        foreach ((array) glob(rtrim($info['file_dir'], '/\\') . '/k_*') as $f) {
            if (is_file($f) && @filemtime($f) < $deadline) {
                @unlink($f);
                $cnt++;
            }
        }
        $use = Cache::fileUsage();
        $out[] = "缓存维护: 清理 {$cnt} 个，现存 {$use['files']} 个 / "
            . round($use['bytes'] / 1024, 1) . " KB";
    } else {
        $out[] = '缓存维护: 驱动 ' . $info['driver_text'] . '，无需清理文件';
    }
} else {
    $out[] = '缓存维护: 缓存未启用';
}

// ------------------------------------------------------------------
// 9. 数据备份
//    内部按 backup.interval_hours 节流：cron 每分钟跑，只有到点才真正备份。
//    未启用或未到周期时不产生文件，只在输出里说明原因。
// ------------------------------------------------------------------
try {
    $b = Backup::auto();
    $out[] = '数据备份: ' . $b['msg'];
} catch (Throwable $e) {
    $out[] = '数据备份: 执行异常（' . $e->getMessage() . '）';
}

// ------------------------------------------------------------------
// 10. 健康巡检
//    磁盘 / 数据库 / 目录权限 / PHP 扩展 / 失败日志 / 备份新鲜度，
//    有异常项时额外写一条日志，便于事后追溯。
// ------------------------------------------------------------------
try {
    $h = Health::run();
    $out[] = '健康巡检: ' . $h['summary'];
    foreach ($h['checks'] as $c) {
        if ($c['level'] !== 'ok') {
            $out[] = '  · [' . $c['level'] . '] ' . $c['name'] . '：' . $c['text'];
        }
    }
    if ($h['level'] !== 'ok') {
        Logger::log('health_check', 0, '健康巡检：' . $h['summary']);
    }
} catch (Throwable $e) {
    $out[] = '健康巡检: 执行异常（' . $e->getMessage() . '）';
}

$cost = round((microtime(true) - $start) * 1000, 2);
$out[] = "耗时: {$cost} ms";
$text = '[' . date('Y-m-d H:i:s') . "] cron 执行完成\n  - " . implode("\n  - ", $out) . "\n";

if (PHP_SAPI === 'cli') {
    echo $text;
} else {
    echo $text;
}
