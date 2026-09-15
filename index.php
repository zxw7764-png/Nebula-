<?php
/**
 * 站点根入口
 * 官网前端位于 /web/，这里统一跳转，方便直接访问 http://<域名>/ 进入官网。
 * 客户端接口仍在 /api/，管理后台仍在 /admin/，互不影响。
 */

$target = 'web/';

// 保留查询串，便于带参数分享
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target, true, 302);
exit;
