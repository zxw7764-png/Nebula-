<?php
/**
 * admin action: agent_code_save
 * 代理商激活码：生成 / 编辑 / 启停 / 删除
 * ------------------------------------------------------------------
 * 激活码 = 代理商的「开户凭证」，码上写死了这些规格：
 *   · 各卡类型生成的卡密激活后进入的用户组（preset[type].group_id）← 代理商无法自改
 *   · 兜底用户组（group_id）：某卡类型未单独指定时用这个
 *   · 卡密设备上限（max_devices）
 *   · 代理生成卡密的固定前缀（card_prefix）← 留空则代理可自填
 *   · 是否允许代理作废自己的卡密（can_void）
 *   · 控量模式（charge_mode）
 *   · 注册后赠予的初始余额（init_balance_yuan，仅余额计费模式生效）
 *   · 每种卡类型的额度 / 单价（preset）
 *   · 可用注册次数（max_uses）与有效期（expire_days）
 * 参数: op = generate|update|toggle|delete
 */

$op = Util::str($input, 'op', '');

/** 把前端提交的 preset 抽出来（也兼容「每类型一组字段」的扁平提交） */
$readPreset = static function () use ($input): array {
    $preset = Util::get($input, 'preset', []);
    if (!is_array($preset) || !$preset) {
        // 扁平提交：t1_enabled / t1_quota / t1_price / t1_group_id ...
        $preset = [];
        foreach (Card::TYPE_LIST as $t) {
            $preset[(string) $t] = [
                'enabled'  => Util::int($input, 't' . $t . '_enabled', 1),
                'quota'    => Util::int($input, 't' . $t . '_quota', 0),
                'price'    => Util::str($input, 't' . $t . '_price', '0'),
                'group_id' => Util::int($input, 't' . $t . '_group_id', 0),
            ];
        }
    }
    return AgentCode::normalizePreset($preset);
};

// ------------------------------------------------------------------
// 生成
// ------------------------------------------------------------------
if ($op === 'generate') {
    $r = AgentCode::generate([
        'software_id' => Util::int($input, 'software_id', 0),
        'count'       => Util::int($input, 'count', 1),
        'prefix'      => Util::str($input, 'prefix', ''),
        'card_prefix' => Util::str($input, 'card_prefix', ''),
        'nickname'    => Util::str($input, 'nickname', ''),
        'group_id'    => Util::int($input, 'group_id', 0),
        'max_devices' => Util::int($input, 'max_devices', 1),
        'can_void'    => Util::int($input, 'can_void', 1),
        'charge_mode' => Util::int($input, 'charge_mode', Agent::MODE_QUOTA),
        'preset'      => $readPreset(),
        'max_uses'    => Util::int($input, 'max_uses', 1),
        'expire_days' => Util::int($input, 'expire_days', 0),
        'remark'      => Util::str($input, 'remark', ''),
    ] + (array_key_exists('init_balance_yuan', $input)
            ? ['init_balance_yuan' => Util::get($input, 'init_balance_yuan', 0)]
            : []), (int) $admin['id']);

    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }

    Audit::log($admin, 'agent_code_create', '激活码×' . $r['data']['count'],
        '生成代理商激活码 ' . $r['data']['count'] . ' 个',
        [], [], [
            'code'        => $r['data']['codes'][0] ?? '',
            'group_id'    => Util::int($input, 'group_id', 0),
            'charge_mode' => Util::int($input, 'charge_mode', Agent::MODE_QUOTA),
            'max_uses'    => Util::int($input, 'max_uses', 1),
        ]);

    Response::ok([
        'count' => $r['data']['count'],
        'codes' => $r['data']['codes'],
    ], $r['msg']);
}

// ------------------------------------------------------------------
// 编辑
// ------------------------------------------------------------------
if ($op === 'update') {
    $id  = Util::int($input, 'id', 0);
    $old = AgentCode::find($id);
    if (!$old) {
        Response::error(1001, '激活码不存在');
    }

    $r = AgentCode::save($id, [
        'nickname'    => Util::str($input, 'nickname', ''),
        'group_id'    => Util::int($input, 'group_id', 0),
        'max_devices' => Util::int($input, 'max_devices', 1),
        'can_void'    => Util::int($input, 'can_void', 1),
        'charge_mode' => Util::int($input, 'charge_mode', Agent::MODE_QUOTA),
        'preset'      => $readPreset(),
        'max_uses'    => Util::int($input, 'max_uses', 1),
        'expire_days' => Util::int($input, 'expire_days', 0),
        'remark'      => Util::str($input, 'remark', ''),
    ] + (array_key_exists('software_id', $input) ? ['software_id' => Util::int($input, 'software_id', 0)] : [])
      + (array_key_exists('card_prefix', $input) ? ['card_prefix' => Util::str($input, 'card_prefix', '')] : [])
      + (array_key_exists('init_balance_yuan', $input)
            ? ['init_balance_yuan' => Util::get($input, 'init_balance_yuan', 0)]
            : []));
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }

    $fresh = AgentCode::find($id);
    Audit::log($admin, 'agent_code_update', '激活码#' . $id . ' ' . $old['code'],
        '编辑代理商激活码规格', $old, $fresh);

    Response::ok(['id' => $id, 'code' => AgentCode::publicInfo($fresh)], '已保存');
}

// ------------------------------------------------------------------
// 启停
// ------------------------------------------------------------------
if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    $r  = AgentCode::toggle($id);
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }
    $fresh = AgentCode::find($id);
    Audit::log($admin, 'agent_code_update', '激活码#' . $id . ' ' . ($fresh['code'] ?? ''),
        '激活码状态：' . $r['msg']);

    Response::ok(['id' => $id, 'status' => $r['data']['status']], $r['msg']);
}

// ------------------------------------------------------------------
// 删除（已注册过的码禁止删除，保留追溯）
// ------------------------------------------------------------------
if ($op === 'delete') {
    $id  = Util::int($input, 'id', 0);
    $old = AgentCode::find($id);
    if (!$old) {
        Response::error(1001, '激活码不存在');
    }
    $r = AgentCode::remove($id);
    if (!$r['ok']) {
        Response::error($r['code'], $r['msg']);
    }

    Audit::log($admin, 'agent_code_delete', '激活码#' . $id . ' ' . $old['code'], '删除代理商激活码');
    Response::ok(['id' => $id], '已删除');
}

Response::error(1001, '不支持的操作：' . $op);
