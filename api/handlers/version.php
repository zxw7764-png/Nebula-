<?php
/**
 * action: version
 * 版本校验（无需登录）
 * 参数: version, channel(可选，默认 stable)
 */

$clientVer = Util::str($requestData, 'version', Util::str($requestData, 'client_ver', ''));
$channel   = Util::str($requestData, 'channel', 'stable');

if ($clientVer === '') {
    Response::error(1001, '请提供当前版本号 version');
}

// 多软件：按 app_key 命中的软件独立下发版本策略
$sw = Software::current() ?: ['id' => 1, 'latest_version' => '1.0.0', 'force_update' => 0, 'update_url' => '', 'update_note' => '', 'min_version' => ''];
$minVer = Software::minVersion($sw);
$vInfo  = Software::versionInfo($sw, $channel);
$latest = $vInfo['version'];
$force  = $vInfo['force_update'];
$url    = $vInfo['download_url'];
$note   = $vInfo['changelog'];
$hash   = $vInfo['file_hash'];
$size   = $vInfo['file_size'];

$needUpdate  = Util::versionCompare($clientVer, $latest) < 0;
$forceUpdate = Util::versionCompare($clientVer, $minVer) < 0 || ($needUpdate && $force);

// 客户端自身版本登记的哈希/大小——仅最新版本参与校验（与 init 同规则）：
// 旧版本客户端一律下发空值跳过自校验，由强制更新机制引导升级
$selfHash = '';
$selfSize = 0;
if (Util::versionCompare($clientVer, $latest) === 0) {
    $selfRel  = Software::releaseOf($sw, $clientVer, $channel);
    $selfHash = (string) ($selfRel['file_hash'] ?? '');
    $selfSize = (int) ($selfRel['file_size'] ?? 0);
}

Response::ok([
    'current'      => $clientVer,
    'latest'       => $latest,
    'min'          => $minVer,
    'channel'      => $channel,
    'need_update'  => $needUpdate,
    'force_update' => $forceUpdate,
    'download_url' => $url,
    'file_hash'    => $hash,
    'file_size'    => $size,
    'changelog'    => $note,
    'self_file_hash' => $selfHash,
    'self_file_size' => $selfSize,
], $needUpdate ? '发现新版本' : '已是最新版本');
