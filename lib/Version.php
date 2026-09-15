<?php
/**
 * 客户端版本信息（渠道最新已发布版本）
 * ------------------------------------------------------------------
 * 数据来源优先级：
 *   1. nb_versions 表 —— 后台「版本管理」发布的最新一条（status = 1）
 *   2. config.php 的 version.* —— 兜底，用于尚未发布过版本的全新站点
 *
 * 客户端 init、客户端 version 与官网「下载」接口共用本类，
 * 避免同一段取值逻辑在三处各写一遍后逐渐漂移。
 */

class Version
{
    /**
     * 取某渠道最新的已发布版本信息。
     *
     * @return array{
     *   source:string, version:string, force_update:bool, download_url:string,
     *   changelog:string, file_hash:string, file_size:int
     * }
     */
    public static function latest(string $channel = 'stable'): array
    {
        $row = Database::one(
            'SELECT * FROM ' . Database::t('versions') . '
             WHERE channel = ? AND status = 1
             ORDER BY id DESC LIMIT 1',
            [$channel]
        );

        if ($row) {
            return [
                'source'       => 'db',
                'version'      => (string) $row['version'],
                'force_update' => (bool) $row['force_update'],
                'download_url' => (string) ($row['download_url'] ?? ''),
                'changelog'    => (string) ($row['changelog'] ?? ''),
                'file_hash'    => (string) ($row['file_hash'] ?? ''),
                'file_size'    => (int) ($row['file_size'] ?? 0),
            ];
        }

        return [
            'source'       => 'config',
            'version'      => (string) Config::get('version.latest_client_version', '1.0.0'),
            'force_update' => (bool) Config::get('version.force_update', false),
            'download_url' => (string) Config::get('version.update_url', ''),
            'changelog'    => (string) Config::get('version.update_note', ''),
            'file_hash'    => '',
            'file_size'    => 0,
        ];
    }

    /** 最低可用版本线（后台「系统设置」优先，未设置时回落 config.php） */
    public static function minClientVersion(): string
    {
        return (string) (Setting::get('min_client_version')
            ?: Config::get('version.min_client_version', '1.0.0'));
    }
}
