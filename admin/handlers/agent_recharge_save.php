<?php
/**
 * admin action: agent_recharge_save
 * 代理商充值卡密：生成 / 启停 / 删除
 * ------------------------------------------------------------------
 * 充值卡密 = 代理商的「续费凭证」，代理商在 /agent/ 输入卡密自助兑换：
 *   · kind=1 余额充值：兑换后给代理余额加 amount（元 -> 分），仅余额计费模式有意义
 *   · kind=2 张数额度：兑换后给**一种或多种卡类型**分别加张数
 *       多类型提交 quota_map（{"1":10,"2":-1}，-1 = 该类型设为不限量）
 *       单类型旧写法 card_type + quota 仍兼容
 * 与「注册用激活码」分开：激活码只用于开户，充值卡密只用于续费 / 加量。
 * 参数: op = generate|toggle|delete
 */

$op = Util::str($input, 'op', '');

// ------------------------------------------------------------------
// 生成
// ------------------------------------------------------------------
if ($op === 'generate') {
    $r = AgentRecharge::generate([
        'count'       => Util::int($input, 'count', 1),
        'prefix'      => Util::str($input, 'prefix', ''),
        'kind'        => Util::int($input, 'kind', AgentRecharge::KIND_BALANCE),
        'amount_yuan' => Util::get($input, 'amount_yuan', 0),
        'quota_map'   => Util::get($input, 'quota_map', []),
        'card_type'   => Util::int($input, 'card_type', 0),
        'quota'       => Util::int($input, 'quota', 0),
        'max_uses'    => Util::int($input, 'max_uses', 1),
        'expire_days' => Util::int($input, 'expire_days', 0),
        'remark'      => Util::str($input, 'remark', ''),
    ], (int) $admin['id']);

    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }

    Audit::log($admin, 'agent_recharge_create', '充值卡密×' . $r['data']['count'],
        '生成代理商充值卡密 ' . $r['data']['count'] . ' 张',
        [], [], [
            'kind'        => Util::int($input, 'kind', AgentRecharge::KIND_BALANCE),
            'amount_yuan' => Util::get($input, 'amount_yuan', 0),
            'quota_map'   => AgentRecharge::normalizeQuotaMap(Util::get($input, 'quota_map', [])),
            'card_type'   => Util::int($input, 'card_type', 0),
            'quota'       => Util::int($input, 'quota', 0),
        ]);

    Response::ok([
        'count' => $r['data']['count'],
        'codes' => $r['data']['codes'],
    ], $r['msg']);
}

// ------------------------------------------------------------------
// 启停
// ------------------------------------------------------------------
if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    $r  = AgentRecharge::toggle($id);
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }
    $fresh = AgentRecharge::find($id);
    Audit::log($admin, 'agent_recharge_update', '充值卡密#' . $id . ' ' . ($fresh['code'] ?? ''),
        '充值卡密状态：' . $r['msg']);

    Response::ok(['id' => $id, 'status' => $r['data']['status']], $r['msg']);
}

// ------------------------------------------------------------------
// 删除（已兑换过的码禁止删除，保留追溯）
// ------------------------------------------------------------------
if ($op === 'delete') {
    $id  = Util::int($input, 'id', 0);
    $old = AgentRecharge::find($id);
    if (!$old) {
        Response::error(1001, '充值卡密不存在');
    }
    $r = AgentRecharge::remove($id);
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }

    Audit::log($admin, 'agent_recharge_delete', '充值卡密#' . $id . ' ' . $old['code'], '删除代理商充值卡密');
    Response::ok(['id' => $id], '已删除');
}

Response::error(1001, '不支持的操作：' . $op);
