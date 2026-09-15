<?php
/**
 * admin action: game_list
 * 官网小游戏排行榜记录（跨用户榜单）
 * 参数: page, size, game(筛选：farm/mario/ink/space；空=全部)
 */

$page = max(1, Util::int($input, 'page', 1));
$size = min(100, max(1, Util::int($input, 'size', 20)));
$game = strtolower(trim((string) Util::get($input, 'game', '')));

$where  = ['1=1'];
$params = [];
// 可筛选游戏 = 内置 + 各端模板 id（模板自带小游戏按文件夹名分榜）
$validGames = array_merge(['farm', 'mario', 'ink', 'space'], UiTemplate::ids('web'), UiTemplate::ids('shop'));
if (in_array($game, $validGames, true)) {
    $where[] = 'game = :g';
    $params['g'] = $game;
}

$baseSql = 'SELECT * FROM ' . Database::t('game_scores') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`score` DESC, `id` ASC');

$gameNames = array_merge(
    ['farm' => '云上农场', 'mario' => '马里奥跳跳', 'ink' => '御剑飞行', 'space' => 'STAR RAIDER'],
    array_column(UiTemplate::detect('web'), 'name', 'id'),
    array_column(UiTemplate::detect('shop'), 'name', 'id')
);

$list = array_map(static function ($r) use ($gameNames) {
    return [
        'id'         => (int) $r['id'],
        'game'       => $r['game'],
        'game_text'  => $gameNames[$r['game']] ?? $r['game'],
        'name'       => $r['name'],
        'score'      => (int) $r['score'],
        'ip'         => $r['ip'],
        'created_at' => Util::date((int) $r['created_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total, 'page' => $page, 'size' => $size,
    'pages' => (int) ceil($total / $size), 'list' => $list,
    'games' => $gameNames,   // {id: 名称}，前端筛选下拉用（含模板自带小游戏）
]);
