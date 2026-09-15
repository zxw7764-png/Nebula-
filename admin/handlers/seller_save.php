<?php
/**
 * admin action: seller_save
 * 新增 / 编辑 / 删除 / 启用停用购买商家
 *
 * 参数：
 *   op       save（默认）| delete | toggle
 *   id       商家 ID，0=新增
 *   name     商家名称
 *   logo     商家 logo 图片地址（http/https）
 *   desc     商家简介，一行一条要点
 *   contact  联系方式（官网前端弹窗展示）
 *   url      店铺链接（http/https，前端新窗口打开）
 *   badge    角标文案（如"授权"）
 *   highlight 1=推荐样式
 *   sort     排序（越大越靠前）
 *   status   1启用 0停用
 */

$op    = Util::str($input, 'op', 'save');
$table = Database::t('sellers');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '商家不存在');
    }

    Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);
    Audit::log($admin, 'seller_delete', "商家#{$id} {$old['name']}", '删除购买商家',
        ['name' => $old['name'], 'url' => $old['url']], []);

    Response::ok(['id' => $id], '商家已删除');
}

if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '商家不存在');
    }

    $new = (int) $old['status'] === 1 ? 0 : 1;
    Database::exec("UPDATE {$table} SET status = ? WHERE id = ?", [$new, $id]);

    Audit::log($admin, 'seller_toggle', "商家#{$id} {$old['name']}", '切换购买商家状态',
        ['status' => (int) $old['status']], ['status' => $new]);

    Response::ok(['id' => $id, 'status' => $new], $new === 1 ? '商家已启用' : '商家已停用');
}

// ---------------------------------------------------------------
// 保存
// ---------------------------------------------------------------
$id        = Util::int($input, 'id', 0);
$name      = mb_substr(trim(Util::str($input, 'name', '')), 0, 60);
$logo      = trim(Util::str($input, 'logo', ''));
$desc      = (string) Util::get($input, 'desc', '');
$contact   = mb_substr(trim(Util::str($input, 'contact', '')), 0, 250);
$url       = trim(Util::str($input, 'url', ''));
$badge     = mb_substr(trim(Util::str($input, 'badge', '')), 0, 30);
$highlight = Util::int($input, 'highlight', 0) === 1 ? 1 : 0;
$sort      = Util::int($input, 'sort', 0);
$status    = Util::int($input, 'status', 1) === 1 ? 1 : 0;

if ($name === '') {
    Response::error(1001, '商家名称不能为空');
}

// logo 支持 http/https 外链或站内上传路径（/uploads/...）；店铺链接仍只认可 http/https
//（防 javascript: 等伪协议被前端渲染或跳转）
if ($logo !== '' && !preg_match('#^https?://#i', $logo) && $logo[0] !== '/') {
    Response::error(1001, 'Logo 地址需为 http(s) 开头或上传后的站内路径');
}
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    Response::error(1001, '店铺链接必须以 http:// 或 https:// 开头');
}

$data = [
    'name'      => $name,
    'logo'      => $logo,
    'desc'      => $desc,
    'contact'   => $contact,
    'url'       => $url,
    'badge'     => $badge,
    'highlight' => $highlight,
    'sort'      => $sort,
    'status'    => $status,
];

// 归属软件（0=全部软件通用）；老库无该列时忽略该维度
if (WebInteract::hasSwCol('sellers') && array_key_exists('software_id', $input)) {
    $swId = Util::int($input, 'software_id', 0);
    if ($swId > 0 && !Database::value('SELECT id FROM ' . Database::t('softwares') . ' WHERE id = ?', [$swId])) {
        Response::error(1001, '所选软件不存在');
    }
    $data['software_id'] = $swId;
}

if ($id > 0) {
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '商家不存在');
    }

    Database::update('sellers', $data, 'id = :id', ['id' => $id]);

    Audit::log($admin, 'seller_save', "商家#{$id} {$name}", '编辑购买商家',
        Util::pick($old, ['name', 'logo', 'desc', 'contact', 'url', 'badge', 'highlight', 'sort', 'status']),
        $data);

    Response::ok(['id' => $id], '商家已更新');
}

$data['created_at'] = time();
$newId = Database::insert('sellers', $data);

Audit::log($admin, 'seller_save', "商家#{$newId} {$name}", '新增购买商家', [], $data);

Response::ok(['id' => $newId], '商家已添加');
