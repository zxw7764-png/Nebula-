/* 洛圣都 GTA 模板 · 交互音效
-------------------------------------------------------------------
官网 + 发卡网自动加载（模板文件夹放 interact.js 即生效）。
仅保留点击音 -> sfx/click.mp3（确认电子音）。悬停音效已取消。
音效文件为 CC0 免费素材，位于本模板 sfx/ 目录。
懒加载 Audio，带静音开关（localStorage: nb_sfx_muted）。
控制台：NebulaSFX.muted = true / false
-------------------------------------------------------------------*/
(function () {
    'use strict';
    var base = '';
    // 以本脚本 src 定位模板根，保证 sfx/ 相对路径正确。
    // 官方注入的 src 形如 ".../gta5/interact.js?v=xxx"，需兼容末尾查询串。
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
        var src = scripts[i].getAttribute('src') || '';
        var m = src.replace(/\?.*$/, '').match(/^(.*\/)interact\.js$/);
        if (m) { base = m[1]; break; }
    }
    var muted = false;
    try { muted = localStorage.getItem('nb_sfx_muted') === '1'; } catch (e) {}

    var audioEl = null;
    function audio() {
        if (!audioEl) {
            var a = new Audio(base + 'sfx/click.mp3');
            a.preload = 'auto';
            audioEl = a;
        }
        return audioEl;
    }

    function play() {
        if (muted) { return; }
        try {
            // 克隆，避免快速连点打断上一个
            var c = audio().cloneNode(true);
            c.volume = 0.55;
            var p = c.play();
            if (p && p.catch) { p.catch(function () {}); }
        } catch (e) { /* ignore */ }
    }

    // 点击音：用 pointerdown（按下即响，更即时）
    document.addEventListener('pointerdown', function (e) {
        if (e.target && e.target.closest && e.target.closest('a, button')) {
            play();
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