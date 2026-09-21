<?php
/**
 * 统一缓存层（Redis 优先 / 文件降级）
 * ------------------------------------------------------------------
 * 定位：把「每个请求都要写库」的高频动作挪出 MySQL 热路径
 *       （心跳的 devices.last_seen、全站接口调用统计 api_stats、
 *         公告计数等），再由 cron 或机会式批量落库。
 *
 * 驱动优先级（config.cache.driver）：
 *   auto   扩展 redis → 内置 RESP 套接字 → 文件 → 关闭   （默认）
 *   redis  仅 Redis（连不上时继续降级，绝不中断业务）
 *   file   仅文件缓存
 *   none   关闭缓存（所有调用方自动走原 DB 路径）
 *
 * 三条硬性设计原则：
 *   1. 任何缓存故障都不能影响业务 —— 所有方法自带 try/catch，
 *      失败时返回「未命中」语义，调用方自动回落数据库。
 *   2. 没装 ext-redis 也要能用 Redis —— 内置精简 RESP 客户端走 TCP，
 *      只实现本项目用得到的命令。
 *   3. Redis 探测结果带负缓存（默认 60s）—— Redis 挂掉后不会每个请求
 *      都卡在连接超时上（这是接入缓存最常见的"性能反而变差"事故）。
 *
 * 注意：文件驱动只适合单机；多机部署请用 Redis，否则各机器缓存不共享。
 */
class Cache
{
    private static array   $cfg        = [];
    private static string  $prefix     = 'nb:';
    private static string  $fileDir    = '';
    private static int     $ttlDefault = 300;

    /** 已解析的驱动：redis | file | none（null = 尚未解析） */
    private static ?string $driver     = null;

    /** @var \Redis|CacheRedis|null */
    private static $redis        = null;
    private static bool $redisIsExt   = false;
    private static bool $redisFailed  = false;

    /** 本次请求内的调试记录（只保留最近若干条，供后台排查） */
    private static array $notes = [];

    // ==================================================================
    // 初始化 / 驱动解析
    // ==================================================================

    public static function init(array $cfg): void
    {
        self::$cfg        = $cfg;
        self::$prefix     = (string) ($cfg['prefix'] ?? 'nb:');
        self::$ttlDefault = (int) ($cfg['default_ttl'] ?? 300);

        $dir = (string) ($cfg['file']['dir'] ?? '');
        if ($dir === '') {
            // 默认落在 logs/ 下：部署模板已整目录拦截 logs，无需改服务器配置
            $dir = (defined('NB_ROOT') ? NB_ROOT : dirname(__DIR__)) . '/logs/cache';
        }
        self::$fileDir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir(self::$fileDir)) {
            @mkdir(self::$fileDir, 0700, true);
        }

        self::$driver      = null;
        self::$redis       = null;
        self::$redisIsExt  = false;
        self::$redisFailed = false;
        self::$notes       = [];
    }

    /** 当前实际生效的驱动 */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $want = strtolower((string) (self::$cfg['driver'] ?? 'auto'));
        if ($want === 'none') {
            return self::$driver = 'none';
        }

        if ($want === 'redis' || $want === 'auto') {
            if (self::connectRedis()) {
                return self::$driver = 'redis';
            }
            if ($want === 'redis') {
                self::note('redis 连接失败，已自动降级为文件缓存');
            }
        }

        if (self::fileReady()) {
            return self::$driver = 'file';
        }

        self::note('文件缓存目录不可写，缓存已关闭');
        return self::$driver = 'none';
    }

    /** 缓存是否可用（false 时所有调用方都应走原 DB 路径） */
    public static function available(): bool
    {
        return self::driver() !== 'none';
    }

    /** 供后台展示的运行时信息 */
    public static function info(): array
    {
        $d   = self::driver();
        $cfg = self::$cfg;
        return [
            'driver'      => $d,
            'driver_text' => ['redis' => 'Redis', 'file' => '文件缓存', 'none' => '未启用'][$d] ?? $d,
            'prefix'      => self::$prefix,
            'file_dir'    => self::$fileDir,
            'file_ready'  => self::fileReady(),
            'ext_redis'   => extension_loaded('redis'),
            'redis_host'  => (string) ($cfg['redis']['host'] ?? ''),
            'redis_port'  => (int) ($cfg['redis']['port'] ?? 0),
            'redis_db'    => (int) ($cfg['redis']['database'] ?? 0),
            'redis_auth'  => !empty($cfg['redis']['password']),
            'default_ttl' => self::$ttlDefault,
            'notes'       => self::$notes,
        ];
    }

    private static function note(string $msg): void
    {
        if (count(self::$notes) < 10) {
            self::$notes[] = $msg;
        }
    }

    // ------------------------------------------------------------------
    // 文件驱动可用性
    // ------------------------------------------------------------------
    private static function fileReady(): bool
    {
        if (self::$fileDir === '') {
            return false;
        }
        if (!is_dir(self::$fileDir) && !@mkdir(self::$fileDir, 0700, true)) {
            return false;
        }
        return is_writable(self::$fileDir);
    }

    // ------------------------------------------------------------------
    // Redis 连接（扩展优先，其次内置 RESP 客户端）
    // ------------------------------------------------------------------
    private static function connectRedis(): bool
    {
        if (self::$redis !== null) {
            return true;
        }
        if (self::$redisFailed) {
            return false;
        }

        $rc = (array) (self::$cfg['redis'] ?? []);

        // 扩展方式（最稳，方法语义有官方保证）
        if (extension_loaded('redis') && class_exists('\Redis')) {
            try {
                $r = new \Redis();
                $ok = @$r->connect(
                    (string) ($rc['host'] ?? '127.0.0.1'),
                    (int) ($rc['port'] ?? 6379),
                    (float) ($rc['timeout'] ?? 1.0)
                );
                if ($ok) {
                    if (!empty($rc['password'])) {
                        @$r->auth((string) $rc['password']);
                    }
                    $db = (int) ($rc['database'] ?? 0);
                    if ($db > 0) {
                        @$r->select($db);
                    }
                    @$r->setOption(\Redis::OPT_PREFIX, self::$prefix);
                    self::$redis      = $r;
                    self::$redisIsExt = true;
                    return true;
                }
                self::note('ext-redis 连接失败，尝试套接字方式');
            } catch (Throwable $e) {
                self::note('ext-redis 异常：' . $e->getMessage());
            }
        }

        // 内置 RESP 客户端（没装扩展也能用 Redis）
        if (!self::probeAllowed()) {
            return false;
        }
        try {
            $c = new CacheRedis([
                'host'     => (string) ($rc['host'] ?? '127.0.0.1'),
                'port'     => (int) ($rc['port'] ?? 6379),
                'password' => (string) ($rc['password'] ?? ''),
                'database' => (int) ($rc['database'] ?? 0),
                'timeout'  => (float) ($rc['timeout'] ?? 1.0),
                'prefix'   => self::$prefix,
            ]);
            if ($c->connect()) {
                self::$redis      = $c;
                self::$redisIsExt = false;
                self::clearProbeOff();
                return true;
            }
            self::$redisFailed = true;
            self::markProbeOff();
            return false;
        } catch (Throwable $e) {
            self::$redisFailed = true;
            self::markProbeOff();
            self::note('Redis 套接字连接失败：' . $e->getMessage());
            return false;
        }
    }

    /** 是否需要重新探测 Redis（避免每次请求都吃连接超时） */
    private static function probeAllowed(): bool
    {
        $sec = (int) (self::$cfg['redis']['reprobe_seconds'] ?? 60);
        if ($sec <= 0) {
            return true;
        }
        $mark = self::$fileDir . '/.redis_off';
        $m    = @filemtime($mark);
        return !($m !== false && (time() - $m) < $sec);
    }

    private static function markProbeOff(): void
    {
        @file_put_contents(self::$fileDir . '/.redis_off', (string) time(), LOCK_EX);
    }

    private static function clearProbeOff(): void
    {
        @unlink(self::$fileDir . '/.redis_off');
    }

    // ==================================================================
    // 基础读写
    // ==================================================================

    public static function get(string $key, $default = null)
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $v = self::redisGet($k);
                    return $v === null ? $default : $v;
                case 'file':
                    $v = self::fileGet(self::path($k));
                    return $v === null ? $default : $v;
            }
        } catch (Throwable $e) {
            self::note('get 失败：' . $e->getMessage());
        }
        return $default;
    }

    public static function set(string $key, $value, int $ttl = 0): bool
    {
        $k   = self::key($key);
        $ttl = $ttl > 0 ? $ttl : self::$ttlDefault;
        try {
            switch (self::driver()) {
                case 'redis':
                    return self::redisSet($k, $value, $ttl);
                case 'file':
                    return self::filePut(self::path($k), $value, $ttl);
            }
        } catch (Throwable $e) {
            self::note('set 失败：' . $e->getMessage());
        }
        return false;
    }

    /** 不存在才写（用于防穿透 / 单次抢占） */
    public static function add(string $key, $value, int $ttl = 0): bool
    {
        if (self::has($key)) {
            return false;
        }
        return self::set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::exists($key);
    }

    public static function exists(string $key): bool
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    return (int) self::redisCmd(['EXISTS', $k]) > 0;
                case 'file':
                    return self::fileGet(self::path($k)) !== null;
            }
        } catch (Throwable $e) {
            self::note('exists 失败：' . $e->getMessage());
        }
        return false;
    }

    public static function del(string ...$keys): int
    {
        if (!$keys) {
            return 0;
        }
        $n = 0;
        try {
            switch (self::driver()) {
                case 'redis':
                    $args = ['DEL'];
                    foreach ($keys as $x) {
                        $args[] = self::key($x);
                    }
                    return max(0, (int) self::redisCmd($args));
                case 'file':
                    foreach ($keys as $x) {
                        if (@unlink(self::path(self::key($x)))) {
                            $n++;
                        }
                    }
                    return $n;
            }
        } catch (Throwable $e) {
            self::note('del 失败：' . $e->getMessage());
        }
        return $n;
    }

    /** 自增并返回新值；键不存在时按 $by 初始化 */
    public static function incr(string $key, int $by = 1, int $ttl = 0): int
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $v = (int) self::redisCmd(['INCRBY', $k, (string) $by]);
                    if ($ttl > 0) {
                        self::redisCmd(['EXPIRE', $k, (string) ($ttl > 0 ? $ttl : self::$ttlDefault)]);
                    }
                    return $v;
                case 'file':
                    $f = self::path($k);
                    return (int) self::fileTransaction($f, function () use ($f, $by, $ttl) {
                        $v = self::fileGet($f);
                        $n = (is_int($v) ? $v : 0) + $by;
                        self::filePut($f, $n, $ttl > 0 ? $ttl : self::$ttlDefault);
                        return $n;
                    });
            }
        } catch (Throwable $e) {
            self::note('incr 失败：' . $e->getMessage());
        }
        return 0;
    }

    public static function expire(string $key, int $ttl): bool
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    return (int) self::redisCmd(['EXPIRE', $k, (string) $ttl]) === 1;
                case 'file':
                    $f = self::path($k);
                    return (bool) self::fileTransaction($f, function () use ($f, $ttl) {
                        $v = self::fileGet($f);
                        if ($v === null) {
                            return false;
                        }
                        return self::filePut($f, $v, $ttl);
                    });
            }
        } catch (Throwable $e) {
            self::note('expire 失败：' . $e->getMessage());
        }
        return false;
    }

    /** 剩余秒数：-1 永久，-2 不存在 */
    public static function ttl(string $key): int
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    return (int) self::redisCmd(['TTL', $k]);
                case 'file':
                    $raw = self::fileRead(self::path($k));
                    if ($raw === null) {
                        return -2;
                    }
                    return (int) $raw['e'] <= 0 ? -1 : max(0, (int) $raw['e'] - time());
            }
        } catch (Throwable $e) {
            self::note('ttl 失败：' . $e->getMessage());
        }
        return -2;
    }

    /**
     * 读缓存，未命中则执行 $fn 并写入
     */
    public static function remember(string $key, int $ttl, callable $fn)
    {
        $hit = false;
        $v   = self::get($key, null);
        if ($v !== null) {
            return $v;
        }
        $v = $fn();
        if ($v !== null) {
            self::set($key, $v, $ttl);
        }
        return $v;
    }

    // ==================================================================
    // 哈希（心跳缓冲用）
    // ==================================================================

    public static function hSet(string $key, string $field, $value, int $ttl = 0): bool
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $r = self::redisCmd(['HSET', $k, $field, (string) $value]);
                    if ($ttl > 0) {
                        self::redisCmd(['EXPIRE', $k, (string) $ttl]);
                    }
                    return $r !== false;
                case 'file':
                    $f = self::path($k);
                    return (bool) self::fileTransaction($f, function () use ($f, $field, $value, $ttl) {
                        $h = self::hashLoad($f);
                        $h['h'][$field] = (string) $value;
                        return self::hashSave($f, $h, $ttl);
                    });
            }
        } catch (Throwable $e) {
            self::note('hSet 失败：' . $e->getMessage());
        }
        return false;
    }

    public static function hGet(string $key, string $field)
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $v = self::redisCmd(['HGET', $k, $field]);
                    return ($v === false || $v === null) ? null : $v;
                case 'file':
                    $h = self::hashLoad(self::path($k));
                    return $h['h'][$field] ?? null;
            }
        } catch (Throwable $e) {
            self::note('hGet 失败：' . $e->getMessage());
        }
        return null;
    }

    /** @return array<string,string> field => value */
    public static function hGetAll(string $key): array
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $v = self::redisCmd(['HGETALL', $k]);
                    if (!is_array($v)) {
                        return [];
                    }
                    // 扩展方式返回 map，套接字方式返回扁平数组，这里统一成 map
                    // 注意：PHP 8.0 没有 array_is_list()，用键类型自行判断
                    $isMap = array_keys($v) !== range(0, count($v) - 1);
                    if ($isMap) {
                        return array_map('strval', $v);
                    }
                    $out = [];
                    for ($i = 0; $i + 1 < count($v); $i += 2) {
                        $out[(string) $v[$i]] = (string) $v[$i + 1];
                    }
                    return $out;
                case 'file':
                    return self::hashLoad(self::path($k))['h'];
            }
        } catch (Throwable $e) {
            self::note('hGetAll 失败：' . $e->getMessage());
        }
        return [];
    }

    public static function hDel(string $key, string ...$fields): int
    {
        if (!$fields) {
            return 0;
        }
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $args = ['HDEL', $k];
                    foreach ($fields as $f) {
                        $args[] = $f;
                    }
                    return max(0, (int) self::redisCmd($args));
                case 'file':
                    $f  = self::path($k);
                    $n  = 0;
                    self::fileTransaction($f, function () use ($f, $fields, &$n) {
                        $h = self::hashLoad($f);
                        foreach ($fields as $x) {
                            if (array_key_exists($x, $h['h'])) {
                                unset($h['h'][$x]);
                                $n++;
                            }
                        }
                        self::hashSave($f, $h, 0);
                        return true;
                    });
                    return $n;
            }
        } catch (Throwable $e) {
            self::note('hDel 失败：' . $e->getMessage());
        }
        return 0;
    }

    public static function hIncrBy(string $key, string $field, int $by = 1, int $ttl = 0): int
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    $v = (int) self::redisCmd(['HINCRBY', $k, $field, (string) $by]);
                    if ($ttl > 0) {
                        self::redisCmd(['EXPIRE', $k, (string) $ttl]);
                    }
                    return $v;
                case 'file':
                    $f = self::path($k);
                    return (int) self::fileTransaction($f, function () use ($f, $field, $by, $ttl) {
                        $h = self::hashLoad($f);
                        $n = (int) ($h['h'][$field] ?? 0) + $by;
                        $h['h'][$field] = (string) $n;
                        self::hashSave($f, $h, $ttl);
                        return $n;
                    });
            }
        } catch (Throwable $e) {
            self::note('hIncrBy 失败：' . $e->getMessage());
        }
        return 0;
    }

    public static function hLen(string $key): int
    {
        $k = self::key($key);
        try {
            switch (self::driver()) {
                case 'redis':
                    return max(0, (int) self::redisCmd(['HLEN', $k]));
                case 'file':
                    return count(self::hashLoad(self::path($k))['h']);
            }
        } catch (Throwable $e) {
            self::note('hLen 失败：' . $e->getMessage());
        }
        return 0;
    }

    /** 给哈希整体续期 */
    public static function hExpire(string $key, int $ttl): bool
    {
        return self::expire($key, $ttl);
    }

    // ==================================================================
    // 简易锁（用于「同一时刻只让一个请求做批量落库」）
    // ==================================================================

    public static function lock(string $key, int $ttl = 10): bool
    {
        $k   = self::key($key);
        $ttl = max(1, $ttl);
        try {
            switch (self::driver()) {
                case 'redis':
                    $r = self::redisCmd(['SET', $k, '1', 'NX', 'EX', (string) $ttl]);
                    return $r === 'OK' || $r === true;
                case 'file':
                    $f = self::path($k);
                    // 原子创建：fopen('x') 在文件已存在时必定失败，天然互斥
                    $fh = @fopen($f, 'x');
                    if ($fh !== false) {
                        // 立刻写入有效期，避免中间态被其它进程当成"空文件=已过期"
                        fwrite($fh, json_encode(['e' => time() + $ttl, 't' => 'int', 'v' => 1]));
                        fclose($fh);
                        return true;
                    }
                    // 已存在：过期就删掉再抢一次（fileRead 内部会自动清理过期文件）
                    if (is_file($f) && self::fileRead($f) === null) {
                        @unlink($f);
                        $fh = @fopen($f, 'x');
                        if ($fh !== false) {
                            fwrite($fh, json_encode(['e' => time() + $ttl, 't' => 'int', 'v' => 1]));
                            fclose($fh);
                            return true;
                        }
                    }
                    return false;
            }
        } catch (Throwable $e) {
            self::note('lock 失败：' . $e->getMessage());
        }
        return false;
    }

    public static function unlock(string $key): void
    {
        self::del($key);
    }

    // ==================================================================
    // 维护
    // ==================================================================

    /** 清空本前缀下的所有键 */
    public static function flushPrefix(): int
    {
        $n = 0;
        try {
            switch (self::driver()) {
                case 'redis':
                    if (self::$redisIsExt) {
                        $it = null;
                        self::$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
                        while (($keys = self::$redis->scan($it, self::$prefix . '*', 500)) !== false) {
                            if ($keys) {
                                $n += (int) self::$redis->del($keys);
                            }
                            if ($it === 0 || $it === null) {
                                break;
                            }
                        }
                    } else {
                        $it   = '0';
                        $loop = 0;
                        do {
                            $res = self::redisCmd(['SCAN', $it, 'MATCH', self::$prefix . '*', 'COUNT', '500']);
                            if (!is_array($res) || count($res) < 2) {
                                break;
                            }
                            $it   = (string) $res[0];
                            $keys = (array) $res[1];
                            // SCAN 返回的键已含前缀，这里要绕开自动加前缀
                            foreach ($keys as $kk) {
                                self::redisCmd(['DEL', $kk]);
                                $n++;
                            }
                        } while ($it !== '0' && ++$loop < 1000);
                    }
                    return $n;
                case 'file':
                    foreach ((array) glob(self::$fileDir . '/k_*') as $f) {
                        if (is_file($f) && @unlink($f)) {
                            $n++;
                        }
                    }
                    return $n;
            }
        } catch (Throwable $e) {
            self::note('flushPrefix 失败：' . $e->getMessage());
        }
        return $n;
    }

    /** 文件缓存占用的空间（字节）与文件数 */
    public static function fileUsage(): array
    {
        $bytes = 0;
        $files = 0;
        foreach ((array) glob(self::$fileDir . '/k_*') as $f) {
            if (is_file($f)) {
                $bytes += (int) @filesize($f);
                $files++;
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    // ==================================================================
    // Redis 命令封装（统一扩展与套接字两种后端）
    // ==================================================================
    private static function redisCmd(array $args)
    {
        if (self::$redisIsExt) {
            /** @var \Redis $r */
            $r = self::$redis;
            // 扩展设置了 OPT_PREFIX，命令里不能重复带前缀
            return $r->rawCommand(...array_values($args));
        }
        /** @var CacheRedis $r */
        return self::$redis->cmd(array_values($args));
    }

    private static function redisGet(string $k)
    {
        if (self::$redisIsExt) {
            $v = self::$redis->get($k);
        } else {
            $v = self::$redis->cmd(['GET', $k]);
        }
        return is_string($v) ? self::decodeRaw($v) : null;
    }

    private static function redisSet(string $k, $value, int $ttl): bool
    {
        // 必须带类型信封：Redis 本身只能存字符串，直接把数组转字符串会变成
        // "Array" 导致数据损坏；整数/布尔也会退化成字符串。
        $raw = self::encode($value);
        if (self::$redisIsExt) {
            $r = $ttl > 0 ? self::$redis->setex($k, $ttl, $raw) : self::$redis->set($k, $raw);
            return (bool) $r;
        }
        $r = (bool) self::$redis->cmd(['SET', $k, $raw]);
        if ($r && $ttl > 0) {
            self::$redis->cmd(['EXPIRE', $k, (string) $ttl]);
        }
        return $r;
    }

    /**
     * 值序列化（带类型标记）。
     * 文件驱动与 Redis 驱动共用同一套格式，保证切换驱动后读到的类型一致。
     */
    private static function encode($value): string
    {
        return (string) json_encode(
            ['t' => self::typeOf($value), 'v' => $value],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * 反序列化。不是信封格式时按原始字符串返回 ——
     * 这样计数器（INCRBY 直接写的纯数字）和升级前遗留的旧值都能正常读到。
     */
    private static function decodeRaw(string $raw)
    {
        if ($raw === '') {
            return '';
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !array_key_exists('t', $d)) {
            return $raw;
        }
        return self::decode($d);
    }

    // ==================================================================
    // 文件驱动底层
    // ==================================================================

    private static function key(string $key): string
    {
        return self::$prefix . $key;
    }

    private static function path(string $fullKey): string
    {
        return self::$fileDir . '/k_' . sha1($fullKey);
    }

    /**
     * 读文件并判断过期。
     * @return array{e:int,t:string,v:mixed}|null
     */
    private static function fileRead(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['t'])) {
            return null;
        }
        if ((int) ($d['e'] ?? 0) > 0 && (int) $d['e'] <= time()) {
            @unlink($file);
            return null;
        }
        return $d;
    }

    private static function fileGet(string $file)
    {
        $d = self::fileRead($file);
        return $d === null ? null : self::decode($d);
    }

    private static function filePut(string $file, $value, int $ttl): bool
    {
        $payload = json_encode([
            'e' => $ttl > 0 ? time() + $ttl : 0,
            't' => self::typeOf($value),
            'v' => $value,
        ], JSON_UNESCAPED_UNICODE);

        // 临时文件 + rename，保证读到的永远是完整内容
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * 哈希文件的结构：{"e":过期, "h":{field:value}}
     * 读改写全程持排他锁，避免并发心跳互相覆盖。
     */
    private static function hashLoad(string $file): array
    {
        if (!is_file($file)) {
            return ['e' => 0, 'h' => []];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['e' => 0, 'h' => []];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['h']) || !is_array($d['h'])) {
            return ['e' => 0, 'h' => []];
        }
        if ((int) ($d['e'] ?? 0) > 0 && (int) $d['e'] <= time()) {
            return ['e' => 0, 'h' => []];
        }
        return ['e' => (int) ($d['e'] ?? 0), 'h' => $d['h']];
    }

    private static function hashSave(string $file, array $h, int $ttl): bool
    {
        $expire = $ttl > 0 ? time() + $ttl : (int) ($h['e'] ?? 0);
        $payload = json_encode(['e' => $expire, 'h' => $h['h']], JSON_UNESCAPED_UNICODE);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** 持锁执行文件读改写 */
    private static function fileTransaction(string $file, callable $fn)
    {
        $lock = $file . '.lock';
        $fh   = @fopen($lock, 'c');
        if ($fh === false) {
            // 拿不到锁文件就直接执行（单机场景几乎不会发生）
            return $fn();
        }
        @flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    // ------------------------------------------------------------------
    // 文件驱动不支持复杂类型，用类型标记做无损往返
    // ------------------------------------------------------------------
    private static function typeOf($v): string
    {
        if ($v === null) return 'null';
        if (is_bool($v)) return 'bool';
        if (is_int($v)) return 'int';
        if (is_float($v)) return 'float';
        if (is_array($v)) return 'arr';
        return 'str';
    }

    private static function decode(array $d)
    {
        $v = $d['v'] ?? null;
        switch ($d['t'] ?? 'str') {
            case 'null':  return null;
            case 'bool':  return (bool) $v;
            case 'int':   return (int) $v;
            case 'float': return (float) $v;
            case 'arr':   return is_array($v) ? $v : [];
            default:      return is_string($v) ? $v : (string) $v;
        }
    }
}

/**
 * 精简 Redis 客户端（原生 RESP 协议，走 TCP 套接字）
 * ------------------------------------------------------------------
 * 为什么自己写：很多虚拟主机 / 集成环境（比如 phpStudy 默认）没有编译
 * ext-redis，但本机或内网其实跑着 Redis。这里只实现本项目用得到的命令，
 * 让「没装扩展"不再成为用不了 Redis 的理由。
 *
 * 只读/写单连接、无连接池；连接失败一律返回 false / null，由 Cache 降级。
 */
final class CacheRedis
{
    private array $cfg;
    /** @var resource|null */
    private $sock = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function connect(): bool
    {
        $host = (string) ($this->cfg['host'] ?? '127.0.0.1');
        $port = (int) ($this->cfg['port'] ?? 6379);
        $to   = (float) ($this->cfg['timeout'] ?? 1.0);
        if ($host === '' || $port <= 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $to > 0 ? $to : 1.0,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            return false;
        }
        stream_set_timeout($sock, (int) max(1, (int) $to), (int) (($to - (int) $to) * 1_000_000));
        $this->sock = $sock;

        if (!empty($this->cfg['password'])) {
            $r = $this->cmd(['AUTH', (string) $this->cfg['password']]);
            if ($r === false) {
                $this->close();
                return false;
            }
        }
        $db = (int) ($this->cfg['database'] ?? 0);
        if ($db > 0) {
            $this->cmd(['SELECT', (string) $db]);
        }
        return $this->cmd(['PING']) !== false;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    /**
     * 执行一条命令。
     * 自动为「键名参数」补前缀：约定每条第 1 个参数是命令，第 2 个是 key。
     */
    public function cmd(array $args)
    {
        if (!$this->sock) {
            return false;
        }
        $args = array_values($args);
        if (!$args) {
            return false;
        }
        $cmd  = strtoupper((string) $args[0]);
        $skip = ['PING', 'AUTH', 'SELECT', 'SCAN', 'INFO', 'FLUSHDB', 'COMMAND'];
        $prefixable = !in_array($cmd, $skip, true);
        if ($prefixable && isset($args[1])) {
            // SCAN 的游标、DEL 的多键等场景逐键补前缀
            for ($i = 1; $i < count($args); $i++) {
                if ($cmd === 'DEL' || $cmd === 'EXISTS') {
                    $args[$i] = $this->pfx((string) $args[$i]);
                } else {
                    $args[$i] = $this->pfx((string) $args[$i]);
                    break; // 只处理键名，后面的字段/值不动
                }
            }
        }

        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string) $a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        if (@fwrite($this->sock, $out) === false) {
            return false;
        }
        return $this->read();
    }

    private function pfx(string $k): string
    {
        $p = (string) ($this->cfg['prefix'] ?? '');
        return ($p !== '' && substr($k, 0, strlen($p)) !== $p) ? $p . $k : $k;
    }

    /** 解析 RESP 回复 */
    private function read()
    {
        $line = @fgets($this->sock, 65536);
        if ($line === false || $line === '') {
            return null;
        }
        $type = $line[0];
        $body = substr($line, 1, -2); // 去掉类型符与 \r\n

        switch ($type) {
            case '+': // 简单字符串
                return $body;
            case '-': // 错误
                return false;
            case ':': // 整数
                return (int) $body;
            case '$': // 批量字符串
                $len = (int) $body;
                if ($len < 0) {
                    return null;
                }
                $data = '';
                $need = $len + 2;
                while (strlen($data) < $need) {
                    $chunk = @fread($this->sock, $need - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*': // 数组
                $n = (int) $body;
                if ($n < 0) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr[] = $this->read();
                }
                return $arr;
            default:
                return false;
        }
    }
}
