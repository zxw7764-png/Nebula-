<?php
/**
 * admin action: admin_list
 * 管理员账号列表 + 权限点目录（超管专属）
 *
 * 返回：
 *   list     管理员数组（含 2FA 区分状态：enabled 已开启 / pending 待绑定 / none 未开启）
 *   catalog  权限点目录（分组 => [权限点 => 中文名]），超管配置界面渲染用
 *   role_names  角色名映射
 */

$table = Database::t('admins');

$rows = Database::all(
    "SELECT id, username, nickname, role, permissions, status,
            last_login_ip, last_login_time, created_at, totp_enabled, totp_secret
     FROM {$table}
     ORDER BY role ASC, id ASC"
);

$list = array_map(static function (array $a): array {
    // 2FA 三态区分：绑定是个人安全行为（个人中心自行操作），
    // 这里只读展示；超管可对丢失验证器的账号执行「重置 2FA」恢复登录
    $enabled = (int) ($a['totp_enabled'] ?? 0) === 1;
    $pending = !$enabled && !empty($a['totp_secret']);
    return [
        'id'              => (int) $a['id'],
        'username'        => (string) $a['username'],
        'nickname'        => (string) ($a['nickname'] ?? ''),
        'role'            => (int) $a['role'],
        'role_text'       => AdminAuth::roleName((int) $a['role']),
        // 自定义权限清单：NULL = 未配置（走角色默认矩阵）
        'permissions'     => AdminPermission::customPermissionsOf($a),
        'status'          => (int) $a['status'],
        'totp'            => $enabled ? 'enabled' : ($pending ? 'pending' : 'none'),
        'last_login_ip'   => (string) ($a['last_login_ip'] ?? ''),
        'last_login_text' => !empty($a['last_login_time'])
            ? Util::date((int) $a['last_login_time']) : '',
        'created_text'    => !empty($a['created_at'])
            ? Util::date((int) $a['created_at']) : '',
    ];
}, $rows);

Response::ok([
    'list'       => $list,
    'catalog'    => AdminPermission::catalog(),
    'role_names' => [2 => '操作员', 3 => '只读'],
]);
