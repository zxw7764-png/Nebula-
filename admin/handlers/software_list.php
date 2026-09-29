<?php
/**
 * admin action: software_list
 * 软件列表（多软件网络验证：含通信密钥，仅超管档可见）
 */

$opts = [];
$list = [];
$scope = Tenant::softwareScope($admin); // 多租户：租户管理员仅见归属软件
foreach (Software::all() as $sw) {
    if ($scope !== null && !in_array((int) $sw['id'], $scope, true)) {
        continue;
    }
    $row = [
        'id'             => (int) $sw['id'],
        'name'           => (string) $sw['name'],
        'app_key'        => (string) $sw['app_key'],
        'aes_key'        => (string) $sw['aes_key'],
        'sign_salt'      => (string) $sw['sign_salt'],
        'min_version'    => (string) $sw['min_version'],
        'latest_version' => (string) $sw['latest_version'],
        'force_update'   => (int) $sw['force_update'],
        'update_url'     => (string) ($sw['update_url'] ?? ''),
        'update_note'    => (string) ($sw['update_note'] ?? ''),
        'login_methods'  => (string) ($sw['login_methods'] ?? ''),
        'feature_key'    => (string) ($sw['feature_key'] ?? ''),
        'policy'         => (is_string($sw['policy_json'] ?? null) && $sw['policy_json'] !== '')
            ? (json_decode($sw['policy_json'], true) ?: [])
            : [],
        'status'         => (int) $sw['status'],
        'remark'         => (string) ($sw['remark'] ?? ''),
        'created_at'     => Util::date((int) $sw['created_at']),
    ];
    $list[] = $row;
    $opts[] = ['id' => (int) $sw['id'], 'name' => (string) $sw['name'], 'status' => (int) $sw['status']];
}

Response::ok([
    'list'    => $list,
    'options' => $opts,
]);
