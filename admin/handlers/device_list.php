<?php
/**
 * admin action: device_list
 * 设备绑定列表
 */

$page     = max(1, Util::int($input, 'page', 1));
$size     = min(200, max(1, Util::int($input, 'size', 20)));
$keyword  = Util::str($input, 'keyword', '');
$status   = Util::get($input, 'status', '');
$onlyOnline = (bool) Util::get($input, 'online', false);

$where  = ['1=1'];
$params = [];

if ($keyword !== '') {
    $where[] = '(d.machine_id LIKE :kw OR d.ip LIKE :kw2 OR d.device_name LIKE :kw3 OR u.username LIKE :kw4)';
    $params['kw']  = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
    $params['kw3'] = "%{$keyword}%";
    $params['kw4'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'd.status = :st';
    $params['st'] = (int) $status;
}
if ($onlyOnline) {
    $where[] = 'd.last_seen > :ls';
    $params['ls'] = time() - Policy::heartbeatTimeout();
}
// 设备指纹风险筛选
//   注意：nb_devices 的指纹列由 install/migrate_device_fp.php 添加，
//   未执行迁移时该列不存在，这里的 SQL 会报错。用一次「探测查询」判断列是否可用，
//   不可用则忽略该筛选条件，保证后台在未迁移的库上照常打开。
$risk = '';
$riskFlag = Util::str($input, 'risk', '');   // '' 全部 | vm 仅虚拟机 | any 仅有风险标记
$fpFlag   = Util::str($input, 'fp', '');     // '' 全部 | none 客户端未上报指纹

// 指纹列是否可用（探测一次，后续复用）
$hasFpCol = (bool) Database::one(
    'SHOW COLUMNS FROM ' . Database::t('devices') . ' LIKE ' . Database::pdo()->quote('risk_flags')
);

if ($riskFlag !== '') {
    if ($hasFpCol) {
        $risk = $riskFlag;
        if ($riskFlag === 'vm') {
            $where[] = 'd.vm_flag = 1';
        } else {
            $where[] = "d.risk_flags IS NOT NULL AND d.risk_flags <> ''";
        }
    }
}
if ($fpFlag === 'none' && $hasFpCol) {
    // 客户端未上报 device_fp（老客户端或客户端未采集硬件信息）
    $where[] = "(d.fp_hash IS NULL OR d.fp_hash = '')";
}

$baseSql = 'SELECT d.*, u.username FROM ' . Database::t('devices') . ' d
            LEFT JOIN ' . Database::t('users') . ' u ON u.id = d.user_id
            WHERE ' . implode(' AND ', $where);

[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`d`.`last_seen` DESC');

$timeout = Policy::heartbeatTimeout();
$list = array_map(function ($d) use ($timeout) {
    // 指纹列由 install/migrate_device_fp.php 添加；未迁移时用 ?? 兜底避免未定义索引
    $flags = trim((string) ($d['risk_flags'] ?? ''));

    // 组件明细：库里以 JSON 存原始特征串，这里解析成 {组件: 值} 供后台展示。
    // 解析失败（未迁移 / 手工改库）时返回空数组，前端展示"-"，不影响列表。
    $fpRaw = $d['fp_json'] ?? null;
    $fpComponents = [];
    if (is_string($fpRaw) && $fpRaw !== '') {
        $decoded = json_decode($fpRaw, true);
        if (is_array($decoded)) {
            $fpComponents = DeviceFp::normalize($decoded);
        }
    }

    return [
        'id'          => (int) $d['id'],
        'user_id'     => (int) $d['user_id'],
        'username'    => $d['username'],
        'machine_id'  => $d['machine_id'],
        'device_name' => $d['device_name'],
        'os_info'     => $d['os_info'],
        'ip'          => $d['ip'],
        'status'      => (int) $d['status'],
        'status_text' => (int) $d['status'] === 1 ? '正常' : '已解绑',
        'online'      => (int) $d['status'] === 1 && (time() - (int) $d['last_seen']) < $timeout,
        'bind_at'     => Util::date((int) $d['bind_at']),
        'last_seen'   => Util::date((int) $d['last_seen']),
        'unbind_reason' => $d['unbind_reason'],
        // 设备指纹（客户端上报的硬件组件 + 服务端合成结果）
        'fp_hash'     => $d['fp_hash'] ?? null,
        'fp_score'    => (int) ($d['fp_score'] ?? 0),
        'fp_max_score'=> DeviceFp::maxScore(),
        'fp_count'    => count($fpComponents),
        'fp_components' => $fpComponents,
        'fp_components_view' => DeviceFp::componentsView($fpComponents),
        'vm'          => (int) ($d['vm_flag'] ?? 0) === 1,
        'risk'        => $flags === '' ? [] : array_values(array_filter(explode(',', $flags))),
        'risk_text'   => DeviceFp::flagsText($flags),
    ];
}, $rows);

// 风险总数（供列表页顶部展示）；未迁移时该列不存在 → 直接跳过
$riskTotal = 0;
try {
    $riskTotal = (int) Database::value(
        "SELECT COUNT(*) FROM " . Database::t('devices') . " WHERE risk_flags IS NOT NULL AND risk_flags <> ''"
    );
} catch (Throwable $e) {
    $riskTotal = 0;
}

Response::ok([
    'total'      => $total,
    'page'       => $page,
    'size'       => $size,
    'pages'      => (int) ceil($total / $size),
    'risk_total' => $riskTotal,
    'risk_filter'=> $risk,
    // 客户端未上报指纹的设备数，用于提示「有多少老客户端还没升级」
    'nofp_total' => $hasFpCol ? (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('devices') . " WHERE fp_hash IS NULL OR fp_hash = ''"
    ) : 0,
    'fp_max_score' => DeviceFp::maxScore(),
    'list'       => $list,
]);
