/* ======================================================================
 * Nebula Menu · 模板自带小游戏加载器（portal-game-frame.js）
 * ------------------------------------------------------------------
 * 文件夹型模板可自带小游戏：templates/<id>/game.html（+ game.js 等资源）。
 * 入口检测 body[data-game-frame]，注入右下角常驻悬浮游戏面板
 * （#nb-game-dock 内嵌 iframe）。右上角 − 浮标点击收起成一条横条
 * （宿主渲染，显示游戏名，不依赖模板内部结构），点横条任意处展开；
 * 收起状态记 sessionStorage，刷新后仍保持收起，关闭页面重新进入才
 * 恢复展开。
 * game.html 内可通过 parent.__NB_GAMES__ 拿到 { enabled, api, name,
 * csrf, cfg:{duration,topN} }（同源可读），用 api + csrf 调
 * game_top / game_score_save 上榜。
 * 样式在 _shared.css 的 #nb-game-dock，模板 css 可覆盖。
 * ====================================================================== */
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

    /* 收起态横条（宿主渲染：游戏名 + 展开） */
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
        } catch (e) { /* 隐私模式等场景忽略 */ }
    }

    toggle.addEventListener('click', function () {
        setCollapsed(!dock.classList.contains('collapsed'));
    });

    bar.addEventListener('click', function () {
        setCollapsed(false);
    });

    /* 同源读游戏名，收起横条上显示（读不到就保持「小游戏」兜底） */
    frame.addEventListener('load', function () {
        try {
            var el = frame.contentDocument.querySelector(
                '#game-header .title, #game-panel .gp-title, #game-title, .gp-title'
            );
            if (el && el.textContent.trim()) {
                label.textContent = '🎮 ' + el.textContent.trim();
            }
        } catch (e) { /* 跨域等异常忽略 */ }
    });

    var saved = false;
    try { saved = sessionStorage.getItem(KEY) === '1'; } catch (e) {}
    setCollapsed(saved);

    dock.appendChild(frame);
    dock.appendChild(bar);
    dock.appendChild(toggle);
    document.body.appendChild(dock);
})();
