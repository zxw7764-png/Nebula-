<?php
/**
 * 心跳缓冲 / 批量落库
 * ------------------------------------------------------------------
 * 背景：heartbeat 是全站最高频接口（每个在线客户端按 heartbeat_interval 调一次）。
 * 旧实现里每次心跳的写库动作有：
 *     1) sessions.expire_at / last_active   —— 单行索引更新，保留（见下方说明）
 *     2) devices.last_seen                  —— 单行更新
 *     3) logs 一条 heartbeat 记录            —— 往 5 个索引的日志表里插一行
 *     4) api_stats 一条 upsert              —— 每个请求都写
 * 在线人数一多，真正的瓶颈是 3) 和 4)（日志表会涨到千万行），
 * 其次是 2)（设备表更新被摊在每次请求里）。
 *
 * 本类的职责：
 *   · devices.last_seen 不再逐次写库，先进缓存缓冲，由 cron 或机会式
 *     批量落库；缓冲区同时支持「同一设备 refresh_seconds 内只落一次」的去重。
 *   · 提供统一的批量落库入口 flush()，供 cron、后台读取前调用。
 *   · 缓存不可用时（driver=none / 未启用聚合）全部方法直接空转，
 *     调用方自动回落原来的逐次写库行为 —— 功能与口径都不变。
 *
 * 为什么 sessions 不一起缓冲（重要取舍）：
 *   sessions.last_active 是「在线人数 / 在线会话列表」的唯一口径来源，
 *   而 sessions.expire_at 决定会话死活。把它们挪进缓存会让
 *   「踢下线」「封号」「会话过期」出现可见延迟，得不偿失。
 *   单行按唯一索引更新本身并不慢，真正贵的是日志与统计表，所以只动后者。
 */
class Heartbeat
{
    /** 设备活跃缓冲：field = "uid|machine_id"，value = 最后心跳时间戳 */
    private const DEV_KEY = 'hb:dev';

    /** 已落库水位：field 同上，value = 最后写入 DB 的时间戳（用于去重） */
    private const FDEV_KEY = 'hb:fdev';

    /** 机会式落库计数器 */
    private const TICK_KEY = 'hb:tick';

    /** 落库互斥锁 */
    private const LOCK_KEY = 'hb:lock';

    /** 总开关：配置打开且缓存可用 */
    public static function enabled(): bool
    {
        if (!Config::get('heartbeat.aggregate', true)) {
            return false;
        }
        return Cache::available();
    }

    // ==================================================================
    // 写缓冲
    // ==================================================================

    /** 记录一次设备心跳（只在缓存里，不写库） */
    public static function mark(int $userId, string $machineId): void
    {
        if ($userId <= 0 || $machineId === '' || !self::enabled()) {
            return;
        }
        $member = $userId . '|' . $machineId;
        $ttl    = max(600, (int) Config::get('heartbeat.buffer_ttl', 86400));
        Cache::hSet(self::DEV_KEY, $member, (string) time(), $ttl);
    }

    /** 设备解绑 / 删除时同步移出缓冲，避免无谓的落库尝试 */
    public static function dropDevice(int $userId, string $machineId): void
    {
        if (!self::enabled()) {
            return;
        }
        $member = $userId . '|' . $machineId;
        Cache::hDel(self::DEV_KEY, $member);
        Cache::hDel(self::FDEV_KEY, $member);
    }

    /** 缓冲区内的设备数（即为「最近有心跳、尚未落库」的设备数） */
    public static function bufferSize(): int
    {
        return self::enabled() ? Cache::hLen(self::DEV_KEY) : 0;
    }

    // ==================================================================
    // 批量落库
    // ==================================================================

    /**
     * 把缓冲区的设备活跃时间批量刷进 nb_devices.last_seen。
     *
     * @param int  $limit 单次最多落库多少台（防止缓冲区积压时一次打爆数据库）
     * @param bool $force 忽略 refresh_seconds 去重，强制刷新（cron 用）
     * @return array{ok:bool,devices:int,skipped:int,reason:string}
     */
    public static function flush(int $limit = 300, bool $force = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'devices' => 0, 'skipped' => 0, 'reason' => '未启用'];
        }
        if (Cache::hLen(self::DEV_KEY) === 0) {
            return ['ok' => true, 'devices' => 0, 'skipped' => 0, 'reason' => '缓冲为空'];
        }
        // 同一时刻只让一个请求做批量落库
        if (!Cache::lock(self::LOCK_KEY, 10)) {
            return ['ok' => true, 'devices' => 0, 'skipped' => 0, 'reason' => '其他进程正在落库'];
        }

        $now      = time();
        $refresh  = max(0, (int) Config::get('heartbeat.refresh_seconds', 120));
        $pending  = Cache::hGetAll(self::DEV_KEY);
        $flushed  = Cache::hGetAll(self::FDEV_KEY);
        $done     = 0;
        $skipped  = 0;
        $stale    = [];
        $deadline = $now - (int) Config::get('heartbeat.stale_seconds', 86400);

        try {
            foreach ($pending as $member => $score) {
                $score = (int) $score;

                // 超过一天的缓冲项直接丢弃：设备早已离线，没必要再刷
                if ($score < $deadline) {
                    $stale[] = (string) $member;
                    continue;
                }
                // 同一设备 refresh_seconds 内只落库一次
                if (!$force && $refresh > 0 && isset($flushed[$member])
                    && ($score - (int) $flushed[$member]) < $refresh) {
                    $skipped++;
                    continue;
                }
                if ($done >= $limit) {
                    $skipped++;
                    continue;
                }

                $pos = strpos((string) $member, '|');
                if ($pos === false) {
                    $stale[] = (string) $member;
                    continue;
                }
                $uid = (int) substr((string) $member, 0, $pos);
                $mid = substr((string) $member, $pos + 1);
                if ($uid <= 0 || $mid === '') {
                    $stale[] = (string) $member;
                    continue;
                }

                Database::update('devices', ['last_seen' => $score],
                    'user_id = :u AND machine_id = :m', ['u' => $uid, 'm' => $mid]);
                Cache::hSet(self::FDEV_KEY, (string) $member, (string) $score,
                    max(600, (int) Config::get('heartbeat.buffer_ttl', 86400)));
                $done++;
            }

            if ($stale) {
                Cache::hDel(self::DEV_KEY, ...$stale);
                Cache::hDel(self::FDEV_KEY, ...$stale);
            }
        } catch (Throwable $e) {
            // 落库失败不能让心跳链路报错，缓冲区保留，下次再刷
            Cache::unlock(self::LOCK_KEY);
            return ['ok' => false, 'devices' => $done, 'skipped' => $skipped,
                    'reason' => '落库异常：' . $e->getMessage()];
        }

        Cache::unlock(self::LOCK_KEY);
        return ['ok' => true, 'devices' => $done, 'skipped' => $skipped, 'reason' => ''];
    }

    /**
     * 机会式落库：心跳请求里顺带触发，保证即使没配 cron 也不会积压太多。
     * 通过计数器 + 短锁控制频率，集群里同一时刻只有一个请求会真正执行。
     */
    public static function maybeFlush(): void
    {
        if (!self::enabled()) {
            return;
        }
        $every = max(1, (int) Config::get('heartbeat.flush_every', 30));
        $tick  = Cache::incr(self::TICK_KEY, 1, 3600);
        if ($tick <= 0 || $tick % $every !== 0) {
            return;
        }
        if (Cache::hLen(self::DEV_KEY) === 0) {
            return;
        }
        self::flush((int) Config::get('heartbeat.flush_batch', 200));
    }

    /** 运行时状态（后台诊断用） */
    public static function stats(): array
    {
        if (!self::enabled()) {
            return [
                'enabled'  => false,
                'buffered' => 0,
                'flushed'  => 0,
                'cache'    => Cache::info(),
            ];
        }
        return [
            'enabled'        => true,
            'buffered'       => Cache::hLen(self::DEV_KEY),
            'flushed'        => Cache::hLen(self::FDEV_KEY),
            'refresh_seconds'=> (int) Config::get('heartbeat.refresh_seconds', 120),
            'flush_every'    => (int) Config::get('heartbeat.flush_every', 30),
            'cache'          => Cache::info(),
        ];
    }

    /**
     * 「清理离线设备」应该使用的超时阈值。
     * ------------------------------------------------------------------
     * 设备活跃时间是缓冲后批量落库的，DB 里的 last_seen 最多可能比真实值
     * 旧 refresh_seconds。如果清理逻辑仍按原阈值判定，就会把【在线设备】
     * 误判为离线并解绑。这里把阈值放宽一个「缓冲延迟 + 60s 余量」，
     * 同时保证 gc 之前先 flush（见 cron.php / device_unbind.php 的调用顺序）。
     */
    public static function gcTimeout(int $timeout): int
    {
        if (!self::enabled()) {
            return $timeout;
        }
        return $timeout + max(0, (int) Config::get('heartbeat.refresh_seconds', 120)) + 60;
    }
}
