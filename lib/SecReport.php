<?php
/**
 * 每日安全审计报告
 * ------------------------------------------------------------------
 * 由 cron 触发（默认每日一次，文件标记节流），扫描最近 24 小时：
 *   1. 暴力破解嫌疑  —— 同 IP 登录失败次数超阈值
 *   2. 撞库嫌疑      —— 同账号登录失败次数超阈值
 *   3. 密钥重置追踪  —— 通信密钥重置操作（应急动作需人工确认）
 *   4. 代理商业绩异常 —— 代理商短时间生成卡密量突增（阈值可配）
 *
 * 产出：
 *   - logs/sec_report_<date>.txt（仅有异常时写）
 *   - nb_logs 一条汇总（sec_report，result=0/1）
 *   - 返回值供 cron 打印
 *
 * 设计原则：只读巡检，绝不阻断；查询失败降级为跳过该项。
 */

class SecReport
{
    /** 报告窗口（秒） */
    public const WINDOW = 86400;

    public static function run(bool $persist = true): array
    {
        $since = time() - self::WINDOW;
        $findings = [];

        // 1. 暴力破解嫌疑（同 IP）
        $thI = max(5, (int) Config::get('security.bruteforce_ip_threshold', 10));
        $rows = self::q(
            'SELECT ip, COUNT(*) c FROM ' . Database::t('logs')
            . " WHERE action = 'login' AND result = 0 AND created_at >= ? AND ip <> ''"
            . ' GROUP BY ip HAVING c >= ' . $thI . ' ORDER BY c DESC LIMIT 10',
            [$since]
        );
        if ($rows) {
            $findings[] = ['level' => 'warn', 'name' => '暴力破解嫌疑（同 IP 登录失败 ≥ ' . $thI . '）', 'items' => $rows];
        }

        // 2. 撞库嫌疑（同账号）
        $thU = max(5, (int) Config::get('security.bruteforce_user_threshold', 10));
        $rows = self::q(
            'SELECT username, COUNT(*) c FROM ' . Database::t('logs')
            . " WHERE action = 'login' AND result = 0 AND created_at >= ?"
            . " AND username IS NOT NULL AND username <> ''"
            . ' GROUP BY username HAVING c >= ' . $thU . ' ORDER BY c DESC LIMIT 10',
            [$since]
        );
        if ($rows) {
            $findings[] = ['level' => 'warn', 'name' => '撞库嫌疑（同账号登录失败 ≥ ' . $thU . '）', 'items' => $rows];
        }

        // 3. 密钥重置追踪（管理端应急动作）
        $rows = self::q(
            'SELECT admin_name, action_text, target, ip, created_at FROM ' . Database::t('audit_logs')
            . " WHERE action = 'software_reset_keys' AND created_at >= ? ORDER BY created_at DESC LIMIT 10",
            [$since]
        );
        if ($rows) {
            $findings[] = ['level' => 'info', 'name' => '通信密钥重置（24h 内）', 'items' => $rows];
        }

        // 4. 代理商生成卡密突增
        $thA = max(50, (int) Config::get('security.agent_bulk_threshold', 500));
        try {
            $rows = Database::all(
                'SELECT agent_id, COUNT(*) c FROM ' . Database::t('cards')
                . ' WHERE created_at >= ? AND agent_id > 0 GROUP BY agent_id HAVING c >= ' . $thA
                . ' ORDER BY c DESC LIMIT 10',
                [$since]
            );
            if ($rows) {
                $names = Agent::nameMap();
                foreach ($rows as &$r0) {
                    $r0['agent'] = $names[(int) $r0['agent_id']] ?? ('#' . $r0['agent_id']);
                }
                unset($r0);
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        if ($rows) {
            $findings[] = ['level' => 'warn', 'name' => '代理商生成卡密突增（≥ ' . $thA . ' 张/24h）', 'items' => $rows];
        }

        // 汇总输出
        $level = 'ok';
        foreach ($findings as $f) {
            if ($f['level'] === 'warn') {
                $level = 'warn';
            }
        }
        $summary = $findings
            ? '发现 ' . count($findings) . ' 类异常（' . implode('；', array_column($findings, 'name')) . '）'
            : '近 24 小时无异常';

        $report = [
            'date'     => date('Y-m-d H:i:s'),
            'level'    => $level,
            'summary'  => $summary,
            'findings' => $findings,
        ];

        // 有异常：写报告文件 + 日志（仅 cron 持久化路径；后台实时查看传 persist=false
        // 只读不落盘，否则每次打开页面都会往报告文件追加一条重复记录）
        if ($persist && $findings) {
            $file = NB_ROOT . '/logs/sec_report_' . date('Y-m-d') . '.txt';
            @file_put_contents(
                $file,
                self::render($report),
                FILE_APPEND
            );
            Logger::log('sec_report', $level === 'warn' ? 0 : 1, '安全审计：' . $summary);
        }

        return $report;
    }

    /** 报告文本渲染 */
    public static function render(array $r): string
    {
        $t = '[' . $r['date'] . "] 安全审计报告（" . ($r['level'] === 'warn' ? '有异常' : '提示') . "）\n  "
            . $r['summary'] . "\n";
        foreach ($r['findings'] as $f) {
            $t .= '  [' . $f['level'] . '] ' . $f['name'] . "\n";
            foreach (array_slice($f['items'], 0, 10) as $row) {
                $parts = [];
                foreach ($row as $k => $v) {
                    if (is_int($k)) {
                        continue;
                    }
                    $val = ($k === 'created_at') ? date('m-d H:i', (int) $v) : (string) $v;
                    $parts[] = $k . '=' . $val;
                }
                $t .= '    · ' . implode('  ', $parts) . "\n";
            }
        }
        return $t . "\n";
    }

    /** 查询封装：失败降级为空 */
    private static function q(string $sql, array $params): array
    {
        try {
            return Database::all($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }
}
