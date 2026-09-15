<?php
/**
 * 图形验证码
 * ------------------------------------------------------------------
 *   - 用 GD 生成 4 位字母数字 PNG，去除易混淆字符（0/O，1/I/L）
 *   - 答案存到 session，5 分钟过期；一次性使用，成功后立刻清除
 *   - verify 大小写不敏感，答错不清除，给用户改的机会
 *   - 失败次数不限流交给 RateLimit::hit('webcaptcha:<ip>', ...) 兜底
 *
 * 之所以不存数据库：会话级图形验证码生命周期短，存库反而要维护过期清理。
 * 之所以不缓存：答案随时间戳走，CDN/反向代理只会把同一张图反复下发。
 */
class Captcha
{
    /** 字符集：去掉 0/O、1/I/L 这类一眼分不清的 */
    public const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const SESSION_KEY = 'nb_web_captcha';
    public const SESSION_TS  = 'nb_web_captcha_at';
    public const TTL         = 300;   // 5 分钟

    /** 启动会话（仅当还没启动时），并返回当前 session_id */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /**
     * 输出 PNG 验证码图并把答案写入 session。
     * 调用方负责在调用前做完 RateLimit 限流等公共校验。
     */
    public static function render(int $len = 4): void
    {
        if (!extension_loaded('gd')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'GD extension is required for captcha';
            return;
        }

        $len = min(6, max(3, $len));
        $code = self::generateCode($len);

        self::ensureSession();
        $_SESSION[self::SESSION_KEY] = $code;
        $_SESSION[self::SESSION_TS]  = time();

        // 画图
        $w = 120;
        $h = 38;
        $im = imagecreate($w, $h);
        // 暗底（和官网深色调一致，避免亮色截图晃眼）
        $bg    = imagecolorallocate($im, 18, 22, 36);
        $fg    = imagecolorallocate($im, 220, 225, 240);
        $line  = imagecolorallocate($im, 90, 110, 170);
        $noise = imagecolorallocate($im, 170, 185, 220);

        // 干扰线：4 条斜线，比噪点更能阻挡简单 OCR
        for ($i = 0; $i < 4; $i++) {
            imageline(
                $im,
                mt_rand(0, $w), mt_rand(0, $h),
                mt_rand(0, $w), mt_rand(0, $h),
                $line
            );
        }
        // 噪点
        for ($i = 0; $i < 90; $i++) {
            imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), $noise);
        }

        // 字符
        $x = 14;
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            // 颜色微抖，避免脚本按单一颜色阈值二分
            $col = imagecolorallocate(
                $im,
                mt_rand(200, 240),
                mt_rand(210, 240),
                mt_rand(220, 245)
            );
            // 垂直抖动 0~6，水平轻微 1~2 抖动
            $y = 8 + mt_rand(0, 6);
            $dx = $x + mt_rand(0, 2);
            // 内置 5 号字（最大号），4 字符 120px 宽度刚好
            imagechar($im, 5, $dx, $y, $code[$i], $col);
            $x += 22;
        }

        // 边框
        imagerectangle($im, 0, 0, $w - 1, $h - 1, $fg);

        header('Content-Type: image/png');
        // 永远不要让浏览器/CDN 缓存：每次刷新都该是新题
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        imagepng($im);
        imagedestroy($im);
    }

    /**
     * 校验用户输入的验证码。
     * 成功：清空 session 中的答案（一次性）。
     * 失败：保留答案，允许用户更正；同时校验 TTL，过期一并清除。
     *
     * @return bool true 表示校验通过
     */
    public static function verify(string $input): bool
    {
        self::ensureSession();
        $expect = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        $ts     = (int) ($_SESSION[self::SESSION_TS] ?? 0);

        if ($expect === '' || $ts <= 0) {
            self::clear();
            return false;
        }

        // 过期
        if (time() - $ts > self::TTL) {
            self::clear();
            return false;
        }

        $u = strtoupper(trim($input));
        if ($u === '' || $u !== $expect) {
            // 答错不清除，让用户改完再提交（成功后才一次性清除）
            return false;
        }

        self::clear();
        return true;
    }

    /**
     * 主动强制刷新：清掉旧答案。
     * 通常前端要求"换一张"时由后端跨接口清掉，避免老答案继续可用。
     * 这里我们采用更简单策略：前端拿到新图就视为刷新，不主动调清。
     */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_TS]);
    }

    /** 生成答案字符串 */
    private static function generateCode(int $len): string
    {
        $max = strlen(self::CHARSET) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::CHARSET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * 输出「请稍后再试」占位图（仍为 PNG）。
     * 限流等场景下不再返回 JSON——<img> 标签无法渲染 JSON，会出现
     * 「验证码不显示」且没有任何提示；统一画一张占位图保证可见。
     */
    public static function renderBusy(string $text = '稍后再试'): void
    {
        if (!extension_loaded('gd')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'GD extension is required for captcha';
            return;
        }

        self::ensureSession();
        self::clear(); // 占位图不算有效题目，旧答案一并清掉

        $w = 120;
        $h = 38;
        $im = imagecreate($w, $h);
        imagecolorallocate($im, 26, 30, 46);              // 底色
        $fg   = imagecolorallocate($im, 210, 216, 235);
        $dim  = imagecolorallocate($im, 120, 128, 160);
        imagerectangle($im, 0, 0, $w - 1, $h - 1, $dim);
        // 居中输出（内置字体按 9px/字符估算）
        $x = max(4, (int) (($w - strlen($text) * 9) / 2));
        imagestring($im, 4, $x, 12, $text, $fg);

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        imagepng($im);
        imagedestroy($im);
    }
}
