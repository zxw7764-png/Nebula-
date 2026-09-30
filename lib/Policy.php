<?php
/**
 * 运行时策略（策略项的「数据库优先、配置文件回退」统一入口）
 * ------------------------------------------------------------------
 * 背景：
 *   早期版本里，一些策略项（心跳间隔、离线判定、限流阈值、单点登录等）
 *   只写在 config/config.php，后台「系统设置」虽然能保存到 nb_settings，
 *   但业务代码从不读库 —— 于是「改了没效果」。
 *
 *   本类把「后台能改的策略项」集中到一处，统一按
 *     数据库 nb_settings（后台改的） → config/config.php（出厂默认）
 *   的顺序取值。业务代码只调 Policy::xxx()，不再直接读 Config::get()。
 *
 * 迁移策略：
 *   全部方法都保留 Config 回退，因此**未跑过任何迁移、nb_settings 为空的
 *   站点行为与升级前逐字节一致**。后台一旦保存过某项，立即以库中值为准。
 *
 * 分软件策略（2.52.0+）：
 *   nb_softwares.policy_json 可按软件覆盖部分策略项（留空键 = 跟随全局）。
 *   常规取值方法（singleLogin / heartbeatInterval 等）在客户端 API 上下文
 *   （Software::current() 已设置）自动按当前软件覆盖取值；非 API 上下文
 *   （官网 / 后台 / 计划任务）保持全局值。register_enable / maintain_mode
 *   没有隐式上下文，必须显式走 xxxFor(?array $sw) 变体。
 *
 * 关于缓存：
 *   Setting 每进程只加载一次全表（Settings::load()），取值是纯内存操作，
 *   因此本类不做额外缓存，避免"改了不生效"的二次缓存陷阱。
 */
class Policy
{
    // ==================================================================
    // 分软件覆盖读取
    // ==================================================================

    /**
     * 读软件策略覆盖值（softwares.policy_json）。
     * @return mixed 未覆盖（软件为空 / 无列 / 无该键）返回 null
     */
    public static function swPolicyVal(?array $sw, string $key)
    {
        if (!$sw) {
            return null;
        }
        $json = $sw['policy_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return null;
        }
        return $data[$key];
    }

    /** 当前请求上下文软件的策略覆盖值（非 API 上下文返回 null） */
    private static function ctxVal(string $key)
    {
        return class_exists('Software') ? self::swPolicyVal(Software::current(), $key) : null;
    }

    // ==================================================================
    // 登录 / 会话
    // ==================================================================

    /** 是否启用同账号单点登录（后登录踢掉先登录） */
    public static function singleLogin(): bool
    {
        $v = self::ctxVal('single_login');
        if ($v !== null) {
            return (bool) $v;
        }
        return Setting::boolWithConfig('single_login', 'policy.single_login');
    }

    /** 是否启用异地登录拦截（IP 变化即拒绝） */
    public static function geoBlock(): bool
    {
        $v = self::ctxVal('geo_block');
        if ($v !== null) {
            return (bool) $v;
        }
        return Setting::boolWithConfig('geo_block', 'policy.geo_block');
    }

    /** 登录态有效期（秒） */
    public static function sessionTtl(): int
    {
        return Setting::intWithConfig('session_ttl', 'policy.session_ttl') ?: 3600;
    }

    /** 注册开关（分软件：按传入软件覆盖，null = 全局） */
    public static function registerEnableFor(?array $sw): bool
    {
        $v = self::swPolicyVal($sw, 'register_enable');
        if ($v !== null) {
            return (bool) $v;
        }
        return Setting::bool('register_enable', true);
    }

    /** 维护模式开关（分软件：按传入软件覆盖，null = 全局） */
    public static function maintainModeFor(?array $sw): bool
    {
        $v = self::swPolicyVal($sw, 'maintain_mode');
        if ($v !== null) {
            return (bool) $v;
        }
        return Setting::bool('maintain_mode');
    }

    /** 维护提示文案（分软件：按传入软件覆盖，空覆盖 = 全局文案） */
    public static function maintainMsgFor(?array $sw): string
    {
        $v = self::swPolicyVal($sw, 'maintain_msg');
        if (is_string($v) && trim($v) !== '') {
            return $v;
        }
        return (string) Setting::get('maintain_msg', '服务器维护中，请稍后再试');
    }

    // ==================================================================
    // 心跳
    // ==================================================================

    /** 心跳间隔（秒），下发给客户端 */
    public static function heartbeatInterval(): int
    {
        $v = self::ctxVal('heartbeat_interval');
        if ($v !== null && (int) $v > 0) {
            return (int) $v;
        }
        $v = Setting::intWithConfig('heartbeat_interval', 'policy.heartbeat_interval');
        return $v > 0 ? $v : 60;
    }

    /** 心跳超时（秒），超过视为离线 */
    public static function heartbeatTimeout(): int
    {
        $v = self::ctxVal('heartbeat_timeout');
        if ($v !== null && (int) $v > 0) {
            return (int) $v;
        }
        $v = Setting::intWithConfig('heartbeat_timeout', 'policy.heartbeat_timeout');
        return $v > 0 ? $v : 180;
    }

    // ==================================================================
    // 限流 / 解绑
    // ==================================================================

    /** 单 IP 每分钟最大请求数 */
    public static function rateLimitPerMin(): int
    {
        $v = Setting::intWithConfig('rate_limit_per_min', 'policy.rate_limit_per_min');
        return $v > 0 ? $v : 120;
    }

    /** 单账号每日解绑次数上限（0 = 不限制） */
    public static function unbindPerDay(): int
    {
        $v = self::ctxVal('unbind_per_day');
        if ($v !== null && (int) $v >= 0) {
            return (int) $v;
        }
        $v = Setting::intWithConfig('unbind_per_day', 'policy.unbind_per_day');
        return $v < 0 ? 0 : $v;
    }

    /** 单卡默认最大设备数（分软件：注册时显式传 $sw；API 上下文不传则按当前软件） */
    public static function defaultMaxDevices(?array $sw = null): int
    {
        $v = $sw !== null
            ? self::swPolicyVal($sw, 'default_max_devices')
            : self::ctxVal('default_max_devices');
        if ($v !== null && (int) $v > 0) {
            return (int) $v;
        }
        $v = Setting::intWithConfig('default_max_devices', 'policy.default_max_devices');
        return $v > 0 ? $v : 1;
    }

    // ==================================================================
    // 设备指纹
    // ==================================================================

    /** 判定疑似伪造机器码时是否直接拒绝登录 */
    public static function blockOnDrift(): bool
    {
        return (bool) Config::get('device_fp.block_on_drift', false);
    }

    /** 命中虚拟机时是否直接拒绝登录 */
    public static function blockVm(): bool
    {
        return (bool) Config::get('device_fp.block_vm', false);
    }

    // ==================================================================
    // 离线宽限（分软件策略）
    // ==================================================================

    /** 分软件离线宽限单次时长（秒），0 或 null 表示跟随全局 */
    public static function graceSeconds(?array $sw = null): int
    {
        $v = self::swPolicyVal($sw, 'grace_seconds');
        if ($v !== null && (int) $v > 0) {
            return (int) $v;
        }
        return (int) Setting::intWithConfig('grace_seconds', 'grace.seconds');
    }

    /** 分软件离线宽限累计上限（秒），0 或 null 表示跟随全局 */
    public static function graceMaxSeconds(?array $sw = null): int
    {
        $v = self::swPolicyVal($sw, 'grace_max_seconds');
        if ($v !== null && (int) $v > 0) {
            return (int) $v;
        }
        return (int) Setting::intWithConfig('grace_max_seconds', 'grace.max_seconds');
    }

    // ==================================================================
    // 运维辅助
    // ==================================================================

    /**
     * 「数据库值 / 配置文件值」对照表，供后台展示与自检脚本使用。
     * 便于一眼看出哪些项后台改过、哪些还在吃配置文件。
     */
    public static function debugTable(): array
    {
        $map = [
            'single_login'       => 'policy.single_login',
            'geo_block'          => 'policy.geo_block',
            'heartbeat_interval' => 'policy.heartbeat_interval',
            'heartbeat_timeout'  => 'policy.heartbeat_timeout',
            'rate_limit_per_min' => 'policy.rate_limit_per_min',
            'unbind_per_day'     => 'policy.unbind_per_day',
            'session_ttl'        => 'policy.session_ttl',
            'default_max_devices'=> 'policy.default_max_devices',
        ];
        $out = [];
        foreach ($map as $key => $cfg) {
            $out[$key] = [
                'config'  => Config::get($cfg),
                'db'      => Setting::isSet($key) ? Setting::get($key) : null,
                'effective' => Setting::isSet($key) ? Setting::get($key) : Config::get($cfg),
                'source'  => Setting::isSet($key) ? 'db' : 'config',
            ];
        }
        return $out;
    }
}
