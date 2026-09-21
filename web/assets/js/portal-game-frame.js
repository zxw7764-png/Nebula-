(function () {
    'use strict';

    var src = document.body.getAttribute('data-game-frame');
    if (!src) { return; }

    var CFG = window.__NB_GAMES__ || {};
    if (!CFG.enabled) { return; }

    var KEY = 'nb_game_dock_collapsed';

    var dock = document.createElement('div');
    dock.id = 'nb-game-dock';

    var frame = document.createElement('iframe');
    frame.id = 'nb-game-frame';
    frame.src = src;
    frame.title = 'game';

    var bar = document.createElement('div');
    bar.id = 'nb-game-bar';
    bar.innerHTML = '<span class="t">🎮 小游戏</span><span class="x">展开 ▸</span>';
    var label = bar.querySelector('.t');

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.id = 'nb-game-dock-toggle';
    toggle.textContent = '−';
    toggle.title = '收起小游戏';

    function setCollapsed(collapsed) {
        dock.classList.toggle('collapsed', collapsed);
        toggle.textContent = collapsed ? '+' : '−';
        toggle.title = collapsed ? '展开小游戏' : '收起小游戏';
        try {
            if (collapsed) { sessionStorage.setItem(KEY, '1'); }
            else { sessionStorage.removeItem(KEY); }
        } catch (e) {
 }
    }

    toggle.addEventListener('click', function () {
        setCollapsed(!dock.classList.contains('collapsed'));
    });

    bar.addEventListener('click', function () {
        setCollapsed(false);
    });

    frame.addEventListener('load', function () {
        try {
            var el = frame.contentDocument.querySelector(
                '#game-header .title, #game-panel .gp-title, #game-title, .gp-title'
            );
            if (el && el.textContent.trim()) {
                label.textContent = '🎮 ' + el.textContent.trim();
            }
        } catch (e) {
 }
    });

    var saved = false;
    try { saved = sessionStorage.getItem(KEY) === '1'; } catch (e) {}
    setCollapsed(saved);

    dock.appendChild(frame);
    dock.appendChild(bar);
    dock.appendChild(toggle);
    document.body.appendChild(dock);
})();
