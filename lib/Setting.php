<?php
/**
 * 系统设置读写
 */
class Setting
{
    private static array $cache = [];
    private static bool $loaded = false;

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        try {
            foreach (Database::all('SELECT skey, svalue FROM ' . Database::t('settings')) as $r) {
                self::$cache[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    public static function get(string $key, $default = null)
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, $value, string $remark = ''): void
    {
        self::load();
        self::$cache[$key] = $value;
        Database::exec(
            'INSERT INTO ' . Database::t('settings') . ' (skey, svalue, remark, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)',
            [$key, (string) $value, $remark, time()]
        );
    }

    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? '1' : '0');
        return $v === '1' || $v === 1 || $v === true;
    }

    /**
     * 带「配置文件回退」的整数读取
     * ------------------------------------------------------------------
     * 用于那些历史上只写在 config/config.php 的策略项（心跳间隔、离线判定、
     * 限流阈值等）。取值优先级：
     *   数据库中已显式保存过 → 用它（后台「系统设置」改的就是这个）
     *   数据库中不存在       → 回退到 config 里的默认值
     *
     * 之所以要判断「存在性」而不是取默认值：这些项在 config 里本就有出厂值，
     * 若拿默认值当兜底，就永远无法区分「后台没配过」和「后台配成了同值」；
     * 而 isSet() 只查库，语义明确。
     *
     * @param string $key    设置键（同时作为 Config 路径，如 policy.heartbeat_interval）
     * @param string $config 配置路径；留空则取 $key 本身
     */
    public static function intWithConfig(string $key, string $config = ''): int
    {
        $config = $config !== '' ? $config : $key;
        $fallback = (int) Config::get($config, 0);
        if (!self::isSet($key)) {
            return $fallback;
        }
        return (int) self::get($key, $fallback);
    }

    /** 带配置文件回退的布尔读取（'1'/1/true 为真，其余为假） */
    public static function boolWithConfig(string $key, string $config = ''): bool
    {
        $config = $config !== '' ? $config : $key;
        if (!self::isSet($key)) {
            return (bool) Config::get($config, false);
        }
        return self::bool($key, false);
    }

    /**
     * 该键在数据库中是否存在（已显式保存过）
     * 注意：不能直接用 self::get() 的返回值判断——值为 '0' 或 '' 也是"已设置"。
     */
    public static function isSet(string $key): bool
    {
        self::load();
        return array_key_exists($key, self::$cache);
    }

    /**
     * 清空进程内缓存（同进程内先写后读、或需要强制重载时调用）
     * 通常不需要：set() 会同步写缓存，get() 立即可见。
     */
    public static function reset(): void
    {
        self::$cache  = [];
        self::$loaded = false;
    }
}
