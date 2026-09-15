<?php
/**
 * 人机风控（防自动化 / 防逆向）
 * ------------------------------------------------------------------
 * 浏览器端不可能做到"绝对防逆向"——JS 最终要在用户机器上执行，攻击者
 * 始终能读源码、改运行时代码。本类的目标不是"防住"，而是把门槛从
 * 「写三行 curl 就能刷」提高到「必须逆向并模拟真人行为」，同时在
 * 攻击发生时留下可追溯的日志。
 *
 * 三层结构：
 *   1. 前端 assets/guard.js 采集信号（_g 字段随请求提交）
 *      —— webdriver / 蜜罐 / devtools / hook 自检 / 行为轨迹 / 环境特征
 *   2. 本类做加权评分，给出 allow / challenge / block 三档处置
 *   3. 调用方按处置决定是否放行：写接口 challenge = 强制图形验证码，
 *      block = 直接拒绝并记录审计日志
 *
 * 两条不可违背的原则：
 *   · 任何异常（无信号 / 老客户端 / 组件故障）都必须放行，绝不能因为
 *     风控组件自身的问题把正常用户挡在门外 —— 宁可放过，不可误杀。
 *   · 只有「不可伪装」的特征才能直接判定（webdriver、脚本 UA）；
 *     其余信号一律走累计分，且只要采集到真实操作轨迹就整体豁免。
 *
 * 误报的教训（v2.63.100 修复）：蜜罐曾用 name="email"/"company"，
 * 被浏览器自动填充填中而误判；UA 表混入了 electron/、java/ 等合法
 * 客户端特征；再加上插件数、无头判定、调试工具的累计分，正常用户
 * 点一次"购买"就可能凑够 100 分被拒。现在蜜罐降权且改随机字段名、
 * UA 表只留脚本库特征、并新增「真人行为豁免」作为兜底。
 *
 * 处理「被逆向后的止损」见 Software::resetKeys()（换钥 + 清会话）。
 */
class Guard
{
    // ------------------------------------------------------------------
    // 权重表
    // ------------------------------------------------------------------
    /**
     * 铁证：出现即判自动化，无需累计。
     * 只收「正常浏览器物理上不可能出现」的特征 —— 每一条都必须是
     * 要么靠自动化框架、要么靠伪造请求才能命中，绝不能出现误报。
     */
    private const W_FATAL = [
        'webdriver' => 100,   // navigator.webdriver === true（真实浏览器恒为 false）
        'botua'     => 100,   // UA 命中脚本客户端特征（curl / 自动化框架）
    ];

    /** 强信号：逆向注入 / 环境异常 */
    private const W_STRONG = [
        // 浏览器自动填充（Chrome 会无视 autocomplete=off 去填 name 有语义的
        // 隐藏字段）曾经把它变成误报大户，现已改成随机字段名 + 只认真实
        // 输入事件，但权重仍从铁证降级为强信号 —— 单条不再直接拒人。
        'honeypot' => 60,     // 隐藏蜜罐字段被填写
        'hook'     => 50,     // 关键 API 被改写（调试器挂载 / 代理注入）
        'headless' => 40,     // 无插件 + WebGL 不可用 + 无语言（无头浏览器）
    ];

    /** 弱信号：单独不足为凭，累计到阈值才生效 */
    private const W_WEAK = [
        'devtools' => 25,     // 开发者工具打开
        'fast'     => 25,     // 页面停留过短即提交（真人来不及）
        'nomouse'  => 20,     // 全程无鼠标 / 触摸 / 键盘 / 滚动
        'nolang'   => 15,     // 浏览器语言列表为空
    ];

    /** 判定阈值 */
    public const SCORE_BLOCK   = 100;   // ≥ 判为自动化
    public const SCORE_SUSPECT = 50;    // ≥ 判为可疑

    /**
     * 累计窗口：同一 IP 在窗口内被拦截次数超过 RISK_HARD_HITS 即临时封禁。
     * 取 10 分钟而不是 1 小时：真攻击者 10 分钟内被拦 20 次轻而易举，而
     * 万一出现误封，正常用户最多等 10 分钟就能自愈，不至于投诉到炸。
     */
    private const RISK_WINDOW_SEC = 600;
    private const RISK_HARD_HITS  = 20;

    /** 页面停留低于该毫秒数即提交 → 判定为脚本 */
    private const FAST_MS = 800;

    /**
     * 真人行为豁免下限：鼠标/键盘/触摸/滚动/点击事件累计到这个次数，
     * 就认为操作者确实是人在操作页面 —— 除铁证外的所有信号一律降级。
     *
     * 阈值取 1 而不是更高：真人点"购买"至少产生一次点击或一段鼠标轨迹，
     * 但鼠标恰好停在按钮上时可能一次 mousemove 都没有。宁可把门槛放到
     * 最低 —— 因为脚本发一个点击事件是要付代价的（见下方说明），而误杀
     * 一个正在付钱的用户是纯损失。
     *
     * 什么时候拦得住：curl / requests 这类脚本根本不会上报 _g；起真浏览器
     * 的自动化框架会命中 navigator.webdriver（铁证，豁免不生效）。能绕过
     * 豁免的只剩"已逆向出协议、手工伪造行为计数"的对手 —— 那已经是签名
     * 层的对抗，风控的义务到此为止。
     */
    private const REAL_ACT_MIN = 1;

    /** 本请求最近一次评估结果（供同一请求内的其它组件复用，避免重复评分） */
    private static ?array $last = null;

    // ------------------------------------------------------------------
    // 信号解析
    // ------------------------------------------------------------------

    /**
     * 从请求体提取前端上报的守卫信号并归一化。
     * 缺字段一律按「未知」处理（不参与加分），保证老前端 / 老客户端不受影响。
     *
     * @return array{wd:int,hp:int,dt:int,hk:int,tt:int,mm:int,kk:int,tc:int,sc:int,ck:int,pl:int,lg:?string,wg:int}
     */
    public static function signals(array $input): array
    {
        $g = $input['_g'] ?? $input['guard'] ?? null;
        if (!is_array($g)) {
            $g = [];
        }

        $bit = static function (string $k) use ($g): int {
            $v = $g[$k] ?? null;
            if (is_bool($v)) {
                return $v ? 1 : 0;
            }
            return (int) (is_numeric($v) ? $v : 0) ? 1 : 0;
        };
        $num = static function (string $k, int $max = 1000000) use ($g): int {
            $v = $g[$k] ?? null;
            if (!is_numeric($v)) {
                return -1;  // -1 = 未上报，参与不了任何判定
            }
            return max(0, min($max, (int) $v));
        };

        $lg = $g['lg'] ?? null;
        if (!is_string($lg)) {
            $lg = null;
        }

        return [
            'wd' => $bit('wd'),
            'hp' => $bit('hp'),
            'dt' => $bit('dt'),
            'hk' => $bit('hk'),
            'tt' => $num('tt'),
            'mm' => $num('mm'),
            'kk' => $num('kk'),
            'tc' => $num('tc'),
            'sc' => $num('sc'),
            'ck' => $num('ck'),
            'pl' => $num('pl', 100),
            'wg' => $bit('wg'),
            'lg' => $lg,
        ];
    }

    /**
     * UA 命中自动化框架 / 脚本客户端特征。
     *
     * 白名单式收敛：只保留「自动化工具或脚本 HTTP 库独有」的标识，
     * 不放任何可能出现在正常浏览器/内置 WebView 里的词。
     *   · electron/  → 桌面 Electron 应用（合法用户端，已移除）
     *   · java/      → 企业环境 / 旧版客户端插件（已移除）
     *   · axios/、node-fetch、httpclient → 可能出现在第三方 App 的 WebView（已移除）
     */
    public static function uaSuspect(?string $ua = null): bool
    {
        $ua = strtolower($ua !== null ? $ua : Util::ua());
        if ($ua === '') {
            // UA 缺失不算铁证：隐私浏览器、企业代理、部分内置 WebView
            // 都可能不发 UA。交给其它信号累计，不单独定罪。
            return false;
        }
        static $pat = [
            'headlesschrome', 'puppeteer', 'playwright', 'selenium', 'webdriver',
            'phantomjs', 'python-requests', 'python-urllib', 'httpx/', 'aiohttp',
            'go-http-client', 'libwww-perl', 'curl/', 'wget/', 'scrapy',
            'postmanruntime', 'insomnia', 'okhttp',
        ];
        foreach ($pat as $p) {
            if (strpos($ua, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 是否是移动端 UA（移动端没有鼠标事件，不能按「无鼠标轨迹」扣分） */
    public static function isMobile(?string $ua = null): bool
    {
        $ua = strtolower($ua !== null ? $ua : Util::ua());
        return strpos($ua, 'mobile') !== false
            || strpos($ua, 'android') !== false
            || strpos($ua, 'iphone') !== false
            || strpos($ua, 'ipad') !== false
            || strpos($ua, 'ipod') !== false;
    }

    // ------------------------------------------------------------------
    // 评分
    // ------------------------------------------------------------------

    /**
     * 对已归一化的信号打分。
     *
     * @return array{score:int, reasons:string[], hard:bool, acts:int}
     *         hard = 命中不可伪装铁证（webdriver / 脚本 UA）
     *         acts = 真人交互事件总次数（鼠标+键盘+触摸+滚动+点击）
     */
    public static function score(array $sig, ?string $ua = null): array
    {
        $score   = 0;
        $reasons = [];
        $hard    = false;

        // 铁证级（只有这两条不可伪装）
        if ($sig['wd'] === 1) {
            $score += self::W_FATAL['webdriver'];
            $reasons[] = 'webdriver';
            $hard = true;
        }
        if (self::uaSuspect($ua)) {
            $score += self::W_FATAL['botua'];
            $reasons[] = 'botua';
            $hard = true;
        }

        // 强信号
        if ($sig['hp'] === 1) {
            $score += self::W_STRONG['honeypot'];
            $reasons[] = 'honeypot';
        }
        if ($sig['hk'] === 1) {
            $score += self::W_STRONG['hook'];
            $reasons[] = 'hook';
        }
        // 无头环境：无插件 + 无 WebGL + 无语言列表，三者同时成立才计分，
        // 单独任一在正常浏览器里都可能出现（企业策略禁插件等）
        if ($sig['pl'] === 0 && $sig['wg'] === 1 && ($sig['lg'] === null || $sig['lg'] === '')) {
            $score += self::W_STRONG['headless'];
            $reasons[] = 'headless';
        }

        // 弱信号
        if ($sig['dt'] === 1) {
            $score += self::W_WEAK['devtools'];
            $reasons[] = 'devtools';
        }
        if ($sig['tt'] >= 0 && $sig['tt'] < self::FAST_MS) {
            $score += self::W_WEAK['fast'];
            $reasons[] = 'fast:' . $sig['tt'] . 'ms';
        }
        // 行为全空：移动端看触摸，桌面端看鼠标/键盘/滚动/点击
        $mobile = self::isMobile($ua);
        $noAct  = $mobile
            ? ($sig['tc'] === 0 && $sig['ck'] === 0)
            : ($sig['mm'] === 0 && $sig['kk'] === 0 && $sig['sc'] === 0 && $sig['ck'] === 0);
        // 只在「行为数据确实上报过」时才判定，避免老前端缺字段被误杀
        $hasActData = $mobile
            ? ($sig['tc'] >= 0 || $sig['ck'] >= 0)
            : ($sig['mm'] >= 0 || $sig['kk'] >= 0 || $sig['sc'] >= 0 || $sig['ck'] >= 0);
        if ($hasActData && $noAct) {
            $score += self::W_WEAK['nomouse'];
            $reasons[] = 'no_activity';
        }
        if ($sig['lg'] !== null && $sig['lg'] === '') {
            $score += self::W_WEAK['nolang'];
            $reasons[] = 'no_language';
        }

        // 真人交互总量：只累加「确实上报过」的项（-1 = 前端没这个字段）
        $acts  = 0;
        foreach (['mm', 'kk', 'tc', 'sc', 'ck'] as $k) {
            if ($sig[$k] > 0) {
                $acts += (int) $sig[$k];
            }
        }

        return ['score' => $score, 'reasons' => $reasons, 'hard' => $hard, 'acts' => $acts];
    }

    // ------------------------------------------------------------------
    // 主入口
    // ------------------------------------------------------------------

    /**
     * 评估一次请求的风险。
     *
     * @param string $scope 业务标识，用于日志与限流键（如 'web:login' / 'shop:order'）
     * @param array  $input 已解析的请求参数
     * @param array  $opt   challenge: 是否允许「可疑」降级为验证码（默认 true）
     *                      block_score / suspect_score: 自定义阈值
     * @return array{action:string, score:int, reasons:string[], block:bool}
     *               action = allow | challenge | block
     */
    public static function assess(string $scope, array $input, array $opt = []): array
    {
        $allow   = !self::enabled();
        $score   = 0;
        $raw     = 0;
        $reasons = [];
        $hard    = false;
        $acts    = 0;

        if (!$allow) {
            try {
                $sig = self::signals($input);
                $r   = self::score($sig);
                $score   = $r['score'];
                $raw     = $r['score'];
                $reasons = $r['reasons'];
                $hard    = (bool) $r['hard'];
                $acts    = (int) $r['acts'];
            } catch (Throwable $e) {
                // 评分异常一律放行
                return ['action' => 'allow', 'score' => 0, 'reasons' => ['error'], 'block' => false];
            }
        }

        $blockAt   = (int) ($opt['block_score'] ?? self::SCORE_BLOCK);
        $suspectAt = (int) ($opt['suspect_score'] ?? self::SCORE_SUSPECT);
        $canChal   = (bool) ($opt['challenge'] ?? true);

        // ------------------------------------------------------------------
        // 真人行为豁免（防误杀底线）
        // 只要采集到真实交互轨迹（鼠标 / 键盘 / 触摸 / 滚动），且没有命中
        // 不可伪装的铁证（webdriver、脚本 UA），就绝不判自动化 —— 顶多算
        // 可疑，连可疑也一并降到阈值之下，只写日志留档供人工复核。
        //
        // 理由：任何"看走眼"的弱信号（自动填充命中蜜罐、企业策略导致
        // 插件数为 0、窗口缩放造成尺寸差……）都会在真人的操作轨迹面前
        // 失去意义。风控的第一原则是别把付钱的用户挡在门外。
        // ------------------------------------------------------------------
        $waived = false;
        if (!$hard && $acts >= self::REAL_ACT_MIN && $score >= $suspectAt) {
            $reasons[] = 'waived:' . $raw;
            $score     = max(0, $suspectAt - 1);
            $waived    = true;
        }

        $action = 'allow';
        if ($score >= $blockAt) {
            $action = 'block';
        } elseif ($score >= $suspectAt && $canChal) {
            $action = 'challenge';
        }

        if ($waived) {
            self::logHit($scope, 'waive', $raw, $reasons);
        } elseif ($action !== 'allow') {
            self::logHit($scope, $action, $score, $reasons);
        }

        self::$last = [
            'action'  => $action,
            'score'   => $score,
            'reasons' => $reasons,
            'block'   => $action === 'block',
        ];
        return self::$last;
    }

    /**
     * 本请求最近一次评估结果；从未评估过返回 null。
     * 供限流、审计等组件复用，避免同一请求内重复评分。
     */
    public static function last(): ?array
    {
        return self::$last;
    }

    /** 本请求是否被判定为「可疑」（含已拦截） */
    public static function suspicious(): bool
    {
        return self::$last !== null && (int) self::$last['score'] >= self::SCORE_SUSPECT;
    }

    /**
     * 便捷判定：是否应当直接拒绝该请求。
     */
    public static function blocked(string $scope, array $input, array $opt = []): bool
    {
        return self::assess($scope, $input, $opt)['block'];
    }

    /**
     * 记一次拦截并判断是否触发临时封禁。
     * 调用时机：已经决定拒绝该请求之后（不是每个请求都调）。
     * 计的是「被拦截次数」而不是风险分数 —— 分数只决定单次处置，
     * 次数才能反映「这个 IP 是不是在持续攻击」。
     *
     * @return bool true 表示该 IP 已进入封禁状态（本次响应可用 1008 区分）
     */
    public static function punish(string $scope, int $score = 0): bool
    {
        try {
            $key  = 'guard:' . Util::ip();
            $hits = RateLimit::incr($key, self::RISK_WINDOW_SEC);
            if ($hits <= 0) {
                return false;
            }
            if ($hits >= self::RISK_HARD_HITS) {
                // 封禁标记：窗口内持续生效，入口处用 isBanned() 快速拒绝
                RateLimit::incr('guard:' . Util::ip() . ':banned', self::RISK_WINDOW_SEC);
                return true;
            }
        } catch (Throwable $e) {
            // 忽略
        }
        return false;
    }

    /** 当前 IP 是否处于风控封禁状态 */
    public static function isBanned(): bool
    {
        try {
            return (int) Database::value(
                'SELECT hits FROM ' . Database::t('rate_limit') . ' WHERE bucket_key = ? AND window_at = ?',
                [
                    substr('guard:' . Util::ip() . ':banned', 0, 128),
                    time() - (time() % self::RISK_WINDOW_SEC),
                ]
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 解除风控封禁（默认解除指定 IP 的全部风控计数）。
     *
     * 用途：后台关掉人机风控开关时顺带清理，让"改配置"立刻生效 ——
     * 否则管理员改完开关自己还被旧封禁挡在门外，体验说不过去。
     * 也供 CLI 运维脚本手工调用（Guard::clearBan('1.2.3.4')）。
     */
    public static function clearBan(?string $ip = null): bool
    {
        try {
            $ip = $ip !== null && $ip !== '' ? $ip : Util::ip();
            Database::exec(
                'DELETE FROM ' . Database::t('rate_limit') . ' WHERE bucket_key LIKE ?',
                ['guard:' . $ip . '%']
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 一次性随机数防重放（可选启用）。
     * 用缓存层的原子 add 实现：重复提交同一 nonce 返回 false。
     * 缓存不可用时返回 true（放行），绝不因缓存故障阻断业务。
     */
    public static function once(string $scope, string $nonce, int $ttl = 300): bool
    {
        $nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
        if ($nonce === '' || strlen($nonce) < 8) {
            return false;
        }
        try {
            return Cache::add('once:' . $scope . ':' . $nonce, 1, $ttl);
        } catch (Throwable $e) {
            return true;
        }
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 开关：后台「系统设置 → 安全」可关，默认开启 */
    public static function enabled(): bool
    {
        try {
            return Setting::bool('guard_enabled', true);
        } catch (Throwable $e) {
            return true;
        }
    }

    /** 命中记录：同一 scope+IP 每小时最多写 20 条，避免日志被刷爆 */
    private static function logHit(string $scope, string $action, int $score, array $reasons): void
    {
        try {
            if (RateLimit::incr('guardlog:' . $scope . ':' . Util::ip(), 3600) > 20) {
                return;
            }
            Logger::log(
                'guard',
                0,
                sprintf('[%s] %s 风险分 %d（%s）', $scope, $action, $score, implode(',', $reasons)),
                ['raw' => ['ua' => Util::ua(), 'ip' => Util::ip(), 'score' => $score]]
            );
        } catch (Throwable $e) {
            // 忽略
        }
    }
}
