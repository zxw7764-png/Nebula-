<?php
/**
 * admin action: game_del
 * 官网小游戏排行榜记录删除
 * 参数: id(单条删除) 或 game(farm/mario/ink/space，清空该游戏全部榜单)
 */

$gameNames = array_merge(
    ['farm' => '云上农场', 'mario' => '马里奥跳跳', 'ink' => '御剑飞行', 'space' => 'STAR RAIDER'],
    array_column(UiTemplate::detect('web'), 'name', 'id'),
    array_column(UiTemplate::detect('shop'), 'name', 'id')
);

$id = Util::int($input, 'id', 0);
$game = strtolower(trim((string) Util::get($input, 'game', '')));
$table = Database::t('game_scores');

if ($id > 0) {
    $row = Database::one("SELECT id, game, name, score FROM {$table} WHERE id = :id", ['id' => $id]);
    if (!$row) {
        Response::error(1001, '榜单记录不存在或已删除');
    }
    Database::exec("DELETE FROM {$table} WHERE id = :id", ['id' => $id]);
    Audit::log($admin, 'game_del', '小游戏排行榜',
        '删除榜单记录 #' . $id . '（' . ($gameNames[$row['game']] ?? $row['game']) . ' · ' . $row['name'] . ' · ' . (int) $row['score'] . ' 分）');
    Response::ok([], '记录已删除');
}

if ($game !== '' && isset($gameNames[$game])) {
    $n = (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE game = :g", ['g' => $game]);
    if ($n <= 0) {
        Response::error(1001, '该游戏暂无榜单记录');
    }
    Database::exec("DELETE FROM {$table} WHERE game = :g", ['g' => $game]);
    Audit::log($admin, 'game_del', '小游戏排行榜', '清空「' . $gameNames[$game] . '」全部榜单（' . $n . ' 条）');
    Response::ok([], '已清空 ' . $n . ' 条记录');
}

Response::error(1001, '缺少参数：id 或 game');
