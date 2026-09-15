<?php
/**
 * admin action: setting_get
 * 获取系统设置（含配置文件中不可写项）
 */

Response::ok([
    'settings' => Setting::all(),
    // 策略项「当前生效值 + 来源」：后台改的策略项（心跳/限流/单点登录等）
    // 由 Policy 统一按「数据库 → 配置文件」取值，这里把结果暴露给前端，
    // 便于管理员确认改动到底有没有落到运行时（避免再次出现"改了没效果"）。
    'effective' => Policy::debugTable(),
    // 登录方式可选项（唯一来源，前端下拉据此渲染，避免两处硬编码漂移）
    'login_methods_options' => LoginMethod::all(),
    'login_method'          => LoginMethod::current(),
    // 代理商控量模式可选项（同上，唯一来源）
    'agent_modes_options'   => Agent::allModes(),
    'agent_enabled'         => Agent::enabled(),
    // 缓存层运行时状态：bootstrap 阶段已把后台配置合并进 Cache，
    // 这里展示的是「合并后真实生效」的驱动与连接参数（不含密码明文）
    'cache_info'            => Cache::info(),
    // 人机风控总开关的「当前生效值」：数据库中没保存过时回退出厂默认（开启），
    // 前端据此回显，避免首次进设置页把开关误显示成「关闭」
    'guard'                 => [
        'enabled' => Guard::enabled(),
    ],
    // 缓存出厂值（config/cache 段，剔除密码）：表单里「后台没保存过」的字段用它回显
    'cache_config'          => [
        'driver' => Config::get('cache.driver', 'auto'),
        'redis'  => [
            'host'     => Config::get('cache.redis.host', '127.0.0.1'),
            'port'     => (int) Config::get('cache.redis.port', 6379),
            'database' => (int) Config::get('cache.redis.database', 0),
        ],
    ],
    'config'   => [
        'version' => Config::get('version'),
        'policy'  => Config::get('policy'),
        'crypto'  => [
            'enforce'     => Config::get('security.enforce_crypto'),
            'algo'        => 'AES-256-CBC',
            'sign'        => 'HMAC-SHA256',
            'time_window' => Config::get('security.time_window'),
        ],
        // 注意：只暴露「展示用」的安全项，绝不返回 entry_key / default_pass 等敏感值
        'admin'   => [
            'path'                 => Config::get('admin.path', 'admin'),
            'session_ttl'          => (int) Config::get('admin.session_ttl', 7200),
            'entry_key_enable'     => Config::get('admin.entry_key', '') !== '',
            'ip_whitelist_enable'  => (bool) Config::get('admin.ip_whitelist_enable', false),
            'ip_whitelist'         => (array) Config::get('admin.ip_whitelist', []),
            'require_password_confirm' => (bool) Config::get('admin.require_password_confirm', true),
            'login_fail_threshold' => (int) Config::get('admin.login_fail_threshold', 5),
            'login_lock_seconds'   => (int) Config::get('admin.login_lock_seconds', 900),
        ],
        // 离线宽限（运行参数只读展示；分软件开关在「软件管理 → 编辑 → 策略覆盖」）
        'grace'   => [
            'enable'      => (bool) Config::get('grace.enable', true),
            'seconds'     => (int) Config::get('grace.seconds', 0),
            'max_seconds' => (int) Config::get('grace.max_seconds', 0),
            // ES256 公钥 PEM（公开信息，可展示）：客户端 SDK 的 vpmkRespSignPubKey 必须填它；
            // 密钥由服务端自动生成落盘 config/grace_keys.php，后台只读展示，改密钥走「删除密钥文件轮换」
            'public_key'  => class_exists('Grace') ? (string) (Grace::publicKey() ?? '') : '',
        ],
    ],
]);
