<?php
/**
 * action: online
 * 在线人数（公开接口，无需登录）
 * ------------------------------------------------------------------
 * 口径：nb_sessions 中 status = 1 且 last_active 在心跳超时内的会话数，
 *       与后台首页「在线会话」一致。
 * 参数：无
 * 返回：{ online, timeout, server_time }
 *
 * 客户端可频繁轮询（建议 30~60 秒一次），走 IP 限流保护。
 */

Response::ok(Session::onlineStat());
