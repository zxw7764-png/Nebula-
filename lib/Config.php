<?php
/**
 * 配置读取（静态封装）
 */
class Config
{
    private static array $data = [];

    public static function load(array $cfg): void
    {
        self::$data = $cfg;
    }

    /** 点号路径读取，如 Config::get('db.host') */
    public static function get(string $path, $default = null)
    {
        $node = self::$data;
        foreach (explode('.', $path) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                return $default;
            }
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
