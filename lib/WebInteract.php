<?php
/**
 * 官网互动功能 · 共享领域逻辑
 * ------------------------------------------------------------------
 * 留言板 / 用户反馈 / 价格套餐 / 客户端截图的**数据读取与文案**，
 * 被两个入口共用：
 *   1. 官网用户端  web/api.php  （用户视角：只看已审核的自己的数据）
 *   2. 管理后台    <admin>/handlers/*.php  （管理视角：看全部、可改状态）
 *
 * 之所以放在 lib/ 而不是 web/inc/portal.php：
 *   portal.php 只在官网入口被 require，后台入口不会加载它。
 *   如果把这些函数写在 portal.php，后台 handler 调用时会直接 Fatal。
 *
 * 本文件由 lib/bootstrap.php 加载，两个入口都可用。
 */

class WebInteract
{
    /** 留言审核状态 */
    const MSG_PENDING = 0;
    const MSG_PASSED  = 1;
    const MSG_REJECT  = 2;

    /** 反馈处理状态 */
    const FB_PENDING = 0;
    const FB_DOING   = 1;
    const FB_REPLIED = 2;
    const FB_CLOSED  = 3;

    /**
     * 表是否已建。
     * 老站可能没跑过 migrate_web_interact.php，此时官网应降级为「暂无内容」
     * 而不是整页 500。结果按请求缓存，避免每次调用都 SHOW TABLES。
     */
    public static function tableReady(string $suffix): bool
    {
        static $cache = [];
        if (array_key_exists($suffix, $cache)) {
            return $cache[$suffix];
        }
        try {
            $table  = Database::t($suffix);
            $exists = Database::one('SHOW TABLES LIKE ' . Database::pdo()->quote(trim($table, '`')));
            $cache[$suffix] = (bool) $exists;
        } catch (Throwable $e) {
            $cache[$suffix] = false;
        }
        return $cache[$suffix];
    }

    /**
     * 表是否有 software_id 列（分软件归属）。
     * 老库未跑 migrate_web_software_id.php 时返回 false，读取/写入自动跳过软件维度，
     * 行为与旧版一致。结果按请求缓存。
     */
    public static function hasSwCol(string $suffix): bool
    {
        static $cache = [];
        if (array_key_exists($suffix, $cache)) {
            return $cache[$suffix];
        }
        $cache[$suffix] = self::tableReady($suffix)
            && (bool) Database::one("SHOW COLUMNS FROM " . Database::t($suffix) . " LIKE 'software_id'");
        return $cache[$suffix];
    }

    /**
     * 拼接软件过滤片段（software_id：0=通用/全部软件，N=仅该软件）。
     * $softwareId<=0 或无该列时返回空串（不过滤，保持旧版行为）。
     *
     * @return array{0: string, 1: array} [SQL 片段（含 AND 前缀）, 参数]
     */
    public static function swFilter(string $suffix, int $softwareId): array
    {
        if ($softwareId > 0 && self::hasSwCol($suffix)) {
            return [' AND (software_id = 0 OR software_id = ?)', [$softwareId]];
        }
        return ['', []];
    }

    // --------------------------------------------------------------
    // 文案
    // --------------------------------------------------------------

    public static function messageStatusText(int $status): string
    {
        return [self::MSG_PENDING => '待审核', self::MSG_PASSED => '已通过', self::MSG_REJECT => '已驳回'][$status] ?? '未知';
    }

    public static function feedbackStatusText(int $status): string
    {
        return [
            self::FB_PENDING => '待处理',
            self::FB_DOING   => '处理中',
            self::FB_REPLIED => '已回复',
            self::FB_CLOSED  => '已关闭',
        ][$status] ?? '未知';
    }

    /** 反馈类型：前后端共用一份，避免两处硬编码漂移 */
    public static function feedbackTypes(): array
    {
        return [
            1 => '功能建议',
            2 => '问题反馈',
            3 => '卡密/订单',
            4 => '其他',
        ];
    }

    /** 反馈类型选项（接口下发用） */
    public static function feedbackTypeOptions(): array
    {
        $out = [];
        foreach (self::feedbackTypes() as $k => $v) {
            $out[] = ['value' => $k, 'label' => $v];
        }
        return $out;
    }

    // --------------------------------------------------------------
    // 留言板（用户端）
    // --------------------------------------------------------------

    /**
     * 留言板列表（仅返回已审核通过的）
     * 返回「主楼 + 其下回复」两层结构。
     *
     * @param int     $page       页码（从 1 起）
     * @param int     $perPage    每页主楼条数
     * @param int     $uid        当前登录用户 ID（用于标记 liked / mine）
     * @param int|null $softwareId 当前官网软件 ID（>0 时只看 通用+该软件；null/0=不过滤）
     */
    public static function messageList(int $page = 1, int $perPage = 10, int $uid = 0, ?int $softwareId = null): array
    {
        if (!self::tableReady('messages')) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        $tbl     = Database::t('messages');
        $page    = max(1, $page);
        $perPage = min(30, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        [$swFrag, $swArgs] = self::swFilter('messages', (int) $softwareId);

        $total = (int) (Database::one(
            "SELECT COUNT(*) AS c FROM {$tbl} WHERE status = 1 AND parent_id = 0{$swFrag}",
            $swArgs
        )['c'] ?? 0);

        $rows = Database::all(
            "SELECT * FROM {$tbl} WHERE status = 1 AND parent_id = 0{$swFrag}
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $swArgs
        );

        $pages = max(1, (int) ceil($total / $perPage));
        if (!$rows) {
            return ['list' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];
        }

        // 一次取回本页所有主楼的回复，避免 N+1 查询
        $ids  = array_column($rows, 'id');
        $hold = implode(',', array_fill(0, count($ids), '?'));
        $reps = Database::all(
            "SELECT * FROM {$tbl} WHERE status = 1 AND parent_id IN ({$hold}){$swFrag} ORDER BY id ASC",
            array_merge($ids, $swArgs)
        );

        $replyMap = [];
        foreach ($reps as $r) {
            $replyMap[(int) $r['parent_id']][] = $r;
        }

        // 当前用户点过赞的留言
        $liked = [];
        if ($uid > 0 && self::tableReady('message_likes')) {
            try {
                $likedRows = Database::all(
                    'SELECT message_id FROM ' . Database::t('message_likes') . '
                     WHERE user_id = ? AND message_id IN (' . $hold . ')',
                    array_merge([$uid], $ids)
                );
                $liked = array_map('intval', array_column($likedRows, 'message_id'));
            } catch (Throwable $e) {
                $liked = [];
            }
        }

        $fmt = static function (array $m) use ($liked, $uid): array {
            return [
                'id'         => (int) $m['id'],
                'username'   => $m['username'],
                'content'    => $m['content'],
                'likes'      => (int) $m['likes'],
                'reply_to'   => (string) ($m['reply_to'] ?? ''),
                'mine'       => $uid > 0 && (int) $m['user_id'] === $uid,
                'liked'      => in_array((int) $m['id'], $liked, true),
                'created_at' => Util::date((int) $m['created_at']),
            ];
        };

        $list = [];
        foreach ($rows as $m) {
            $item            = $fmt($m);
            $item['replies'] = array_map($fmt, $replyMap[(int) $m['id']] ?? []);
            $list[]          = $item;
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // --------------------------------------------------------------
    // 反馈（用户端）
    // --------------------------------------------------------------

    /** 某用户的反馈列表（只返回自己的；$softwareId>0 时仅看 通用+该软件） */
    public static function feedbackList(int $uid, ?int $softwareId = null): array
    {
        if ($uid <= 0 || !self::tableReady('feedbacks')) {
            return [];
        }
        [$swFrag, $swArgs] = self::swFilter('feedbacks', (int) $softwareId);
        $rows = Database::all(
            'SELECT * FROM ' . Database::t('feedbacks') . ' WHERE user_id = ?' . $swFrag . ' ORDER BY id DESC LIMIT 50',
            array_merge([$uid], $swArgs)
        );

        $types = self::feedbackTypes();
        return array_map(static function (array $f) use ($types) {
            $status = (int) $f['status'];
            $shown  = $status === WebInteract::FB_REPLIED || $status === WebInteract::FB_CLOSED;
            return [
                'id'          => (int) $f['id'],
                'type'        => (int) $f['type'],
                'type_text'   => $types[(int) $f['type']] ?? '其他',
                'title'       => $f['title'],
                'content'     => $f['content'],
                'contact'     => (string) $f['contact'],
                'status'      => $status,
                'status_text' => WebInteract::feedbackStatusText($status),
                // 未回复前不下发 reply 字段内容，避免前端误显示空回复
                'reply'       => $shown ? (string) $f['reply'] : '',
                'reply_admin' => $shown ? (string) $f['reply_admin'] : '',
                'replied_at'  => (int) $f['replied_at'] > 0 ? Util::date((int) $f['replied_at']) : '',
                'created_at'  => Util::date((int) $f['created_at']),
            ];
        }, $rows);
    }

    // --------------------------------------------------------------
    // 价格套餐 / 截图（用户端）
    // --------------------------------------------------------------

    /** 官网价格套餐（仅启用的，按 sort 倒序） */
    public static function planList(?int $softwareId = null): array
    {
        if (!self::tableReady('plans')) {
            return [];
        }
        $table = Database::t('plans');
        // 官网套餐按软件区分展示（web_software_id：0=全部软件通用，N=仅该软件官网）。
        // 老库未跑迁移（无该列）时跳过过滤，行为与旧版一致。
        $swFilter = '';
        $args = [];
        if ($softwareId !== null && $softwareId > 0
            && (bool) Database::one("SHOW COLUMNS FROM {$table} LIKE 'web_software_id'")) {
            $swFilter = ' AND (web_software_id = 0 OR web_software_id = ?)';
            $args[] = $softwareId;
        }
        $rows = Database::all(
            'SELECT id, name, price, unit, duration, `desc`, badge, highlight
             FROM ' . $table . ' WHERE status = 1' . $swFilter . ' ORDER BY sort DESC, id ASC',
            $args
        );

        return array_map(static function (array $p) {
            // desc 一行一条，前端拆成要点列表渲染
            $points = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $p['desc'])),
                static fn ($s) => $s !== ''
            ));

            return [
                'id'        => (int) $p['id'],
                'name'      => $p['name'],
                'price'     => $p['price'],
                'unit'      => $p['unit'],
                'duration'  => $p['duration'],
                'points'    => $points,
                'badge'     => (string) $p['badge'],
                'highlight' => (int) $p['highlight'] === 1,
            ];
        }, $rows);
    }

    /** 官网客户端截图（仅启用的），只放行 http/https；$softwareId>0 时仅看 通用+该软件 */
    public static function screenshotList(?int $softwareId = null): array
    {
        if (!self::tableReady('screenshots')) {
            return [];
        }
        [$swFrag, $swArgs] = self::swFilter('screenshots', (int) $softwareId);
        $rows = Database::all(
            'SELECT id, title, url FROM ' . Database::t('screenshots') . '
             WHERE status = 1' . $swFrag . ' ORDER BY sort DESC, id ASC LIMIT 12',
            $swArgs
        );

        $out = [];
        foreach ($rows as $s) {
            // 与后台保存侧同口径：放行 http/https 外链或站内上传路径（/uploads/...），挡掉 javascript:/data: 等伪协议
            if (!preg_match('#^(https?://|/)#i', (string) $s['url'])) {
                continue;
            }
            $out[] = [
                'id'    => (int) $s['id'],
                'title' => (string) $s['title'],
                'url'   => (string) $s['url'],
            ];
        }
        return $out;
    }

    /**
     * 官网购买商家（仅启用的，按 sort 倒序）
     * logo / url 只放行 http/https，与 screenshotList 同口径，
     * 挡掉 javascript:/data: 等伪协议，避免官网被塞恶意链接。
     */
    public static function sellerList(?int $softwareId = null): array
    {
        if (!self::tableReady('sellers')) {
            return [];
        }
        [$swFrag, $swArgs] = self::swFilter('sellers', (int) $softwareId);
        $rows = Database::all(
            'SELECT id, name, logo, `desc`, contact, url, badge, highlight
             FROM ' . Database::t('sellers') . ' WHERE status = 1' . $swFrag . ' ORDER BY sort DESC, id ASC',
            $swArgs
        );

        $out = [];
        foreach ($rows as $s) {
            // desc 一行一条，前端拆成要点列表渲染
            $points = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $s['desc'])),
                static fn ($x) => $x !== ''
            ));

            $out[] = [
                'id'        => (int) $s['id'],
                'name'      => (string) $s['name'],
                'logo'      => preg_match('#^(https?://|/)#i', (string) $s['logo']) ? (string) $s['logo'] : '',
                'points'    => $points,
                'contact'   => (string) $s['contact'],
                'url'       => preg_match('#^https?://#i', (string) $s['url']) ? (string) $s['url'] : '',
                'badge'     => (string) $s['badge'],
                'highlight' => (int) $s['highlight'] === 1,
            ];
        }
        return $out;
    }
}
