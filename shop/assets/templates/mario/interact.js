/* ======================================================================
 * mario/interact.js — 马里奥模板交互音效（单文件 css 模板的同名文件夹资源）
 * ------------------------------------------------------------------
 * 官网与发卡网检测到 <id>/interact.js 时会在页面底部自动加载本文件
 * （发卡网悬停含 .goods-card 商品卡与分类 / 商品挑选按钮）。
 * 规范见 docs/TEMPLATE.md「官网交互音效」：Web Audio 合成、
 * 懒创建 AudioContext、静音状态存 localStorage、悬停节流防炸响。
 * ====================================================================== */
(function () {
    'use strict';
    var AC = null, muted = false;
    try { muted = localStorage.getItem('nb_sfx_muted') === '1'; } catch (e) {}

    function actx() {
        if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); }
        if (AC.state === 'suspended') { AC.resume(); }   // 首次点击内自动解锁
        return AC;
    }

    /* 浏览器自动播放策略：首次 pointerdown 提前创建并解锁 AudioContext，
       保证「第一次点击」就有声音（click 里再 resume 会因异步晚半拍） */
    document.addEventListener('pointerdown', function () { try { actx(); } catch (e) {} },
        { once: true, capture: true });

    function tone(freq, dur, type, vol) {
        if (muted) { return; }
        try {
            var a = actx(), t = a.currentTime;
            var o = a.createOscillator(), g = a.createGain();
            o.type = type || 'sine';
            o.frequency.value = freq;
            g.gain.setValueAtTime(vol || 0.04, t);
            g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            o.connect(g); g.connect(a.destination);
            o.start(t); o.stop(t + dur + 0.02);
        } catch (e) { /* 音频不可用静默忽略 */ }
    }

    /* 悬停：轻柔短音（委托 + 80ms 节流，防止扫过一排按钮时连环炸响） */
    var lastHover = 0;
    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('a, button, .goods-card, .plan, .card, .seller') : null;
        if (!el) { return; }
        var now = Date.now();
        if (now - lastHover < 80) { return; }
        lastHover = now;
        tone(1180, 0.05, 'sine', 0.035);
    });

    /* 点击：确认音 */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a, button')) {
            tone(660, 0.09, 'triangle', 0.07);
        }
    });

    /* 静音开关：控制台 NebulaSFX.muted = true / false（状态记住） */
    window.NebulaSFX = {
        get muted() { return muted; },
        set muted(v) {
            muted = !!v;
            try { localStorage.setItem('nb_sfx_muted', muted ? '1' : '0'); } catch (e) {}
        }
    };
})();
