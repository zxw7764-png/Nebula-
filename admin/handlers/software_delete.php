<?php
/**
 * admin action: software_delete
 * 删除软件（至少保留一个启用软件）
 */

$id = Util::int($input, 'id', 0);

$r = Software::delete($id);
if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Response::ok(null, $r['msg']);
