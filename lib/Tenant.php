<?php
/**
 * 多租户数据隔离（基于代理商体系）
 * ------------------------------------------------------------------
 * 模型：
 *   - 平台管理员：admins.agent_id = 0（默认），可见全部软件及其数据；
 *   - 租户管理员：admins.agent_id > 0，绑定某代理商，仅可见
 *     softwares.owner_agent_id = 该代理商 的软件及其下用户/卡密/设备数据；
 *   - 代理商自己在 /agent/ 门户的数据边界本就按 cards.agent_id 隔离，
 *     本类管的是「代理商以管理员身份登入总后台」的可见范围。
 *
 * 用法（handler 内，$admin 已就绪）：
 *   Tenant::applyNamed($where, $params);        // 命名参数风格的列表
 *   Tenant::applyPositional($where, $params);   // 位置参数风格的列表
 *   Tenant::requireTouch($admin, $swId);        // 单条操作越权即拒绝
 *
 * 设计原则：默认拒绝 —— 无法确定归属的数据一律不可见/不可改。
 */

class Tenant
{
    /**
     * 当前管理员的软件可见范围。
     * @return int[]|null null=不限制（平台管理员）；空数组=租户无任何软件
     */
    public static function softwareScope(?array $admin): ?array
    {
        if (!$admin) {
            return null;
        }
        $agentId = (int) ($admin['agent_id'] ?? 0);
        if ($agentId === 0) {
            return null; // 平台管理员
        }
        try {
            $rows = Database::all(
                'SELECT id FROM ' . Database::t('softwares') . ' WHERE owner_agent_id = ?',
                [$agentId]
            );
        } catch (Throwable $e) {
            // 列不存在（未迁移）等异常：默认拒绝，宁可看不见也不能越权
            return [];
        }
        return array_map('intval', array_column($rows, 'id'));
    }

    /** 是否租户管理员（受数据隔离约束） */
    public static function isTenant(?array $admin): bool
    {
        return $admin && (int) ($admin['agent_id'] ?? 0) > 0;
    }

    /** 命名参数风格过滤：$where[] = 'software_id IN (:sw0,:sw1)' */
    public static function applyNamed(array &$where, array &$params, string $column = 'software_id'): void
    {
        $ids = self::softwareScope($GLOBALS['nb_admin'] ?? null);
        if ($ids === null) {
            return;
        }
        if (!$ids) {
            $where[] = '1=0'; // 租户无软件：一行都看不见
            return;
        }
        $ph = [];
        foreach (array_values($ids) as $i => $id) {
            $ph[] = ':swScope' . $i;
            $params['swScope' . $i] = $id;
        }
        $where[] = $column . ' IN (' . implode(',', $ph) . ')';
    }

    /** 位置参数风格过滤：$where[] = 'software_id IN (?,?)' */
    public static function applyPositional(array &$where, array &$params, string $column = 'software_id'): void
    {
        $ids = self::softwareScope($GLOBALS['nb_admin'] ?? null);
        if ($ids === null) {
            return;
        }
        if (!$ids) {
            $where[] = '1=0';
            return;
        }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }

    /** 单条写操作越权校验：租户管理员碰非自有软件时直接拒绝 */
    public static function requireTouch(?array $admin, int $softwareId): void
    {
        $ids = self::softwareScope($admin);
        if ($ids === null) {
            return; // 平台管理员
        }
        if (!in_array($softwareId, $ids, true)) {
            Response::error(4031, '无权操作该软件的数据');
        }
    }

    // ------------------------------------------------------------------
    // 2026-09-30 审计补强：所有单条/批量写路径统一从这里过授权，
    // 不再依赖各 handler「记得调用」—— 用法见各方法注释。
    // ------------------------------------------------------------------

    /**
     * 单条记录按表归属校验（表需含 software_id 列）。
     * 典型：编辑卡密 / 公告 / 版本前先 $row = 读取，再 touchRow($admin, 'cards', $row)。
     * 记录不存在时由调用方自行 404；这里只管「存在但越权」。
     */
    public static function touchRow(?array $admin, string $table, array $row, string $column = 'software_id'): void
    {
        if (self::softwareScope($admin) === null) {
            return;
        }
        self::requireTouch($admin, (int) ($row[$column] ?? 0));
    }

    /**
     * 批量记录归属校验：$ids 中任何一条落在租户范围之外即整单拒绝。
     * $table 必须含 software_id 列（cards / card_batches / users / versions / notices...）。
     * 幂等安全：传入前请先做 intval 清洗。
     */
    public static function requireTouchAll(?array $admin, string $table, array $ids, string $column = 'software_id'): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || self::softwareScope($admin) === null) {
            return;
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT id, {$column} sw FROM " . Database::t($table) . " WHERE id IN ($ph)",
            $ids
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (int) $r['sw'];
        }
        foreach ($ids as $id) {
            // 查无此记录交给后续业务逻辑 404；存在但越权 → 拒绝
            if (isset($map[$id])) {
                self::requireTouch($admin, $map[$id]);
            }
        }
    }

    /** 用户链路授权（users.software_id） */
    public static function requireTouchUser(?array $admin, int $userId): void
    {
        if (self::softwareScope($admin) === null) {
            return;
        }
        $sw = (int) Database::value(
            'SELECT software_id FROM ' . Database::t('users') . ' WHERE id = ?',
            [$userId]
        );
        self::requireTouch($admin, $sw);
    }

    /** 设备链路授权：device → user → software_id（设备表无 software_id，走授权链推导） */
    public static function requireTouchDevice(?array $admin, int $deviceId): void
    {
        if (self::softwareScope($admin) === null) {
            return;
        }
        $sw = (int) Database::value(
            'SELECT u.software_id FROM ' . Database::t('devices') . ' d'
            . ' JOIN ' . Database::t('users') . ' u ON u.id = d.user_id WHERE d.id = ?',
            [$deviceId]
        );
        self::requireTouch($admin, $sw);
    }
}
