(function () {
    'use strict';

    var S = window.__NB_SHOP__ || {};
    var $ = function (sel) { return document.querySelector(sel); };

    function toast(msg, type, ms) {
        var box = $('#toasts');
        if (!box) { return; }
        var el = document.createElement('div');
        el.className = 'toast' + (type ? ' ' + type : '');
        el.textContent = msg;
        box.appendChild(el);
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity .25s';
            setTimeout(function () { el.remove(); }, 260);
        }, ms || 3000);
    }

    function customConfirm(title, message, onConfirm) {
        var m = document.createElement('div');
        m.className = 'modal';
        m.innerHTML = '<div class="modal-card" style="max-width:400px;text-align:center">'
            + '<h3 class="modal-title" style="text-align:center">' + esc(title) + '</h3>'
            + '<p style="color:var(--text-sub);margin-bottom:20px;line-height:1.6">' + esc(message) + '</p>'
            + '<div style="display:flex;gap:10px;justify-content:center">'
            + '<button class="btn ghost" type="button" data-cancel style="min-width:90px">取消</button>'
            + '<button class="btn primary" type="button" data-ok style="min-width:90px">确定</button>'
            + '</div></div>';
        document.body.appendChild(m);
        m.hidden = false;
        function close() { m.remove(); }
        m.querySelector('[data-cancel]').addEventListener('click', close);
        m.querySelector('[data-ok]').addEventListener('click', function () {
            close();
            if (onConfirm) { onConfirm(); }
        });
        backdropClose(m, close);
        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', handler); }
        });
        m.querySelector('[data-ok]').focus();
    }

    function api(action, data, cb) {
        var body = data || {};
        body.action = action;
        if (window.NBGuard) { try { NBGuard.attach(body); } catch (e) { } }
        fetch(S.api + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF': S.csrf || ''
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (j) {
            cb(j);
        }).catch(function () {
            cb({ code: 5000, msg: '网络异常，请稍后重试' });
        });
    }

    var currentPlan = null;
    var modal = $('#orderModal');
    var pageHost = $('#detailPage');
    if (!modal) { return; }
    var host = null;

    function yuan(fen) {
        return (fen / 100).toFixed(2);
    }

    function scopeCss(css, scope) {
        var out = '', i = 0, n = css.length;
        function parseBlock() {
            while (i < n) {
                var start = i;
                while (i < n && css.charAt(i) !== '{' && css.charAt(i) !== '}') { i++; }
                if (i >= n) { out += css.slice(start); return; }
                var head = css.slice(start, i).trim();
                if (css.charAt(i) === '}') { out += css.slice(start, i); i++; return; }
                i++;
                if (/^@(media|supports|layer|container)/i.test(head)) {
                    out += head + '{';
                    parseBlock();
                    continue;
                }
                if (head.charAt(0) === '@') {

                    var innerStart = i, depth = 1;
                    while (i < n && depth > 0) {
                        if (css.charAt(i) === '{') { depth++; }
                        else if (css.charAt(i) === '}') { depth--; }
                        i++;
                    }
                    out += head + '{' + css.slice(innerStart, i);
                    continue;
                }

                var declStart = i, d2 = 0;
                while (i < n) {
                    var c = css.charAt(i);
                    if (c === '{') { d2++; }
                    else if (c === '}') { if (d2 === 0) { break; } d2--; }
                    i++;
                }
                var decls = css.slice(declStart, i);
                if (i < n) { i++; }
                var sels = head.split(',').map(function (s) {
                    s = s.trim();
                    if (!s) { return s; }
                    if (/^body\b/i.test(s)) { return scope + s.replace(/^body/i, ''); }
                    if (/^html\b/i.test(s)) { return scope + ' ' + s.replace(/^html\s*/i, ''); }
                    return scope + ' ' + s;
                }).join(', ');
                out += sels + '{' + decls + '}';
            }
        }
        parseBlock();
        return out;
    }

    function detailFragment(src) {
        var s = String(src), css = '';
        s = s.replace(/<style[^>]*>([\s\S]*?)<\/style>/gi, function (m, inner) {
            css += scopeCss(inner, '.dp-intro.html') + '\n';
            return '';
        });
        s = s.replace(/<!DOCTYPE[^>]*>/gi, '')
            .replace(/<head[\s\S]*?<\/head>/gi, '')
            .replace(/<\/?(html|body)[^>]*>/gi, '')
            .replace(/<(meta|title|link)\b[^>]*>/gi, '');
        return { html: s, css: css };
    }

    function cdurText(c) {
        var v = parseInt(c.card_duration, 10) || 0;
        var t = Number(c.card_type);
        if (t === 4 || v <= 0) return '';
        if (t === 2) return v + ' 点数';
        if (t === 3) return v + ' 次';
        var U = [[31536000, '年'], [2592000, '月'], [604800, '星期'], [86400, '天'], [3600, '小时'], [60, '分钟']];
        for (var i = 0; i < U.length; i++) { if (v % U[i][0] === 0) return (v / U[i][0]) + ' ' + U[i][1]; }
        return v + ' 秒';
    }

    function unitText(c) {
        var t = Number(c.card_type);
        if (t === 4) return '永久';
        return cdurText(c);
    }

    function buildDetail(plan) {
        var isManual = !!S.manual;
        var media = plan.icon
            ? '<img src="' + esc(plan.icon) + '" alt="' + esc(plan.name) + '" referrerpolicy="no-referrer">'
            : '<div class="dp-ph">' + esc((plan.name || '?').slice(0, 1)) + '</div>';

        var tags = '<span class="dp-tag">' + (isManual ? '人工发货' : '自动发货') + '</span>';
        if (plan.highlight) { tags += '<span class="dp-tag hot">推荐</span>'; }
        if (plan.badge) { tags += '<span class="dp-tag hot">' + esc(plan.badge) + '</span>'; }
        if (plan.is_ext) {

            tags += plan.stock > 0
                ? '<span class="dp-tag">库存 <b>' + esc(plan.stock) + '</b> 张</span>'
                : '<span class="dp-tag out">暂时缺货</span>';
            tags += '<span class="dp-tag">付款后自动发卡</span>';
        } else {
            var genTip = { stock: '售完即止，缺货订单转人工补发', auto: '优先发库存卡密，缺货时自动生成新卡发货', gen: '每单自动生成全新卡密，无需担心缺货' }[S.genmode || 'stock'];
            tags += plan.stock > 0
                ? '<span class="dp-tag">库存 <b>' + esc(plan.stock) + '</b> 张</span>'
                : '<span class="dp-tag out">' + (S.genmode === 'stock' ? '暂时缺货' : '可自动生成') + '</span>';
            tags += '<span class="dp-tag">' + esc(genTip) + '</span>';
        }

        var payBlock;
        if (!isManual) {
            var chans = Array.isArray(S.channels) && S.channels.length ? S.channels
                : [{ value: 'alipay', label: '支付宝' }, { value: 'wxpay', label: '微信支付' }, { value: 'qqpay', label: 'QQ钱包' }];
            payBlock = '<div class="field"><label>支付方式</label><div class="channels" data-ch>'
                + chans.map(function (c, i) {
                    return '<label class="channel' + (i === 0 ? ' on' : '') + '"><input type="radio" name="channel" value="' + esc(c.value) + '"' + (i === 0 ? ' checked' : '') + '>' + esc(c.label || c.value) + '</label>';
                }).join('')
                + '</div></div>'
                + '<p class="pay-tip">点击「去支付」后将跳转到收银台完成付款，支付成功自动发卡。</p>';
        } else {
            payBlock = '<div class="manual-pay">'
                + (S.qrcode ? '<img class="qrcode" src="' + esc(S.qrcode) + '" alt="收款码" referrerpolicy="no-referrer">' : '')
                + '<p class="pay-tip">' + (S.qrcode
                    ? '请先扫码付款，再点击「我已付款」，管理员确认后发货。'
                    : '本商品为人工发货：点击「我已付款」提交订单，并按联系方式与我们确认。')
                + '</p></div>';
        }

        var pts = '';
        if (plan.points && plan.points.length) {
            pts = '<ul class="gd-points">';
            plan.points.forEach(function (pt) { pts += '<li>' + esc(pt) + '</li>'; });
            pts += '</ul>';
        }

        var specs = (Array.isArray(plan.cards) ? plan.cards : []).filter(function (c) { return Number(c.price) >= 0; });
        var specBlock = '';
        if (specs.length > 1) {
            specBlock = '<div class="field"><label>选择规格</label><div class="spec-list" data-specs>'
                + specs.map(function (c, i) {
                    return '<label class="spec-item' + (i === 0 ? ' on' : '') + '" data-sp="' + i + '" data-price="' + esc(c.price) + '" data-unit="' + esc(unitText(c)) + '">'
                        + '<input type="radio" name="spec" value="' + i + '"' + (i === 0 ? ' checked' : '') + '>'
                        + '<span class="sp-name">' + esc(c.card_type_text || c.type_text || ('规格 ' + (i + 1))) + '</span>'
                        + '</label>';
                }).join('')
                + '</div></div>';
        }

        var detailHtml = '';
        if (plan.detail) {
            if (/<[a-z!/][^>]*>/i.test(plan.detail)) {
                var frag = detailFragment(plan.detail);
                detailHtml = '<div class="dp-intro html">' + frag.html
                    + (frag.css ? '<style>' + frag.css + '</style>' : '') + '</div>';
            } else {
                detailHtml = '<p class="dp-intro pre">' + esc(plan.detail) + '</p>';
            }
        } else if (plan.intro) {
            detailHtml = '<p class="dp-intro">' + esc(plan.intro) + '</p>';
        }

        var firstPrice = specs.length ? effPrice(specs[0], plan.shop_price) : (parseFloat(plan.shop_price) || 0);

        return '<div class="dp-grid">'
            + '<div class="dp-media">' + media + '</div>'
            + '<div class="dp-side">'
            + '<h2 class="dp-name">' + esc(plan.name) + '</h2>'
            + '<div class="dp-tags">' + tags + '</div>'
            + '<div class="dp-price">&yen;<b data-price>' + firstPrice.toFixed(2) + '</b>' + (specs.length > 1 && unitText(specs[0]) ? '<span class="dp-unit">/' + esc(unitText(specs[0])) + '</span>' : '') + '</div>'
            + specBlock
            + (S.user
                ? '<div class="logged-tip">已登录 <b>' + esc(S.user.nickname) + '</b>，下单免填凭证，订单自动关联账号，可在「我的订单」随时查看</div>'
                : '<div class="field"><label>' + esc(S.clabel || '联系方式') + '</label>'
                + '<input data-contact maxlength="100" spellcheck="false" placeholder="' + esc(S.cph || '') + '"></div>'
                + '<div class="field"><label>查询密码（4-32 位，凭此查订单）</label>'
                + '<input data-pwd type="password" maxlength="32" placeholder="设置一个查询密码，务必牢记"></div>')
            + '<div class="field"><label>购买数量</label>'
            + '<div class="stepper" data-stepper>'
            + '<button type="button" data-minus aria-label="减少">&minus;</button>'
            + '<b data-qty>1</b>'
            + '<button type="button" data-plus aria-label="增加">+</button>'
            + '</div></div>'
            + payBlock
            + '<div class="order-foot"><div class="amount-line">应付金额 <b data-amount>&yen;' + firstPrice.toFixed(2) + '</b></div>'
            + '<button class="btn primary block" type="button" data-submit>' + (isManual ? '我已付款' : '去支付') + '</button></div>'
            + '<div class="order-result" data-result hidden></div>'
            + '</div>'
            + '<section class="dp-body"><h3>商品详情</h3>'
            + detailHtml
            + pts
            + '</section></div>';
    }

    function effPrice(card, base) {
        var v = parseFloat(card && card.price);
        var b = parseFloat(base) || 0;
        return (isFinite(v) && v > 0) ? v : b;
    }

    function openImgViewer(src, alt) {
        var v = document.getElementById('imgViewer');
        if (!v) {
            v = document.createElement('div');
            v.id = 'imgViewer';
            v.innerHTML = '<img alt=""><span class="cap">点击任意处关闭</span>';
            document.body.appendChild(v);
            v.addEventListener('click', function () { v.hidden = true; });
        }
        v.querySelector('img').src = src;
        v.querySelector('img').alt = alt || '';
        v.hidden = false;
    }

    function bindDetail(box, plan) {
        var qtyEl  = box.querySelector('[data-qty]');

        var specEls = box.querySelectorAll('[data-specs] .spec-item');
        var price  = specEls.length
            ? effPrice({ price: specEls[0].dataset.price }, plan.shop_price)
            : (parseFloat(plan.shop_price) || 0);
        var maxQty = plan.stock > 0 ? Math.min(99, plan.stock) : 99;

        Array.prototype.forEach.call(specEls, function (lab) {
            lab.addEventListener('click', function () {
                Array.prototype.forEach.call(specEls, function (x) { x.classList.remove('on'); });
                lab.classList.add('on');
                var r = lab.querySelector('input[name="spec"]');
                if (r) r.checked = true;
                price = effPrice({ price: lab.dataset.price }, plan.shop_price);
                var dpe = box.querySelector('[data-price]');
                if (dpe) dpe.textContent = price.toFixed(2);
                var due = box.querySelector('.dp-unit');
                if (due) { var u = lab.dataset.unit || ''; due.textContent = u ? '/' + u : ''; due.style.display = u ? '' : 'none'; }
                syncAmount();
            });
        });

        var mediaImg = box.querySelector('.dp-media img');
        if (mediaImg) {
            mediaImg.addEventListener('click', function () {
                openImgViewer(mediaImg.src, plan.name);
            });
        }

        function qty() { return parseInt(qtyEl.textContent, 10) || 1; }
        function syncAmount() {
            box.querySelector('[data-amount]').innerHTML = '&yen;' + (price * qty()).toFixed(2);
        }
        box.querySelector('[data-minus]').onclick = function () {
            var q = qty();
            if (q > 1) { qtyEl.textContent = q - 1; syncAmount(); }
        };
        box.querySelector('[data-plus]').onclick = function () {
            var q = qty();
            if (q < maxQty) { qtyEl.textContent = q + 1; syncAmount(); }
            else { toast(plan.stock > 0 ? '最多可买 ' + maxQty + ' 张（受库存限制）' : '单次最多 99 张', 'info'); }
        };
        Array.prototype.forEach.call(box.querySelectorAll('.channel'), function (lab) {
            lab.addEventListener('click', function () {
                Array.prototype.forEach.call(box.querySelectorAll('.channel'), function (x) {
                    x.classList.remove('on');
                });
                lab.classList.add('on');
            });
        });

        var submitBtn = box.querySelector('[data-submit]');
        submitBtn.onclick = function () {
            var channelEl = box.querySelector('input[name="channel"]:checked');
            var cEl = box.querySelector('[data-contact]');
            var pEl = box.querySelector('[data-pwd]');
            var specChecked = box.querySelector('input[name="spec"]:checked');
            var payload = {
                plan_id:   plan.id,
                channel:   channelEl ? channelEl.value : 'alipay',
                contact:   cEl ? (cEl.value || '').trim() : '',
                query_pwd: pEl ? (pEl.value || '').trim() : '',
                qty:       qty()
            };

            if (specChecked) payload.spec_index = parseInt(specChecked.value, 10) || 0;
            if (!S.user) {
                if (payload.contact === '') {
                    toast('请填写' + (S.clabel || '联系方式'), 'warn');
                    cEl.focus();
                    return;
                }
                if (payload.query_pwd.length < 4 || payload.query_pwd.length > 32) {
                    toast('查询密码需 4-32 位，用于日后查询订单', 'warn');
                    pEl.focus();
                    return;
                }
            }

            submitBtn.disabled = true;
            api('order', payload, function (j) {
                submitBtn.disabled = false;
                if (j.code !== 0) {
                    renderResult(box, false, j.msg || '下单失败，请稍后重试');
                    return;
                }
                var d = j.data;
                if (d.pay_type === 1 && d.pay_url) {
                    try { sessionStorage.setItem('nb_shop_last', d.order_no); } catch (e) {}
                    toast('订单已创建，正在跳转支付…', 'ok');
                    setTimeout(function () { window.location.href = d.pay_url; }, 500);
                } else {
                    renderManualResult(box, submitBtn, d);
                }
            });
        };
    }

    function openOrder(plan) {
        currentPlan = plan;
        if (S.dstyle !== 'modal' && pageHost) {

            host = pageHost;
            host.innerHTML = buildDetail(plan);
            bindDetail(host, plan);
            showView('detail');
        } else {

            host = $('#modalHost');
            host.innerHTML = '<button class="modal-close" data-close type="button" aria-label="关闭">&times;</button>'
                + '<h3 class="modal-title">商品详情</h3>' + buildDetail(plan);
            bindDetail(host, plan);
            host.querySelector('[data-close]').onclick = closeOrder;
            modal.classList.add('wide');
            modal.hidden = false;
        }
    }

    function closeOrder() {
        modal.hidden = true;
        modal.classList.remove('wide');
        if (host === pageHost) { showView('shop'); }
        currentPlan = null;
    }

    function backdropClose(el, fn) {
        var downOnMask = false;
        el.addEventListener('mousedown', function (e) { downOnMask = e.target === el; });
        el.addEventListener('click', function (e) {
            if (e.target === el && downOnMask) { downOnMask = false; fn(); }
        });
    }
    backdropClose(modal, closeOrder);

    var authModal = document.getElementById('authModal');
    var authMode  = 'login';
    var setShopCaptcha = function (img) {
    if (!img) { return; }
    img.onerror = function () {
        img.onerror = null;
        setTimeout(function () { img.src = 'api.php?action=captcha&' + Date.now() + '&r' + Math.random(); }, 1200);
    };
    img.src = 'api.php?action=captcha&' + Date.now();
};
    var navLogin  = document.getElementById('navLogin');
    if (navLogin) {
        navLogin.addEventListener('click', function (e) {
            e.preventDefault();
            authModal.hidden = false;
            var aci = document.getElementById('authCaptchaImg');
            setShopCaptcha(aci);
        });
    }
    document.getElementById('authClose').addEventListener('click', function () { authModal.hidden = true; });
    backdropClose(authModal, function () { authModal.hidden = true; });
    Array.prototype.forEach.call(authModal.querySelectorAll('[data-at]'), function (b) {
        b.addEventListener('click', function () {
            authMode = b.dataset.at;
            Array.prototype.forEach.call(authModal.querySelectorAll('[data-at]'), function (x) {
                x.classList.toggle('on', x === b);
            });
            document.getElementById('authTitle').textContent = authMode === 'login' ? '登录' : '注册';
            document.getElementById('authBtn').textContent = authMode === 'login' ? '登录' : '注册并登录';
            document.getElementById('authEmailRow').hidden = authMode !== 'register';
        });
    });
    document.getElementById('authForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('authBtn');
        var spec = window.__NB_SHOP__ && window.__NB_SHOP__.login ? window.__NB_SHOP__.login : { need_code: false };
        btn.disabled = true;
        var payload;
        var captchaInput = document.getElementById('authCaptcha');
        if (spec.need_code) {

            payload = {
                op: 'login',
                username: document.getElementById('authUser') ? document.getElementById('authUser').value.trim() : '',
                code: document.getElementById('authCode') ? document.getElementById('authCode').value.trim() : '',
                captcha: captchaInput ? captchaInput.value.trim() : ''
            };
        } else {
            payload = {
                op: authMode,
                username: document.getElementById('authUser').value.trim(),
                password: document.getElementById('authPwd').value,
                email: document.getElementById('authEmail').value.trim(),
                captcha: captchaInput ? captchaInput.value.trim() : ''
            };
        }
        api('auth', payload, function (j) {
            btn.disabled = false;
            if (j.code !== 0) {
                toast(j.msg || '操作失败', 'warn');
                var aci = document.getElementById('authCaptchaImg');
                setShopCaptcha(aci);
                if (captchaInput) captchaInput.value = '';
                return;
            }
            toast(j.msg || '成功', 'ok', 2600);
            setTimeout(function () { location.reload(); }, 600);
        });
    });
    var navLogout = document.getElementById('navLogout');
    if (navLogout) {
        navLogout.addEventListener('click', function (e) {
            e.preventDefault();
            api('auth', { op: 'logout' }, function () { location.reload(); });
        });
    }

    var reclaimForm = document.getElementById('reclaimForm');
    if (reclaimForm) {
        var authForm = document.getElementById('authForm');
        var rcCaptchaImg = document.getElementById('rcCaptchaImg');
        function rcRefreshCaptcha() {
            setShopCaptcha(rcCaptchaImg);
        }
        function showReclaim(show) {
            reclaimForm.hidden = !show;
            authForm.hidden = show;
            if (show) rcRefreshCaptcha();
        }
        document.getElementById('reclaimLink').addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('authTitle').textContent = '激活码找回';
            showReclaim(true);
        });
        document.getElementById('reclaimBack').addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('authTitle').textContent = '登录';
            showReclaim(false);
        });
        rcCaptchaImg.addEventListener('click', rcRefreshCaptcha);

        document.getElementById('rcLookup').addEventListener('click', function () {
            var code = document.getElementById('rcCode').value.trim();
            if (!code) { toast('请先输入激活码', 'warn'); return; }
            api('reclaim_lookup', { code: code }, function (j) {
                if (j.code !== 0) { toast(j.msg || '查询失败', 'warn'); return; }
                var inp = document.getElementById('rcUser');
                inp.value = (j.data || {}).username || '';
                inp.readOnly = true;
                toast('已找到绑定账号，用户名已填入', 'ok');
            });
        });
        document.getElementById('rcCode').addEventListener('input', function () {
            document.getElementById('rcUser').readOnly = false;
        });

        reclaimForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var p1 = document.getElementById('rcPass').value;
            var p2 = document.getElementById('rcPass2').value;
            if (p1 !== p2) { toast('两次输入的密码不一致', 'warn'); return; }
            var btn = document.getElementById('rcBtn');
            btn.disabled = true;
            api('reclaim_save', {
                code: document.getElementById('rcCode').value.trim(),
                username: document.getElementById('rcUser').value.trim(),
                password: p1,
                password2: p2,
                captcha: document.getElementById('rcCaptcha').value.trim()
            }, function (j) {
                btn.disabled = false;
                if (j.code !== 0) {
                    toast(j.msg || '设置失败', 'warn');
                    rcRefreshCaptcha();
                    return;
                }
                toast(j.msg || '新密码已设置', 'ok', 2600);
                setTimeout(function () { location.reload(); }, 800);
            });
        });
    }

    (function () {
        var pb = document.querySelector('.pop-body');
        if (!pb) { return; }
        var css = '';
        var tpls = pb.querySelectorAll('template.pop-style-tpl');
        for (var i = 0; i < tpls.length; i++) {
            css += tpls[i].textContent + '\n';
            tpls[i].parentNode.removeChild(tpls[i]);
        }
        if (css) {
            var st = document.createElement('style');
            st.textContent = scopeCss(css, '.pop-body');
            document.head.appendChild(st);
        }
    })();

    var pop = document.getElementById('popNotice');
    if (pop) {
        var seen = false, forever = false;
        try {
            seen = !!sessionStorage.getItem('nb_pop_seen');
            forever = !!localStorage.getItem('nb_pop_forever');
        } catch (e) {
 }
        if (!seen && !forever) { pop.hidden = false; }
        pop.addEventListener('click', function (e) {
            if (e.target === pop || (e.target.closest && (e.target.closest('[data-close]') || e.target.closest('[data-forever]')))) {
                pop.hidden = true;
                try {
                    if (e.target.closest && e.target.closest('[data-forever]')) {
                        localStorage.setItem('nb_pop_forever', '1');
                    } else {
                        sessionStorage.setItem('nb_pop_seen', '1');
                    }
                } catch (err) {
 }
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { closeOrder(); }
    });

    (function () {
        var wrap = document.getElementById('shopBanner');
        var img = wrap && wrap.querySelector('img');
        if (!img) return;
        var src = img.getAttribute('src') || '';
        if (/\.(mp4|webm|mov|m4v|ogv|jpe?g|png|gif|webp|avif|svg)(\?|#|$)/i.test(src)) return;
        fetch(src, { method: 'HEAD' }).then(function (r) {
            var ct = (r.headers.get('Content-Type') || '').toLowerCase();
            if (ct.indexOf('video/') === 0) {
                var v = document.createElement('video');
                v.src = src;
                v.autoplay = true; v.muted = true; v.loop = true; v.playsInline = true;
                img.replaceWith(v);
            }
        }).catch(function () {
 });
    })();

    function renderResult(box, ok, html) {
        var el = box.querySelector('[data-result]');
        el.hidden = false;
        el.innerHTML = '<div class="or-box ' + (ok ? 'ok' : 'err') + '">' + html + '</div>';
    }

    function renderManualResult(box, submitBtn, d) {
        var html = '<div class="or-box ok">'
            + '<p>订单已创建！请保存订单号：<span class="or-no"><code>' + esc(d.order_no) + '</code></span></p>'
            + '<p class="qr-tip">应付 <b>&yen;' + esc(d.amount_text || yuan(d.amount)) + '</b>'
            + (S.qrcode ? '，扫上方收款码支付后等待管理员确认发货即可。' : '，按页面联系方式与我们确认发货。')
            + '</p>';
        if (S.contact) {
            html += '<p class="qr-tip">客服联系方式：' + esc(S.contact) + '</p>';
        }
        html += '</div>';
        renderResult(box, true, html);
        submitBtn.textContent = '完成';
        submitBtn.onclick = closeOrder;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var catTabs = $('#catTabs');
    if (catTabs) {
        var catCards = Array.prototype.slice.call(document.querySelectorAll('#goodsGrid .goods-card'));
        Array.prototype.forEach.call(catTabs.querySelectorAll('button'), function (b) {
            b.onclick = function () {
                Array.prototype.forEach.call(catTabs.querySelectorAll('button'), function (x) {
                    x.classList.remove('on');
                });
                b.classList.add('on');
                var cat = b.dataset.cat || '';
                catCards.forEach(function (card) {
                    card.hidden = cat !== '' && (card.dataset.cat || '') !== cat;
                });
            };
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-goods]'), function (b) {
        b.onclick = function () {
            if (!S.plans || !S.plans.length) { return; }
            var id = parseInt(b.dataset.goods, 10);
            var p = S.plans.find(function (x) { return x.id === id; });
            if (!p) { return; }
            openOrder(p);
        };
    });

    var pickCats = $('#pickCats');
    var pickGoods = $('#pickGoods');
    var pickDetail = $('#pickDetail');
    if (pickCats && pickGoods && pickDetail) {
        var pickBtns = Array.prototype.slice.call(pickGoods.querySelectorAll('button[data-pick]'));
        var pickGoodsArea = $('#pickGoodsArea');
        var pickHint = $('#pickHint');

        var showPick = function (btn) {
            Array.prototype.forEach.call(pickGoods.querySelectorAll('button'), function (x) {
                x.classList.toggle('on', x === btn);
            });
            var id = parseInt(btn.dataset.pick, 10);
            var p = (S.plans || []).find(function (x) { return x.id === id; });
            if (!p) { return; }
            pickDetail.hidden = false;
            pickDetail.innerHTML = buildDetail(p);
            bindDetail(pickDetail, p);
            if (pickDetail.scrollIntoView) { pickDetail.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        };

        var applyPickCat = function (cat) {
            if (pickHint) { pickHint.hidden = cat !== ''; }
            if (pickGoodsArea) { pickGoodsArea.hidden = cat === ''; }
            pickBtns.forEach(function (b) {
                b.hidden = cat === '' ? true : (b.dataset.pcat || '') !== cat;
                b.classList.remove('on');
            });
            pickDetail.hidden = true;
            pickDetail.innerHTML = '';
        };

        Array.prototype.forEach.call(pickCats.querySelectorAll('button'), function (b) {
            b.onclick = function () {
                Array.prototype.forEach.call(pickCats.querySelectorAll('button'), function (x) {
                    x.classList.remove('on');
                });
                b.classList.add('on');
                applyPickCat(b.dataset.cat || '');
            };
        });
        Array.prototype.forEach.call(pickBtns, function (b) {
            b.onclick = function () { showPick(b); };
        });

        if (pickCats.querySelector('button[data-cat=""]')) { applyPickCat(''); }
    }

    var viewShop    = $('#viewShop');
    var viewQuery   = $('#viewQuery');
    var viewDetail  = $('#viewDetail');
    var viewAccount = $('#viewAccount');
    var navQuery    = $('#navQuery');
    var navAccount  = $('#navAccount');

    function showView(v) {
        var toQuery  = v === 'query';
        var toDetail = v === 'detail';
        var toAcct   = v === 'account';
        if (viewShop)    { viewShop.hidden = toQuery || toDetail || toAcct; }
        if (viewQuery)   { viewQuery.hidden = !toQuery; }
        if (viewDetail)  { viewDetail.hidden = !toDetail; }
        if (viewAccount) { viewAccount.hidden = !toAcct; }
        if (navQuery) {
            navQuery.textContent = toQuery ? '返回商店' : '订单查询';
            navQuery.classList.toggle('on', toQuery);
        }
        if (navAccount) {
            navAccount.textContent = toAcct ? '返回商店' : '我的订单';
            navAccount.classList.toggle('on', toAcct);
        }
        window.scrollTo(0, 0);
    }

    var detailBack = $('#detailBack');
    if (detailBack) {
        detailBack.onclick = function () { showView('shop'); };
    }

    if (navQuery && viewQuery) {
        navQuery.onclick = function (e) {
            e.preventDefault();
            showView(viewQuery.hidden ? 'query' : 'shop');
        };
    }

    function loadAccount() {
        var box = $('#accountBox');
        if (!box) { return; }
        api('account', {}, function (j) {
            if (j.code !== 0) {
                box.innerHTML = '<div class="or-box err">' + esc(j.msg || '请先登录') + '</div>';
                return;
            }
            var d = j.data;
            var html = '';
            if (d.orders.length) {
                d.orders.forEach(function (o) { html += orderBox(o); });
            } else {
                html = '<div class="empty">暂无订单，去选购一个套餐吧</div>';
            }
            if (d.activated.length) {
                html += '<h3 class="acct-sub">已激活卡密</h3>';
                d.activated.forEach(function (c) {
                    html += '<div class="qr-box"><div class="qr-code-line"><code>' + esc(c.code) + '</code>'
                        + '<button class="btn ghost sm" type="button" data-copy="' + esc(c.code) + '">复制</button></div>'
                        + '<div class="qr-meta">激活时间：' + esc(c.used_at) + '</div></div>';
                });
            }
            box.innerHTML = html;
            Array.prototype.forEach.call(box.querySelectorAll('[data-copy]'), function (b) {
                b.onclick = function () {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(b.dataset.copy).then(function () {
                            toast('已复制', 'ok', 1800);
                        }).catch(function () { toast('复制失败，请手动选中复制', 'err'); });
                    } else {
                        toast('请手动选中复制', 'info');
                    }
                };
            });
        });
    }

    if (navAccount && viewAccount) {
        navAccount.onclick = function (e) {
            e.preventDefault();
            if (viewAccount.hidden) { loadAccount(); showView('account'); }
            else { showView('shop'); }
        };
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-activate]');
        if (!btn) { return; }
        if (!S.user) {
            toast('请先登录后再激活', 'warn');
            var am = document.getElementById('authModal');
            if (am) { am.hidden = false; }
            return;
        }
        var code = btn.dataset.activate || '';
        if (!code) { return; }
        btn.disabled = true;
        api('activate', { code: code }, function (j) {
            btn.disabled = false;
            if (j.code !== 0) {
                toast(j.msg || '激活失败', 'warn');
                return;
            }
            toast(j.msg || '激活成功', 'ok', 2600);
            if (viewAccount && !viewAccount.hidden) { loadAccount(); }
        });
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-repay]');
        if (!b || !b.dataset.repay) { return; }
        location.href = b.dataset.repay;
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-close-order]');
        if (!b || !b.dataset.closeOrder) { return; }
        var no = b.dataset.closeOrder;
        customConfirm('取消订单', '确定取消该订单吗？取消后如需购买请重新下单。', function () {
            b.disabled = true;
            api('order_close', { order_no: no }, function (j) {
                b.disabled = false;
                if (j.code !== 0) { toast(j.msg || '取消失败', 'warn'); return; }
                toast(j.msg || '订单已关闭', 'ok', 2200);
                if (viewAccount && !viewAccount.hidden) { loadAccount(); }
                if (viewQuery && !viewQuery.hidden) { doQuery(no, true); }
            });
        });
    });

    function orderBanner(st) {

        if (st === 1) {
            return '<div class="or-box ok"><b>&#10003; 支付成功，卡密已发货</b><p class="qr-tip">卡密在下方，请复制保存；也可直接点「激活」到账。</p></div>';
        }
        if (st === 2) {
            return '<div class="or-box err"><b>&#10007; 订单已关闭</b><p class="qr-tip">订单未完成支付被关闭；若已付款请联系客服核实。</p></div>';
        }
        if (st === 3) {
            return '<div class="or-box ok"><b>已收到付款，人工处理中</b><p class="qr-tip">管理员确认后发货，请稍候刷新查看。</p></div>';
        }
        return '<div class="or-box"><b>支付结果确认中…</b><p class="qr-tip">银行/支付平台确认稍有延迟，正在自动刷新查询。</p></div>';
    }

    function doQuery(orderNo, silent) {
        api('query', { order_no: orderNo }, function (j) {
            var box = $('#queryResult');
            box.hidden = false;
            if (j.code !== 0) {
                box.innerHTML = '<div class="or-box err">' + esc(j.msg || '查询失败') + '</div>';
                return;
            }
            var o = j.data;
            box.innerHTML = orderBanner((o.status | 0)) + orderBox(o);

            var cp = box.querySelector('[data-copy]');
            if (cp) {
                cp.onclick = function () {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(cp.dataset.copy).then(function () {
                            toast('已复制激活码', 'ok', 1800);
                        }).catch(function () { toast('复制失败，请手动选中复制', 'err'); });
                    } else {
                        toast('请手动选中复制', 'info');
                    }
                };
            }
        });
    }

    var pollTimer = null, pollLeft = 0, verifying = false;
    function pollOrder(orderNo) {
        clearInterval(pollTimer);
        pollLeft = 10;
        verifying = true;
        pollTimer = setInterval(function () {
            var stop = pollLeft-- <= 0 || !$('#viewQuery') || $('#viewQuery').hidden;
            if (stop) {
                clearInterval(pollTimer);
                if (verifying) {
                    verifying = false;
                    if ($('#viewQuery') && !$('#viewQuery').hidden) { doQuery(orderNo, true); }
                }
                return;
            }
            api('query', { order_no: orderNo }, function (j) {
                if (j.code !== 0) { return; }
                if ((j.data.status | 0) !== 0) {
                    clearInterval(pollTimer);
                    verifying = false;
                    doQuery(orderNo, true);
                }
            });
        }, 3000);
    }

    var qType = 'no';
    var qSwitch = $('#querySwitch');
    if (qSwitch) {
        Array.prototype.forEach.call(qSwitch.querySelectorAll('button'), function (b) {
            b.onclick = function () {
                Array.prototype.forEach.call(qSwitch.querySelectorAll('button'), function (x) {
                    x.classList.remove('on');
                });
                b.classList.add('on');
                qType = b.dataset.qt || 'no';
                var byContact = qType === 'contact';
                $('#queryNo').hidden = byContact;
                $('#queryContact').hidden = !byContact;
                $('#queryPwd').hidden = !byContact;
                if (byContact) { $('#queryContact').focus(); } else { $('#queryNo').focus(); }
            };
        });
    }

    var queryForm = $('#queryForm');
    if (queryForm) {
        queryForm.onsubmit = function (e) {
            e.preventDefault();
            if (qType === 'contact') {
                var ct = ($('#queryContact').value || '').trim();
                var pw = ($('#queryPwd').value || '').trim();
                if (!ct) { toast('请输入下单时填写的' + (S.clabel || '联系方式'), 'warn'); return; }
                if (!pw) { toast('请输入查询密码', 'warn'); return; }
                verifying = false;
                doQueryByContact(ct, pw);
                return;
            }
            var no = ($('#queryNo').value || '').trim().toUpperCase();
            if (!no) { toast('请输入订单号', 'warn'); return; }
            verifying = false;
            doQuery(no);
        };
    }

    function orderBox(o) {
        var stMap = { 0: 's0', 1: 's1', 2: 's2', 3: 's3' };
        var stTxt = (o.status === 0 && verifying) ? '核验中' : esc(o.status_text);
        var html = '<div class="qr-box">'
            + '<div class="qr-head"><b>' + esc(o.plan_name) + '</b>'
            + '<span class="qr-status ' + (stMap[o.status] || 's2') + '">' + stTxt + '</span>'
            + '<span class="qr-meta">&yen;' + esc(o.amount) + ' · ' + esc(o.order_no) + '</span></div>'
            + '<div class="qr-meta">下单时间：' + esc(o.created_at) + '</div>';
        if (o.notice) {
            html += '<div class="qr-notice">' + esc(o.notice).replace(/\n/g, '<br>') + '</div>';
        }
        if (o.codes && o.codes.length) {
            o.codes.forEach(function (c, i) {
                html += '<div class="qr-code-line"><code>' + esc(c.code) + '</code>'
                    + (c.activated
                        ? '<span class="qr-status s1">已激活</span>'
                        : '<span class="qr-status s0">未激活</span>')
                    + (!c.activated
                        ? '<button class="btn primary sm" type="button" data-activate="' + esc(c.code) + '" data-idx="' + i + '">激活</button>'
                        : '')
                    + '<button class="btn ghost sm" type="button" data-copy="' + esc(c.code) + '">复制</button></div>';
            });
            html += S.user
                ? '<p class="qr-tip">点「激活」立即到账；也可到官网个人中心「激活卡密」处使用。</p>'
                : '<p class="qr-tip">登录后可直接激活；或复制激活码到官网个人中心「激活卡密」完成激活。</p>';
        } else if (o.status === 1) {
            html += '<p class="qr-tip">卡密发货中，请稍后刷新查看。</p>';
        } else if (o.status === 0) {
            if (verifying) {
                html += '<p class="qr-tip">支付结果核验中，请稍候，页面会自动刷新确认发卡结果。</p>';
            } else {
                html += S.manual
                    ? '<p class="qr-tip">订单待支付。人工发货订单请按页面提示完成付款并联系管理员确认。</p>'
                    : '<p class="qr-tip">订单待支付。请尽快完成支付，支付成功后系统将自动发卡；若已付款未到账，请稍后刷新本页查看。</p>';
                if (o.pay_url) {
                    html += '<button class="btn primary sm" type="button" data-repay="' + esc(o.pay_url) + '">继续支付</button> ';
                }
                html += '<button class="btn ghost sm" type="button" data-close-order="' + esc(o.order_no) + '">取消订单</button>';
            }
        } else if (o.status === 3) {
            html += '<p class="qr-tip">已收款，正在为您人工处理，请稍候或联系客服。</p>';
        }
        html += '</div>';
        return html;
    }

    function doQueryByContact(contact, pwd) {
        api('query', { contact: contact, query_pwd: pwd }, function (j) {
            var box = $('#queryResult');
            box.hidden = false;
            if (j.code !== 0) {
                box.innerHTML = '<div class="or-box err">' + esc(j.msg || '查询失败') + '</div>';
                return;
            }
            var list = (j.data && j.data.list) || [];
            if (!list.length) {
                box.innerHTML = '<div class="or-box err">未找到匹配的订单</div>';
                return;
            }
            var html = '';
            list.forEach(function (o) { html += orderBox(o); });
            box.innerHTML = html;
            Array.prototype.forEach.call(box.querySelectorAll('[data-copy]'), function (b) {
                b.onclick = function () {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(b.dataset.copy).then(function () {
                            toast('已复制激活码', 'ok', 1800);
                        }).catch(function () { toast('复制失败，请手动选中复制', 'err'); });
                    } else {
                        toast('请手动选中复制', 'info');
                    }
                };
            });
        });
    }

    if (S.back_no) {
        var qn = $('#queryNo');
        if (qn) { qn.value = S.back_no; }
        showView('query');
        doQuery(S.back_no);
        pollOrder(S.back_no);
        try {
            window.history.replaceState(null, '', location.pathname);
        } catch (e) {
 }
    }
})();
