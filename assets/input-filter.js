(function () {
    'use strict';

    var RULES = {
        num:   /[^0-9]/g,
        alnum: /[^A-Za-z0-9]/g,
        id:    /[^A-Za-z0-9_\-]/g,
        host:  /[^A-Za-z0-9.\-:]/g,
        ascii: /[^\x21-\x7E]/g
    };

    function ruleFor(el) {
        var k = ((el.name || '') + ' ' + (el.id || '') + ' ' +
                 (el.getAttribute('autocomplete') || '') + ' ' +
                 (el.getAttribute('data-filter') || '')).toLowerCase();
        var df = el.getAttribute('data-filter');
        if (df && RULES[df]) return df;
        if (/phone|mobile|\btel\b|\bport\b|qq/.test(k)) return 'num';
        if (/captcha|verify|card_?key|cardnum/.test(k)) return 'alnum';
        if (/prefix/.test(k)) return 'id';
        if (/host/.test(k)) return 'host';
        if (/user|account/.test(k)) return 'alnum';
        if (/pass/.test(k)) return 'ascii';
        return null;
    }

    function bind(el) {
        if (el.__nbFilter) return;
        var rule = ruleFor(el);
        if (!rule || !RULES[rule]) return;
        el.__nbFilter = rule;
        el.addEventListener('input', function () {
            var re = RULES[rule], s = el.value;
            if (re.test(s)) {
                re.lastIndex = 0;
                el.value = s.replace(re, '');
            }
            re.lastIndex = 0;
        });
    }

    function bindAll(root) {
        (root.querySelectorAll ? root.querySelectorAll('input[type="text"],input[type="password"],input[type="tel"],input[type="number"],input:not([type])') : []).forEach(bind);
    }

    function init() {
        bindAll(document);

        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    for (var i = 0; i < m.addedNodes.length; i++) {
                        var n = m.addedNodes[i];
                        if (n.nodeType !== 1) continue;
                        if (n.tagName === 'INPUT') bind(n);
                        else if (n.querySelectorAll) bindAll(n);
                    }
                });
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.nbBindInputFilter = bindAll;
})();
