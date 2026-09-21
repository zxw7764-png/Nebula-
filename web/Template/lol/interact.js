(function () {
    'use strict';
    var AC = null, muted = false;
    try { muted = localStorage.getItem('nb_sfx_muted') === '1'; } catch (e) {}

    function actx() {
        if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); }
        if (AC.state === 'suspended') { AC.resume(); }
        return AC;
    }

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
        } catch (e) {
 }
    }

    var lastHover = 0;
    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('a, button, .plan, .card, .seller, .goods-card') : null;
        if (!el) { return; }
        var now = Date.now();
        if (now - lastHover < 80) { return; }
        lastHover = now;
        tone(1180, 0.05, 'sine', 0.035);
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a, button')) {
            tone(660, 0.09, 'triangle', 0.07);
        }
    });

    window.NebulaSFX = {
        get muted() { return muted; },
        set muted(v) {
            muted = !!v;
            try { localStorage.setItem('nb_sfx_muted', muted ? '1' : '0'); } catch (e) {}
        }
    };
})();
