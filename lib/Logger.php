<?php
/**
 * 日志记录
 * 同时写入数据库（nb_logs）和文件日志
 */
class Logger
{
    private static array $cfg = [];
    private static int $startMs = 0;

    /** 防重入：批量落库过程中又触发一次刷写 */
    private static bool $statFlushing = false;

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
        self::$startMs = (int) (microtime(true) * 1000);
    }

    /**
     * 记录一条操作日志
     */
    public static function log(
        string $action,
        int $result = 1,
        string $message = '',
        array $ctx = []
    ): void {
        $userId   = (int) ($ctx['user_id'] ?? 0);
        $adminId  = (int) ($ctx['admin_id'] ?? 0);
        $username = $ctx['username']  ?? null;
        $machine  = $ctx['machine_id'] ?? null;
        $raw      = (!empty(self::$cfg['record_raw']) && isset($ctx['raw']))
            ? (is_string($ctx['raw']) ? $ctx['raw'] : json_encode($ctx['raw'], JSON_UNESCAPED_UNICODE))
            : null;

        // 数据库
        try {
            Database::insert('logs', [
                'user_id'    => max(0, $userId),
                'admin_id'   => max(0, $adminId),
                'username'   => $username,
                'action'     => $action,
                'result'     => $result,
                'message'    => mb_substr($message, 0, 250),
                'ip'         => Util::ip(),
                'machine_id' => $machine ? mb_substr((string) $machine, 0, 128) : null,
                'ua'         => Util::ua(),
                'raw'        => $raw,
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            // 日志失败不影响业务
        }

        // 文件
        if (!empty(self::$cfg['file_enable'])) {
            self::writeFile($action, $result, $message, $userId ?: $adminId, $machine);
        }
    }

    private static function writeFile(
        string $action,
        int $result,
        string $message,
        int $userId,
        ?string $machine
    ): void {
        $dir = self::$cfg['dir'] ?? (__DIR__ . '/../logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = rtrim($dir, '/\\') . '/' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s | %s | uid=%d | ip=%s | mid=%s | %s\n",
            date('H:i:s'),
            strtoupper($action),
            $result ? 'OK' : 'FAIL',
            $userId,
            Util::ip(),
            $machine ? substr($machine, 0, 16) : '-',
            $message
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 记录 API 调用统计
     * ------------------------------------------------------------------
     * api_stats 是【每个请求】都要写的一条 upsert（按 日期+接口+IP 聚合）。
     * 流量一上来，这张表的写入就会和业务争锁。启用 stat_buffer 后改为：
     *   请求内 → 只做几次 HINCRBY（内存/文件）
     *   落库   → 由 cron 或累计到 stat_flush_at 次时机会式批量 upsert
     * 缓存不可用时自动回落成原来的直接写库，后台统计口径完全不变。
     */
    public static function stat(string $endpoint, bool $success, int $costMs): void
    {
        $ip     = Util::ip();
        $costMs = max(0, $costMs);

        if (self::statBufferEnabled()) {
            $date = date('Y-m-d');
            $ttl  = 172800; // 留足两天，跨天也能刷掉
            $key  = $endpoint . '|' . $ip;
            try {
                Cache::hIncrBy('stat:c:' . $date, $key, 1, $ttl);
                if (!$success) {
                    Cache::hIncrBy('stat:f:' . $date, $key, 1, $ttl);
                }
                Cache::hIncrBy('stat:m:' . $date, $key, $costMs, $ttl);

                $at = max(1, (int) Config::get('log.stat_flush_at', 50));
                if (Cache::incr('stat:tick', 1, 3600) >= $at) {
                    Cache::del('stat:tick');
                    self::flushStats();
                }
            } catch (Throwable $e) {
                // 缓冲失败就直接写库，统计不能丢
                self::statDirect($date, $endpoint, $ip, $success, $costMs);
            }
            return;
        }

        self::statDirect(date('Y-m-d'), $endpoint, $ip, $success, $costMs);
    }

    private static function statBufferEnabled(): bool
    {
        return (bool) Config::get('log.stat_buffer', false) && Cache::available();
    }

    /** 直接写库（原实现，作为缓冲不可用时的回落路径） */
    private static function statDirect(string $date, string $endpoint, string $ip,
                                       bool $success, int $costMs): void
    {
        $costMs = max(0, $costMs);
        try {
            // 注意 ON DUPLICATE KEY UPDATE 的赋值是【从左到右】求值的，
            // 后面的表达式会读到前面刚更新过的列值。因此 avg_ms 必须写在
            // call_count = call_count + 1 之前，否则分母会多算一次。
            $sql = 'INSERT INTO ' . Database::t('api_stats') . '
                    (stat_date, endpoint, ip, call_count, fail_count, avg_ms, updated_at)
                    VALUES (:d, :e, :i, 1, :f, :m, :u)
                    ON DUPLICATE KEY UPDATE
                        avg_ms     = ROUND((avg_ms * call_count + :m2) / (call_count + 1)),
                        call_count = call_count + 1,
                        fail_count = fail_count + :f2,
                        updated_at = :u2';
            Database::exec($sql, [
                'd'  => $date,
                'e'  => $endpoint,
                'i'  => $ip,
                'f'  => $success ? 0 : 1,
                'm'  => $costMs,
                'u'  => time(),
                'f2' => $success ? 0 : 1,
                'm2' => $costMs,
                'u2' => time(),
            ]);
        } catch (Throwable $e) {
            // 统计失败绝不能影响业务，但 debug 模式下留下线索便于排查
            if (Config::get('debug')) {
                error_log('[nb_stat] ' . $e->getMessage());
            }
        }
    }

    /**
     * 把缓冲的调用统计批量落库（cron 与机会式触发都走这里）。
     * 同时刷「今天」和「昨天」，避免跨天时前一天的尾巴被漏掉。
     *
     * @return array{ok:bool,rows:int,skipped:int,reason:string}
     */
    public static function flushStats(): array
    {
        if (!Cache::available() || self::$statFlushing) {
            return ['ok' => false, 'rows' => 0, 'skipped' => 0, 'reason' => '缓存不可用或正在刷'];
        }
        self::$statFlushing = true;

        $rows = 0;
        $skip = 0;
        try {
            foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $date) {
                $calls = Cache::hGetAll('stat:c:' . $date);
                if (!$calls) {
                    continue;
                }
                $fails = Cache::hGetAll('stat:f:' . $date);
                $sums  = Cache::hGetAll('stat:m:' . $date);

                $doneFields = [];
                foreach ($calls as $field => $n) {
                    $n = (int) $n;
                    if ($n <= 0) {
                        $doneFields[] = (string) $field;
                        continue;
                    }
                    $pos = strrpos((string) $field, '|');
                    if ($pos === false) {
                        $doneFields[] = (string) $field;
                        $skip++;
                        continue;
                    }
                    $endpoint = substr((string) $field, 0, $pos);
                    $ip       = substr((string) $field, $pos + 1);
                    $fail     = (int) ($fails[$field] ?? 0);
                    $sum      = (int) ($sums[$field] ?? 0);
                    $avg      = (int) round($sum / $n);

                    try {
                        Database::exec(
                            'INSERT INTO ' . Database::t('api_stats') . '
                                (stat_date, endpoint, ip, call_count, fail_count, avg_ms, updated_at)
                             VALUES (:d, :e, :i, :c1, :f1, :m1, :u1)
                             ON DUPLICATE KEY UPDATE
                                avg_ms     = ROUND((avg_ms * call_count + :s2) / (call_count + :c2)),
                                call_count = call_count + :c3,
                                fail_count = fail_count + :f3,
                                updated_at = :u3',
                            [
                                'd'  => $date,
                                'e'  => $endpoint,
                                'i'  => $ip,
                                'c1' => $n,
                                'f1' => $fail,
                                'm1' => $avg,
                                'u1' => time(),
                                's2' => $sum,
                                'c2' => $n,
                                'c3' => $n,
                                'f3' => $fail,
                                'u3' => time(),
                            ]
                        );
                        $rows++;
                        $doneFields[] = (string) $field;
                    } catch (Throwable $e) {
                        $skip++;
                        if (Config::get('debug')) {
                            error_log('[nb_stat_flush] ' . $e->getMessage());
                        }
                    }
                }

                // 只删除成功落库的字段，失败的下次继续
                foreach (array_chunk($doneFields, 500) as $chunk) {
                    if ($chunk) {
                        Cache::hDel('stat:c:' . $date, ...$chunk);
                        Cache::hDel('stat:f:' . $date, ...$chunk);
                        Cache::hDel('stat:m:' . $date, ...$chunk);
                    }
                }
            }
        } catch (Throwable $e) {
            self::$statFlushing = false;
            return ['ok' => false, 'rows' => $rows, 'skipped' => $skip, 'reason' => $e->getMessage()];
        }

        self::$statFlushing = false;
        return ['ok' => true, 'rows' => $rows, 'skipped' => $skip, 'reason' => ''];
    }

    /** 缓冲区内待落库的统计条目数（后台诊断用） */
    public static function statBufferSize(): int
    {
        if (!Cache::available()) {
            return 0;
        }
        return Cache::hLen('stat:c:' . date('Y-m-d'))
             + Cache::hLen('stat:c:' . date('Y-m-d', strtotime('-1 day')));
    }

    /** 计算耗时毫秒 */
    public static function costMs(): int
    {
        return (int) (microtime(true) * 1000) - self::$startMs;
    }

    /** 清理过期日志 */
    public static function cleanup(int $keepDays = 30): void
    {
        $deadline = time() - $keepDays * 86400;
        try {
            Database::exec('DELETE FROM ' . Database::t('logs') . ' WHERE created_at < ?', [$deadline]);
            Database::exec('DELETE FROM ' . Database::t('sessions') . ' WHERE expire_at > 0 AND expire_at < ?', [time() - 86400]);
            Database::exec('DELETE FROM ' . Database::t('rate_limit') . ' WHERE window_at < ?', [time() - 600]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
