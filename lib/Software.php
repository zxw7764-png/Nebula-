<?php
/**
 * 软件（多应用）管理
 * ------------------------------------------------------------------
 * 一套验证系统同时服务多个客户端软件：
 *   · 每个软件有独立的 app_key（客户端请求携带，用于识别软件）
 *   · 每个软件有独立的 aes_key / sign_salt（通信密钥，可重置）
 *   · 每个软件有独立的版本策略（最低版本 / 最新版本 / 强更 / 下载地址）
 *   · 用户 / 卡密 / 批次 / 代理商 / 代理激活码 / 版本发布 / 会话均挂 software_id
 */

class Software
{
    /** 当前请求上下文命中的软件（null = 非客户端 API 上下文） */
    private static ?array $current = null;

    /**
     * 取当前请求上下文的软件行；未设置时返回 null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /** 当前软件 ID；无上下文返回 0 */
    public static function currentId(): int
    {
        return self::$current ? (int) self::$current['id'] : 0;
    }

    /** 设置当前上下文软件（仅 api/index.php 解析 app_key 后调用） */
    public static function setCurrent(array $sw): void
    {
        self::$current = $sw;
    }

    public static function table(): string
    {
        return Database::t('softwares');
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM ' . self::table() . ' WHERE id = ?', [$id]);
    }

    public static function byAppKey(string $appKey): ?array
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            return null;
        }
        return Database::one(
            'SELECT * FROM ' . self::table() . ' WHERE app_key = ? AND status = 1',
            [$appKey]
        );
    }

    /**
     * 解析当前软件：app_key 命中 → 对应软件；缺失/无效 → ID 最小的启用软件（兼容旧客户端）
     */
    public static function resolve(array $input): ?array
    {
        $appKey = '';
        foreach (['app_key', 'appkey', 'app'] as $k) {
            $v = $input[$k] ?? '';
            if (is_string($v) && trim($v) !== '') {
                $appKey = trim($v);
                break;
            }
        }
        $sw = self::byAppKey($appKey);
        if ($sw) {
            return $sw;
        }
        // 必须携带有效 app_key：未携带或无效一律返回 null，
        // 由调用方（api/index.php）统一报 1004 —— 不再回落默认软件。
        return null;
    }

    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM ' . self::table();
        if ($onlyActive) {
            $sql .= ' WHERE status = 1';
        }
        $sql .= ' ORDER BY id ASC';
        return Database::all($sql);
    }

    /** 下拉选项用：id => name */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $sw) {
            $out[] = ['id' => (int) $sw['id'], 'name' => (string) $sw['name'], 'status' => (int) $sw['status']];
        }
        return $out;
    }

    /** 生成 32 位 hex AES 密钥 */
    public static function genAesKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 生成 48 位 hex 签名盐 */
    public static function genSignSalt(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** 生成 app_key（客户端标识，如 SW-XXXXXXXX） */
    public static function genAppKey(): string
    {
        return 'SW' . strtoupper(bin2hex(random_bytes(6)));
    }

    /** 老库兼容：nb_softwares.login_methods 列是否存在（静态缓存） */
    private static function hasLoginCol(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::one("SHOW COLUMNS FROM " . self::table() . " LIKE 'login_methods'");
        }
        return $has;
    }

    /** 登录方式入参规范化：合法值原样返回，其余（含空）返回空串 = 跟随全局 */
    private static function normLoginMethod($v): string
    {
        $m = trim((string) $v);
        return isset(LoginMethod::all()[$m]) ? $m : '';
    }

    /** 老库兼容：nb_softwares.feature_key 列是否存在（静态缓存） */
    private static function hasFeatureCol(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::one("SHOW COLUMNS FROM " . self::table() . " LIKE 'feature_key'");
        }
        return $has;
    }

    /** 功能密钥入参规范化：去除首尾空白与控制字符，最长 128 字符（空 = 未启用） */
    private static function normFeatureKey($v): string
    {
        $k = preg_replace('/[\x00-\x1f\x7f]/u', '', trim((string) $v)) ?? '';
        return mb_substr($k, 0, 128);
    }

    /** 老库兼容：nb_softwares.policy_json 列是否存在（静态缓存） */
    private static function hasPolicyCol(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::one("SHOW COLUMNS FROM " . self::table() . " LIKE 'policy_json'");
        }
        return $has;
    }

    /** 策略覆盖布尔键（0/1）与整数键（>=0），与 lib/Policy.php 的读取键一致 */
    private const POLICY_BOOL_KEYS = ['register_enable', 'maintain_mode', 'single_login', 'geo_block', 'device_fp_enable', 'grace_enable'];
    private const POLICY_INT_KEYS  = ['heartbeat_interval', 'heartbeat_timeout', 'unbind_per_day', 'default_max_devices'];

    /**
     * 策略覆盖入参规范化：仅收白名单键；值为空串/null 的键视为「不覆盖」剔除；
     * 清洗后为空则返回 null（= 全部跟随全局，落库 NULL）。
     */
    private static function normPolicy($v): ?string
    {
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach (self::POLICY_BOOL_KEYS as $k) {
            if (array_key_exists($k, $v) && $v[$k] !== '' && $v[$k] !== null) {
                $out[$k] = (int) (bool) $v[$k];
            }
        }
        foreach (self::POLICY_INT_KEYS as $k) {
            if (array_key_exists($k, $v) && $v[$k] !== '' && $v[$k] !== null && is_numeric($v[$k])) {
                $out[$k] = max(0, (int) $v[$k]);
            }
        }
        if (array_key_exists('maintain_msg', $v)) {
            $msg = trim((string) $v['maintain_msg']);
            if ($msg !== '') {
                $out['maintain_msg'] = mb_substr($msg, 0, 200);
            }
        }
        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * 创建软件（密钥不传则自动生成）
     */
    public static function create(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            return ['ok' => false, 'msg' => '软件名称必填且不超过 64 字'];
        }

        $appKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['app_key'] ?? ''));
        if ($appKey === '') {
            $appKey = self::genAppKey();
        }
        if (Database::value('SELECT id FROM ' . self::table() . ' WHERE app_key = ?', [$appKey])) {
            return ['ok' => false, 'msg' => 'app_key 已存在'];
        }

        $aesKey  = preg_replace('/[^a-fA-F0-9]/', '', (string) ($in['aes_key'] ?? ''));
        $signSalt = preg_replace('/[^a-fA-F0-9]/', '', (string) ($in['sign_salt'] ?? ''));
        if ($aesKey === '') {
            $aesKey = self::genAesKey();
        }
        if (strlen($aesKey) !== 32) {
            return ['ok' => false, 'msg' => 'AES_KEY 必须是 32 位 hex'];
        }
        if ($signSalt === '') {
            $signSalt = self::genSignSalt();
        }
        if (strlen($signSalt) !== 48) {
            return ['ok' => false, 'msg' => 'SIGN_SALT 必须是 48 位 hex'];
        }

        $row = [
            'name'           => $name,
            'app_key'        => $appKey,
            'aes_key'        => $aesKey,
            'sign_salt'      => $signSalt,
            'min_version'    => trim((string) ($in['min_version'] ?? '')) ?: '1.0.0',
            'latest_version' => trim((string) ($in['latest_version'] ?? '')) ?: '1.0.0',
            'force_update'   => (int) (bool) ($in['force_update'] ?? 0),
            'update_url'     => trim((string) ($in['update_url'] ?? '')),
            'update_note'    => trim((string) ($in['update_note'] ?? '')),
            'status'         => (int) ($in['status'] ?? 1) === 0 ? 0 : 1,
            'remark'         => mb_substr(trim((string) ($in['remark'] ?? '')), 0, 250) ?: null,
            'created_at'     => time(),
            'updated_at'     => time(),
        ];
        // 分软件登录方式（老库无该列时跳过，默认空 = 跟随全局）
        if (self::hasLoginCol()) {
            $row['login_methods'] = self::normLoginMethod($in['login_methods'] ?? '');
        }
        // 功能密钥（老库无该列时跳过，默认空 = 未启用）
        if (self::hasFeatureCol()) {
            $row['feature_key'] = self::normFeatureKey($in['feature_key'] ?? '');
        }
        // 分软件策略覆盖（老库无该列时跳过，默认 NULL = 全部跟随全局）
        if (self::hasPolicyCol()) {
            $row['policy_json'] = self::normPolicy($in['policy'] ?? null);
        }

        $id = Database::insert('softwares', $row);

        Logger::log('software', 1, '创建软件 #' . $id . ' ' . $name);
        return ['ok' => true, 'msg' => '软件创建成功', 'id' => $id];
    }

    /**
     * 更新软件（app_key / 名称 / 版本策略 / 状态）
     * 密钥不在此修改 —— 必须走 resetKeys（防止误改导致客户端全体失联）
     */
    public static function update(int $id, array $in): array
    {
        $sw = self::find($id);
        if (!$sw) {
            return ['ok' => false, 'msg' => '软件不存在'];
        }

        $data = ['updated_at' => time()];

        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 64) {
                return ['ok' => false, 'msg' => '软件名称必填且不超过 64 字'];
            }
            $data['name'] = $name;
        }
        if (array_key_exists('app_key', $in)) {
            $appKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $in['app_key']);
            if ($appKey === '') {
                return ['ok' => false, 'msg' => 'app_key 不能为空'];
            }
            $dup = Database::value('SELECT id FROM ' . self::table() . ' WHERE app_key = ? AND id != ?', [$appKey, $id]);
            if ($dup) {
                return ['ok' => false, 'msg' => 'app_key 已被其他软件占用'];
            }
            $data['app_key'] = $appKey;
        }
        foreach (['min_version', 'latest_version', 'update_url', 'update_note'] as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = trim((string) $in[$f]);
            }
        }
        // 分软件登录方式：传空串 = 清除单独设置（跟随全局）；老库无该列时跳过
        if (array_key_exists('login_methods', $in) && self::hasLoginCol()) {
            $data['login_methods'] = self::normLoginMethod($in['login_methods']);
        }
        // 功能密钥：传空串 = 未启用；老库无该列时跳过
        if (array_key_exists('feature_key', $in) && self::hasFeatureCol()) {
            $data['feature_key'] = self::normFeatureKey($in['feature_key']);
        }
        // 分软件策略覆盖：传 policy 对象整体保存（空值键 = 移除该覆盖项）；
        // 全部为空时落 NULL = 恢复全部跟随全局
        if (array_key_exists('policy', $in) && self::hasPolicyCol()) {
            $data['policy_json'] = self::normPolicy($in['policy']);
        }
        if (array_key_exists('force_update', $in)) {
            $data['force_update'] = (int) (bool) $in['force_update'];
        }
        if (array_key_exists('status', $in)) {
            $data['status'] = (int) $in['status'] === 0 ? 0 : 1;
        }
        if (array_key_exists('remark', $in)) {
            $data['remark'] = mb_substr(trim((string) $in['remark']), 0, 250) ?: null;
        }

        Database::update('softwares', $data, 'id = :id', ['id' => $id]);
        Logger::log('software', 1, '修改软件 #' . $id);
        return ['ok' => true, 'msg' => '已保存'];
    }

    /**
     * 重置通信密钥（被破解后一键换钥，旧客户端立即失联）
     * aes_key / sign_salt 至少提供一个；传 'auto' 则自动生成
     */
    public static function resetKeys(int $id, string $aesKey = 'auto', string $signSalt = 'auto'): array
    {
        $sw = self::find($id);
        if (!$sw) {
            return ['ok' => false, 'msg' => '软件不存在'];
        }

        $data = ['updated_at' => time()];

        if ($aesKey !== '') {
            if ($aesKey === 'auto') {
                $aesKey = self::genAesKey();
            } else {
                $aesKey = preg_replace('/[^a-fA-F0-9]/', '', $aesKey);
                if (strlen($aesKey) !== 32) {
                    return ['ok' => false, 'msg' => 'AES_KEY 必须是 32 位 hex'];
                }
            }
            $data['aes_key'] = $aesKey;
        }
        if ($signSalt !== '') {
            if ($signSalt === 'auto') {
                $signSalt = self::genSignSalt();
            } else {
                $signSalt = preg_replace('/[^a-fA-F0-9]/', '', $signSalt);
                if (strlen($signSalt) !== 48) {
                    return ['ok' => false, 'msg' => 'SIGN_SALT 必须是 48 位 hex'];
                }
            }
            $data['sign_salt'] = $signSalt;
        }

        if (isset($data['aes_key']) || isset($data['sign_salt'])) {
            Database::update('softwares', $data, 'id = :id', ['id' => $id]);
            // 密钥已换：该软件所有会话级签名密钥作废，客户端需重新 init
            Database::exec('DELETE FROM ' . Database::t('sign_keys') . ' WHERE software_id = ?', [$id]);

            // 一并作废该软件的全部登录会话。
            // 换钥场景通常是客户端被逆向：只换钥而不清 token 的话，攻击者先前
            // 窃取的 token 在过期前仍可照常调用业务接口（换钥挡不住存量令牌）。
            // 老库 sessions.software_id 可能为 0（软件维度上线前登录的），这些
            // 历史数据归属首个软件，故重置首个软件时把它们一起清掉。
            $swIds = [$id];
            if ((int) Database::value('SELECT MIN(id) FROM ' . self::table()) === $id) {
                $swIds[] = 0;
            }
            Database::exec(
                'DELETE FROM ' . Database::t('sessions')
                . ' WHERE software_id IN (' . implode(',', array_fill(0, count($swIds), '?')) . ')',
                $swIds
            );

            Logger::log('software', 1, '重置软件密钥 #' . $id . ' ' . $sw['name'] . '（同时清空该软件会话）');
        }

        $fresh = self::find($id);
        return [
            'ok'        => true,
            'msg'       => '密钥已重置，该软件所有会话已失效，请更新客户端内置密钥后重新发布',
            'aes_key'   => $fresh['aes_key'],
            'sign_salt' => $fresh['sign_salt'],
        ];
    }

    /**
     * 平滑轮换通信密钥（宽限期双钥并行）
     * ------------------------------------------------------------------
     * 与 resetKeys（硬重置，旧客户端立即失联）不同：
     *   1. 旧钥存入 aes_key_prev / sign_salt_prev，新客户端用新钥、
     *      未升级的老客户端在宽限期内仍可正常通信（服务端自动回落旧钥验签）；
     *   2. 不删除会话 / 会话签名密钥 —— 在线用户无感知；
     *   3. 宽限期（security.key_grace_days，默认 7 天）过后旧钥自动失效，
     *      到期未升级的客户端需重新 init。
     *
     * 适用：例行密钥轮换、怀疑密钥泄露但希望平滑过渡。
     * 被确认破解需立刻掐断时仍应使用 resetKeys。
     */
    public static function rotateKeysGraceful(int $id): array
    {
        $sw = self::find($id);
        if (!$sw) {
            return ['ok' => false, 'msg' => '软件不存在'];
        }
        if ((string) $sw['aes_key'] === '') {
            return ['ok' => false, 'msg' => '该软件尚未配置通信密钥，请先在编辑中设置'];
        }

        Database::update('softwares', [
            'aes_key_prev'    => (string) $sw['aes_key'],
            'sign_salt_prev'  => (string) $sw['sign_salt'],
            'keys_rotated_at' => time(),
            'aes_key'         => self::genAesKey(),
            'sign_salt'       => self::genSignSalt(),
            'updated_at'      => time(),
        ], 'id = :id', ['id' => $id]);

        Logger::log('software', 1, '平滑轮换软件密钥 #' . $id . ' ' . $sw['name']
            . '（宽限期 ' . (int) Config::get('security.key_grace_days', 7) . ' 天，老客户端无感知）');

        $fresh = self::find($id);
        return [
            'ok'             => true,
            'msg'            => '密钥已平滑轮换：新客户端请内置新钥发布；老客户端宽限期内不受影响',
            'aes_key'        => $fresh['aes_key'],
            'sign_salt'      => $fresh['sign_salt'],
            'grace_days'     => (int) Config::get('security.key_grace_days', 7),
            'rotated_at'     => (int) $fresh['keys_rotated_at'],
        ];
    }

    /**
     * 取该软件的轮换旧钥（宽限期内有效，过期返回 null）
     * @return array|null {aes_key, sign_salt}
     */
    public static function prevKeys(array $sw): ?array
    {
        $aes = (string) ($sw['aes_key_prev'] ?? '');
        $salt = (string) ($sw['sign_salt_prev'] ?? '');
        if ($aes === '' && $salt === '') {
            return null;
        }
        $rotatedAt = (int) ($sw['keys_rotated_at'] ?? 0);
        $graceDays = max(0, (int) Config::get('security.key_grace_days', 7));
        if ($graceDays === 0 || $rotatedAt <= 0 || (time() - $rotatedAt) > $graceDays * 86400) {
            return null; // 宽限期已过（或配置为 0 = 不启用宽限）
        }
        return ['aes_key' => $aes, 'sign_salt' => $salt];
    }

    public static function delete(int $id): array
    {
        $sw = self::find($id);
        if (!$sw) {
            return ['ok' => false, 'msg' => '软件不存在'];
        }
        $cnt = (int) Database::value(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE id != ? AND status = 1',
            [$id]
        );
        if ($cnt === 0) {
            return ['ok' => false, 'msg' => '至少保留一个启用的软件'];
        }
        Database::exec('DELETE FROM ' . self::table() . ' WHERE id = ?', [$id]);
        Database::exec('DELETE FROM ' . Database::t('sign_keys') . ' WHERE software_id = ?', [$id]);
        Logger::log('software', 1, '删除软件 #' . $id . ' ' . $sw['name']);
        return ['ok' => true, 'msg' => '已删除'];
    }

    /**
     * 版本信息（优先 nb_versions 发布记录，回落软件自身配置，再回落全局配置）
     */
    public static function versionInfo(array $sw, string $channel = 'stable'): array
    {
        // 取版本号最大的一条已发布记录：按版本号语义比较而非插入顺序，
        // 避免「后补发的低版本插在前面」导致误判最新版
        $rows = Database::all(
            'SELECT * FROM ' . Database::t('versions') . '
             WHERE software_id = ? AND channel = ? AND status = 1
             LIMIT 500',
            [(int) $sw['id'], $channel]
        );
        $row = self::newestByVersion($rows);
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
        // 回落（软件表默认值）：force_update 恒为 false——强制更新只由「版本管理」
        // 的发版记录控制，否则下架版本后软件表里的存量开关还会继续拦截登录
        return [
            'source'       => 'software',
            'version'      => (string) ($sw['latest_version'] ?: Config::get('version.latest_client_version', '1.0.0')),
            'force_update' => false,
            'download_url' => (string) ($sw['update_url'] ?: Config::get('version.update_url', '')),
            'changelog'    => (string) ($sw['update_note'] ?: Config::get('version.update_note', '')),
            'file_hash'    => '',
            'file_size'    => 0,
        ];
    }

    /**
     * 取某软件某渠道的历史版本列表（客户端「更新日志」展示用）。
     * 只返回已发布且填写了 changelog 的记录，按版本号倒序。
     */
    public static function changelogList(array $sw, string $channel = 'stable', int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $rows  = Database::all(
            'SELECT version, channel, changelog, force_update, download_url, file_hash, file_size, created_at'
            . ' FROM ' . Database::t('versions')
            . " WHERE software_id = ? AND channel = ? AND status = 1 AND changelog IS NOT NULL AND changelog <> ''"
            . ' LIMIT 500',
            [(int) $sw['id'], $channel]
        );

        // 语义化排序需在 PHP 侧完成（MySQL 无法按版本号逐段比较）
        usort($rows, static fn($a, $b) => Util::versionCompare((string) $b['version'], (string) $a['version']));
        $rows = array_slice($rows, 0, $limit);

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'version'      => (string) $r['version'],
                'channel'      => (string) $r['channel'],
                'changelog'    => (string) $r['changelog'],
                'force_update' => (int) $r['force_update'],
                'download_url' => (string) ($r['download_url'] ?? ''),
                'file_hash'    => (string) ($r['file_hash'] ?? ''),
                'file_size'    => (int) ($r['file_size'] ?? 0),
                'created_at'   => (int) ($r['created_at'] ?? 0),
            ];
        }
        return $list;
    }

    /** 从版本记录中挑出版本号最大的一条（版本号逐段语义比较） */
    private static function newestByVersion(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $r) {
            if ($best === null || Util::versionCompare((string) $r['version'], (string) $best['version']) > 0) {
                $best = $r;
            }
        }
        return $best;
    }

    /**
     * 指定版本号的已发布记录（客户端完整性自校验用）：
     * 客户端启动时按自身 client_ver 取该版本登记的 file_hash / file_size 与 exe 实际值比对，
     * 不一致即判定被篡改。版本未登记 / 未发布 / 未填哈希时返回 null（客户端跳过校验）。
     */
    public static function releaseOf(array $sw, string $version, string $channel = 'stable'): ?array
    {
        if ($version === '') {
            return null;
        }
        return Database::one(
            'SELECT file_hash, file_size FROM ' . Database::t('versions') . '
             WHERE software_id = ? AND channel = ? AND version = ? AND status = 1
             ORDER BY id DESC LIMIT 1',
            [(int) $sw['id'], $channel, $version]
        );
    }

    /** 该软件的最低版本线（软件配置优先，回落全局设置） */    public static function minVersion(array $sw): string
    {
        $v = trim((string) ($sw['min_version'] ?? ''));
        if ($v !== '') {
            return $v;
        }
        return (string) (Setting::get('min_client_version')
            ?: Config::get('version.min_client_version', '1.0.0'));
    }
}
