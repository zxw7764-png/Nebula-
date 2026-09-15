/**
 * 统一输入过滤（全站引入）
 * 根据输入框 name/id/autocomplete 自动识别字段类型，仅放行对应字符：
 *   num   纯数字        —— 手机号/端口/QQ 等
 *   alnum 字母+数字      —— 用户名/验证码/卡密等
 *   id    字母+数字+_-  —— 前缀/标识符等
 *   host  域名/主机字符  —— 字母数字 . - :
 *   ascii 可见英文符号  —— 密码（含常用符号，不含中文/空格外控制符）
 * 未匹配到的输入框（中文文本类）不做限制。
 */
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
        // 后台/动态表单：监听新增节点自动绑定
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
