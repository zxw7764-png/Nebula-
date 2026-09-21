<?php
/**
 * 代理商充值卡密（续费 / 加量）
 * ------------------------------------------------------------------
 * 与「注册用激活码」（AgentCode）职责分开：
 *   · AgentCode       —— 开户凭证，代理凭码在 /agent/ 自助注册
 *   · AgentRecharge   —— 充值凭证，代理在 /agent/ 输入卡密兑换余额 / 张数额度
 *
 * 卡密两种（kind）：
 *   1 = 余额充值：兑换后给代理余额加 amount（分），仅在「余额计费」模式下有意义
 *   2 = 张数额度：兑换后给**一种或多种卡类型**分别加张数
 *       多类型存于 quota_map（JSON，如 {"1":10,"2":5,"3":-1}），quota = -1 表示该类型设为「不限量」；
 *       quota_map 为空时回退旧字段 card_type + quota（单类型，兼容升级前发出的卡密）。
 *
 * 生成：主管理员在「代理商激活码 → 充值卡密」批量生成；
 * 兑换：代理商登录 /agent/ → 充值卡密 → 输入卡密（见 redeem()）。
 *
 * 金额一律以「元」从前端提交，服务端转「分」存储（与全站口径一致）。
 */

class AgentRecharge
{
    /* ------------------------- 常量 ------------------------- */
    const KIND_BALANCE = 1;
    const KIND_QUOTA   = 2;

    const STATUS_OFF = 0;
    const STATUS_ON  = 1;

    /** 默认前缀（生成的码形如 RCG-XXXX-XXXXX） */
    const DEFAULT_PREFIX = 'RCG';

    /** 单次最多生成张数 */
    const MAX_BATCH = 200;

    /* ------------------------- 枚举 ------------------------- */
    public static function kinds(): array
    {
        return [
            self::KIND_BALANCE => '余额充值',
            self::KIND_QUOTA   => '张数额度',
        ];
    }

    public static function kindName(int $kind): string
    {
        return self::kinds()[$kind] ?? '未知';
    }

    public static function statusName(int $status): string
    {
        return $status === self::STATUS_ON ? '可用' : '已停用';
    }

    /* ------------------------- 查询 ------------------------- */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM ' . Database::t('agent_recharge_codes') . ' WHERE id = ?', [$id]) ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $raw = self::normalizeCode($code);
        if ($raw === '') {
            return null;
        }
        $tbl = Database::t('agent_recharge_codes');

        // 先按原样匹配；用户少打 / 多打连字符时，再按「去掉连字符」兜底匹配
        $row = Database::one('SELECT * FROM ' . $tbl . ' WHERE code = ?', [$raw]);
        if ($row) {
            return $row;
        }
        return Database::one(
            'SELECT * FROM ' . $tbl . ' WHERE REPLACE(code, \'-\', \'\') = ? LIMIT 1',
            [str_replace('-', '', $raw)]
        ) ?: null;
    }

    /** 归一化输入：去空白、转大写、只保留字母数字与连字符 */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($code)));
    }

    /** 生成一个卡密（与卡密同风格：前缀-4位-5位） */
    public static function makeCode(string $prefix = ''): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix);
        $prefix = $prefix !== '' ? strtoupper(substr($prefix, 0, 8)) : self::DEFAULT_PREFIX;
        return Util::cardCode($prefix, 2, 4);
    }

    /** 「元」-> 「分」，上限约 1000 万元防手滑多敲零；下限 0 */
    public static function normalizeAmount($yuan): int
    {
        $fen = (int) round((float) $yuan * 100);
        return max(0, min(1000000000, $fen));
    }

    /* ------------------------- 多卡类型张数 ------------------------- */
    /**
     * 归一化「多卡类型张数表」
     * 入参可为 JSON 字符串或数组：{"1":10,"2":-1}
     * 规则：
     *   · key 必须是合法卡类型，否则丢弃
     *   · value 取整；0 视为「不充值」丢弃；-1 表示该类型不限量；小于 -1 收敛为 -1
     * @return array<int,int> 卡类型 => 张数（按卡类型升序）
     */
    public static function normalizeQuotaMap($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $k => $v) {
            $t = (int) $k;
            if (!in_array($t, Agent::CARD_TYPES, true)) {
                continue;
            }
            $q = (int) $v;
            if ($q === 0) {
                continue;              // 0 = 该类型不充值
            }
            if ($q < -1) {
                $q = -1;               // 只支持 -1 = 不限量
            }
            $out[$t] = $q;
        }
        ksort($out);
        return $out;
    }

    /** 把归一化后的张数表编码成入库 JSON；空表返回 null */
    public static function encodeQuotaMap(array $map): ?string
    {
        return $map ? (string) json_encode($map, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * 取出该卡密要充值的「卡类型 => 张数」清单
     * 优先读 quota_map（多类型），为空时回退旧字段 card_type + quota（单类型）。
     * @return array<int,int>
     */
    public static function quotaItems(array $row): array
    {
        $map = self::normalizeQuotaMap($row['quota_map'] ?? null);
        if ($map) {
            return $map;
        }
        $ct = (int) ($row['card_type'] ?? 0);
        $q  = (int) ($row['quota'] ?? 0);
        if (in_array($ct, Agent::CARD_TYPES, true) && $q !== 0 && $q >= -1) {
            return [$ct => $q];
        }
        return [];
    }

    /** 单条张数规格文案：如「永久卡 +10 张」「时长卡 不限量」 */
    public static function quotaItemText(int $type, int $quota): string
    {
        return Card::typeName($type) . ($quota === -1 ? ' 不限量' : ' +' . $quota . ' 张');
    }

    /* ------------------------- 生成 / 启停 / 删除 ------------------------- */
    /**
     * 批量生成充值卡密
     * @param array $in count / prefix / kind / amount_yuan / quota_map / card_type / quota / max_uses / expire_days / remark
     *                  quota_map 为多卡类型张数（{"1":10,"2":-1}）；card_type + quota 为单类型旧写法（兼容保留）
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function generate(array $in, int $adminId): array
    {
        $count = max(1, min(self::MAX_BATCH, (int) Util::get($in, 'count', 1)));

        $kind = (int) Util::get($in, 'kind', self::KIND_BALANCE);
        if (!array_key_exists($kind, self::kinds())) {
            return ['ok' => false, 'code' => 1001, 'msg' => '卡密类型不正确', 'data' => null];
        }

        $now        = time();
        $prefix     = Util::str($in, 'prefix', '');
        $remark     = mb_substr(Util::str($in, 'remark', ''), 0, 250);
        $maxUses    = max(1, min(1000, Util::int($in, 'max_uses', 1)));
        $expireDays = max(0, min(3650, Util::int($in, 'expire_days', 0)));
        $expire     = $expireDays > 0 ? $now + $expireDays * 86400 : 0;

        $amount   = 0;
        $cardType = 0;
        $quota    = 0;
        $quotaMapJ = null;

        if ($kind === self::KIND_BALANCE) {
            $amount = self::normalizeAmount(Util::get($in, 'amount_yuan', 0));
            if ($amount <= 0) {
                return ['ok' => false, 'code' => 1001, 'msg' => '请填写大于 0 的充值金额（元）', 'data' => null];
            }
        } else {
            // 多卡类型：优先取 quota_map；未提供时回退旧的 card_type + quota（单类型）
            $map = self::normalizeQuotaMap(Util::get($in, 'quota_map', []));
            if (!$map) {
                $ct = (int) Util::get($in, 'card_type', 0);
                $q  = (int) Util::get($in, 'quota', 0);
                if (in_array($ct, Agent::CARD_TYPES, true) && $q !== 0 && $q >= -1) {
                    $map = [$ct => $q];
                }
            }
            if (!$map) {
                return ['ok' => false, 'code' => 1001, 'msg' => '请至少为一种卡类型填写增加张数（-1 表示不限量）', 'data' => null];
            }
            $quotaMapJ = self::encodeQuotaMap($map);
            // 只勾选了一种类型时，顺带写入旧字段，方便旧版列表 / 导出查看
            if (count($map) === 1) {
                $cardType = (int) array_key_first($map);
                $quota    = (int) $map[$cardType];
            }
        }

        $made = [];
        Database::begin();
        try {
            for ($i = 0; $i < $count; $i++) {
                // 唯一性：最多试 10 次
                $code = '';
                for ($try = 0; $try < 10; $try++) {
                    $c = self::makeCode($prefix);
                    if (!Database::value('SELECT id FROM ' . Database::t('agent_recharge_codes') . ' WHERE code = ?', [$c])) {
                        $code = $c;
                        break;
                    }
                }
                if ($code === '') {
                    throw new RuntimeException('卡密生成失败，请重试');
                }

                Database::insert('agent_recharge_codes', [
                    'code'          => $code,
                    'kind'          => $kind,
                    'amount'        => $amount,
                    'card_type'     => $cardType,
                    'quota'         => $quota,
                    'quota_map'     => $quotaMapJ,
                    'status'        => self::STATUS_ON,
                    'max_uses'      => $maxUses,
                    'used_count'    => 0,
                    'expire_at'     => $expire,
                    'last_agent_id' => 0,
                    'last_used_at'  => 0,
                    'create_admin'  => $adminId,
                    'remark'        => $remark ?: null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
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
            'msg'  => '已生成 ' . count($made) . ' 张充值卡密',
            'data' => ['count' => count($made), 'codes' => $made],
        ];
    }

    public static function toggle(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'code' => 1001, 'msg' => '充值卡密不存在', 'data' => null];
        }
        $new = (int) $row['status'] === self::STATUS_ON ? self::STATUS_OFF : self::STATUS_ON;
        Database::update('agent_recharge_codes', ['status' => $new, 'updated_at' => time()], 'id = :id', ['id' => $id]);
        return ['ok' => true, 'code' => 0, 'msg' => $new === self::STATUS_ON ? '已启用' : '已停用', 'data' => ['id' => $id, 'status' => $new]];
    }

    public static function remove(int $id): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'code' => 1001, 'msg' => '充值卡密不存在', 'data' => null];
        }
        if ((int) $row['used_count'] > 0) {
            return ['ok' => false, 'code' => 1001, 'msg' => '该卡密已被兑换过，无法删除；请改为「停用」以保留追溯记录', 'data' => null];
        }
        Database::exec('DELETE FROM ' . Database::t('agent_recharge_codes') . ' WHERE id = ?', [$id]);
        return ['ok' => true, 'code' => 0, 'msg' => '已删除', 'data' => ['id' => $id]];
    }

    /* ------------------------- 校验 / 消费 ------------------------- */
    /**
     * 兑换前校验
     * @return array{ok:bool, code:int, msg:string, row:?array}
     */
    public static function validate(string $code): array
    {
        $fail = static function (int $code2, string $msg): array {
            return ['ok' => false, 'code' => $code2, 'msg' => $msg, 'row' => null];
        };

        if (trim($code) === '') {
            return $fail(2005, '请输入充值卡密');
        }
        $row = self::findByCode($code);
        if (!$row) {
            return $fail(2005, '充值卡密无效，请核对后重试');
        }
        if ((int) $row['status'] !== self::STATUS_ON) {
            return $fail(2005, '该充值卡密已被停用，请联系管理员');
        }
        if ((int) $row['expire_at'] > 0 && (int) $row['expire_at'] < time()) {
            return $fail(2007, '该充值卡密已过期，请联系管理员重新获取');
        }
        if ((int) $row['used_count'] >= (int) $row['max_uses']) {
            return $fail(2006, '该充值卡密已被兑换，请联系管理员重新获取');
        }

        return ['ok' => true, 'code' => 0, 'msg' => 'ok', 'row' => $row];
    }

    /** 消费一次兑换次数（条件 UPDATE，防并发超用） */
    public static function consume(int $id, int $agentId): bool
    {
        $n = Database::exec(
            'UPDATE ' . Database::t('agent_recharge_codes') . '
             SET used_count = used_count + 1, last_agent_id = ?, last_used_at = ?, updated_at = ?
             WHERE id = ? AND status = 1 AND used_count < max_uses',
            [$agentId, time(), time(), $id]
        );
        return $n > 0;
    }

    /* ------------------------- 兑换 ------------------------- */
    /**
     * 代理商兑换充值卡密（扣次数 + 加余额 / 额度，同一事务）
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function redeem(int $agentId, string $code): array
    {
        $agent = Agent::find($agentId);
        if (!$agent) {
            return ['ok' => false, 'code' => 1002, 'msg' => '代理商不存在', 'data' => null];
        }
        if ((int) $agent['status'] !== Agent::STATUS_ON) {
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被禁用，无法兑换', 'data' => null];
        }

        $v = self::validate($code);
        if (!$v['ok']) {
            return ['ok' => false, 'code' => $v['code'], 'msg' => $v['msg'], 'data' => null];
        }
        $row = $v['row'];

        $kind = (int) $row['kind'];
        $items = [];
        if ($kind === self::KIND_QUOTA) {
            $items = self::quotaItems($row);
            if (!$items) {
                return ['ok' => false, 'code' => 1001, 'msg' => '该卡密配置有误（未指定卡类型或张数），请联系管理员', 'data' => null];
            }
        }

        $now   = time();
        $table = Database::t('agent_recharge_codes');

        Database::begin();
        try {
            // 1) 扣次数（条件 UPDATE，防并发）
            $n = Database::exec(
                'UPDATE ' . $table . '
                 SET used_count = used_count + 1, last_agent_id = ?, last_used_at = ?, updated_at = ?
                 WHERE id = ? AND status = 1 AND used_count < max_uses',
                [$agentId, $now, $now, (int) $row['id']]
            );
            if ($n <= 0) {
                Database::rollback();
                return ['ok' => false, 'code' => 2006, 'msg' => '该充值卡密刚被兑换过，请核对后重试', 'data' => null];
            }

            // 2) 入账
            if ($kind === self::KIND_BALANCE) {
                $fen = (int) $row['amount'];
                Database::exec(
                    'UPDATE ' . Database::t('agents') . '
                     SET balance = GREATEST(0, balance + ?), updated_at = ? WHERE id = ?',
                    [$fen, $now, $agentId]
                );
                $detail = '余额 +' . Agent::fen2yuan($fen) . ' 元';
            } else {
                Agent::ensureRows($agentId);

                // 逐项入账：一张卡密可同时给多种卡类型加张数
                $parts = [];
                foreach ($items as $ct => $q) {
                    if ($q === -1) {
                        Database::exec(
                            'UPDATE ' . Database::t('agent_types') . '
                             SET quota_total = -1, updated_at = ? WHERE agent_id = ? AND card_type = ?',
                            [$now, $agentId, $ct]
                        );
                        $parts[] = Card::typeName($ct) . ' 设为不限量';
                    } else {
                        Database::exec(
                            'UPDATE ' . Database::t('agent_types') . '
                             SET quota_total = IF(quota_total < 0, -1, GREATEST(0, quota_total + ?)), updated_at = ?
                             WHERE agent_id = ? AND card_type = ?',
                            [$q, $now, $agentId, $ct]
                        );
                        $parts[] = Card::typeName($ct) . ' +' . $q . ' 张';
                    }
                }
                $detail = implode('、', $parts);
            }

            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            return ['ok' => false, 'code' => 9999, 'msg' => '兑换失败：' . $e->getMessage(), 'data' => null];
        }

        // 3) 记账（事务外写日志，失败也不影响入账结果）
        Agent::log($agentId, 'recharge', '兑换充值卡密 ' . $row['code'] . '：' . $detail);

        $fresh = Agent::find($agentId);
        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '兑换成功：' . $detail,
            'data' => [
                'detail' => $detail,
                'agent'  => $fresh ? Agent::publicInfo($fresh) : null,
            ],
        ];
    }

    /* ------------------------- 输出 ------------------------- */
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
     * 同时给出「结构化字段」与「可读文本」，前端不必再拼装
     */
    public static function publicInfo(array $r): array
    {
        $kind   = (int) $r['kind'];
        $isBal  = $kind === self::KIND_BALANCE;
        $amount = (int) $r['amount'];
        $ct     = (int) $r['card_type'];
        $q      = (int) $r['quota'];

        // 多卡类型规格（quota_map 优先，回退旧字段）
        $items  = $isBal ? [] : self::quotaItems($r);
        $quotas = [];
        foreach ($items as $t => $qq) {
            $quotas[] = [
                'type'       => $t,
                'type_name'  => Card::typeName($t),
                'quota'      => $qq,
                'quota_text' => $qq === -1 ? '不限量' : ($qq . ' 张'),
            ];
        }
        $quotaTexts = array_map(
            function (array $it) { return self::quotaItemText($it['type'], $it['quota']); },
            $quotas
        );

        // 兼容字段：单类型时给出该类型，多类型时留空
        $onlyOne     = count($quotas) === 1 ? $quotas[0] : null;
        $ctOut       = $onlyOne ? $onlyOne['type'] : $ct;
        $qOut        = $onlyOne ? $onlyOne['quota'] : $q;

        $maxUses  = (int) $r['max_uses'];
        $used     = (int) $r['used_count'];
        $expireAt = (int) $r['expire_at'];

        $lastId   = (int) ($r['last_agent_id'] ?? 0);
        $lastName = '';
        if ($lastId > 0) {
            $lastName = (string) (Database::value(
                'SELECT username FROM ' . Database::t('agents') . ' WHERE id = ?',
                [$lastId]
            ) ?: ('代理#' . $lastId));
        }

        return [
            'id'                => (int) $r['id'],
            'code'              => (string) $r['code'],
            'kind'              => $kind,
            'kind_text'         => self::kindName($kind),
            'amount'            => $amount,
            'amount_text'       => Agent::fen2yuan($amount),
            'card_type'         => $ctOut,
            'card_type_name'    => $ctOut > 0 ? Card::typeName($ctOut) : '',
            'quota'             => $qOut,
            'quota_text'        => $qOut === -1 ? '不限' : ($qOut ? ($qOut . ' 张') : ''),
            // 多卡类型张数（结构化 + 可读文本），余额充值卡为空数组
            'quotas'            => $quotas,
            'quota_count'       => count($quotas),
            // 一句话规格，列表直接展示
            'spec_text'         => $isBal
                                    ? ('余额 ¥ ' . Agent::fen2yuan($amount))
                                    : ($quotaTexts ? implode(' · ', $quotaTexts) : '未配置'),
            'status'            => (int) $r['status'],
            'status_text'       => self::statusName((int) $r['status']),
            'usable'            => self::isUsable($r),
            'max_uses'          => $maxUses,
            'used_count'        => $used,
            'left_uses'         => max(0, $maxUses - $used),
            'expire_at'         => $expireAt,
            'expire_text'       => $expireAt > 0 ? Util::date($expireAt) : '永久有效',
            'last_agent_id'     => $lastId,
            'last_agent_name'   => $lastName,
            'last_used_at_text' => !empty($r['last_used_at']) ? Util::date((int) $r['last_used_at']) : '',
            'remark'            => (string) ($r['remark'] ?? ''),
            'created_at_text'   => !empty($r['created_at']) ? Util::date((int) $r['created_at']) : '',
        ];
    }
}
