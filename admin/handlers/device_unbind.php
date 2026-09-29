<?php
/**
 * admin action: device_unbind
 * 解绑设备 / 批量解绑 / 按用户解绑 / 清理离线设备
 */

$op = Util::str($input, 'op', 'single');

switch ($op) {
    case 'single':
        $deviceId = Util::int($input, 'device_id', 0);
        if ($deviceId <= 0) {
            Response::error(1001, '缺少 device_id');
        }
        $dev = Database::one('SELECT * FROM ' . Database::t('devices') . ' WHERE id = ?', [$deviceId]);
        if (!$dev) {
            Response::error(1001, '设备不存在');
        }
        // 租户隔离：device → user → software_id 授权链校验
        Tenant::requireTouchDevice($admin, $deviceId);
        $ok = Device::forceUnbind($deviceId, '管理员解绑');
        // 同步踢掉该设备会话
        Database::exec(
            'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE user_id = ? AND machine_id = ?',
            [$dev['user_id'], $dev['machine_id']]
        );
        Audit::log($admin, 'device_unbind', "设备#{$deviceId}",
            "解绑设备 {$dev['machine_id']}（用户 #{$dev['user_id']}）");
        Response::ok(null, $ok ? '设备已解绑' : '解绑失败');
        break;

    case 'batch':
        $ids = $input['device_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, '请选择设备');
        }
        if (count($ids) > 500) {
            Response::error(1001, '单次最多操作 500 台设备');
        }

        $in   = implode(',', $ids);
        $devs = Database::all(
            'SELECT d.id, d.user_id, d.machine_id FROM ' . Database::t('devices') . ' d'
            . ' JOIN ' . Database::t('users') . ' u ON u.id = d.user_id'
            . ' WHERE d.id IN (' . $in . ')'
            . (Tenant::isTenant($admin)
                ? ' AND u.software_id IN (' . implode(',', array_map('intval', Tenant::softwareScope($admin) ?: [0])) . ')'
                : '')
        );
        $n = 0;
        foreach ($devs as $dv) {
            if (Device::forceUnbind((int) $dv['id'], '管理员批量解绑')) {
                $n++;
            }
            Database::exec(
                'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE user_id = ? AND machine_id = ?',
                [(int) $dv['user_id'], $dv['machine_id']]
            );
        }
        Audit::log($admin, 'device_unbind', '设备批量', "批量解绑 {$n} 台设备",
            [], [], ['ids' => $ids]);
        Response::ok(['count' => $n], "已解绑 {$n} 台设备");
        break;

    case 'user':
        $userId = Util::int($input, 'user_id', 0);
        if ($userId <= 0) {
            Response::error(1001, '缺少 user_id');
        }
        // 租户隔离：目标用户的归属软件必须在范围内
        Tenant::requireTouchUser($admin, $userId);
        $n = Device::unbindAll($userId, '管理员批量解绑');
        Session::kickUser($userId);
        Audit::log($admin, 'device_unbind', "用户#{$userId}", "解绑用户 #{$userId} 的 {$n} 台设备");
        Response::ok(['count' => $n], "已解绑 {$n} 台设备");
        break;

    case 'gc':
        // 先把心跳缓冲落库，否则聚合模式下 DB 里的 last_seen 偏旧，
        // 会把仍然在线的设备当成离线设备解绑掉。
        if (Heartbeat::enabled()) {
            Heartbeat::flush((int) Config::get('heartbeat.flush_batch', 200));
        }
        $timeout = Policy::heartbeatTimeout();
        $n = Device::gcOffline(Heartbeat::gcTimeout($timeout));
        Audit::log($admin, 'device_gc', '离线设备', "清理离线设备 {$n} 台");
        Response::ok(['count' => $n], "已清理 {$n} 台离线设备");
        break;

    // ---------------- 批量删除设备记录（不可恢复） ----------------
    case 'delete':
        $ids = $input['device_ids'] ?? ($input['ids'] ?? []);
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            Response::error(1001, '请选择设备');
        }
        if (count($ids) > 500) {
            Response::error(1001, '单次最多删除 500 台设备');
        }

        Deleter::confirmPassword($admin, $input, '批量删除设备记录');

        // 租户隔离：目标设备经 user → software_id 授权链逐条校验
        if (Tenant::isTenant($admin)) {
            $devRows = Database::all(
                'SELECT id FROM ' . Database::t('devices') . ' WHERE id IN (' . implode(',', $ids) . ')'
            );
            foreach ($devRows as $dr) {
                Tenant::requireTouchDevice($admin, (int) $dr['id']);
            }
        }

        $r = Deleter::devices($ids);

        Audit::log($admin, 'device_delete', '设备批量(删除)',
            "批量删除 {$r['deleted']} 条设备记录，对应会话已结束",
            [], [], ['ids' => $ids, 'machine_ids' => array_slice($r['machine_ids'], 0, 200)]);

        Response::ok(['count' => $r['deleted']], "已删除 {$r['deleted']} 条设备记录");
        break;

    default:
        Response::error(1001, '未知操作');
}
