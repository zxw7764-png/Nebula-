(function () {
    'use strict';

    var body = document.body;
    var tpl = body.classList.contains('ui-farm')  ? 'farm'
            : body.classList.contains('ui-mario') ? 'mario'
            : body.classList.contains('ui-ink')   ? 'ink'
            : body.classList.contains('ui-space') ? 'space'
            : '';
    if (!tpl) { return; }

    var CFG = window.__NB_GAMES__ || {};
    if (!CFG.enabled) { return; }
    var API = (CFG.api || 'api.php');
    var CSRF = CFG.csrf || '';

    var LOGIN_NAME = String(CFG.name || '').trim().substring(0, 16);

    var META = {
        farm:  { title: '种田收菜', w: 360, h: 240, hint: '点击空地种 · 成熟点击收 · 点击杂草害虫清 · ⭐ 结算上榜', defName: '匿名农夫', unit: '金币' },
        mario: { title: '跳跳跳',   w: 320, h: 180, hint: '空格 / ↑ / 点击画面 跳跃',                 defName: '匿名水管工', unit: '分' },
        ink:   { title: '御剑飞行', w: 360, h: 220, hint: '↑ ↓ 或 W S 御剑上下 · 穿过金环 +20',       defName: '匿名散仙', unit: '灵石' },
        space: { title: 'STAR RAIDER', w: 320, h: 200, hint: '← → 移动 · 空格射击 · 点击画面开火',     defName: '匿名领航员', unit: '分' }
    };

    var BEST_KEY = 'nb_game_best_' + tpl;
    var NAME_KEY = 'nb_game_name_' + tpl;
    var best = Number(localStorage.getItem(BEST_KEY) || 0);

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var m = META[tpl];
    var panel = document.createElement('div');
    panel.id = 'nb-game-panel';
    panel.innerHTML =
        '<div class="gp-head">' +
            '<div class="gp-title">' + esc(m.title) + '</div>' +
            '<div class="gp-btns">' +
                (tpl === 'farm' ? '<button type="button" class="gp-btn" data-act="settle" title="结算金币上榜">⭐</button>' : '') +
                '<button type="button" class="gp-btn" data-act="board" title="排行榜">🏆</button>' +
                '<button type="button" class="gp-btn" data-act="pause" title="暂停/继续">⏸</button>' +
                '<button type="button" class="gp-btn" data-act="toggle" title="收起/展开">−</button>' +
            '</div>' +
        '</div>' +
        '<div class="gp-body">' +
            '<canvas width="' + m.w + '" height="' + m.h + '"></canvas>' +
            '<div class="gp-hud"><span class="gp-hud-l"></span><span class="gp-hud-r"></span></div>' +
            '<div class="gp-overlay">' +
                '<h4></h4>' +
                '<p></p>' +
                '<button type="button" class="gp-start"></button>' +
            '</div>' +
        '</div>' +
        '<div class="gp-hint">' + esc(m.hint) + '</div>';
    document.body.appendChild(panel);

    var COLLAPSE_KEY = 'nb_game_collapsed';
    var btnToggle = panel.querySelector('[data-act="toggle"]');
    try {
        if (sessionStorage.getItem(COLLAPSE_KEY) === '1') {
            panel.classList.add('collapsed');
            if (btnToggle) { btnToggle.textContent = '+'; }
        }
    } catch (e) {
 }

    var canvas   = panel.querySelector('canvas');
    var ctx      = canvas.getContext('2d');
    var overlay  = panel.querySelector('.gp-overlay');
    var ovTitle  = overlay.querySelector('h4');
    var ovText   = overlay.querySelector('p');
    var btnStart = overlay.querySelector('.gp-start');
    var hudL     = panel.querySelector('.gp-hud-l');
    var hudR     = panel.querySelector('.gp-hud-r');
    var btnPause = panel.querySelector('[data-act="pause"]');

    function setHud(l, r) {
        if (hudL) hudL.textContent = l;
        if (hudR) hudR.textContent = r;
    }

    function boardApi(cb) {
        fetch(API + '?action=game_top&game=' + tpl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { cb(j && j.code === 0 ? (j.data.list || []) : null); })
            .catch(function () { cb(null); });
    }

    function renderBoard(list, n) {
        if (!list) { return '<div>榜单暂时无法获取</div>'; }
        if (!list.length) { return '<div>还没有人上榜，等你来一战！</div>'; }
        var html = '';
        for (var i = 0; i < list.length && i < n; i++) {
            var it = list[i];
            html += '<div>' + (i === 0 ? '<b class="top">🥇 ' : i === 1 ? '<b class="top">🥈 ' : i === 2 ? '<b class="top">🥉 ' : (i + 1) + '. ')
                + esc(it.name) + (i < 3 ? '</b>' : '')
                + ' —— ' + it.score + '</div>';
        }
        return html;
    }

    function getPlayerName() {

        if (LOGIN_NAME) { return LOGIN_NAME; }
        var v = '';
        var inp = overlay.querySelector('input.gp-name');
        if (inp) { v = inp.value.trim(); }
        if (!v) { v = localStorage.getItem(NAME_KEY) || ''; }
        if (!v) { v = m.defName; }
        localStorage.setItem(NAME_KEY, v);
        return v.substring(0, 16);
    }

    function submitScore(score, done) {
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'game_score_save', game: tpl, name: getPlayerName(), score: score, csrf: CSRF })
        }).then(function (r) { return r.json(); })
          .then(function (j) { done(j && j.code === 0); })
          .catch(function () { done(false); });
    }

    var view = 'start';
    var lastScore = 0;

    function refreshOverlay() {
        if (view === 'start') {
            ovTitle.textContent = m.title;
            ovText.innerHTML = '最高纪录 <b>' + best + '</b><div class="gp-board" data-board>加载中…</div>' +
                (LOGIN_NAME
                    ? '<div class="gp-hint-name">上榜昵称：<b>' + esc(LOGIN_NAME) + '</b>（当前登录账号）</div>'
                    : '<input class="gp-name" maxlength="16" placeholder="上榜昵称（留空匿名）" value="' + esc(localStorage.getItem(NAME_KEY) || '') + '">');
            btnStart.textContent = '开始游戏';
            boardApi(function (list) {
                var box = overlay.querySelector('[data-board]');
                if (box && view === 'start') { box.innerHTML = renderBoard(list, 5); }
            });
        } else if (view === 'over') {
            ovTitle.textContent = tpl === 'farm' ? '收获结算' : tpl === 'ink' ? '剑折人亡' : tpl === 'space' ? '任务失败' : '游戏结束';
            ovText.innerHTML = '本次 ' + m.unit + ' <b>' + lastScore + '</b> · 最高 ' + best +
                '<div class="gp-board" data-board>上传成绩中…</div>';
            btnStart.textContent = '再来一局';
        } else if (view === 'board') {
            ovTitle.textContent = '🏆 排行榜';
            ovText.innerHTML = '<div class="gp-board" data-board>加载中…</div>';
            btnStart.textContent = '返回';
            boardApi(function (list) {
                var box = overlay.querySelector('[data-board]');
                if (box && view === 'board') { box.innerHTML = renderBoard(list, 10); }
            });
        }
    }

    function showOverlay(v) {
        view = v;
        overlay.classList.remove('hidden');
        refreshOverlay();
    }
    function hideOverlay() {
        overlay.classList.add('hidden');
    }

    function onOver(score) {
        score = Math.max(0, Math.floor(score));
        if (score > best) {
            best = score;
            localStorage.setItem(BEST_KEY, String(best));
        }
        lastScore = score;
        showOverlay('over');
        submitScore(score, function (ok) {
            boardApi(function (list) {
                var box = overlay.querySelector('[data-board]');
                if (box && view === 'over') {
                    box.innerHTML = (ok ? '' : '成绩上传失败，仅记录本地<br>') + renderBoard(list, 5);
                }
            });
        });
    }

    var game = null;

    overlay.addEventListener('click', function (e) {
        if (e.target.closest('input.gp-name')) { return; }
        if (view === 'board') {
            showOverlay('start');
            return;
        }
        if (!game) { return; }
        hideOverlay();
        game.start();
    });

    panel.addEventListener('click', function (e) {
        var b = e.target.closest('.gp-btn');
        if (!b) { return; }
        var act = b.dataset.act;
        if (act === 'toggle') {
            panel.classList.toggle('collapsed');
            var folded = panel.classList.contains('collapsed');
            b.textContent = folded ? '+' : '−';
            try {
                if (folded) { sessionStorage.setItem('nb_game_collapsed', '1'); }
                else { sessionStorage.removeItem('nb_game_collapsed'); }
            } catch (e) {
 }
        } else if (act === 'pause') {
            if (!game) { return; }
            var paused = game.togglePause();
            btnPause.textContent = paused ? '▶' : '⏸';
        } else if (act === 'board') {
            if (view === 'board') { showOverlay('start'); }
            else { showOverlay('board'); }
        } else if (act === 'settle') {
            if (game && game.settle) { game.settle(); }
        }
    });

    function fixedLoop(update, draw) {
        var STEP = 1000 / 60;
        var acc = 0, last = 0;
        function loop(t) {
            requestAnimationFrame(loop);
            if (!last) { last = t; }
            var dt = t - last;
            last = t;
            if (dt > 100) { dt = 100; }
            acc += dt;
            while (acc >= STEP) { update(); acc -= STEP; }
            draw();
        }
        requestAnimationFrame(loop);
    }
    function rand(a, b) { return Math.random() * (b - a) + a; }
    function rectHit(a, b) {
        return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
    }

    function makeFarm() {
        var W = m.w, H = m.h;
        var COLS = 3, ROWS = 3, PADDING = 16, HEADER_H = 30;
        var CELL_W = (W - PADDING * 2) / COLS;
        var CELL_H = (H - PADDING * 2 - HEADER_H) / ROWS;
        var CELL_Y0 = PADDING + HEADER_H;
        var C = {
            sky: '#87ceeb', skyTop: '#b8e0f0', grass: '#7ec850', grass2: '#5fa83a', grass3: '#4a8a2a',
            dirt: '#a0825a', dirtDark: '#7a5f3f', wood: '#8b6f47', wood3: '#4a3520',
            wheat: '#f4d03f', wheat2: '#e8b923', tomato: '#e74c3c', carrot: '#e67e22',
            corn: '#f1c40f', pumpkin: '#d35400', leaf: '#5fa83a', leaf2: '#3a7a1a',
            flower: '#ff6b9d', weed: '#7a9a3a', bug: '#5a3a1a'
        };
        var CROPS = [
            { name: 'tomato',  color: C.tomato,  coin: 8,  stages: 4 },
            { name: 'carrot',  color: C.carrot,  coin: 5,  stages: 4 },
            { name: 'corn',    color: C.corn,    coin: 10, stages: 5 },
            { name: 'pumpkin', color: C.pumpkin, coin: 15, stages: 6 }
        ];
        var running = false, paused = false;
        var coins = 0, day = 1, frame = 0;
        var growSpeed = 180, pestTimer = 0, pestGap = 300;
        var plots = [], floaters = [], decor = [];

        function plotX(c) { return PADDING + c * CELL_W; }
        function plotY(r) { return CELL_Y0 + r * CELL_H; }
        function hud() { setHud('🪙 ' + coins, '第 ' + day + ' 天'); }

        function reset() {
            plots = []; floaters = []; decor = [];
            for (var r = 0; r < ROWS; r++) {
                for (var c = 0; c < COLS; c++) {
                    plots.push({ col: c, row: r, crop: null, stage: 0, growth: 0, hasPest: false, pestType: null, pestTimer: 0, jitter: rand(-2, 2) });
                }
            }
            for (var i = 0; i < 3; i++) {
                decor.push({ x: rand(20, W - 20), y: rand(CELL_Y0, H - 20), vx: rand(-0.3, 0.3), vy: rand(-0.2, 0.2), type: Math.random() < 0.5 ? 'butterfly' : 'bee', phase: rand(0, Math.PI * 2) });
            }
            coins = 0; day = 1; frame = 0; pestTimer = 0; pestGap = 300;
            hud();
        }

        function clearPest(p) {
            if (!p.hasPest) { return; }
            p.hasPest = false; p.pestType = null;
            coins += 1;
            floaters.push({ x: plotX(p.col) + CELL_W / 2, y: plotY(p.row) + CELL_H / 2, text: '+1', color: C.leaf, life: 1 });
            hud();
        }
        function harvest(p) {
            if (!p.crop || p.stage < p.crop.stages) { return; }
            coins += p.crop.coin;
            floaters.push({ x: plotX(p.col) + CELL_W / 2, y: plotY(p.row) + CELL_H / 2, text: '+' + p.crop.coin, color: C.wheat2, life: 1 });
            p.crop = null; p.stage = 0; p.growth = 0; p.hasPest = false; p.pestType = null;
            hud();
        }

        function update() {
            if (!running || paused) { return; }
            frame++;
            plots.forEach(function (p) {
                if (!p.crop) { return; }
                p.growth += p.hasPest ? 0.5 : 1;
                if (p.growth >= growSpeed && p.stage < p.crop.stages) { p.growth = 0; p.stage++; }
                if (p.hasPest) {
                    p.pestTimer++;
                    if (p.pestTimer > 600) {
                        p.crop = null; p.stage = 0; p.growth = 0; p.hasPest = false; p.pestType = null; p.pestTimer = 0;
                    }
                }
            });
            pestTimer++;
            if (pestTimer >= pestGap) {
                pestTimer = 0;
                pestGap = Math.max(120, 300 - coins * 2);
                var cands = plots.filter(function (p) { return p.crop && !p.hasPest; });
                if (cands.length) {
                    var t = cands[Math.floor(Math.random() * cands.length)];
                    t.hasPest = true; t.pestType = Math.random() < 0.5 ? 'weed' : 'bug'; t.pestTimer = 0;
                }
            }
            if (frame % 600 === 0) { day++; hud(); }
            for (var i = floaters.length - 1; i >= 0; i--) {
                var f = floaters[i];
                f.y -= 0.6; f.life -= 0.02;
                if (f.life <= 0) { floaters.splice(i, 1); }
            }
            decor.forEach(function (d) {
                d.x += d.vx; d.y += d.vy; d.phase += 0.1;
                if (d.x < 10 || d.x > W - 10) { d.vx *= -1; }
                if (d.y < CELL_Y0 + 10 || d.y > H - 10) { d.vy *= -1; }
            });
        }

        function drawCrop(p, px, py) {
            var cx = px + CELL_W / 2, cy = py + CELL_H / 2 + 4, j = p.jitter;
            ctx.save();
            ctx.translate(cx, cy);
            var st = p.stage, stages = p.crop.stages;
            if (st === 1) {
                ctx.fillStyle = C.leaf; ctx.fillRect(-1, -2, 2, 4);
                ctx.fillStyle = C.leaf2; ctx.fillRect(-3, -4, 6, 3);
            } else if (st === 2) {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -6, 2, 10);
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.ellipse(-4, -6, 4, 2, -0.5, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.ellipse(4, -6, 4, 2, 0.5, 0, Math.PI * 2); ctx.fill();
            } else if (st === 3 && st < stages) {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -10, 2, 16);
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.ellipse(-6, -8, 5, 3, -0.5, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.ellipse(6, -8, 5, 3, 0.5, 0, Math.PI * 2); ctx.fill();
            } else if (st >= stages) {
                drawMature(p, j);
                if (Math.floor(frame / 20) % 2 === 0) {
                    ctx.fillStyle = 'rgba(255,255,255,.7)';
                    ctx.fillRect(-8, -14, 2, 2); ctx.fillRect(6, -10, 2, 2);
                }
            } else {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -8, 2, 12);
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.ellipse(-5, -6, 4, 2.5, -0.5, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.ellipse(5, -6, 4, 2.5, 0.5, 0, Math.PI * 2); ctx.fill();
            }
            ctx.restore();
        }
        function drawMature(p, j) {
            var name = p.crop.name;
            if (name === 'tomato') {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -10, 2, 16);
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.ellipse(-6, -4, 4, 2, -0.5, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.ellipse(6, -4, 4, 2, 0.5, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = C.tomato;
                ctx.beginPath(); ctx.arc(-4 + j, -6, 4, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.arc(4, -8, 4, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.arc(0, -2, 3.5, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.5)';
                ctx.fillRect(-5 + j, -8, 2, 2); ctx.fillRect(3, -10, 2, 2);
            } else if (name === 'carrot') {
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(-4, -12); ctx.lineTo(-2, -12); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(4, -12); ctx.lineTo(6, -12); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(0, -14); ctx.lineTo(2, -14); ctx.closePath(); ctx.fill();
                ctx.fillStyle = C.carrot;
                ctx.beginPath(); ctx.moveTo(-4, 0); ctx.lineTo(4, 0); ctx.lineTo(0, 10); ctx.closePath(); ctx.fill();
            } else if (name === 'corn') {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -16, 2, 22);
                ctx.fillStyle = C.leaf;
                ctx.beginPath(); ctx.moveTo(0, -4); ctx.lineTo(-10, -8); ctx.lineTo(-9, -6); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(10, -4); ctx.lineTo(9, -2); ctx.closePath(); ctx.fill();
                ctx.fillStyle = C.corn; ctx.fillRect(-3, -10, 6, 10);
                ctx.fillStyle = C.wheat2;
                for (var yy = -9; yy < 0; yy += 3) {
                    for (var xx = -2; xx < 3; xx += 2) { ctx.fillRect(xx, yy, 1.5, 1.5); }
                }
            } else if (name === 'pumpkin') {
                ctx.fillStyle = C.leaf2; ctx.fillRect(-1, -4, 2, 10);
                ctx.fillStyle = C.pumpkin;
                ctx.beginPath(); ctx.ellipse(0, 2, 9, 7, 0, 0, Math.PI * 2); ctx.fill();
                ctx.strokeStyle = 'rgba(0,0,0,.25)'; ctx.lineWidth = 1;
                ctx.beginPath(); ctx.ellipse(0, 2, 5, 7, 0, 0, Math.PI * 2); ctx.stroke();
            }
        }
        function drawPest(p, px, py) {
            var cx = px + CELL_W / 2, cy = py + CELL_H / 2, bob = Math.sin(frame * 0.15) * 2;
            ctx.save();
            ctx.translate(cx, cy + bob);
            if (p.pestType === 'weed') {
                ctx.fillStyle = C.weed;
                ctx.beginPath(); ctx.moveTo(-6, 8); ctx.lineTo(-2, -6); ctx.lineTo(-4, -6); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(0, 8); ctx.lineTo(2, -8); ctx.lineTo(4, -8); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(2, 8); ctx.lineTo(6, -4); ctx.lineTo(8, -4); ctx.closePath(); ctx.fill();
            } else {
                ctx.fillStyle = C.bug;
                ctx.beginPath(); ctx.ellipse(0, 0, 5, 4, 0, 0, Math.PI * 2); ctx.fill();
                ctx.strokeStyle = C.bug; ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(-4, -2); ctx.lineTo(-8, -4);
                ctx.moveTo(-4, 0); ctx.lineTo(-8, 0);
                ctx.moveTo(-4, 2); ctx.lineTo(-8, 4);
                ctx.moveTo(4, -2); ctx.lineTo(8, -4);
                ctx.moveTo(4, 0); ctx.lineTo(8, 0);
                ctx.moveTo(4, 2); ctx.lineTo(8, 4);
                ctx.stroke();
            }
            ctx.fillStyle = 'rgba(231,76,60,.9)';
            ctx.font = 'bold 10px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('!', 0, -10);
            ctx.textAlign = 'left';
            ctx.restore();
        }
        function drawDecor(d) {
            var bob = Math.sin(d.phase) * 3;
            ctx.save();
            ctx.translate(d.x, d.y + bob);
            if (d.type === 'butterfly') {
                var wing = Math.sin(frame * 0.3) * 2;
                ctx.fillStyle = C.flower;
                ctx.beginPath(); ctx.ellipse(-3, -1, 3, 4 + wing, 0.3, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.ellipse(3, -1, 3, 4 + wing, -0.3, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = C.wood3; ctx.fillRect(-0.5, -3, 1, 6);
            } else {
                ctx.fillStyle = C.wheat2;
                ctx.beginPath(); ctx.ellipse(0, 0, 4, 3, 0, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.6)';
                ctx.beginPath(); ctx.ellipse(-2, -3, 3, 1.5, -0.3, 0, Math.PI * 2); ctx.fill();
            }
            ctx.restore();
        }
        function draw() {
            var grad = ctx.createLinearGradient(0, 0, 0, H);
            grad.addColorStop(0, C.skyTop);
            grad.addColorStop(1, C.sky);
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = 'rgba(95,168,58,.35)';
            ctx.beginPath();
            ctx.moveTo(0, CELL_Y0 - 10);
            for (var x = 0; x <= W; x += 20) {
                ctx.lineTo(x, CELL_Y0 - 40 - Math.sin(x * 0.015) * 20 - Math.sin(x * 0.03) * 8);
            }
            ctx.lineTo(W, CELL_Y0 - 10);
            ctx.closePath(); ctx.fill();
            ctx.fillStyle = C.grass;
            ctx.fillRect(0, CELL_Y0 - 10, W, H - CELL_Y0 + 10);
            plots.forEach(function (p) {
                var px = plotX(p.col), py = plotY(p.row);
                ctx.fillStyle = C.dirt;
                ctx.fillRect(px + 2, py + 2, CELL_W - 4, CELL_H - 4);
                ctx.strokeStyle = C.dirtDark; ctx.lineWidth = 2;
                ctx.strokeRect(px + 2, py + 2, CELL_W - 4, CELL_H - 4);
                if (p.crop) { drawCrop(p, px, py); }
                if (p.hasPest) { drawPest(p, px, py); }
            });
            decor.forEach(drawDecor);
            floaters.forEach(function (f) {
                ctx.globalAlpha = f.life;
                ctx.font = 'bold 14px monospace';
                ctx.textAlign = 'center';
                ctx.lineWidth = 3;
                ctx.strokeStyle = '#fff';
                ctx.strokeText(f.text, f.x, f.y);
                ctx.fillStyle = f.color;
                ctx.fillText(f.text, f.x, f.y);
            });
            ctx.globalAlpha = 1;
            ctx.textAlign = 'left';
        }

        canvas.addEventListener('mousedown', click);
        canvas.addEventListener('touchstart', click, { passive: false });
        function click(e) {
            e.preventDefault();
            if (!running || paused) { return; }
            var rect = canvas.getBoundingClientRect();
            var x = (e.touches ? e.touches[0].clientX : e.clientX);
            var y = (e.touches ? e.touches[0].clientY : e.clientY);
            var cx = (x - rect.left) / rect.width * W;
            var cy = (y - rect.top) / rect.height * H;
            for (var i = 0; i < plots.length; i++) {
                var p = plots[i];
                var px = plotX(p.col), py = plotY(p.row);
                if (cx >= px && cx <= px + CELL_W && cy >= py && cy <= py + CELL_H) {
                    if (p.hasPest) { clearPest(p); return; }
                    if (p.crop && p.stage >= p.crop.stages) { harvest(p); return; }
                    if (!p.crop) {
                        p.crop = CROPS[Math.floor(Math.random() * CROPS.length)];
                        p.stage = 1; p.growth = 0; p.hasPest = false; p.pestType = null;
                    }
                    return;
                }
            }
        }

        reset();
        fixedLoop(update, draw);
        return {
            start: function () {
                reset();
                running = true; paused = false;
                btnPause.textContent = '⏸';
            },
            togglePause: function () {
                if (!running) { return paused; }
                paused = !paused;
                return paused;
            },
            settle: function () {
                if (!running || paused) { return; }
                running = false;
                onOver(coins);
            }
        };
    }

    function makeMario() {
        var W = m.w, H = m.h;
        var GROUND_H = 30, GROUND_Y = H - GROUND_H;
        var GRAVITY = 0.6, JUMP_V = -10;
        var running = false, paused = false;
        var score = 0, speed = 3, spawnTimer = 0, spawnGap = 90, frame = 0;
        var player = { x: 50, y: GROUND_Y - 24, w: 20, h: 24, vy: 0, onGround: true, runFrame: 0 };
        var pipes = [];
        var clouds = [{ x: 40, y: 30, s: 1 }, { x: 180, y: 60, s: 0.7 }, { x: 280, y: 20, s: 1.2 }];
        var fired = false;

        function hud() { setHud('分数 ' + Math.floor(score), '最高 ' + best); }

        function reset() {
            player.x = 50; player.y = GROUND_Y - player.h; player.vy = 0; player.onGround = true; player.runFrame = 0;
            pipes = [];
            score = 0; speed = 3; spawnTimer = 0; spawnGap = 90; frame = 0;
            hud();
        }
        function jump() {
            if (!running || paused) { return; }
            if (player.onGround) { player.vy = JUMP_V; player.onGround = false; }
        }
        function spawnPipe() {
            var w = rand(18, 30), h = rand(30, 55);
            pipes.push({ x: W + 10, y: GROUND_Y - h, w: w, h: h });
        }
        function update() {
            if (!running || paused) { return; }
            frame++;
            score += 0.15;
            speed = 3 + Math.min(3, score / 80);
            player.vy += GRAVITY;
            player.y += player.vy;
            if (player.y >= GROUND_Y - player.h) {
                player.y = GROUND_Y - player.h; player.vy = 0; player.onGround = true;
            }
            if (player.onGround) { player.runFrame = (player.runFrame + 0.25) % 4; }
            spawnTimer++;
            if (spawnTimer >= spawnGap) {
                spawnTimer = 0;
                spawnGap = Math.max(50, 90 - score / 4);
                spawnPipe();
            }
            for (var i = pipes.length - 1; i >= 0; i--) {
                var p = pipes[i];
                p.x -= speed;
                if (rectHit({ x: player.x + 3, y: player.y + 3, w: player.w - 6, h: player.h - 6 }, p)) {
                    running = false;
                    onOver(score);
                    return;
                }
                if (p.x + p.w < -10) { pipes.splice(i, 1); }
            }
            clouds.forEach(function (c) {
                c.x -= 0.3 * c.s;
                if (c.x < -40) { c.x = W + 40; c.y = rand(10, 70); }
            });
            hud();
        }
        function drawCloud(x, y, s) {
            ctx.save();
            ctx.translate(x, y); ctx.scale(s, s);
            ctx.fillStyle = '#fff'; ctx.strokeStyle = '#000'; ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(10, 6, 8, 0, Math.PI * 2);
            ctx.arc(22, 2, 10, 0, Math.PI * 2);
            ctx.arc(36, 6, 8, 0, Math.PI * 2);
            ctx.fill(); ctx.stroke();
            ctx.restore();
        }
        function draw() {
            ctx.fillStyle = '#5C94FC';
            ctx.fillRect(0, 0, W, H);
            clouds.forEach(function (c) { drawCloud(c.x, c.y, c.s); });
            ctx.fillStyle = '#C84C0C';
            ctx.fillRect(0, GROUND_Y, W, GROUND_H);
            ctx.strokeStyle = '#000'; ctx.lineWidth = 2;
            ctx.beginPath();
            for (var x = 0; x < W; x += 20) { ctx.moveTo(x, GROUND_Y); ctx.lineTo(x, H); }
            ctx.moveTo(0, GROUND_Y); ctx.lineTo(W, GROUND_Y);
            ctx.stroke();
            pipes.forEach(function (p) {
                ctx.fillStyle = '#00A800';
                ctx.fillRect(p.x, p.y, p.w, p.h);
                ctx.strokeStyle = '#000'; ctx.lineWidth = 2;
                ctx.strokeRect(p.x, p.y, p.w, p.h);
                ctx.fillStyle = '#00D000';
                ctx.fillRect(p.x - 2, p.y, p.w + 4, 6);
                ctx.strokeRect(p.x - 2, p.y, p.w + 4, 6);
            });

            ctx.save();
            ctx.translate(player.x, player.y);
            var w = player.w, h = player.h;
            ctx.fillStyle = '#E52521';
            ctx.fillRect(0, 0, w, 6); ctx.fillRect(2, 6, w - 4, 2);
            ctx.fillStyle = '#FBD0A0'; ctx.fillRect(2, 8, w - 4, 8);
            ctx.fillStyle = '#000';
            ctx.fillRect(6, 10, 2, 3); ctx.fillRect(w - 9, 10, 2, 3);
            ctx.fillStyle = '#3A1F0A'; ctx.fillRect(4, 14, w - 8, 2);
            ctx.fillStyle = '#0B6FC2'; ctx.fillRect(2, 16, w - 4, h - 16);
            ctx.fillStyle = '#E52521';
            ctx.fillRect(5, 16, 3, 5); ctx.fillRect(w - 8, 16, 3, 5);
            if (player.onGround) {
                ctx.fillStyle = '#0B6FC2';
                var ph = Math.floor(player.runFrame) % 2;
                if (ph === 0) { ctx.fillRect(3, h - 3, 5, 3); ctx.fillRect(w - 8, h - 3, 5, 3); }
                else { ctx.fillRect(5, h - 3, 5, 3); ctx.fillRect(w - 10, h - 3, 5, 3); }
            }
            ctx.restore();
        }

        document.addEventListener('keydown', function (e) {
            if (e.code !== 'Space' && e.code !== 'ArrowUp') { return; }
            var tag = (e.target && e.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }
            if (overlay.classList.contains('hidden')) { e.preventDefault(); jump(); }
        });
        canvas.addEventListener('mousedown', function () { jump(); });
        canvas.addEventListener('touchstart', function (e) { e.preventDefault(); jump(); }, { passive: false });

        reset();
        fixedLoop(update, draw);
        return {
            start: function () { reset(); running = true; paused = false; btnPause.textContent = '⏸'; fired = false; },
            togglePause: function () { if (!running) { return paused; } paused = !paused; return paused; }
        };
    }

    function makeInk() {
        var W = m.w, H = m.h;
        var GROUND_H = 40, GROUND_Y = H - GROUND_H;
        var INK = { black: '#1a1a1a', light: '#8a8a8a', red: '#c8102e', green: '#2d5a4a', gold: '#b8860b', paper: '#ede4d3' };
        var running = false, paused = false;
        var score = 0, frame = 0, speed = 2.2, spawnTimer = 0, spawnGap = 90;
        var keys = { up: false, down: false };
        var player = { x: 70, y: H / 2, w: 28, h: 10, vy: 0, speed: 2.8, trail: [] };
        var obstacles = [], rings = [], particles = [];
        var clouds = [{ x: 60, y: 40, s: 1 }, { x: 200, y: 80, s: 0.7 }, { x: 320, y: 30, s: 0.9 }, { x: 150, y: 160, s: 0.6 }];

        function hud() { setHud('靈石 ' + Math.floor(score), '最高 ' + best); }
        function circleHit(cx, cy, r, rect) {
            var nx = Math.max(rect.x, Math.min(cx, rect.x + rect.w));
            var ny = Math.max(rect.y, Math.min(cy, rect.y + rect.h));
            var dx = cx - nx, dy = cy - ny;
            return dx * dx + dy * dy <= r * r;
        }
        function explode(x, y, color, count) {
            for (var i = 0; i < count; i++) {
                var a = Math.random() * Math.PI * 2, sp = rand(0.8, 3);
                particles.push({ x: x, y: y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, life: 1, color: color, size: rand(1.5, 3) });
            }
        }
        function spawnMountain() {
            var gapSize = rand(70, 100);
            var gapCenter = rand(gapSize / 2 + 20, H - gapSize / 2 - 20);
            var gapTop = gapCenter - gapSize / 2, gapBottom = gapCenter + gapSize / 2;
            if (gapTop > 10) {
                obstacles.push({ type: 'top', x: W + 10, y: 0, w: rand(18, 26), h: gapTop, peak: rand(0.4, 0.6) });
            }
            if (gapBottom < GROUND_Y - 10) {
                obstacles.push({ type: 'bottom', x: W + 10, y: gapBottom, w: rand(18, 26), h: GROUND_Y - gapBottom, peak: rand(0.4, 0.6) });
            }
            if (Math.random() < 0.7) {
                rings.push({ x: W + 30, y: gapCenter, r: rand(12, 16), collected: false, pulse: 0 });
            }
        }
        function reset() {
            player.x = 70; player.y = H / 2; player.vy = 0; player.trail = [];
            obstacles = []; rings = []; particles = [];
            score = 0; frame = 0; speed = 2.2; spawnTimer = 0; spawnGap = 90;
            keys.up = false; keys.down = false;
            hud();
        }
        function update() {
            if (!running || paused) { return; }
            frame++;
            score += 0.05;
            speed = 2.2 + Math.min(3, score / 80);
            player.vy = 0;
            if (keys.up) { player.vy -= player.speed; }
            if (keys.down) { player.vy += player.speed; }
            player.y += player.vy;
            if (player.y < player.h / 2 + 6) { player.y = player.h / 2 + 6; }
            if (player.y > GROUND_Y - player.h / 2) { player.y = GROUND_Y - player.h / 2; }
            if (frame % 2 === 0) {
                player.trail.push({ x: player.x - player.w / 2 + 2, y: player.y + rand(-2, 2), life: 1, size: rand(1.5, 3) });
                if (player.trail.length > 30) { player.trail.shift(); }
            }
            for (var i = player.trail.length - 1; i >= 0; i--) {
                var t = player.trail[i];
                t.x -= speed * 0.6; t.life -= 0.06; t.size *= 0.97;
                if (t.life <= 0) { player.trail.splice(i, 1); }
            }
            spawnTimer++;
            if (spawnTimer >= spawnGap) {
                spawnTimer = 0;
                spawnGap = Math.max(55, 90 - score / 10);
                spawnMountain();
            }
            for (var j = obstacles.length - 1; j >= 0; j--) {
                var o = obstacles[j];
                o.x -= speed;
                if (rectHit({ x: player.x - player.w / 2 + 4, y: player.y - player.h / 2 + 2, w: player.w - 8, h: player.h - 4 }, { x: o.x, y: o.y, w: o.w, h: o.h })) {
                    explode(player.x, player.y, INK.red, 30);
                    running = false;
                    onOver(score);
                    return;
                }
                if (o.x + o.w < -10) { obstacles.splice(j, 1); }
            }
            for (var k = rings.length - 1; k >= 0; k--) {
                var r = rings[k];
                r.x -= speed; r.pulse += 0.1;
                if (!r.collected) {
                    var dx = player.x - r.x, dy = player.y - r.y;
                    if (dx * dx + dy * dy < r.r * r.r) {
                        r.collected = true; score += 20;
                        explode(r.x, r.y, INK.gold, 14);
                        hud();
                    }
                }
                if (r.x + r.r < -10) { rings.splice(k, 1); }
            }
            for (var mm = particles.length - 1; mm >= 0; mm--) {
                var pp = particles[mm];
                pp.x += pp.vx; pp.y += pp.vy; pp.vx *= 0.95; pp.vy *= 0.95; pp.life -= 0.025;
                if (pp.life <= 0) { particles.splice(mm, 1); }
            }
            clouds.forEach(function (c) {
                c.x -= 0.2 * c.s;
                if (c.x < -40) { c.x = W + 40; c.y = rand(20, H - 60); }
            });
            hud();
        }
        function draw() {
            ctx.fillStyle = INK.paper;
            ctx.fillRect(0, 0, W, H);
            ctx.save();
            ctx.globalAlpha = 0.12;
            ctx.fillStyle = INK.black;
            ctx.beginPath();
            ctx.moveTo(0, H);
            for (var x = 0; x <= W; x += 20) {
                ctx.lineTo(x, H - 60 - Math.sin(x * 0.01 + frame * 0.002) * 25 - Math.sin(x * 0.02) * 10);
            }
            ctx.lineTo(W, H);
            ctx.closePath(); ctx.fill();
            ctx.restore();
            clouds.forEach(function (c) {
                ctx.save();
                ctx.translate(c.x, c.y); ctx.scale(c.s, c.s);
                ctx.strokeStyle = 'rgba(26,26,26,.15)'; ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(-20, 0); ctx.quadraticCurveTo(-10, -12, 0, -6);
                ctx.quadraticCurveTo(8, -16, 18, -4); ctx.quadraticCurveTo(26, -10, 32, 0);
                ctx.stroke();
                ctx.restore();
            });
            ctx.fillStyle = 'rgba(26,26,26,.08)';
            ctx.fillRect(0, GROUND_Y, W, GROUND_H);
            player.trail.forEach(function (t) {
                ctx.globalAlpha = t.life * 0.5;
                ctx.fillStyle = INK.light;
                ctx.beginPath(); ctx.arc(t.x, t.y, t.size, 0, Math.PI * 2); ctx.fill();
            });
            ctx.globalAlpha = 1;
            obstacles.forEach(function (o) {
                ctx.fillStyle = 'rgba(26,26,26,.55)';
                ctx.strokeStyle = INK.black; ctx.lineWidth = 1.5;
                ctx.beginPath();
                if (o.type === 'top') {
                    ctx.moveTo(o.x, o.y);
                    ctx.lineTo(o.x + o.w * 0.5, o.y);
                    ctx.lineTo(o.x + o.w, o.y + o.h * o.peak);
                    ctx.lineTo(o.x + o.w, o.y + o.h);
                    ctx.lineTo(o.x, o.y + o.h);
                } else {
                    ctx.moveTo(o.x, o.y);
                    ctx.lineTo(o.x, o.y + o.h * (1 - o.peak));
                    ctx.lineTo(o.x + o.w * 0.5, o.y + o.h);
                    ctx.lineTo(o.x + o.w, o.y + o.h);
                    ctx.lineTo(o.x + o.w, o.y);
                }
                ctx.closePath(); ctx.fill(); ctx.stroke();
            });
            rings.forEach(function (r) {
                ctx.save();
                ctx.translate(r.x, r.y);
                var radius = r.r * (1 + Math.sin(r.pulse) * 0.08);
                ctx.strokeStyle = r.collected ? 'rgba(184,134,11,.3)' : INK.gold;
                ctx.lineWidth = 2;
                ctx.beginPath(); ctx.arc(0, 0, radius, 0, Math.PI * 2); ctx.stroke();
                if (!r.collected) {
                    ctx.fillStyle = INK.gold;
                    ctx.beginPath(); ctx.arc(0, 0, 2, 0, Math.PI * 2); ctx.fill();
                }
                ctx.restore();
            });
            particles.forEach(function (p) {
                ctx.globalAlpha = p.life;
                ctx.fillStyle = p.color;
                ctx.beginPath(); ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2); ctx.fill();
            });
            ctx.globalAlpha = 1;

            ctx.save();
            ctx.translate(player.x, player.y);
            ctx.globalAlpha = 0.15;
            ctx.fillStyle = INK.green;
            ctx.beginPath(); ctx.ellipse(0, 0, player.w * 0.9, player.h * 1.5, 0, 0, Math.PI * 2); ctx.fill();
            ctx.globalAlpha = 1;
            ctx.fillStyle = INK.black;
            ctx.beginPath();
            ctx.moveTo(player.w / 2, 0); ctx.lineTo(0, -player.h / 2);
            ctx.lineTo(-player.w / 2, 0); ctx.lineTo(0, player.h / 2);
            ctx.closePath(); ctx.fill();
            ctx.fillStyle = INK.red;
            ctx.fillRect(-player.w / 2 - 2, -2, 4, 4);
            ctx.strokeStyle = INK.red; ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.moveTo(-player.w / 2 - 2, 0);
            ctx.quadraticCurveTo(-player.w / 2 - 10, Math.sin(frame * 0.2) * 3, -player.w / 2 - 16, 0);
            ctx.stroke();
            ctx.restore();
        }

        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('hidden')) { return; }
            if (e.code === 'ArrowUp' || e.code === 'KeyW') { keys.up = true; }
            if (e.code === 'ArrowDown' || e.code === 'KeyS') { keys.down = true; }
        });
        document.addEventListener('keyup', function (e) {
            if (e.code === 'ArrowUp' || e.code === 'KeyW') { keys.up = false; }
            if (e.code === 'ArrowDown' || e.code === 'KeyS') { keys.down = false; }
        });
        canvas.addEventListener('touchstart', function (e) {
            e.preventDefault();
            var rect = canvas.getBoundingClientRect();
            var y = (e.touches[0].clientY - rect.top) / rect.height * H;
            keys.up = y < player.y;
            keys.down = !keys.up;
        }, { passive: false });
        canvas.addEventListener('touchend', function () { keys.up = false; keys.down = false; });

        reset();
        fixedLoop(update, draw);
        return {
            start: function () { reset(); running = true; paused = false; btnPause.textContent = '⏸'; },
            togglePause: function () { if (!running) { return paused; } paused = !paused; return paused; }
        };
    }

    function makeSpace() {
        var W = m.w, H = m.h;
        var running = false, paused = false;
        var score = 0, hp = 3, maxHp = 3, frame = 0, spawnTimer = 0, spawnGap = 50, shootTimer = 0, shootDelay = 12;
        var keys = { left: false, right: false, fire: false };
        var player = { x: W / 2, y: H - 30, w: 20, h: 16, speed: 3.2, invincible: 0 };
        var bullets = [], rocks = [], particles = [], stars = [];
        for (var i = 0; i < 40; i++) {
            stars.push({ x: Math.random() * W, y: Math.random() * H, r: Math.random() * 1.2 + 0.3, speed: Math.random() * 0.6 + 0.2 });
        }

        function hud() {
            var bars = '';
            for (var j = 0; j < maxHp; j++) { bars += j < hp ? '█' : '░'; }
            setHud('SCORE ' + Math.floor(score), 'HP ' + bars);
        }
        function explode(x, y, color, count) {
            for (var i = 0; i < count; i++) {
                var a = Math.random() * Math.PI * 2, sp = rand(1, 4);
                particles.push({ x: x, y: y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, life: 1, color: color });
            }
        }
        function spawnRock() {
            var size = rand(12, 24);
            var pts = [];
            for (var i = 0; i < 7; i++) {
                var a = (i / 7) * Math.PI * 2, r = size / 2 * rand(0.7, 1.1);
                pts.push({ x: Math.cos(a) * r, y: Math.sin(a) * r });
            }
            rocks.push({ x: rand(size, W - size), y: -size, w: size, h: size, speed: rand(1.2, 2.4) + score / 200, rot: 0, rotSpeed: rand(-0.05, 0.05), shape: pts });
        }
        function fire() {
            if (shootTimer > 0) { return; }
            shootTimer = shootDelay;
            bullets.push({ x: player.x, y: player.y - player.h / 2, w: 3, h: 8, speed: 6 });
        }
        function reset() {
            player.x = W / 2; player.y = H - 30; player.invincible = 0;
            bullets = []; rocks = []; particles = [];
            score = 0; hp = maxHp; frame = 0; spawnTimer = 0; spawnGap = 50; shootTimer = 0;
            keys.left = false; keys.right = false; keys.fire = false;
            hud();
        }
        function update() {
            if (!running || paused) { return; }
            frame++;
            if (shootTimer > 0) { shootTimer--; }
            if (player.invincible > 0) { player.invincible--; }
            if (keys.left) { player.x -= player.speed; }
            if (keys.right) { player.x += player.speed; }
            if (player.x < player.w / 2) { player.x = player.w / 2; }
            if (player.x > W - player.w / 2) { player.x = W - player.w / 2; }
            if (keys.fire) { fire(); }
            for (var i = bullets.length - 1; i >= 0; i--) {
                var b = bullets[i];
                b.y -= b.speed;
                if (b.y + b.h < 0) { bullets.splice(i, 1); }
            }
            spawnTimer++;
            if (spawnTimer >= spawnGap) {
                spawnTimer = 0;
                spawnGap = Math.max(22, 50 - score / 20);
                spawnRock();
            }
            for (var j = rocks.length - 1; j >= 0; j--) {
                var r = rocks[j];
                r.y += r.speed;
                r.rot += r.rotSpeed;
                if (r.y - r.h > H) { rocks.splice(j, 1); continue; }
                if (player.invincible <= 0 && rectHit({ x: player.x - player.w / 2 + 3, y: player.y - player.h / 2 + 3, w: player.w - 6, h: player.h - 6 }, { x: r.x - r.w / 2, y: r.y - r.h / 2, w: r.w, h: r.h })) {
                    hp--;
                    player.invincible = 90;
                    explode(player.x, player.y, '#ff2d95', 20);
                    rocks.splice(j, 1);
                    hud();
                    if (hp <= 0) {
                        running = false;
                        onOver(score);
                        return;
                    }
                    continue;
                }
                for (var k = bullets.length - 1; k >= 0; k--) {
                    var bb = bullets[k];
                    if (rectHit({ x: bb.x, y: bb.y, w: bb.w, h: bb.h }, { x: r.x - r.w / 2, y: r.y - r.h / 2, w: r.w, h: r.h })) {
                        bullets.splice(k, 1);
                        explode(r.x, r.y, '#00e5ff', 12);
                        score += 10;
                        rocks.splice(j, 1);
                        hud();
                        break;
                    }
                }
            }
            for (var mm = particles.length - 1; mm >= 0; mm--) {
                var pp = particles[mm];
                pp.x += pp.vx; pp.y += pp.vy; pp.vx *= 0.94; pp.vy *= 0.94; pp.life -= 0.025;
                if (pp.life <= 0) { particles.splice(mm, 1); }
            }
            stars.forEach(function (s) {
                s.y += s.speed;
                if (s.y > H) { s.y = 0; s.x = Math.random() * W; }
            });
            score += 0.05;
            hud();
        }
        function draw() {
            var grad = ctx.createLinearGradient(0, 0, 0, H);
            grad.addColorStop(0, '#0a0a1a');
            grad.addColorStop(1, '#14142e');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, W, H);
            stars.forEach(function (s) {
                ctx.fillStyle = 'rgba(255,255,255,' + (0.3 + s.r / 2) + ')';
                ctx.fillRect(s.x, s.y, s.r, s.r);
            });
            particles.forEach(function (p) {
                ctx.globalAlpha = p.life;
                ctx.fillStyle = p.color;
                ctx.fillRect(p.x - 1, p.y - 1, 2, 2);
            });
            ctx.globalAlpha = 1;
            bullets.forEach(function (b) {
                ctx.fillStyle = '#00e5ff';
                ctx.shadowColor = '#00e5ff';
                ctx.shadowBlur = 6;
                ctx.fillRect(b.x - b.w / 2, b.y, b.w, b.h);
                ctx.shadowBlur = 0;
            });
            rocks.forEach(function (r) {
                ctx.save();
                ctx.translate(r.x, r.y);
                ctx.rotate(r.rot);
                ctx.strokeStyle = '#8a8ab0';
                ctx.fillStyle = '#2a2a4a';
                ctx.lineWidth = 2;
                ctx.beginPath();
                r.shape.forEach(function (pt, i) {
                    if (i === 0) { ctx.moveTo(pt.x, pt.y); } else { ctx.lineTo(pt.x, pt.y); }
                });
                ctx.closePath();
                ctx.fill(); ctx.stroke();
                ctx.fillStyle = '#4a4a6a';
                ctx.fillRect(-2, -2, 4, 4);
                ctx.restore();
            });
            if (!(player.invincible > 0 && Math.floor(frame / 4) % 2 === 0)) {
                ctx.save();
                ctx.translate(player.x, player.y);
                var w = player.w, h = player.h;
                ctx.fillStyle = '#00e5ff';
                ctx.shadowColor = '#00e5ff';
                ctx.shadowBlur = 8;
                ctx.beginPath();
                ctx.moveTo(0, -h / 2); ctx.lineTo(-w / 2, h / 2);
                ctx.lineTo(0, h / 2 - 4); ctx.lineTo(w / 2, h / 2);
                ctx.closePath(); ctx.fill();
                ctx.fillStyle = '#ffffff';
                ctx.shadowBlur = 4;
                ctx.beginPath(); ctx.arc(0, -2, 2.5, 0, Math.PI * 2); ctx.fill();
                ctx.shadowBlur = 8;
                ctx.fillStyle = '#ff2d95';
                var flame = 4 + Math.random() * 4;
                ctx.beginPath();
                ctx.moveTo(-3, h / 2 - 2); ctx.lineTo(0, h / 2 + flame); ctx.lineTo(3, h / 2 - 2);
                ctx.closePath(); ctx.fill();
                ctx.restore();
            }
        }

        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('hidden')) { return; }
            var tag = (e.target && e.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }
            if (e.code === 'ArrowLeft' || e.code === 'KeyA') { keys.left = true; }
            if (e.code === 'ArrowRight' || e.code === 'KeyD') { keys.right = true; }
            if (e.code === 'Space') { e.preventDefault(); keys.fire = true; }
        });
        document.addEventListener('keyup', function (e) {
            if (e.code === 'ArrowLeft' || e.code === 'KeyA') { keys.left = false; }
            if (e.code === 'ArrowRight' || e.code === 'KeyD') { keys.right = false; }
            if (e.code === 'Space') { keys.fire = false; }
        });
        canvas.addEventListener('mousedown', function () { keys.fire = true; });
        canvas.addEventListener('mouseup', function () { keys.fire = false; });
        canvas.addEventListener('touchstart', function (e) {
            e.preventDefault();
            var rect = canvas.getBoundingClientRect();
            var x = (e.touches[0].clientX - rect.left) / rect.width * W;
            player.x = Math.max(player.w / 2, Math.min(W - player.w / 2, x));
            keys.fire = true;
        }, { passive: false });
        canvas.addEventListener('touchend', function () { keys.fire = false; });

        reset();
        fixedLoop(update, draw);
        return {
            start: function () { reset(); running = true; paused = false; btnPause.textContent = '⏸'; },
            togglePause: function () { if (!running) { return paused; } paused = !paused; return paused; }
        };
    }

    var factories = { farm: makeFarm, mario: makeMario, ink: makeInk, space: makeSpace };
    game = factories[tpl]();
    showOverlay('start');
})();
