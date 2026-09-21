(function () {
    'use strict';

    var CFG = (window.parent && window.parent.__NB_GAMES__) || {};
    var API = new URL(CFG.api || 'api.php', window.parent.location.href).toString();
    var CSRF = String(CFG.csrf || '');
    var GAME = 'lol';

    var CFGC = CFG.cfg || {};
    var GID = String(CFGC.game || GAME);
    var DURATION = Math.max(10, Math.min(300,
        parseInt((CFGC.durations || {})[GID], 10) || parseInt(CFGC.duration, 10) || 30));
    var TOPN = Math.max(3, Math.min(20, parseInt(CFGC.topN, 10) || 10));
    var BEST_KEY = 'nb_game_best_' + GAME;
    var NAME_KEY = 'nb_my_name';

    var canvas = document.getElementById('game-canvas');
    var ctx = canvas.getContext('2d');
    var overlay = document.getElementById('game-overlay');
    var boardEl = document.getElementById('board');
    var bestLine = document.getElementById('best-line');
    var hudGold = document.getElementById('hud-gold');
    var hudTime = document.getElementById('hud-time');
    var btnStart = document.getElementById('btn-start');
    var nameInput = document.querySelector('#game-overlay input.name');
    var toastEl = document.getElementById('toast');

    var LOGGED = String(CFG.name || '').trim();
    if (LOGGED) {
        nameInput.style.display = 'none';
    } else {
        nameInput.value = localStorage.getItem(NAME_KEY) || '';
    }
    function playerName() {
        if (LOGGED) { return LOGGED; }
        var n = nameInput.value.trim().substring(0, 16);
        if (n) { try { localStorage.setItem(NAME_KEY, n); } catch (e) {} }
        return n || '匿名召唤师';
    }

    var toastTimer = 0;
    function toast(msg, isErr) {
        toastEl.textContent = msg;
        toastEl.classList.toggle('err', !!isErr);
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2500);
    }

    function loadBoard() {
        fetch(API + '?action=game_top&game=' + GAME, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var list = (j.data && j.data.list) || [];
                boardEl.innerHTML = list.length
                    ? '🏆 <b>峡谷榜 TOP' + Math.min(list.length, 5) + '</b><br>' + list.slice(0, 5).map(function (x, i) {
                        return (i + 1) + '. ' + esc(String(x.name)) + ' — <em>' + Number(x.score) + '</em> 金币';
                      }).join('<br>')
                    : '🏆 排行榜虚位以待，快来抢第一！';
            })
            .catch(function () { boardEl.textContent = '🏆 排行榜暂不可用'; });
    }

    var rankPanel = document.getElementById('game-rank');
    var rankList = document.getElementById('rank-list');
    var btnRank = document.getElementById('btn-rank');
    var btnRankClose = document.getElementById('btn-rank-close');
    var pausedByRank = false;

    function loadRank() {
        rankList.innerHTML = '<div class="empty">加载中…</div>';
        fetch(API + '?action=game_top&game=' + GAME, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var list = (j.data && j.data.list) || [];
                rankList.innerHTML = list.length
                    ? list.slice(0, TOPN).map(function (x, i) {
                        var cls = i === 0 ? ' top1' : i === 1 ? ' top2' : i === 2 ? ' top3' : '';
                        return '<div class="row' + cls + '"><span class="no">' + (i + 1) + '</span>' +
                            '<span class="nm">' + esc(String(x.name)) + '</span>' +
                            '<span class="sc">' + Number(x.score) + ' 金币</span></div>';
                      }).join('')
                    : '<div class="empty">暂无上榜记录，快来抢第一！</div>';
            })
            .catch(function () { rankList.innerHTML = '<div class="empty">榜单暂不可用</div>'; });
    }

    btnRank.addEventListener('click', function () {
        var show = !rankPanel.classList.contains('show');
        rankPanel.classList.toggle('show', show);
        if (show) {
            pausedByRank = state.running;
            state.running = false;
            loadRank();
        } else if (pausedByRank && !state.over) {
            state.running = true;
        }
    });
    btnRankClose.addEventListener('click', function () {
        rankPanel.classList.remove('show');
        if (pausedByRank && !state.over) { state.running = true; }
    });

    var btnPause = document.getElementById('btn-pause');
    var hintEl = document.getElementById('game-hint');
    var HINT_DEF = '点击小兵补刀 · 每刀 +10 金币 · ' + DURATION + ' 秒限时';

    function setPaused(paused) {
        state.running = !paused;
        btnPause.textContent = paused ? '▶' : '⏸';
        btnPause.title = paused ? '继续' : '暂停';
        hintEl.textContent = paused ? '已暂停 · 点 ▶ 继续' : HINT_DEF;
    }

    btnPause.addEventListener('click', function () {
        if (state.over || rankPanel.classList.contains('show')) { return; }
        if (state.running) { setPaused(true); }
        else if (state.left > 0 && state.left < DURATION) { setPaused(false); }
    });

    function esc(s) {
        return s.replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function submitScore(gold) {
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'game_score_save', game: GAME, name: playerName(), score: gold, csrf: CSRF })
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.code === 0) { toast('✅ 已上榜：' + playerName() + ' · ' + gold + ' 金币'); }
            else { toast('❌ ' + (j.msg || '提交失败'), true); }
            loadBoard();
        }).catch(function () { toast('❌ 网络错误', true); });
    }

    var W = 320, H = 200;
    var state = { running: false, over: false, gold: 0, left: DURATION, frame: 0, spawn: 0, gap: 70 };
    var minions = [];
    var sparks = [];
    var best = Number(localStorage.getItem(BEST_KEY) || 0);

    function fit() {
        canvas.width = W;
        canvas.height = H;
    }
    fit();

    function updateHud() {
        hudGold.textContent = '💰 ' + state.gold;
        hudTime.textContent = '⏱ ' + state.left + 's';
    }

    function paintBest() {
        bestLine.textContent = '最高纪录 ' + best + ' 金币';
    }

    function spawn() {
        minions.push({
            x: W + 20,
            y: 40 + Math.random() * (H - 90),
            w: 22, h: 16,
            speed: 0.7 + Math.random() * 0.5 + state.gold / 260,
            phase: Math.random() * 6
        });
    }

    function hit(x, y) {
        for (var i = minions.length - 1; i >= 0; i--) {
            var m = minions[i];
            if (x >= m.x - 14 && x <= m.x + m.w + 6 && y >= m.y - 12 && y <= m.y + m.h + 8) {
                minions.splice(i, 1);
                state.gold += 10;
                sfx('coin');
                for (var k = 0; k < 8; k++) {
                    sparks.push({ x: m.x + m.w / 2, y: m.y + m.h / 2, vx: (Math.random() - .5) * 4, vy: (Math.random() - .5) * 4, life: 1 });
                }
                updateHud();
                return;
            }
        }
    }

    var last = 0, acc = 0, STEP = 1000 / 60;

    function loop(t) {
        requestAnimationFrame(loop);
        if (!state.running) { draw(); return; }
        if (!last) { last = t; }
        acc += Math.min(t - last, 100);
        last = t;
        while (acc >= STEP) { update(); acc -= STEP; }
        draw();
    }

    function update() {
        state.frame++;
        if (state.frame % 60 === 0 && state.left > 0) {
            state.left--;
            updateHud();
            if (state.left === 0) { return gameOver(); }
        }
        state.spawn++;
        if (state.spawn >= Math.max(26, state.gap - state.gold / 12)) {
            state.spawn = 0;
            spawn();
        }
        for (var i = minions.length - 1; i >= 0; i--) {
            var m = minions[i];
            m.x -= m.speed;
            m.phase += 0.2;
            if (m.x + m.w < 0) { minions.splice(i, 1); }
        }
        for (var j = sparks.length - 1; j >= 0; j--) {
            var s = sparks[j];
            s.x += s.vx; s.y += s.vy; s.life -= 0.05;
            if (s.life <= 0) { sparks.splice(j, 1); }
        }
    }

    function draw() {

        var grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, '#0a1428');
        grad.addColorStop(1, '#010a13');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, W, H);

        ctx.strokeStyle = 'rgba(200,170,110,.14)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, H - 26); ctx.lineTo(W, H - 26);
        ctx.stroke();

        minions.forEach(function (m) {
            var bob = Math.sin(m.phase) * 2;
            ctx.fillStyle = '#be1e37';
            ctx.fillRect(m.x, m.y + bob, m.w, m.h);
            ctx.fillStyle = '#7a1424';
            ctx.fillRect(m.x, m.y + bob + m.h - 4, m.w, 4);
            ctx.fillStyle = '#f0e6d2';
            ctx.fillRect(m.x + 3, m.y + bob + 4, 3, 3);

            ctx.fillStyle = '#03141a';
            ctx.fillRect(m.x - 2, m.y + bob - 6, m.w + 4, 3);
            ctx.fillStyle = '#0ac8b9';
            ctx.fillRect(m.x - 2, m.y + bob - 6, (m.w + 4) * .6, 3);
        });

        sparks.forEach(function (s) {
            ctx.globalAlpha = s.life;
            ctx.fillStyle = '#c8aa6e';
            ctx.fillRect(s.x - 1, s.y - 1, 3, 3);
        });
        ctx.globalAlpha = 1;

        ctx.fillStyle = '#0a323c';
        ctx.fillRect(W - 26, H - 66, 18, 40);
        ctx.fillStyle = '#c8aa6e';
        ctx.fillRect(W - 26, H - 66, 18, 3);
    }

    function startGame() {
        minions = [];
        sparks = [];
        state.running = true;
        state.over = false;
        state.gold = 0;
        state.left = DURATION;
        state.frame = 0;
        state.spawn = 0;
        btnPause.textContent = '⏸';
        btnPause.title = '暂停';
        hintEl.textContent = HINT_DEF;
        updateHud();
        overlay.classList.add('hidden');
    }

    function gameOver() {
        state.running = false;
        state.over = true;
        sfx('over');
        btnPause.textContent = '⏸';
        btnPause.title = '暂停';
        hintEl.textContent = HINT_DEF;
        var g = state.gold;
        if (g > best) {
            best = g;
            try { localStorage.setItem(BEST_KEY, String(g)); } catch (e) {}
        }
        paintBest();
        overlay.querySelector('h4').textContent = '⚔ 补刀结算';
        overlay.querySelector('p').textContent = '本次 ' + g + ' 金币 · 最高 ' + best;
        btnStart.textContent = '再来一波';
        overlay.classList.remove('hidden');
        if (g > 0) { submitScore(g); }
    }

    btnStart.addEventListener('click', function (e) {
        e.stopPropagation();
        startGame();
    });

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay && !state.running && state.left === 0) { startGame(); }
    });

    function toGameXY(e) {
        var r = canvas.getBoundingClientRect();
        return {
            x: (e.clientX - r.left) / r.width * W,
            y: (e.clientY - r.top) / r.height * H
        };
    }
    canvas.addEventListener('mousedown', function (e) {
        e.preventDefault();
        if (!state.running) { return; }
        var p = toGameXY(e);
        hit(p.x, p.y);
    });
    canvas.addEventListener('touchstart', function (e) {
        e.preventDefault();
        if (!state.running) { return; }
        var t = e.touches[0];
        var p = toGameXY(t);
        hit(p.x, p.y);
    }, { passive: false });

    document.querySelector('#game-rank .rank-head b').textContent = '⚔ 峡谷榜 · TOP' + TOPN;
    hintEl.textContent = HINT_DEF;
    updateHud();
    paintBest();
    loadBoard();
    requestAnimationFrame(loop);
})();
