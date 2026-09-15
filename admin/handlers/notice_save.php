<?php
/**
 * admin action: notice_save
 * 新增 / 编辑 / 删除公告
 */

$op = Util::str($input, 'op', 'save');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one('SELECT * FROM ' . Database::t('notices') . ' WHERE id = ?', [$id]);
    Database::exec('DELETE FROM ' . Database::t('notices') . ' WHERE id = ?', [$id]);
    Audit::log($admin, 'notice_delete', "公告#{$id}" . ($old ? ' ' . $old['title'] : ''),
        '删除公告', $old ? ['title' => $old['title'], 'type' => (int) $old['type']] : [], []);
    Response::ok(null, '公告已删除');
}

$id      = Util::int($input, 'id', 0);
$title   = mb_substr(Util::str($input, 'title', ''), 0, 120);
$content = (string) Util::get($input, 'content', '');
$type    = Util::int($input, 'type', 1);
$status  = Util::int($input, 'status', 1);
$sort    = Util::int($input, 'sort', 0);
$startAt = Util::int($input, 'start_at', 0);
$endAt   = Util::int($input, 'end_at', 0);

if ($title === '') {
    Response::error(1001, '标题不能为空');
}
if (!in_array($type, [1, 2, 3, 4], true)) {
    $type = 1;
}

$data = [
    'title'   => $title,
    'content' => $content,
    'type'    => $type,
    'status'  => $status,
    'sort'    => $sort,
    'start_at'=> $startAt,
    'end_at'  => $endAt,
];

// 软件归属：0=全部软件通用；未提交该字段则保持原值（兼容旧前端/状态切换重放）
if (array_key_exists('software_id', $input)) {
    $swId = Util::int($input, 'software_id', 0);
    if ($swId > 0 && !Database::value('SELECT id FROM ' . Database::t('softwares') . ' WHERE id = ?', [$swId])) {
        Response::error(1001, '所选软件不存在');
    }
    $data['software_id'] = $swId;
}

if ($id > 0) {
    $old = Database::one('SELECT * FROM ' . Database::t('notices') . ' WHERE id = ?', [$id]);
    Database::update('notices', $data, 'id = :id', ['id' => $id]);
    Audit::log($admin, 'notice_save', "公告#{$id} {$title}", '编辑公告',
        $old ? Util::pick($old, ['title', 'content', 'type', 'status', 'sort', 'start_at', 'end_at']) : [],
        $data);
    Cache::del('notice:push_count'); // 公告变更，清掉心跳用的缓存计数
    Response::ok(['id' => $id], '公告已更新');
}

$data['created_at'] = time();
$newId = Database::insert('notices', $data);
Audit::log($admin, 'notice_save', "公告#{$newId} {$title}", '发布公告', [], $data);
Cache::del('notice:push_count');
Response::ok(['id' => $newId], '公告已发布');
