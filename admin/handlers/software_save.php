<?php
/**
 * admin action: software_save
 * 新增 / 编辑软件
 */

$id = Util::int($input, 'id', 0);

// 多租户：编辑时校验归属；新建时自动归属到租户管理员的代理商
if ($id > 0) {
    Tenant::requireTouch($admin, $id);
    $r = Software::update($id, $input);
} else {
    $r = Software::create($input);
    if ($r['ok'] && Tenant::isTenant($admin)) {
        Database::update('softwares', ['owner_agent_id' => (int) $admin['agent_id']], 'id = :id', ['id' => (int) $r['id']]);
    }
}

if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Response::ok(['id' => $r['id'] ?? $id], $r['msg']);
