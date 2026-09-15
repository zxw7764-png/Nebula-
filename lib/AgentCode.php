<?php
/**
 * 代理商激活码（自助注册用）
 * ------------------------------------------------------------------
 * 主管理员在后台生成「专属激活码」，代理商凭码在 /agent/ 自助注册。
 * 激活码本身携带两样东西，注册时一次性复制到代理账号，代理商不可自改：
 *   1. 发货规格：卡密激活后进入的用户组 / 设备上限 / 是否可作废 / 控量模式
 *      —— 因此「卡密激活后进入哪个用户组」始终由主管理员决定
 *   2. 按卡类型的预设：每种卡类型是否开放 + 该类型的额度（配额模式）
 *      或单价（余额模式）
 *   3. 代理生成卡密的固定前缀（card_prefix）——留空则代理仍可自填
 *   4. 余额计费模式下，注册后赠予代理的初始余额（init_balance，单位：分）
 *      —— 代理注册后即可直接发货，不必先找管理员充值
 *
 * preset 存储形态（JSON，金额用「元」便于人工核对）：
 *   {"1":{"enabled":1,"quota":100,"price":"1.00","group_id":2},
 *    "2":{"enabled":1,"quota":50, "price":"0.50","group_id":2},
 *    "3":{"enabled":0,"quota":0,  "price":"0",  "group_id":0},
 *    "4":{"enabled":1,"quota":5,  "price":"5.00","group_id":3}}
 *   quota    = -1 表示该类型不限量
 *   group_id = 该类型生成的卡密激活后进入的用户组（0=跟随代理默认组 / 不换组）
 *              —— 因此可按卡类型分别投放不同用户组
 */

class AgentCode
{
    const STATUS_OFF = 0;
    const STATUS_ON  = 1;

    /** 默认前缀（生成的码形如 AGT-XXXX-XXXXX） */
    const DEFAULT_PREFIX = 'AGT';

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM ' . Database::t('agent_codes') . ' WHERE id = ?', [$id]) ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $raw = self::normalizeCode($code);
        if ($raw === '') {
            return null;
        }
        $tbl = Database::t('agent_codes');

        // 先按原样匹配；用户少打/多打连字符时，再按「去掉连字符」兜底匹配
        $row = Database::one('SELECT * FROM ' . $tbl . ' WHERE code = ?', [$raw]);
        if ($row) {
            return $row;
        }
        return Database::one(
            "SELECT * FROM " . $tbl . " WHERE REPLACE(code, '-', '') = ? LIMIT 1",
            [str_replace('-', '', $raw)]
        ) ?: null;
    }

    /**
     * 归一化输入：去空白、转大写、只保留字母数字与连字符
     * （存库形态含连字符如 AGT-XXXX-XXXX，用户漏打连字符由 findByCode 兜底）
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($code)));
    }

    /** 生成一个激活码（与卡密同风格：前缀-4位-5位） */
    public static function makeCode(string $prefix = ''): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix);
        $prefix = $prefix !== '' ? strtoupper(substr($prefix, 0, 8)) : self::DEFAULT_PREFIX;
        return Util::cardCode($prefix, 2, 4);
    }

    /**
     * 归一化「代理生成卡密的固定前缀」：只留字母数字、转大写、最多 8 位。
     * 空串 = 不作限制（代理可自填前缀）。
     */
    public static function normalizeCardPrefix(string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix);
        return strtoupper(substr((string) $prefix, 0, 8));
    }

    /**
     * 「注册后初始余额」：前端以「元」提交，服务端转「分」存储。
     * 仅余额计费模式（MODE_BALANCE）生效，其它模式一律 0 —— 避免误发余额。
     */
    public static function normalizeInitBalance($yuan, int $mode): int
    {
        if ($mode !== Agent::MODE_BALANCE) {
            return 0;
        }
        $fen = (int) round((float) $yuan * 100);
        // 上限约 1000 万元，防手滑多敲零；下限 0
        return max(0, min(1000000000, $fen));
    }

    // ------------------------------------------------------------------
    // 预设（按卡类型）
    // ------------------------------------------------------------------
    /** 默认预设：4 种卡类型全开放，额度 0（管理员按需填），单价 0（用全局默认），用户组跟随代理默认 */
    public static function presetDefaults(): array
    {
        $out = [];
        foreach (Agent::CARD_TYPES as $t) {
            $out[(string) $t] = ['enabled' => 1, 'quota' => 0, 'price' => '0', 'group_id' => 0];
        }
        return $out;
    }

    /**
     * 把前端/DB 里的 preset 归一化成完整结构（缺项用默认补齐，非法值收敛）
     * @param mixed $raw JSON 字符串或数组
     */
    public static function normalizePreset($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $out = [];
        foreach (Agent::CARD_TYPES as $t) {
            $row     = (array) ($raw[(string) $t] ?? $raw[$t] ?? []);
            $quota   = (int) ($row['quota'] ?? 0);
            $price   = round((float) ($row['price'] ?? 0), 2);
            $enabled = (int) ($row['enabled'] ?? 1) === 1 ? 1 : 0;
            // 该类型激活后进入的用户组：0 = 跟随代理默认组 / 不换组
            $groupId = max(0, (int) ($row['group_id'] ?? 0));

            if ($quota < -1) {
                $quota = -1;
            }
            if ($price < 0) {
                $price = 0;
            }

            $out[(string) $t] = [
                'enabled'  => $enabled,
                'quota'    => $quota,
                'price'    => number_format($price, 2, '.', ''),
                'group_id' => $groupId,
            ];
        }
        return $out;
    }

    /** 预设 -> 落库 JSON */
    public static function encodePreset(array $preset): string
    {
        return json_encode(self::normalizePreset($preset), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // 生成 / 编辑 / 启停 / 删除
    // ------------------------------------------------------------------

    /**
     * 生成激活码
     * @param array $in preset / group_id / max_devices / can_void / charge_mode / max_uses / expire_days / nickname / remark / prefix / card_prefix / count
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function generate(array $in, int $adminId): array
    {
        $count = max(1, min(100, (int) Util::get($in, 'count', 1)));

        // 归属软件：生成的激活码注册的代理，只能为该软件生成/售卡
        $softwareId = Util::int($in, 'software_id', 0);
        if ($softwareId <= 0 || !Software::find($softwareId)) {
            $softwareId = (int) Database::value('SELECT id FROM ' . Database::t('softwares') . ' WHERE status = 1 ORDER BY id ASC LIMIT 1');
        }
        if ($softwareId <= 0) {
            return ['ok' => false, 'code' => 1001, 'msg' => '请先在「软件管理」创建软件', 'data' => null];
        }

        $groupId = Util::int($in, 'group_id', 0);
        if ($groupId > 0 && !Database::value('SELECT id FROM ' . Database::t('groups') . ' WHERE id = ?', [$groupId])) {
            return ['ok' => false, 'code' => 1001, 'msg' => '所选用户组不存在', 'data' => null];
        }

        $mode = Util::int($in, 'charge_mode', Agent::MODE_QUOTA);
        if (!array_key_exists((string) $mode, Agent::allModes())) {
            return ['ok' => false, 'code' => 1001, 'msg' => '控量模式不正确', 'data' => null];
        }

        $maxDevices = max(1, min(99, Util::int($in, 'max_devices', 1)));
        $canVoid    = Util::int($in, 'can_void', 1) === 1 ? 1 : 0;
        $maxUses    = max(1, min(1000, Util::int($in, 'max_uses', 1)));
        $expireDays = max(0, min(3650, Util::int($in, 'expire_days', 0)));
        $nickname   = mb_substr(Util::str($in, 'nickname', ''), 0, 64);
        $remark     = mb_substr(Util::str($in, 'remark', ''), 0, 250);
        $prefix     = Util::str($in, 'prefix', '');
        // 代理生成卡密的固定前缀（空 = 代理仍可自填）
        $cardPrefix = self::normalizeCardPrefix(Util::str($in, 'card_prefix', ''));
        // 余额计费模式下注册后赠予的初始余额（元 -> 分；其它模式为 0）
        $initBalance = self::normalizeInitBalance(Util::get($in, 'init_balance_yuan', 0), $mode);

        $preset  = self::normalizePreset(Util::get($in, 'preset', []));
        $now     = time();
        $expire  = $expireDays > 0 ? $now + $expireDays * 86400 : 0;
        $presetJ = self::encodePreset($preset);

        $made = [];
        Database::begin();
        try {
            for ($i = 0; $i < $count; $i++) {
                // 唯一性：最多试 10 次
                $code = '';
                for ($try = 0; $try < 10; $try++) {
                    $c = self::makeCode($prefix);
                    if (!Database::value('SELECT id FROM ' . Database::t('agent_codes') . ' WHERE code = ?', [$c])) {
                        $code = $c;
                        break;
                    }
                }
                if ($code === '') {
                    throw new RuntimeException('激活码生成失败，请重试');
                }
                Database::insert('agent_codes', [
                    'code'         => $code,
                    'nickname'     => $nickname ?: null,
                    'software_id'  => $softwareId,
                    'group_id'     => $groupId,
                    'max_devices'  => $maxDevices,
                    'card_prefix'  => $cardPrefix !== '' ? $cardPrefix : null,
                    'can_void'     => $canVoid,
                    'charge_mode'  => $mode,
                    'init_balance' => $initBalance,
                    'preset'       => $presetJ,
                    'max_uses'     => $maxUses,
                    'used_count'   => 0,
                    'expire_at'    => $expire,
                    'status'       => self::STATUS_ON,
                    'create_admin' => $adminId,
                    'remark'       => $remark ?: null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $made[] = $code;
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            return ['ok' => false, 'code' => 9999, 'msg' => '生成失败：' . $e->getMessage(), 'data' => null];
        }

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '已生成 ' . count($made) . ' 个激活码',
            'data' => ['count' => count($made), 'codes' => $made],
        ];
    }

    /** 编辑（沿用生成时的同一套校验） */
    public static function save(int $id, array $in): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'code' => 1001, 'msg' => '激活码不存在', 'data' => null];
        }

        $groupId = Util::int($in, 'group_id', 0);
        if ($groupId > 0 && !Database::value('SELECT id FROM ' . Database::t('groups') . ' WHERE id = ?', [$groupId])) {
            return ['ok' => false, 'code' => 1001, 'msg' => '所选用户组不存在', 'data' => null];
        }
        $mode = Util::int($in, 'charge_mode', Agent::MODE_QUOTA);
        if (!array_key_exists((string) $mode, Agent::allModes())) {
            return ['ok' => false, 'code' => 1001, 'msg' => '控量模式不正确', 'data' => null];
        }

        $maxUses = max(1, min(1000, Util::int($in, 'max_uses', 1)));
        if ($maxUses < (int) $row['used_count']) {
            return ['ok' => false, 'code' => 1001, 'msg' => '可用次数不能小于已注册次数（' . (int) $row['used_count'] . '）', 'data' => null];
        }

        $expireDays = max(0, min(3650, Util::int($in, 'expire_days', 0)));

        $fields = [
            'nickname'    => mb_substr(Util::str($in, 'nickname', ''), 0, 64) ?: null,
            'group_id'    => $groupId,
            'max_devices' => max(1, min(99, Util::int($in, 'max_devices', 1))),
            'can_void'    => Util::int($in, 'can_void', 1) === 1 ? 1 : 0,
            'charge_mode' => $mode,
            'preset'      => self::encodePreset(Util::get($in, 'preset', [])),
            'max_uses'    => $maxUses,
            'expire_at'   => $expireDays > 0 ? time() + $expireDays * 86400 : 0,
            'remark'      => mb_substr(Util::str($in, 'remark', ''), 0, 250) ?: null,
            'updated_at'  => time(),
        ];

        // 软件归属：仅在显式提交时更新（旧前端不传则保持原值）
        if (array_key_exists('software_id', $in)) {
            $swId = Util::int($in, 'software_id', 0);
            if ($swId > 0 && Software::find($swId)) {
                $fields['software_id'] = $swId;
            }
        }

        // 防御：旧版前端（浏览器缓存）不会提交 card_prefix，此时保持原值不动，避免误清空
        if (array_key_exists('card_prefix', $in)) {
            $cp = self::normalizeCardPrefix((string) $in['card_prefix']);
            $fields['card_prefix'] = $cp !== '' ? $cp : null;
        }

        // 注册后初始余额（元 -> 分）。同样只在显式提交时更新；切到非余额模式会自动归零
        if (array_key_exists('init_balance_yuan', $in)) {
            $fields['init_balance'] = self::normalizeInitBalance($in['init_balance_yuan'], $mode);
        }

        Database::update('agent_codes', $fields, 'id = :id', ['id' => $id]);

        return ['ok' => true, 'code' => 0, 'msg' => '已保存', 'data' => ['id' => $id]];
    }

    public static function toggle(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'code' => 1001, 'msg' => '激活码不存在', 'data' => null];
        }
        $new = (int) $row['status'] === self::STATUS_ON ? self::STATUS_OFF : self::STATUS_ON;
        Database::update('agent_codes', ['status' => $new, 'updated_at' => time()], 'id = :id', ['id' => $id]);
        return ['ok' => true, 'code' => 0, 'msg' => $new === self::STATUS_ON ? '已启用' : '已停用', 'data' => ['id' => $id, 'status' => $new]];
    }

    public static function remove(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'code' => 1001, 'msg' => '激活码不存在', 'data' => null];
        }
        if ((int) $row['used_count'] > 0) {
            return ['ok' => false, 'code' => 1001, 'msg' => '该激活码已被注册使用，无法删除；请改为「停用」以保留追溯记录', 'data' => null];
        }
        Database::exec('DELETE FROM ' . Database::t('agent_codes') . ' WHERE id = ?', [$id]);
        return ['ok' => true, 'code' => 0, 'msg' => '已删除', 'data' => ['id' => $id]];
    }

    // ------------------------------------------------------------------
    // 校验 / 消费
    // ------------------------------------------------------------------

    /**
     * 注册前校验
     * @return array{ok:bool, code:int, msg:string, row:?array}
     */
    public static function validate(string $code): array
    {
        $fail = static function (int $code2, string $msg): array {
            return ['ok' => false, 'code' => $code2, 'msg' => $msg, 'row' => null];
        };

        if ($code === '') {
            return $fail(2005, '请填写代理商激活码');
        }
        $row = self::findByCode($code);
        if (!$row) {
            return $fail(2005, '激活码无效，请核对后重试');
        }
        if ((int) $row['status'] !== self::STATUS_ON) {
            return $fail(2005, '该激活码已被停用，请联系管理员');
        }
        if ((int) $row['expire_at'] > 0 && (int) $row['expire_at'] < time()) {
            return $fail(2007, '该激活码已过期，请联系管理员重新获取');
        }
        if ((int) $row['used_count'] >= (int) $row['max_uses']) {
            return $fail(2006, '该激活码可用次数已用完，请联系管理员重新获取');
        }

        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'row' => $row];
    }

    /** 消费一次使用次数（条件 UPDATE，防并发超用） */
    public static function consume(int $id): bool
    {
        $n = Database::exec(
            'UPDATE ' . Database::t('agent_codes') . '
             SET used_count = used_count + 1, updated_at = ?
             WHERE id = ? AND status = 1 AND used_count < max_uses',
            [time(), $id]
        );
        return $n > 0;
    }

    /** 注册失败时把使用次数退回去 */
    public static function release(int $id): void
    {
        Database::exec(
            'UPDATE ' . Database::t('agent_codes') . '
             SET used_count = GREATEST(0, used_count - 1), updated_at = ?
             WHERE id = ?',
            [time(), $id]
        );
    }

    // ------------------------------------------------------------------
    // 输出
    // ------------------------------------------------------------------
    /** 该激活码注册出来的代理数量 / 账号列表 */
    public static function registeredAgents(int $codeId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT id, username, nickname, status, created_at
             FROM ' . Database::t('agents') . ' WHERE reg_code = (
                 SELECT code FROM ' . Database::t('agent_codes') . ' WHERE id = ?
             ) ORDER BY id DESC LIMIT ' . $limit,
            [$codeId]
        );
    }

    public static function statusName(int $status): string
    {
        return $status === self::STATUS_ON ? '可用' : '已停用';
    }

    /** 用户组名（进程内缓存，避免列表逐条查询）；id<=0 返回空串 */
    private static $groupNameCache = null;

    public static function groupName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        if (self::$groupNameCache === null) {
            self::$groupNameCache = [];
            foreach (Database::all('SELECT id, name FROM ' . Database::t('groups')) as $g) {
                self::$groupNameCache[(int) $g['id']] = (string) $g['name'];
            }
        }
        return self::$groupNameCache[$id] ?? ('用户组#' . $id);
    }

    /** 码是否真正可用（供列表标注） */
    public static function isUsable(array $row): bool
    {
        if ((int) $row['status'] !== self::STATUS_ON) {
            return false;
        }
        if ((int) $row['expire_at'] > 0 && (int) $row['expire_at'] < time()) {
            return false;
        }
        return (int) $row['used_count'] < (int) $row['max_uses'];
    }

    /**
     * 对外信息（后台列表 / 详情共用）
     * preset 同时以「归一化结构」和「可读文本」两种形式给出，前端不必再解析
     */
    public static function publicInfo(array $c): array
    {
        $gid   = (int) $c['group_id'];
        $gName = self::groupName($gid);

        $preset = self::normalizePreset($c['preset'] ?? null);
        $mode   = (int) $c['charge_mode'];
        $isBal  = $mode === Agent::MODE_BALANCE;

        $types = [];
        foreach (Agent::CARD_TYPES as $t) {
            $p    = $preset[(string) $t];
            $q    = (int) $p['quota'];
            $tgid = (int) ($p['group_id'] ?? 0);
            $types[] = [
                'type'       => $t,
                'name'       => Card::typeName($t),
                'enabled'    => (int) $p['enabled'] === 1,
                'quota'      => $q,
                'quota_text' => $q === -1 ? '不限' : ($q . ' 张'),
                'price_text' => $isBal ? ('¥ ' . $p['price']) : '',
                // 该类型生成的卡密激活后进入的用户组（0 = 跟随代理默认组）
                'group_id'   => $tgid,
                'group_name' => $tgid > 0 ? self::groupName($tgid) : '',
                'group_text' => $tgid > 0 ? self::groupName($tgid) : '跟随默认',
            ];
        }

        $expireAt = (int) $c['expire_at'];
        $maxUses  = (int) $c['max_uses'];
        $used     = (int) $c['used_count'];

        return [
            'id'               => (int) $c['id'],
            'code'             => (string) $c['code'],
            'nickname'         => (string) ($c['nickname'] ?? ''),
            'group_id'         => $gid,
            'group_name'       => $gName,
            'max_devices'      => (int) $c['max_devices'],
            // 代理生成卡密的固定前缀（空 = 代理可自填）
            'card_prefix'      => (string) ($c['card_prefix'] ?? ''),
            'card_prefix_text' => ($c['card_prefix'] ?? '') !== '' ? (string) $c['card_prefix'] : '不限制',
            'can_void'         => (int) $c['can_void'] === 1,
            'charge_mode'      => $mode,
            'charge_mode_text' => Agent::modeName($mode),
            // 余额计费模式下，注册后赠予代理的初始余额
            'init_balance'      => (int) ($c['init_balance'] ?? 0),
            'init_balance_text' => Agent::fen2yuan((int) ($c['init_balance'] ?? 0)),
            'preset'           => $preset,
            'types'            => $types,
            'max_uses'         => $maxUses,
            'used_count'       => $used,
            'left_uses'        => max(0, $maxUses - $used),
            'expire_at'        => $expireAt,
            'expire_text'      => $expireAt > 0 ? Util::date($expireAt) : '永久有效',
            'status'           => (int) $c['status'],
            'status_text'      => self::statusName((int) $c['status']),
            'usable'           => self::isUsable($c),
            'remark'           => (string) ($c['remark'] ?? ''),
            'created_at_text'  => !empty($c['created_at']) ? Util::date((int) $c['created_at']) : '',
        ];
    }
}
