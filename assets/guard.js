(function (w, d) {
    'use strict';
    if (w.NBGuard) { return; }

    var S = {
        wd: 0, hp: 0, dt: 0, hk: 0,
        tt: -1, mm: 0, kk: 0, tc: 0, sc: 0, ck: 0,
        pl: -1, wg: 0, lg: ''
    };
    var T0 = Date.now();
    var _TS = Function.prototype.toString;
    var _SIG = '';
    try { _SIG = _TS.call(_TS); } catch (e) { }

    function on(el, tp, fn, pv) {
        try { el.addEventListener(tp, fn, pv ? { passive: true } : false); } catch (e) { }
    }

    function env() {
        try {
            if (navigator['\x77\x65\x62\x64\x72\x69\x76\x65\x72'] === true) { S.wd = 1; }
        } catch (e) { }
        try {
            S.pl = navigator.plugins ? navigator.plugins.length : -1;
        } catch (e) { }
        try {
            if (navigator.languages && navigator.languages.length) {
                S.lg = navigator.languages.join(',');
            } else if (navigator.language) {
                S.lg = navigator.language;
            }
        } catch (e) { }
        try {
            var c = d.createElement('canvas');
            var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
            S.wg = gl ? 0 : 1;
        } catch (e) { S.wg = 1; }
    }

    function acts() {
        var t = 0;
        on(d, 'mousemove', function () {
            var n = Date.now();
            if (n - t < 50) { return; }
            t = n;
            S.mm++;
        }, true);
        on(d, 'keydown', function () { S.kk++; }, true);
        on(d, 'touchstart', function () { S.tc++; }, true);
        on(d, 'scroll', function () { S.sc++; }, true);
        on(d, 'click', function () { S.ck++; }, true);
    }

    var SH = 0;
    function size() {
        try {
            var bad = (w.outerWidth - w.innerWidth > 170) || (w.outerHeight - w.innerHeight > 200);
            if (bad) {
                SH++;
                if (SH >= 2) { S.dt = 1; }
            } else {
                SH = 0;
                S.dt = 0;
            }
        } catch (e) { }
    }

    var _D = null;
    try { _D = new Function('\x64\x65\x62\x75\x67\x67\x65\x72'); } catch (e) { }
    function dbg() {
        try {
            if (!_D || !w.performance || !performance.now) { return; }
            var t = performance.now();
            _D();
            if (performance.now() - t > 200) { S.dt = 1; }
        } catch (e) { }
    }

    var NAT = '\x5bnative code\x5d';
    function hooked(fn) {
        try {
            if (typeof fn !== 'function') { return false; }
            return _TS.call(fn).indexOf(NAT) === -1;
        } catch (e) { return false; }
    }

    function hk() {
        try {
            if (_SIG && _TS.call(_TS) !== _SIG) { S.hk = 1; return; }
            if (hooked(w.fetch)) { S.hk = 1; return; }
            if (w.XMLHttpRequest && w.XMLHttpRequest.prototype
                && hooked(w.XMLHttpRequest.prototype.open)) { S.hk = 1; return; }
            if (hooked(JSON.stringify)) { S.hk = 1; }
        } catch (e) { }
    }

    var HP = 'nbxq7f3a';
    function plant() {
        try {
            if (d.getElementById(HP)) { return; }
            var box = d.createElement('div');
            box.id = HP;
            box.setAttribute('aria-hidden', 'true');
            box.style.cssText = 'position:absolute!important;left:-9999px!important;'
                              + 'top:-9999px!important;width:1px;height:1px;overflow:hidden;';
            box.innerHTML =
                '<label>备注<input type="text" name="nbxq7f3a" tabindex="-1"'
              + ' autocomplete="new-password" autocorrect="off" spellcheck="false"></label>'
              + '<label>参考<input type="text" name="nbxq8c21" tabindex="-1"'
              + ' autocomplete="new-password" autocorrect="off" spellcheck="false"></label>';

            (d.body || d.documentElement).appendChild(box);

            var ins = box.getElementsByTagName('input');
            var mark = function () { S.hp = 1; };
            for (var i = 0; i < ins.length; i++) {
                on(ins[i], 'input', mark);
                on(ins[i], 'change', mark);
            }
        } catch (e) { }
    }

    function hp() {
        try {
            var box = d.getElementById(HP);
            if (!box) { return; }
            var ins = box.getElementsByTagName('input');
            for (var i = 0; i < ins.length; i++) {
                if (ins[i].value !== '') { S.hp = 1; return; }
            }
        } catch (e) { }
    }

    function payload() {
        hp();
        hk();
        S.tt = Date.now() - T0;
        return {
            wd: S.wd, hp: S.hp, dt: S.dt, hk: S.hk,
            tt: S.tt, mm: S.mm, kk: S.kk, tc: S.tc, sc: S.sc, ck: S.ck,
            pl: S.pl, wg: S.wg, lg: S.lg
        };
    }

    function attach(body) {
        try {
            if (body && typeof body === 'object') { body._g = payload(); }
        } catch (e) { }
        return body;
    }

    w.NBGuard = {
        payload: payload,
        attach: attach,
        signal: function () { return S; }
    };

    function boot() {
        env();
        acts();
        plant();
        size();
        setInterval(size, 1200);
        setTimeout(dbg, 2500);
    }

    try {
        if (d.readyState === 'loading') {
            on(d, 'DOMContentLoaded', boot);
        } else {
            boot();
        }
    } catch (e) { }
})(window, document);
