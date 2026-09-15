<?php
/**
 * TOTP 动态验证码（RFC 6238）
 * ------------------------------------------------------------------
 * 纯 PHP 实现，不依赖任何扩展或第三方库（只用 hash_hmac，openssl 不需要）。
 * 与 Google Authenticator / Microsoft Authenticator / 1Password / Authy
 * 等标准验证器兼容：SHA1 + 6 位 + 30 秒步长。
 *
 * 密钥用 Base32 表示（验证器只认 Base32），恢复码是另外一套一次性码，
 * 用于手机丢失时登录（由 AdminAuth 负责存储与核销）。
 */
class Totp
{
    /** RFC 4648 Base32 字母表（去掉易混的 0/1/8/9） */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 步长（秒） */
    private const PERIOD = 30;

    /** 生成随机密钥（默认 20 字节 = 160 bit，Base32 后 32 字符） */
    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * 计算某个时间片的 6 位验证码
     * @param int|null $slice 时间片序号（默认当前），便于校验相邻时间片
     */
    public static function code(string $secret, ?int $slice = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $slice = $slice ?? self::slice();
        if ($slice < 0) {
            $slice = 0;
        }
        // 8 字节大端时间戳：高 4 字节恒为 0，低 4 字节放时间片
        $msg  = pack('N*', 0) . pack('N*', $slice);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off  = ord($hash[19]) & 0x0F;
        $part = ((ord($hash[$off]) & 0x7F) << 24)
              | ((ord($hash[$off + 1]) & 0xFF) << 16)
              | ((ord($hash[$off + 2]) & 0xFF) << 8)
              |  (ord($hash[$off + 3]) & 0xFF);
        return str_pad((string) ($part % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 校验验证码
     * @param int $window 允许前后各多少个时间片（默认 1 = 前后 30 秒，容忍时钟偏差）
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== 6 || $secret === '') {
            return false;
        }
        $now = self::slice();
        for ($i = -$window; $i <= $window; $i++) {
            $expect = self::code($secret, $now + $i);
            if ($expect !== '' && hash_equals($expect, $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// URI —— 验证器扫码/手动导入用（本项目的二维码由前端生成） */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }

    /** 生成一批一次性恢复码（返回明文，展示给管理员；库里只存摘要） */
    public static function recoveryCodes(int $count = 8): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = strtoupper(bin2hex(random_bytes(5))); // 10 位十六进制
        }
        return $out;
    }

    /** 当前时间片序号 */
    private static function slice(): int
    {
        return intdiv(time(), self::PERIOD);
    }

    private static function base32Encode(string $raw): string
    {
        $out  = '';
        $bits = 0;
        $val  = 0;
        $len  = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $val   = ($val << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::ALPHABET[($val >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($val << (5 - $bits)) & 31];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $map = array_flip(str_split(self::ALPHABET)); // 字符 => 5bit 值
        $out  = '';
        $bits = 0;
        $val  = 0;
        $len  = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $c = $b32[$i];
            if (!isset($map[$c])) {
                continue;
            }
            $val   = ($val << 5) | $map[$c];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($val >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}
