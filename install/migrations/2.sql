-- ============================================================
-- Nebula 发卡网 数据库升级脚本 v2
-- 对应版本: 2.65.0
-- 前置版本: >= 2.64.4
-- ============================================================
-- 新增字段:
--   jsapi_params  TEXT     微信 JSAPI 预下单参数（前端调起支付用）
--   openid        VARCHAR  微信 openid（JSAPI 支付必需）
-- ============================================================

ALTER TABLE `nb_shop_orders`
  ADD COLUMN `jsapi_params` TEXT DEFAULT NULL COMMENT '微信JSAPI支付参数（预下单结果，前端调起支付用）' AFTER `wechat_code_url`,
  ADD COLUMN `openid` VARCHAR(64) DEFAULT NULL COMMENT '微信openid（JSAPI支付需传入，下单时从前端获取）' AFTER `jsapi_params`;
