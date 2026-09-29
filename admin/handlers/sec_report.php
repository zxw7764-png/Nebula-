<?php
/**
 * admin action: sec_report
 * 安全巡检报告：查看最新报告 + 可手动触发立即巡检（audit.read 权限）
 */

$run = (bool) Util::int($input, 'run', 0);

// 最新一次巡检结果（实时跑，查询均降级安全）
$report = SecReport::run();

// 历史报告文件（logs/sec_report_*.txt，仅异常时写入），取最近 5 个的尾部
$history = [];
$files = glob(NB_ROOT . '/logs/sec_report_*.txt');
if ($files) {
    sort($files);
    foreach (array_slice($files, -5) as $f) {
        $content = (string) @file_get_contents($f);
        if ($content !== '') {
            // 同一天多次追加，只取末尾一段
            $segs = preg_split('/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
            $history[] = [
                'file' => basename($f),
                'time' => date('Y-m-d H:i', (int) @filemtime($f)),
                'text' => mb_substr(end($segs) ?: $content, 0, 2000),
            ];
        }
    }
    $history = array_reverse($history);
}

Response::ok([
    'report'  => $report,
    'text'    => SecReport::render($report),
    'history' => $history,
    'ran'     => $run ? '手动触发' : '页面加载巡检',
]);
