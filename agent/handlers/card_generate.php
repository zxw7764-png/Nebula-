<?php
// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: card_generate
 * 代理商生成卡密
 * ------------------------------------------------------------------
 * 流程：按「该卡类型」的额度/单价预扣 → 复用 Card::generate 生成并归属到本代理
 *       → 失败则补偿退回
 * 计费（agents.charge_mode）：
 *   1 张数额度：扣该卡类型的 quota_used（该类型 quota_total = -1 时不限）
 *   2 余额计费：扣该卡类型的单价 × 张数
 *   3 不限量  ：不扣减
 * 注意：设备上限由代理档案（激活码）固定；激活用户组按「卡类型」取
 *       （该类型专属组 → 代理默认组 → 不换组）；
 *       卡密前缀在代理档案上被固定时同样强制使用 —— 提交的同名字段一律忽略
 */

$agentId = (int) $agent['id'];

$count = max(1, min(500, Util::int($input, 'count', 10)));
$type  = Util::int($input, 'type', Card::TYPE_DURATION);

if (!in_array($type, Card::TYPE_LIST, true)) {
    Response::error(1001, '卡密类型不正确');
}

// 时长卡：优先 duration_sec（秒，新前端按「天/小时/分钟」换算后提交）；
// 未提交该字段则按「天」兼容旧前端。其余类型按原始数值。
$rawDur  = (int) Util::get($input, 'duration', 0);
$duration = 0;
if ($type === Card::TYPE_DURATION) {
    $durationSec = Util::get($input, 'duration_sec', null);
    $duration = ($durationSec !== null && (int) $durationSec > 0)
        ? (int) $durationSec
        : $rawDur * 86400;
} else {
    $duration = $rawDur;
}
if ($type !== Card::TYPE_FOREVER && $duration <= 0) {
    Response::error(1001, '时长 / 点数必须大于 0');
}

// 卡密前缀：代理档案上被固定时强制使用（提交值一律忽略），否则用提交值
$fixedPrefix = Agent::cardPrefix($agent);
if ($fixedPrefix !== '') {
    $prefix = $fixedPrefix;
} else {
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', Util::str($input, 'prefix', ''));
    $prefix = substr($prefix, 0, 8);
}
$expireDays = max(0, min(3650, Util::int($input, 'expire_days', 0)));
$name       = mb_substr(Util::str($input, 'name', ''), 0, 60);
$remark     = mb_substr(Util::str($input, 'remark', ''), 0, 100);

// 代理生成限流：每分钟最多 10 次生成、每天最多 100 次
if (!RateLimit::hit('agentgen:' . $agentId, 10, 60)) {
    Response::error(5001, '生成过于频繁，请稍后再试');
}
if (!RateLimit::hit('agentgen_day:' . $agentId, 100, 86400)) {
    Response::error(5001, '今日生成次数已达上限');
}

// ------------------------------------------------------------------
// 预扣 + 生成：放在同一个事务里（P1-05）
// ------------------------------------------------------------------
// 修复前的问题：charge()（预扣）与 Card::generate() 各自独立提交，
// 中间靠 refund() 补偿。若进程在两者之间被杀（超时/OOM/网络中断），
// 预扣已落库而卡密没生成，代理的额度或余额就凭空消失了 —— 补偿逻辑
// 只在「代码正常走到 refund」时有效，进程死亡时根本不会执行。
//
// 现在把三步（扣款 → 生成 → 记账）包进同一个事务：
//   · 任一步抛异常 → 整笔回滚，代理额度分文不动，也不再需要 refund 兜底
//   · Card::generate() 内部自带事务，Database 的嵌套计数会把它扁平化，
//     内层 commit 不会提前提交外层
// ------------------------------------------------------------------
Database::begin();
try {
    $charge = Agent::charge($agent, $count, $type);
    if (!$charge['ok']) {
        Database::rollback();
        Agent::log($agentId, 'generate', Card::typeName($type) . ' 生成失败：' . $charge['msg']);
        Response::error($charge['code'], $charge['msg']);
    }

    // 激活后进入的用户组：优先该卡类型的专属组，其次代理档案默认组，最后 0=不换组
    $typeGroupId = Agent::typeGroupId($agent, $type);

    $r = Card::generate([
        // 代理归属软件：生成的卡密只能在该软件使用（前端提交值不生效）
        'software_id' => max(1, (int) ($agent['software_id'] ?? 1)),
        'count'       => $count,
        'type'        => $type,
        'duration'    => $duration,
        'max_devices' => (int) $agent['max_devices'],
        'group_id'    => $typeGroupId,
        'prefix'      => $prefix,
        'expire_days' => $expireDays,
        'name'        => $name !== '' ? $name : (($agent['nickname'] ?: $agent['username']) . ' 批次'),
        'remark'      => $remark,
    ], 0, $agentId);

    if (!$r['ok']) {
        // 整笔回滚：连预扣一起撤销，无需再调 refund()
        Database::rollback();
        Agent::log($agentId, 'generate', '生成失败：' . $r['msg']);
        Response::error($r['code'], $r['msg']);
    }
} catch (Throwable $e) {
    Database::rollback();
    Agent::log($agentId, 'generate', '生成异常：' . $e->getMessage());
    throw $e;   // 交给 api.php 的统一异常处理输出
}

// 生成成功：先落库记账，再提交
$created = (int) $r['data']['count'];
$batchId = (int) $r['data']['batch_id'];

Agent::log($agentId, 'generate',
    '生成 ' . $created . ' 张' . Card::typeName($type) . '（批次 #' . $batchId . '）', $created);

Database::commit();

$fresh = Agent::find($agentId);

$preview = array_slice($r['data']['codes'], 0, 50);
$data = $r['data'];
$data['codes']         = $preview;
$data['preview_count'] = count($preview);
$data['agent']         = $fresh ? Agent::publicInfo($fresh) : null;
$data['cost']          = [
    'quota'        => (int) $charge['cost']['quota'],
    'balance'      => (int) $charge['cost']['balance'],
    'balance_text' => Agent::fen2yuan((int) $charge['cost']['balance']),
    'type'         => $type,
    'type_text'    => Card::typeName($type),
];

Response::ok($data, $r['msg']);
