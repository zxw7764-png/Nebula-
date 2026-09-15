<?php
/**
 * admin action: captcha
 * 登录图形验证码：GET 输出 PNG，答案存 session，login 时校验。
 * 与页面共用入口密钥 Cookie 校验（index.php 已完成），无需登录令牌。
 */

// 浏览器 <img> 原生 GET 拉图，IP 限流兜底防刷
if (!RateLimit::hit('admincaptcha:' . Util::ip(), 60, 60)) {
    // 限流也输出占位图，避免 <img> 拿到 JSON 显示破图
    Captcha::renderBusy();
    return;
}

Captcha::render();
