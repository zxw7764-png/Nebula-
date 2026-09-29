<?php
/**
 * admin action: user_risk
 * 单用户风险评分明细（评估 + 自动冻结判定，只读不落盘）
 */

$id = Util::int($input, 'id', 0);
if ($id <= 0) {
    Response::error(4000, '参数错误');
}

$u = Database::one('SELECT id, username, status FROM ' . Database::t('users') . ' WHERE id = ?', [$id]);
if (!$u) {
    Response::error(4004, '用户不存在');
}

$force = (bool) Util::int($input, 'force', 0);
if ($force) {
    RiskScore::flush($id); // 强制重评
}

$r = RiskScore::evaluate($id);
$th = (int) Config::get('security.risk_freeze_score', 80);
if (Setting::isSet('risk_freeze_score')) {
    $sv = trim((string) Setting::get('risk_freeze_score', ''));
    if ($sv !== '') $th = (int) $sv;
}

Response::ok([
    'id'       => (int) $u['id'],
    'username' => $u['username'],
    'status'   => (int) $u['status'],
    'score'    => (int) $r['score'],
    'threshold' => $th,
    'level'    => (int) $r['score'] >= $th ? 'danger' : ((int) $r['score'] >= (int) ceil($th / 2) ? 'warn' : 'ok'),
    'breakdown' => $r['breakdown'],
]);
