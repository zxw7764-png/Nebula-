<?php
/**
 * action: init
 * 客户端初始化：拉取配置、公告、版本、服务器状态
 * 参数: client_ver (可选), machine_id (可选)
 */

$clientVer = Util::str($requestData, 'client_ver', '');
$machineId = Util::str($requestData, 'machine_id', '');

// 版本信息：按当前软件独立下发（发布表该软件记录优先，回落软件自身配置）
$sw = Software::current() ?: ['id' => 1, 'latest_version' => '1.0.0', 'force_update' => 0, 'update_url' => '', 'update_note' => '', 'min_version' => ''];
$minVer = Software::minVersion($sw);

$vInfo = Software::versionInfo($sw, 'stable');

$latestVer = $vInfo['version'];
$rowForce  = $vInfo['force_update'];
$updateUrl = $vInfo['download_url'];
$changelog = $vInfo['changelog'];
$fileHash  = $vInfo['file_hash'];
$fileSize  = $vInfo['file_size'];

$needUpdate  = false;
$forceUpdate = false;

if ($clientVer !== '') {
    $needUpdate  = Util::versionCompare($clientVer, $latestVer) < 0;
    $forceUpdate = Util::versionCompare($clientVer, $minVer) < 0 || ($needUpdate && $rowForce);
}

// 完整性自校验数据：只在客户端版本号 == 最新已发布版本时下发（用户策略：
// 仅校验最新版——如存在 1/2/3 三个版本且 3 为最新，则 2 等旧版本一律不校验，
// 旧版本客户端由强制更新机制引导升级，避免每维护一个旧版哈希）。
// 客户端启动时计算自己 exe 的哈希与大小与此比对，不一致即拒绝运行（防篡改）；
// 未登记 / 非最新版时为空串与 0，客户端跳过校验。
$selfHash = '';
$selfSize = 0;
if ($clientVer !== '' && Util::versionCompare($clientVer, $latestVer) === 0) {
    $selfRel  = Software::releaseOf($sw, $clientVer, 'stable');
    $selfHash = (string) ($selfRel['file_hash'] ?? '');
    $selfSize = (int) ($selfRel['file_size'] ?? 0);
}

// 公告（software_id=0 为全部软件通用，否则仅下发给归属软件；仅列表公告 type=4——
// 公告栏展示用；弹窗公告 type=2 / 立即公告 type=3 由客户端经 notice 接口 + SDK popupNotices/flashNotices 处理）
$now = time();
$notices = Database::all(
    'SELECT id, title, content, type FROM ' . Database::t('notices') . '
     WHERE status = 1 AND type = 4
       AND (software_id = 0 OR software_id = ?)
       AND (start_at = 0 OR start_at <= ?)
       AND (end_at = 0 OR end_at >= ?)
     ORDER BY sort DESC, id DESC LIMIT 10',
    [(int) $sw['id'], $now, $now]
);

// ------------------------------------------------------------------
// 会话级签名密钥：每次 init 下发新密钥（本响应体本身用主密钥加密传输）
// 之后客户端所有业务请求的信封必须带 k，验签/响应签名均改用会话盐。
// 同一机器码仅保留最新一把；7 天未续（未再 init）自动过期。
// ------------------------------------------------------------------
$kid  = '';
$skey = '';
if ($machineId !== '') {
    $nowSk = time();
    $kid   = bin2hex(random_bytes(8));
    $skey  = bin2hex(random_bytes(24));

    Database::exec('DELETE FROM ' . Database::t('sign_keys') . ' WHERE expire_at > 0 AND expire_at < ?', [$nowSk]);
    Database::exec(
        'DELETE FROM ' . Database::t('sign_keys') . ' WHERE machine_id = ? AND software_id = ?',
        [$machineId, (int) $sw['id']]
    );
    Database::exec(
        'INSERT INTO ' . Database::t('sign_keys') . ' (kid, skey, software_id, machine_id, created_at, expire_at, last_used_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$kid, $skey, (int) $sw['id'], $machineId, $nowSk, $nowSk + 86400 * 7, $nowSk]
    );
}

Response::ok([
    'server_time'    => time(),
    'app_key'        => (string) $sw['app_key'],
    'software'       => ['id' => (int) $sw['id'], 'name' => (string) $sw['name']],
    'site_name'      => Setting::get('site_name', 'Nebula 网络验证'),
    'heartbeat_interval' => Policy::heartbeatInterval(),
    'session_ttl'    => (int) Config::get('policy.session_ttl', 3600),
    // 注册 / 维护：分软件策略覆盖优先（软件未单独配置时跟随全局）
    'register_enable'=> Policy::registerEnableFor($sw),
    'maintain_mode'  => Policy::maintainModeFor($sw),
    // 登录方式：客户端据此决定登录界面渲染哪些输入框、提交哪些字段
    // （分软件设置优先，软件未单独配置时跟随「系统设置 → 安全」）
    'login'          => LoginMethod::specFor($sw),
    // 设备指纹采集说明：客户端可以按需上报 device_fp（组件名 => 该硬件的哈希）
    // 上报后服务端会做加权指纹校验与模拟器/虚拟机识别；不上报同样可正常使用。
    'device_fp'      => [
        'enable'     => DeviceFp::enabled(),
        'components' => array_keys(DeviceFp::WEIGHTS),
        'core'       => array_values((array) Config::get('device_fp.core_components', ['board', 'cpu'])),
        'weights'    => DeviceFp::WEIGHTS,
    ],
    'session'        => ['k' => $kid, 's' => $skey],
    // 离线宽限：客户端在此取公钥并缓存（或直接内置到客户端），
    // 之后 login / heartbeat 会下发签名票据，心跳失败时本地验签即可离线运行。
    // enable=false 表示服务端未开启，客户端跳过该逻辑。
    'grace'          => Grace::info(),
    'crypto'         => [
        'enforce' => (bool) Config::get('security.enforce_crypto', true),
        'algo'    => 'AES-256-CBC',
        'sign'    => 'HMAC-SHA256',
    ],
    'version'        => [
        'client_ver'   => $clientVer,
        'latest'       => $latestVer,
        'min'          => $minVer,
        'need_update'  => $needUpdate,
        'force_update' => $forceUpdate,
        'update_url'   => $updateUrl,
        'download_url' => $updateUrl,
        'update_note'  => $changelog,
        'changelog'    => $changelog,
        'file_hash'    => $fileHash,
        'file_size'    => $fileSize,
        // 客户端自身版本登记的哈希/大小（完整性自校验；未登记为 '' / 0）
        'self_file_hash' => $selfHash,
        'self_file_size' => $selfSize,
        // 历史版本列表：客户端「更新日志」据此渲染多条版本条目
        'versions'     => Software::changelogList($sw, 'stable', 10),
    ],
    'notices'        => $notices,
]);
