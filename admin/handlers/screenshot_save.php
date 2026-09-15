<?php
/**
 * admin action: screenshot_save
 * 新增 / 编辑 / 删除 / 启停客户端截图
 *
 * 参数：
 *   op     save（默认）| delete | toggle
 *   id     截图 ID，0=新增
 *   title  截图标题
 *   url    图片地址（必须 http/https）
 *   sort   排序（越大越靠前）
 *   status 1启用 0停用
 */

$op    = Util::str($input, 'op', 'save');
$table = Database::t('screenshots');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '截图不存在');
    }

    Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);
    Audit::log($admin, 'screenshot_delete', "截图#{$id} {$old['title']}", '删除截图',
        ['title' => $old['title'], 'url' => $old['url']], []);

    Response::ok(['id' => $id], '截图已删除');
}

if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '截图不存在');
    }

    $new = (int) $old['status'] === 1 ? 0 : 1;
    Database::exec("UPDATE {$table} SET status = ? WHERE id = ?", [$new, $id]);

    Audit::log($admin, 'screenshot_toggle', "截图#{$id} {$old['title']}", '切换截图状态',
        ['status' => (int) $old['status']], ['status' => $new]);

    Response::ok(['id' => $id, 'status' => $new], $new === 1 ? '截图已启用' : '截图已停用');
}

// ---------------------------------------------------------------
// 保存
// ---------------------------------------------------------------
$id     = Util::int($input, 'id', 0);
$title  = mb_substr(trim(Util::str($input, 'title', '')), 0, 60);
$url    = trim(Util::str($input, 'url', ''));
$sort   = Util::int($input, 'sort', 0);
$status = Util::int($input, 'status', 1) === 1 ? 1 : 0;

if ($url === '') {
    Response::error(1001, '请填写图片地址');
}
if (mb_strlen($url, 'UTF-8') > 480) {
    Response::error(1001, '图片地址过长');
}
// 支持 http/https 外链或站内上传路径（/uploads/...）：避免 javascript: data: 等伪协议被前台塞进 img src
if (!preg_match('#^https?://#i', $url) && $url[0] !== '/') {
    Response::error(1001, '图片地址需为 http(s) 开头或上传后的站内路径');
}

$data = [
    'title'  => $title,
    'url'    => $url,
    'sort'   => $sort,
    'status' => $status,
];

// 归属软件（0=全部软件通用）；老库无该列时忽略该维度
if (WebInteract::hasSwCol('screenshots') && array_key_exists('software_id', $input)) {
    $swId = Util::int($input, 'software_id', 0);
    if ($swId > 0 && !Database::value('SELECT id FROM ' . Database::t('softwares') . ' WHERE id = ?', [$swId])) {
        Response::error(1001, '所选软件不存在');
    }
    $data['software_id'] = $swId;
}

if ($id > 0) {
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '截图不存在');
    }

    Database::update('screenshots', $data, 'id = :id', ['id' => $id]);

    Audit::log($admin, 'screenshot_save', "截图#{$id} {$title}", '编辑截图',
        Util::pick($old, ['title', 'url', 'sort', 'status']), $data);

    Response::ok(['id' => $id], '截图已更新');
}

$data['created_at'] = time();
$newId = Database::insert('screenshots', $data);

Audit::log($admin, 'screenshot_save', "截图#{$newId} {$title}", '新增截图', [], $data);

Response::ok(['id' => $newId], '截图已添加');
