<?php
/**
 * 代理商（分销）业务
 * ------------------------------------------------------------------
 * 代理商是「对外分销」的角色：登录独立后台 /agent/ 自行生成激活码，
 * 生成的卡密通过 cards.agent_id 归属到该代理，便于对账与结算。
 *
 * 控量三选一（agents.charge_mode）：
 *   1 QUOTA     按张数额度：每种卡类型各有各的额度（-1=不限），生成即扣
 *   2 BALANCE   按余额计费：按「该卡类型的单价 × 张数」从余额（分）扣款
 *   3 UNLIMITED 不限：只记录归属与日志
 *
 * 额度与单价一律按卡类型存放（nb_agent_types）：永久卡可以单独定价/单独配额，
 * 时长卡、点数卡、次数卡同理。agents.quota_total / unit_price 为历史字段，不参与计费。
 *
 * 说明：
 *   · 扣减用「带条件的 UPDATE」保证并发安全（单条 UPDATE 由数据库保证原子，
 *     不依赖外层事务；即使外层没有事务也不会超扣）
 *   · Database 的事务自 v2.30.0 起支持嵌套（引用计数扁平化），
 *     因此 `Card::generate` 自带的事务可以安全地包在外层事务里，
 *     调用方（如 agent/handlers/card_generate.php）可以把「预扣 + 生成」
 *     放进同一个事务，靠回滚完成补偿，无需再依赖 refund()
 *   · refund() 保留用于没有外层事务的历史调用路径
 *   · 代理可生成的卡密规格中，设备上限与用户组由主管理员（或激活码）固定，
 *     代理只能选类型/时长/数量/前缀，避免越权发放高权限卡密
 *   · 代理商有两种来源：主管理员后台创建、或凭「代理商激活码」自助注册（register）
 */

class Agent
{
    const MODE_QUOTA     = 1;
    const MODE_BALANCE   = 2;
    const MODE_UNLIMITED = 3;

    const STATUS_OFF = 0;
    const STATUS_ON  = 1;

    /** 不限量哨兵（quota_total = -1） */
    const QUOTA_UNLIMITED = -1;

    /** 卡密类型（与 Card::TYPE_* 一致；额度与单价按此逐一配置） */
    const CARD_TYPES = [1, 2, 3, 4];

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM ' . Database::t('agents') . ' WHERE id = ?', [$id]) ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::one(
            'SELECT * FROM ' . Database::t('agents') . ' WHERE username = ?',
            [$username]
        ) ?: null;
    }

    /** 代理商下拉/筛选用：id => 显示名（昵称优先） */
    public static function nameMap(): array
    {
        $map = [];
        foreach (Database::all('SELECT id, username, nickname FROM ' . Database::t('agents') . ' ORDER BY id ASC') as $a) {
            $map[(int) $a['id']] = ($a['nickname'] !== null && $a['nickname'] !== '')
                ? $a['nickname'] : $a['username'];
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // 认证
    // ------------------------------------------------------------------

    /**
     * 代理商登录
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function login(string $username, string $password): array
    {
        $failLog = static function (string $msg) use ($username) {
            Logger::log('agent_login', 0, $msg, ['username' => $username]);
        };

        $agent = self::findByUsername($username);
        if (!$agent) {
            $failLog('登录失败：代理账号不存在');
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }
        if ((int) $agent['lock_until'] > time()) {
            $left = (int) $agent['lock_until'] - time();
            $failLog('登录被拒：账号锁定中');
            return ['ok' => false, 'code' => 2003, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后重试', 'data' => null];
        }
        if ((int) $agent['status'] !== self::STATUS_ON) {
            $failLog('登录被拒：账号已禁用');
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被禁用，请联系管理员', 'data' => null];
        }

        $threshold = (int) Config::get('admin.login_fail_threshold', 5);
        $lockSec   = (int) Config::get('admin.login_lock_seconds', 900);

        $nbRehash = false;
        if (!Util::verifyPassword($password, (string) $agent['password'], $nbRehash)) {
            $fail = (int) $agent['login_fail_cnt'] + 1;
            $data = ['login_fail_cnt' => $fail, 'updated_at' => time()];
            if ($fail >= $threshold) {
                $data['lock_until']     = time() + $lockSec;
                $data['login_fail_cnt'] = 0;
                $failLog("登录失败：连续 {$fail} 次，账号锁定 " . ($lockSec / 60) . ' 分钟');
            } else {
                $failLog("登录失败：密码错误（第 {$fail} 次）");
            }
            Database::update('agents', $data, 'id = :id', ['id' => $agent['id']]);
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }

        $token = Util::token(32);
        $ttl   = (int) Config::get('admin.session_ttl', 7200);
        $now   = time();

        // 历史弱哈希（无盐 md5 / 低成本 bcrypt）借这次明文已验证的机会升级
        if ($nbRehash) {
            try {
                Database::update('agents', ['password' => Util::hashPassword($password)],
                    'id = :id', ['id' => (int) $agent['id']]);
            } catch (Throwable $e) {
                // 升级失败不影响本次登录
            }
        }

        // 会话密钥（P0-02）：与主管理后台同一套方案。
        // 令牌泄露（XSS / 抓包）时代理账号同样会被完整接管，因此这里也
        // 签发一份只下发一次的 session_key，库里只存 SHA-256 摘要。
        $sessionKey = Util::token(32);

        Database::exec(
            'UPDATE ' . Database::t('agents') . '
             SET last_login_ip = ?, last_login_time = ?, login_fail_cnt = 0, lock_until = 0
             WHERE id = ?',
            [Util::ip(), $now, $agent['id']]
        );

        $sessionRow = [
            'token'       => $token,
            'agent_id'    => (int) $agent['id'],
            'ip'          => Util::ip(),
            'ua'          => mb_substr(Util::ua(), 0, 250),
            'login_at'    => $now,
            'last_active' => $now,
            'expire_at'   => $now + $ttl,
            'status'      => 1,
            'sk_hash'     => hash('sha256', $sessionKey),
            'ua_hash'     => hash('sha256', Util::ua()),
        ];
        try {
            Database::insert('agent_sessions', $sessionRow);
        } catch (Throwable $e) {
            // 降级：站点未跑迁移时去掉新列重试，保证登录不被打挂
            unset($sessionRow['sk_hash'], $sessionRow['ua_hash']);
            Database::insert('agent_sessions', $sessionRow);
            Logger::log('agent_session_bind', 0, '降级：sk_hash 列缺失，会话密钥绑定未生效，请执行 install/migrate_admin_session_bind.php');
            $sessionKey = '';
        }

        self::log((int) $agent['id'], 'login', '登录成功');
        Logger::log('agent_login', 1, '代理登录成功', [
            'username' => $agent['username'],
            'raw'      => ['agent_id' => (int) $agent['id']],
        ]);

        $agent['last_login_ip']   = Util::ip();
        $agent['last_login_time'] = $now;

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '登录成功',
            'data' => [
                'token'       => $token,
                // 会话密钥：仅此一次下发，客户端需随每次请求回传
                'session_key' => $sessionKey,
                'expire_at'   => $now + $ttl,
                'agent'       => self::publicInfo($agent),
            ],
        ];
    }

    /**
     * 校验令牌，返回代理行；同时续期
     *
     * 绑定策略与 AdminAuth::check 一致（P0-02）：
     *   · sk_hash 为 NULL 的历史会话按旧模型放行（升级不踢人）
     *   · 新会话必须提供正确的 session_key，否则拒绝并吊销
     *   · UA 变化直接吊销；IP 变化默认只记日志（admin.strict_ip_bind 可收紧）
     */
    public static function check(string $token, ?string $sessionKey = null): ?array
    {
        if ($token === '') {
            return null;
        }
        $s = Database::one('SELECT * FROM ' . Database::t('agent_sessions') . ' WHERE token = ?', [$token]);
        if (!$s || (int) $s['status'] !== 1) {
            return null;
        }
        if ((int) $s['expire_at'] > 0 && (int) $s['expire_at'] < time()) {
            return null;
        }

        // ---------------------- 会话密钥绑定校验 ----------------------
        $skHash = $s['sk_hash'] ?? null;
        if ($skHash !== null && $skHash !== '') {
            if (!is_string($sessionKey) || $sessionKey === '') {
                Logger::log('agent_session_bind', 0, '拒绝：会话已启用密钥绑定但请求未携带 X-Session-Key', [
                    'agent_id' => (int) $s['agent_id'],
                ]);
                return null;
            }
            if (!hash_equals((string) $skHash, hash('sha256', $sessionKey))) {
                Database::exec(
                    'UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE id = ?',
                    [(int) $s['id']]
                );
                Logger::log('agent_session_bind', 0, '会话密钥不匹配，已吊销该会话', [
                    'agent_id' => (int) $s['agent_id'],
                    'session'  => (int) $s['id'],
                ]);
                return null;
            }

            $uaHash = $s['ua_hash'] ?? null;
            if ($uaHash !== null && $uaHash !== '') {
                if (!hash_equals((string) $uaHash, hash('sha256', Util::ua()))) {
                    Database::exec(
                        'UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE id = ?',
                        [(int) $s['id']]
                    );
                    Logger::log('agent_session_bind', 0, 'UA 与登录时不符，已吊销该会话', [
                        'agent_id' => (int) $s['agent_id'],
                        'session'  => (int) $s['id'],
                    ]);
                    return null;
                }
            }

            // 软绑：记录一次后把会话 ip 同步为最新值，
            // 使「同一会话同一 IP 只记一次」，避免每个请求重复写日志。
            $loginIp = (string) ($s['ip'] ?? '');
            $nowIp   = Util::ip();
            if ($loginIp !== '' && $nowIp !== '' && $loginIp !== $nowIp) {
                Logger::log('agent_session_bind', 1, '会话 IP 发生变化', [
                    'agent_id' => (int) $s['agent_id'],
                    'raw'      => ['login_ip' => $loginIp, 'now_ip' => $nowIp],
                ]);
                if (Config::get('admin.strict_ip_bind', false)) {
                    Database::exec(
                        'UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE id = ?',
                        [(int) $s['id']]
                    );
                    return null;
                }
                Database::exec(
                    'UPDATE ' . Database::t('agent_sessions') . ' SET ip = ? WHERE id = ?',
                    [$nowIp, (int) $s['id']]
                );
            }
        }

        $agent = self::find((int) $s['agent_id']);
        if (!$agent || (int) $agent['status'] !== self::STATUS_ON) {
            return null;
        }

        $ttl = (int) Config::get('admin.session_ttl', 7200);
        Database::exec(
            'UPDATE ' . Database::t('agent_sessions') . ' SET last_active = ?, expire_at = ? WHERE token = ?',
            [time(), time() + $ttl, $token]
        );

        return $agent;
    }

    public static function logout(string $token): void
    {
        if ($token === '') {
            return;
        }
        $s = Database::one('SELECT * FROM ' . Database::t('agent_sessions') . ' WHERE token = ?', [$token]);
        Database::exec('UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE token = ?', [$token]);
        if ($s) {
            self::log((int) $s['agent_id'], 'logout', '退出登录');
        }
    }

    /** 修改自己的密码 */
    public static function changePassword(int $agentId, string $oldPass, string $newPass): array
    {
        if (($pwIssue = Util::passwordIssue($newPass)) !== null) {
            return ['ok' => false, 'msg' => $pwIssue];
        }
        $agent = self::find($agentId);
        if (!$agent) {
            return ['ok' => false, 'msg' => '账号不存在'];
        }
        if (!Util::verifyPassword($oldPass, (string) $agent['password'])) {
            return ['ok' => false, 'msg' => '原密码错误'];
        }
        Database::update('agents', [
            'password'   => Util::hashPassword($newPass),
            'updated_at' => time(),
        ], 'id = :id', ['id' => $agentId]);

        // 改密后让该代理的其他会话全部失效
        Database::exec(
            'UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE agent_id = ?',
            [$agentId]
        );
        self::log($agentId, 'password', '修改登录密码');

        return ['ok' => true, 'msg' => '密码修改成功，请重新登录'];
    }

    // ------------------------------------------------------------------
    // 自助注册（凭主管理员生成的激活码）
    // ------------------------------------------------------------------

    /**
     * 代理商凭激活码注册
     * ------------------------------------------------------------------
     * 卡密规格（激活用户组 / 设备上限 / 是否可作废 / 控量模式）与各类型额度单价
     * 全部来自激活码，注册后写死在代理档案里，代理商无法自改 —— 因此
     * 「卡密激活后进入的用户组」始终由主管理员在生成激活码时决定。
     *
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function register(array $in): array
    {
        $code     = AgentCode::normalizeCode(Util::str($in, 'code', ''));
        $username = preg_replace('/[^A-Za-z0-9_-]/', '', Util::str($in, 'username', ''));
        $nickname = mb_substr(Util::str($in, 'nickname', ''), 0, 64);
        $contact  = mb_substr(Util::str($in, 'contact', ''), 0, 120);
        $pass     = (string) Util::get($in, 'password', '');
        $pass2    = (string) Util::get($in, 'password2', $pass);

        $deny = static function (int $c, string $m): array {
            return ['ok' => false, 'code' => $c, 'msg' => $m, 'data' => null];
        };

        if (!Setting::bool('agent_register_enable', true)) {
            return $deny(6003, '暂未开放代理商注册，请联系管理员');
        }
        if (strlen($username) < 3 || strlen($username) > 32) {
            return $deny(1001, '代理账号需 3-32 位，仅限字母、数字、下划线、短横线');
        }
        if (($pwIssue = Util::passwordIssue($pass)) !== null) {
            return $deny(1001, $pwIssue);
        }
        if ($pass !== $pass2) {
            return $deny(1001, '两次输入的密码不一致');
        }

        $v = AgentCode::validate($code);
        if (!$v['ok']) {
            Logger::log('agent_register', 0, '注册失败：' . $v['msg'], [
                'username' => $username,
                'raw'      => ['code' => $code],
            ]);
            return $deny($v['code'], $v['msg']);
        }
        $c = $v['row'];

        if (self::findByUsername($username)) {
            return $deny(2004, '该代理账号已被占用，请更换一个');
        }

        // 先占坑（条件 UPDATE，防并发超用）；后续任一步失败都会把次数退回去
        if (!AgentCode::consume((int) $c['id'])) {
            return $deny(2006, '该激活码可用次数已用完，请联系管理员重新获取');
        }

        $now = time();
        // 余额计费模式下注册后赠予的初始余额（激活码上固定）；其它模式一律 0
        $initBal = (int) $c['charge_mode'] === self::MODE_BALANCE
            ? max(0, (int) ($c['init_balance'] ?? 0)) : 0;

        Database::begin();
        try {
            $agentId = Database::insert('agents', [
                'username'    => $username,
                'password'    => Util::hashPassword($pass),
                'nickname'    => $nickname !== '' ? $nickname : ($c['nickname'] ?: null),
                'software_id' => max(1, (int) ($c['software_id'] ?? 1)),
                'contact'     => $contact !== '' ? $contact : null,
                'charge_mode' => (int) $c['charge_mode'],
                'quota_total' => 0,
                'quota_used'  => 0,
                'balance'     => $initBal,
                'unit_price'  => 0,
                'reg_code'    => (string) $c['code'],
                'max_devices' => (int) $c['max_devices'],
                'group_id'    => (int) $c['group_id'],
                'card_prefix' => AgentCode::normalizeCardPrefix((string) ($c['card_prefix'] ?? '')) ?: null,
                'can_void'    => (int) $c['can_void'],
                'status'      => self::STATUS_ON,
                'remark'      => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            // 按激活码预设写入各卡类型额度/单价
            self::seedTypes($agentId, $c['preset']);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            AgentCode::release((int) $c['id']);
            Logger::log('agent_register', 0, '注册失败：' . $e->getMessage(), ['username' => $username]);
            return $deny(9999, '注册失败，请稍后重试');
        }

        self::log($agentId, 'register', '凭激活码 ' . $c['code'] . ' 注册成功'
            . ($initBal > 0 ? '，赠予初始余额 ' . self::fen2yuan($initBal) . ' 元' : ''));
        Logger::log('agent_register', 1, '代理商注册成功', [
            'username' => $username,
            'raw'      => ['agent_id' => $agentId, 'code' => $c['code']],
        ]);

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '注册成功，请使用新账号登录',
            'data' => ['id' => $agentId, 'username' => $username],
        ];
    }

    // ------------------------------------------------------------------
    // 按卡类型的额度与单价（nb_agent_types）
    // ------------------------------------------------------------------

    /** 该代理的类型配置：[card_type => row]；库中缺失的类型用内存默认补齐（不写库） */
    public static function typeRows(int $agentId): array
    {
        $rows = [];
        foreach (Database::all(
            'SELECT * FROM ' . Database::t('agent_types') . ' WHERE agent_id = ?',
            [$agentId]
        ) as $r) {
            $rows[(int) $r['card_type']] = $r;
        }
        foreach (self::CARD_TYPES as $t) {
            if (!isset($rows[$t])) {
                $rows[$t] = [
                    'agent_id'    => $agentId,
                    'card_type'   => $t,
                    'enabled'     => 0,
                    'quota_total' => 0,
                    'quota_used'  => 0,
                    'price'       => 0,
                    'group_id'    => 0,
                ];
            }
        }
        ksort($rows);
        return $rows;
    }

    /** 补齐缺失的类型行（后台编辑/充值前调用，保证 UPDATE 一定命中） */
    public static function ensureRows(int $agentId): void
    {
        $have = [];
        foreach (Database::all(
            'SELECT card_type FROM ' . Database::t('agent_types') . ' WHERE agent_id = ?',
            [$agentId]
        ) as $r) {
            $have[(int) $r['card_type']] = true;
        }
        $now = time();
        foreach (self::CARD_TYPES as $t) {
            if (isset($have[$t])) {
                continue;
            }
            Database::insert('agent_types', [
                'agent_id'    => $agentId,
                'card_type'   => $t,
                'enabled'     => 0,
                'quota_total' => 0,
                'quota_used'  => 0,
                'price'       => 0,
                'group_id'    => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    /** 该类型的单价（分/张）：类型自设优先，否则用全局默认（全局以「元」存储） */
    public static function typePrice(array $row): int
    {
        $own = (int) ($row['price'] ?? 0);
        if ($own > 0) {
            return $own;
        }
        return (int) round((float) Setting::get('agent_unit_price', 0) * 100);
    }

    /** 该类型剩余可生成张数（-1 = 不限） */
    public static function typeLeft(array $row): int
    {
        $total = (int) $row['quota_total'];
        if ($total === self::QUOTA_UNLIMITED) {
            return -1;
        }
        return max(0, $total - (int) $row['quota_used']);
    }

    /** 各类型剩余合计（-1 = 其中存在不限量的类型）；仅用于头部概览展示 */
    public static function quotaLeftTotal(int $agentId): int
    {
        $sum = 0;
        foreach (self::typeRows($agentId) as $row) {
            if ((int) $row['enabled'] !== 1) {
                continue;
            }
            $left = self::typeLeft($row);
            if ($left === -1) {
                return -1;
            }
            $sum += $left;
        }
        return $sum;
    }

    /** 前端展示用的类型清单（含各类型剩余额度与单价） */
    public static function typeList(array $agent): array
    {
        $isBal  = (int) $agent['charge_mode'] === self::MODE_BALANCE;
        $balFen = max(0, (int) ($agent['balance'] ?? 0));
        $list   = [];
        foreach (self::typeRows((int) $agent['id']) as $t => $row) {
            $left  = self::typeLeft($row);
            $price = self::typePrice($row);
            $gid   = (int) ($row['group_id'] ?? 0);

            // 余额计费：用「当前余额 ÷ 该类型单价」估算还能生成多少张（不足一张按 0 计）
            $canMake = 0;
            if ($isBal && $price > 0) {
                $canMake = intdiv($balFen, $price);
            }
            if (!$isBal) {
                $canMakeText = '';
            } elseif ($price <= 0) {
                $canMakeText = '未配置单价';
            } else {
                $canMakeText = $canMake . ' 张';
            }

            $list[] = [
                'type'        => $t,
                'name'        => Card::typeName($t),
                'enabled'     => (int) $row['enabled'] === 1,
                'quota_total' => (int) $row['quota_total'],
                'quota_used'  => (int) $row['quota_used'],
                'quota_left'  => $left,
                'quota_text'  => $left === -1 ? '不限' : ($left . ' 张'),
                'price'       => $isBal ? $price : 0,
                'price_text'  => $isBal ? self::fen2yuan($price) : '',
                // 余额计费下「还能生成多少张」（按该类型单价折算）
                'can_make'      => $isBal ? $canMake : 0,
                'can_make_text' => $canMakeText,
                // 该类型生成的卡密激活后进入的用户组（0 = 跟随代理默认组）
                'group_id'    => $gid,
                'group_name'  => $gid > 0 ? AgentCode::groupName($gid) : '',
                'group_text'  => $gid > 0 ? AgentCode::groupName($gid) : '跟随默认',
            ];
        }
        return $list;
    }

    /**
     * 该卡类型生成卡密时应进入的用户组
     * 优先级：类型自设 → 代理档案默认组 → 0（激活后不换组）
     */
    public static function typeGroupId(array $agent, int $type): int
    {
        $rows = self::typeRows((int) $agent['id']);
        $own  = isset($rows[$type]) ? (int) ($rows[$type]['group_id'] ?? 0) : 0;
        if ($own > 0) {
            return $own;
        }
        return (int) ($agent['group_id'] ?? 0);
    }

    /**
     * 该代理生成卡密的「固定前缀」
     * 返回空串表示不作限制（代理可在 /agent/ 自行填写前缀）。
     * 前缀由主管理员在激活码 / 代理档案上固定，代理不可自改。
     */
    public static function cardPrefix(array $agent): string
    {
        return AgentCode::normalizeCardPrefix((string) ($agent['card_prefix'] ?? ''));
    }

    /**
     * 按预设写入各类型配置（激活码注册 / 后台新建代理时调用）
     * @param array $preset AgentCode::normalizePreset() 形态（含 enabled/quota/price 元）
     */
    public static function seedTypes(int $agentId, $preset): void
    {
        $preset = AgentCode::normalizePreset($preset);
        $now    = time();
        foreach (self::CARD_TYPES as $t) {
            $p = $preset[(string) $t];
            Database::insert('agent_types', [
                'agent_id'    => $agentId,
                'card_type'   => $t,
                'enabled'     => (int) $p['enabled'] === 1 ? 1 : 0,
                'quota_total' => (int) $p['quota'],
                'quota_used'  => 0,
                'price'       => (int) round((float) $p['price'] * 100),
                'group_id'    => max(0, (int) ($p['group_id'] ?? 0)),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    /**
     * 绝对配置（后台「编辑代理商」表单提交）
     * @param array $in [card_type => ['enabled'=>0|1,'quota_total'=>n,'price_yuan'=>x]]
     */
    public static function saveTypes(int $agentId, array $in): void
    {
        self::ensureRows($agentId);
        $now = time();
        foreach (self::CARD_TYPES as $t) {
            $row = (array) ($in[(string) $t] ?? $in[$t] ?? []);
            if (!$row) {
                continue;
            }

            $quota = (int) ($row['quota_total'] ?? 0);
            $quota = $quota < -1 ? -1 : $quota;

            // 不允许把总额度调到已用张数之下，否则剩余会变成负数
            $used = (int) Database::value(
                'SELECT quota_used FROM ' . Database::t('agent_types') . ' WHERE agent_id = ? AND card_type = ?',
                [$agentId, $t]
            );
            if ($quota !== self::QUOTA_UNLIMITED && $quota < $used) {
                $quota = $used;
            }

            $enabled = (int) ($row['enabled'] ?? 1) === 1 ? 1 : 0;
            $price   = max(0, (int) round((float) ($row['price_yuan'] ?? 0) * 100));

            // 该类型生成的卡密激活后进入的用户组（0 = 跟随代理默认组）
            // 仅当表单显式提交 group_id 时才更新，避免旧版前端缓存把它意外重置
            if (array_key_exists('group_id', $row)) {
                Database::exec(
                    'UPDATE ' . Database::t('agent_types') . '
                     SET enabled = ?, quota_total = ?, price = ?, group_id = ?, updated_at = ?
                     WHERE agent_id = ? AND card_type = ?',
                    [$enabled, $quota, $price, max(0, (int) $row['group_id']), $now, $agentId, $t]
                );
            } else {
                Database::exec(
                    'UPDATE ' . Database::t('agent_types') . '
                     SET enabled = ?, quota_total = ?, price = ?, updated_at = ?
                     WHERE agent_id = ? AND card_type = ?',
                    [$enabled, $quota, $price, $now, $agentId, $t]
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 计费
    // ------------------------------------------------------------------

    /**
     * 预校验能否生成 count 张「指定卡类型」的卡密（不改数据）
     * @return array{ok:bool, code:int, msg:string, cost:array}
     */
    public static function preview(array $agent, int $count, int $type): array
    {
        $mode = (int) $agent['charge_mode'];
        $zero = ['quota' => 0, 'balance' => 0, 'type' => $type];

        if ($count <= 0) {
            return ['ok' => false, 'code' => 1001, 'msg' => '生成数量不正确', 'cost' => $zero];
        }

        $rows = self::typeRows((int) $agent['id']);
        if (!isset($rows[$type])) {
            return ['ok' => false, 'code' => 1001, 'msg' => '卡密类型不正确', 'cost' => $zero];
        }
        $row  = $rows[$type];
        $name = Card::typeName($type);

        if ((int) $row['enabled'] !== 1) {
            return [
                'ok' => false, 'code' => 1005, 'cost' => $zero,
                'msg' => '管理员未开放你生成「' . $name . '」，请联系管理员',
            ];
        }

        if ($mode === self::MODE_QUOTA) {
            $left = self::typeLeft($row);
            if ($left !== -1 && $left < $count) {
                return [
                    'ok' => false, 'code' => 1005, 'cost' => $zero,
                    'msg' => $name . '额度不足：可用 ' . $left . ' 张，本次需要 ' . $count . ' 张，请联系管理员充值',
                ];
            }
            $cost         = $zero;
            $cost['quota'] = ($left === -1) ? 0 : $count;
            return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'cost' => $cost];
        }

        if ($mode === self::MODE_BALANCE) {
            $price = self::typePrice($row);
            if ($price <= 0) {
                return [
                    'ok' => false, 'code' => 1005, 'cost' => $zero,
                    'msg' => '管理员未配置「' . $name . '」的单价，请联系管理员',
                ];
            }
            $total = $price * $count;
            if ((int) $agent['balance'] < $total) {
                return [
                    'ok' => false, 'code' => 1005, 'cost' => $zero,
                    'msg' => $name . '需扣款 ' . self::fen2yuan($total) . ' 元，余额不足（当前 '
                             . self::fen2yuan((int) $agent['balance']) . ' 元）',
                ];
            }
            $cost            = $zero;
            $cost['balance'] = $total;
            return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'cost' => $cost];
        }

        // 不限量：不扣减，但仍要求该类型已开放
        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'cost' => $zero];
    }

    /**
     * 扣减该类型的额度 / 余额（带条件 UPDATE，并发安全）
     * @return array{ok:bool, code:int, msg:string, cost:array}
     */
    public static function charge(array $agent, int $count, int $type): array
    {
        $pre = self::preview($agent, $count, $type);
        if (!$pre['ok']) {
            return $pre;
        }
        $cost = $pre['cost'];
        $id   = (int) $agent['id'];
        $zero = ['quota' => 0, 'balance' => 0, 'type' => $type];

        if ($cost['quota'] > 0) {
            $n = Database::exec(
                'UPDATE ' . Database::t('agent_types') . '
                 SET quota_used = quota_used + ?, updated_at = ?
                 WHERE agent_id = ? AND card_type = ? AND quota_total >= 0 AND quota_total - quota_used >= ?',
                [$cost['quota'], time(), $id, $type, $cost['quota']]
            );
            if ($n < 1) {
                return ['ok' => false, 'code' => 1005, 'msg' => '剩余额度不足，请刷新后重试', 'cost' => $zero];
            }
        }

        if ($cost['balance'] > 0) {
            $n = Database::exec(
                'UPDATE ' . Database::t('agents') . '
                 SET balance = balance - ?, updated_at = ?
                 WHERE id = ? AND balance >= ?',
                [$cost['balance'], time(), $id, $cost['balance']]
            );
            if ($n < 1) {
                // 余额扣款失败：把刚才扣掉的额度补回去（额度与余额不会同时扣，此处仅为兜底）
                if ($cost['quota'] > 0) {
                    Database::exec(
                        'UPDATE ' . Database::t('agent_types') . '
                         SET quota_used = quota_used - ? WHERE agent_id = ? AND card_type = ?',
                        [$cost['quota'], $id, $type]
                    );
                }
                return ['ok' => false, 'code' => 1005, 'msg' => '余额不足，请刷新后重试', 'cost' => $zero];
            }
        }

        if ($cost['quota'] > 0 || $cost['balance'] > 0) {
            self::log($id, 'charge', Card::typeName($type) . ' 生成预扣：'
                . ($cost['quota'] > 0 ? $cost['quota'] . ' 张额度' : '')
                . ($cost['balance'] > 0 ? self::fen2yuan($cost['balance']) . ' 元' : ''),
                -$count);
        }

        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'cost' => $cost];
    }

    /** 补偿回滚（生成失败时调用，$cost 由 charge() 返回，内含 type） */
    public static function refund(int $agentId, array $cost): void
    {
        $quota   = (int) ($cost['quota'] ?? 0);
        $balance = (int) ($cost['balance'] ?? 0);
        $type    = (int) ($cost['type'] ?? 0);
        if (($quota <= 0 || $type <= 0) && $balance <= 0) {
            return;
        }

        if ($quota > 0 && $type > 0) {
            Database::exec(
                'UPDATE ' . Database::t('agent_types') . '
                 SET quota_used = GREATEST(0, quota_used - ?), updated_at = ?
                 WHERE agent_id = ? AND card_type = ?',
                [$quota, time(), $agentId, $type]
            );
        }
        if ($balance > 0) {
            Database::exec(
                'UPDATE ' . Database::t('agents') . ' SET balance = balance + ?, updated_at = ? WHERE id = ?',
                [$balance, time(), $agentId]
            );
        }

        self::log($agentId, 'charge', '生成失败，已退回'
            . Card::typeName($type) . ($quota > 0 ? $quota . ' 张额度' : '')
            . ($balance > 0 ? self::fen2yuan($balance) . ' 元' : ''));
    }

    /**
     * 主管理员调整代理额度 / 余额
     * @param array $typeAdds     [card_type => 增量张数]（正数增加，负数扣减）
     * @param int   $addBalanceFen 余额增量（分）
     * @return array{ok:bool, msg:string}
     */
    public static function grant(int $agentId, array $typeAdds, int $addBalanceFen, string $operator = '', string $remark = ''): array
    {
        $agent = self::find($agentId);
        if (!$agent) {
            return ['ok' => false, 'msg' => '代理商不存在'];
        }
        self::ensureRows($agentId);

        $now   = time();
        $parts = [];

        foreach (self::CARD_TYPES as $t) {
            $add = (int) ($typeAdds[(string) $t] ?? $typeAdds[$t] ?? 0);
            if ($add === 0) {
                continue;
            }
            // 不限量（-1）保持不限量；其余在 0 处封底，避免加负数把额度变成「不限」
            Database::exec(
                'UPDATE ' . Database::t('agent_types') . '
                 SET quota_total = IF(quota_total < 0, -1, GREATEST(0, quota_total + ?)), updated_at = ?
                 WHERE agent_id = ? AND card_type = ?',
                [$add, $now, $agentId, $t]
            );
            $parts[] = Card::typeName($t) . ' ' . ($add > 0 ? '+' : '') . $add . ' 张';
        }

        if ($addBalanceFen !== 0) {
            Database::exec(
                'UPDATE ' . Database::t('agents') . '
                 SET balance = GREATEST(0, balance + ?), updated_at = ? WHERE id = ?',
                [$addBalanceFen, $now, $agentId]
            );
            $parts[] = '余额 ' . ($addBalanceFen > 0 ? '+' : '-') . self::fen2yuan(abs($addBalanceFen)) . ' 元';
        }

        if (!$parts) {
            return ['ok' => false, 'msg' => '请填写要调整的张数或金额'];
        }

        self::log($agentId, 'grant', '管理员 ' . ($operator ?: '-') . ' 调整：' . implode('、', $parts)
            . ($remark !== '' ? '（' . $remark . '）' : ''));

        return ['ok' => true, 'msg' => '调整成功'];
    }

    // ------------------------------------------------------------------
    // 统计
    // ------------------------------------------------------------------
    public static function stats(int $agentId): array
    {
        $tbl = Database::t('cards');
        $row = Database::one(
            "SELECT
                COUNT(*)                              AS total,
                SUM(status = 0)                       AS unused,
                SUM(status = 1)                       AS used,
                SUM(status = 2)                       AS void,
                SUM(status = 0 AND expire_at > 0 AND expire_at < ?) AS expired
             FROM {$tbl} WHERE agent_id = ?",
            [time(), $agentId]
        ) ?: [];

        $todayStart = strtotime('today');
        $today = (int) Database::value(
            "SELECT COUNT(*) FROM {$tbl} WHERE agent_id = ? AND created_at >= ?",
            [$agentId, $todayStart]
        );
        $batches = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('card_batches') . ' WHERE agent_id = ?',
            [$agentId]
        );

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'unused'   => (int) ($row['unused'] ?? 0),
            'used'     => (int) ($row['used'] ?? 0),
            'void'     => (int) ($row['void'] ?? 0),
            'expired'  => (int) ($row['expired'] ?? 0),
            'today'    => $today,
            'batches'  => $batches,
        ];
    }

    /** 最近操作日志（代理端「操作记录」用） */
    public static function recentLogs(int $agentId, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT * FROM ' . Database::t('agent_logs') . '
             WHERE agent_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$agentId]
        );
    }

    // ------------------------------------------------------------------
    // 输出
    // ------------------------------------------------------------------
    public static function modeName(int $mode): string
    {
        return [
            self::MODE_QUOTA     => '张数额度',
            self::MODE_BALANCE   => '余额计费',
            self::MODE_UNLIMITED => '不限量',
        ][$mode] ?? '未知';
    }

    /** 供后台下拉渲染（唯一来源，避免前后端各硬编码一份） */
    public static function allModes(): array
    {
        return [
            (string) self::MODE_QUOTA     => '按张数额度（主管理员分配张数）',
            (string) self::MODE_BALANCE   => '按余额计费（单价 × 张数扣款）',
            (string) self::MODE_UNLIMITED => '不限量（只记录归属与日志）',
        ];
    }

    public static function statusName(int $status): string
    {
        return $status === self::STATUS_ON ? '正常' : '已禁用';
    }

    /** 分 -> 元（保留两位小数） */
    public static function fen2yuan(int $fen): string
    {
        return number_format($fen / 100, 2, '.', '');
    }

    /** 对外信息（代理端 profile 与后台列表共用） */
    public static function publicInfo(array $agent): array
    {
        $gid       = (int) ($agent['group_id'] ?? 0);
        $groupName = '';
        if ($gid > 0) {
            $groupName = (string) (Database::value(
                'SELECT name FROM ' . Database::t('groups') . ' WHERE id = ?',
                [$gid]
            ) ?: ('用户组#' . $gid));
        }

        $mode       = (int) $agent['charge_mode'];
        $id         = (int) $agent['id'];
        $rows       = self::typeRows($id);
        $cardPrefix = self::cardPrefix($agent);

        // 归属软件（多软件版：代理生成的卡密与账号都落在该软件下）
        $swId = (int) ($agent['software_id'] ?? 0);
        $swName = '';
        if ($swId > 0) {
            $swName = (string) (Database::value(
                'SELECT name FROM ' . Database::t('softwares') . ' WHERE id = ?',
                [$swId]
            ) ?: '');
        }

        return [
            'id'               => $id,
            'username'         => (string) $agent['username'],
            'nickname'         => (string) ($agent['nickname'] !== null && $agent['nickname'] !== ''
                                    ? $agent['nickname'] : $agent['username']),
            'contact'          => (string) ($agent['contact'] ?? ''),
            'software_id'      => $swId,
            'software_name'    => $swName,
            'charge_mode'      => $mode,
            'charge_mode_text' => self::modeName($mode),
            // 按卡类型的额度与单价（计费唯一来源）
            'types'            => self::typeList($agent),
            'quota_left_total' => self::quotaLeftTotal($id),
            'type_count'       => count(array_filter($rows, function ($r) { return (int) $r['enabled'] === 1; })),
            'reg_code'         => (string) ($agent['reg_code'] ?? ''),
            // 历史字段：agents 表上的统一额度/单价，v1.1 起不再参与计费，仅供追溯
            'quota_total'      => (int) $agent['quota_total'],
            'quota_used'       => (int) $agent['quota_used'],
            'balance'          => (int) $agent['balance'],
            'balance_text'     => self::fen2yuan((int) $agent['balance']),
            'max_devices'      => (int) $agent['max_devices'],
            'group_id'         => $gid,
            'group_name'       => $groupName,
            // 代理生成卡密的固定前缀（空 = 代理可自填）
            'card_prefix'      => $cardPrefix,
            'card_prefix_text' => $cardPrefix !== '' ? $cardPrefix : '不限制',
            'can_void'         => (int) $agent['can_void'] === 1,
            'status'           => (int) $agent['status'],
            'status_text'      => self::statusName((int) $agent['status']),
            'remark'           => (string) ($agent['remark'] ?? ''),
            'last_login_ip'    => (string) ($agent['last_login_ip'] ?? ''),
            'last_login_text'  => !empty($agent['last_login_time']) ? Util::date((int) $agent['last_login_time']) : '',
            'created_at_text'  => !empty($agent['created_at']) ? Util::date((int) $agent['created_at']) : '',
        ];
    }

    // ------------------------------------------------------------------
    // 日志
    // ------------------------------------------------------------------
    public static function log(int $agentId, string $action, string $detail = '', int $amount = 0): void
    {
        try {
            Database::insert('agent_logs', [
                'agent_id'   => $agentId,
                'action'     => $action,
                'detail'     => mb_substr($detail, 0, 250),
                'amount'     => $amount,
                'ip'         => Util::ip(),
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            Logger::log('agent_log_fail', 0, $e->getMessage(), ['raw' => ['agent_id' => $agentId]]);
        }
    }

    public static function actionName(string $action): string
    {
        return [
            'login'    => '登录',
            'logout'   => '退出',
            'register' => '注册',
            'generate' => '生成卡密',
            'void'     => '作废卡密',
            'export'   => '导出卡密',
            'charge'   => '额度变动',
            'recharge' => '卡密充值',
            'grant'    => '管理员调整',
            'password' => '修改密码',
        ][$action] ?? $action;
    }

    /** 代理商后台是否开放（后台「系统设置」控制） */
    public static function enabled(): bool
    {
        return Setting::bool('agent_enable', true);
    }
}
