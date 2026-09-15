/* ======================================================================
   Nebula Menu · 官网前端
   流程：首页 -> 注册/登录 -> 个人中心 -> 激活卡密
   ====================================================================== */
(function () {
    'use strict';

    var RT    = window.__NB_WEB__ || {};
    var API   = RT.api || 'api.php';
    var CSRF  = RT.csrf || '';
    var SITE  = RT.site || {};
    var state = {
        logged: !!RT.logged,
        profile: RT.profile || null,
        // 互动区：首屏数据由服务端注入，避免二次请求
        msgPage: 1,
        msgData: RT.messages || { list: [], page: 1, pages: 1, total: 0 },
        replyTo: { id: 0, name: '' },
        // 未登录时点了「我要反馈」暂存意图，登录成功后自动定位到反馈表单
        afterLogin: ''
    };

    var $  = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // ------------------------------------------------------------------
    // 基础工具
    // ------------------------------------------------------------------
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(msg, type, ms) {
        var box = $('#toasts');
        var el  = document.createElement('div');
        el.className = 'toast ' + (type || 'info');
        el.textContent = msg;
        box.appendChild(el);
        setTimeout(function () {
            el.style.transition = '.25s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(24px)';
            setTimeout(function () { el.remove(); }, 260);
        }, ms || 2800);
    }

    function busy(on) {
        var m = $('#loadingMask');
        if (m) { m.hidden = !on; }
    }

    function setBusyBtn(btn, on, text) {
        if (!btn) { return; }
        if (on) {
            btn.dataset._t = btn.textContent;
            btn.disabled = true;
            btn.textContent = text || '处理中...';
        } else {
            btn.disabled = false;
            if (btn.dataset._t) { btn.textContent = btn.dataset._t; }
        }
    }

    // ------------------------------------------------------------------
    // 通用确认弹窗（返回 Promise<boolean>，替代 window.confirm）
    // ------------------------------------------------------------------
    var _confirmCb = null;
    function showConfirm(opts) {
        opts = opts || {};
        var m = $('#confirmModal');
        if (!m) { return Promise.resolve(window.confirm(opts.desc || '')); }
        var titleEl = $('#confirmTitle');
        var descEl  = $('#confirmDesc');
        var okBtn   = $('#confirmOk');
        var cancelBtn = $('#confirmCancel');
        if (titleEl) { titleEl.textContent = opts.title || '确认操作'; }
        if (descEl)  { descEl.textContent  = opts.desc  || '确定要执行此操作吗？'; }
        if (opts.okText && okBtn) { okBtn.textContent = opts.okText; }
        if (opts.cancelText && cancelBtn) { cancelBtn.textContent = opts.cancelText; }
        if (opts.danger && okBtn) { okBtn.className = 'btn danger block'; }
        else if (okBtn) { okBtn.className = 'btn primary block'; }

        m.hidden = false;
        document.body.style.overflow = 'hidden';

        return new Promise(function (resolve) {
            _confirmCb = resolve;
        });
    }
    function closeConfirm(result) {
        var m = $('#confirmModal');
        if (!m || m.hidden || m.classList.contains('closing')) { return; }
        m.classList.add('closing');
        setTimeout(function () {
            m.hidden = true;
            m.classList.remove('closing');
            document.body.style.overflow = '';
        }, 150);
        if (_confirmCb) {
            var cb = _confirmCb;
            _confirmCb = null;
            cb(result);
        }
    }

    // ------------------------------------------------------------------
    // 接口调用
    // ------------------------------------------------------------------
    function api(action, data, opts) {
        opts = opts || {};
        var body = Object.assign({}, data || {});
        if (window.NBGuard) { try { NBGuard.attach(body); } catch (e) { } }
        return fetch(API + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () {
                throw new Error('服务器返回格式异常');
            });
        }).then(function (res) {
            if (res && res.code === 0) { return res; }
            var err = new Error((res && res.msg) || '请求失败');
            err.code = res ? res.code : -1;
            err.res  = res;
            throw err;
        });
    }

    // ------------------------------------------------------------------
    // 视图切换
    // ------------------------------------------------------------------
    // keepScroll = true 时不强制回到顶部（用于从个人中心跳回首页锚点）
    // 视图记忆：进入个人中心时地址栏写 #panel，离开时清掉。
    // 刷新后「停在哪」跟随用户所在视图——首页刷新停在首页，
    // 个人中心刷新回到个人中心，不再无条件把已登录用户拉进个人页。
    function showView(which, keepScroll) {
        $('#viewHome').hidden  = which !== 'home';
        $('#viewPanel').hidden = which !== 'panel';
        // 导航栏「首页」按钮仅在个人中心视图显示（回首页入口）
        var goHome = $('#btnGoHome');
        if (goHome) { goHome.hidden = which !== 'panel'; }
        try {
            if (which === 'panel') {
                history.replaceState(null, '', '#panel');
            } else if (location.hash === '#panel') {
                history.replaceState(null, '', location.pathname + location.search);
            }
        } catch (e) { /* 非常规环境（file:// 等）忽略 */ }
        if (!keepScroll) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    // 锚点跳转：若当前停留在个人中心，先切回首页再滚动到目标区块
    function gotoSection(hash) {
        var el = hash ? document.querySelector(hash) : null;
        var onHome = !$('#viewHome').hidden;

        if (!onHome) {
            showView('home', true);
            loadNotices('#noticeList');
        }
        if (el) {
            // 等一帧，确保首页已恢复显示后才有正确的滚动高度
            requestAnimationFrame(function () {
                el.scrollIntoView({ behavior: onHome ? 'smooth' : 'auto', block: 'start' });
            });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    function bindNavLinks() {
        $$('#navLinks a, .foot-links a').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (href.charAt(0) !== '#' || href.length < 2) { return; }
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                // 移动端抽屉导航：点击锚点后自动收起
                var nv = $('.nav');
                if (nv) { nv.classList.remove('open'); }
                // 「我要反馈」入口：未登录先弹登录，登录后自动定位反馈表单
                if (a.dataset.feedbackLink) { enterFeedback(); return; }
                gotoSection(href);
            });
        });

        // 站点 Logo：已登录时不再整页刷新，直接切回首页
        var brand = $('.brand');
        if (brand) {
            brand.addEventListener('click', function (ev) {
                ev.preventDefault();
                gotoSection('');
            });
        }

        // 移动端汉堡菜单：开合抽屉；点击抽屉外自动收起
        var nav = $('.nav'), burger = $('#navBurger');
        if (nav && burger) {
            burger.addEventListener('click', function (ev) {
                ev.stopPropagation();
                nav.classList.toggle('open');
            });
            document.addEventListener('click', function (ev) {
                if (nav.classList.contains('open') && !nav.contains(ev.target)) {
                    nav.classList.remove('open');
                }
            });
        }
    }

    /** 设备分档标记（html data-device）：pc >1024 / tablet 641-1024 / mobile ≤640，
        与 site.css 三档断点一致，便于按端排查与后续针对性调整 */
    function markDevice() {
        var w = window.innerWidth || document.documentElement.clientWidth;
        document.documentElement.dataset.device = w <= 640 ? 'mobile' : (w <= 1024 ? 'tablet' : 'pc');
    }
    markDevice();
    window.addEventListener('resize', markDevice);

    /** 导航栏「发卡商店」入口按钮：商店开启时渲染，关闭时不出现 */
    function shopNavBtn() {
        var shop = SITE.shop || {};
        return (shop.enabled && shop.url)
            ? '<button class="btn shop-btn" id="btnGoShop" type="button" title="进入发卡商店 · 自动发货">发卡商店</button>'
            : '';
    }

    function renderNav() {
        var navActions = $('#navActions');
        var heroBtns   = $('#heroBtns');
        var shopBtn    = shopNavBtn();

        if (state.logged && state.profile) {
            var name = (state.profile.user && state.profile.user.nickname) || '用户';
            navActions.innerHTML =
                '<div class="nav-user"><i class="mini-avatar">' + esc(name.charAt(0).toUpperCase()) + '</i>' +
                '<span>' + esc(name) + '</span></div>' +
                '<button class="btn ghost" id="btnGoHome" type="button">首页</button>' +
                shopBtn +
                '<button class="btn primary" id="btnGoPanel" type="button">个人中心</button>';

            if (heroBtns) {
                heroBtns.innerHTML =
                    '<button class="btn primary lg" id="btnHeroPanel" type="button">进入个人中心</button>' +
                    '<a class="btn ghost lg" href="#features">了解功能</a>';
            }

            $('#btnGoPanel').onclick   = function () { enterPanel(); };
            $('#btnGoHome').onclick    = function () { gotoSection(''); };
            // 首页视图下不显示「首页」按钮（仅个人中心显示）
            var vh = $('#viewHome');
            if (vh) { $('#btnGoHome').hidden = !vh.hidden; }
            var hp = $('#btnHeroPanel');
            if (hp) { hp.onclick = function () { enterPanel(); }; }
        } else {
            navActions.innerHTML = shopBtn +
                '<button class="btn ghost" id="btnNavLogin" type="button">登录</button>' +
                (SITE.register_enable === false ? '' :
                    '<button class="btn primary" id="btnNavReg" type="button">免费注册</button>');

            heroBtns.innerHTML =
                (SITE.register_enable === false
                    ? '<button class="btn primary lg" id="btnHeroLogin" type="button">登录</button>'
                    : '<button class="btn primary lg" id="btnHeroReg" type="button">免费注册</button>' +
                      '<button class="btn ghost lg" id="btnHeroLogin" type="button">已有账号，去登录</button>');

            var bind = function (id, tab) {
                var el = $('#' + id);
                if (el) { el.onclick = function () { openAuth(tab); }; }
            };
            bind('btnNavLogin', 'login');
            bind('btnNavReg', 'register');
            bind('btnHeroLogin', 'login');
            bind('btnHeroReg', 'register');
        }

        var goShop = $('#btnGoShop');
        if (goShop) { goShop.onclick = gotoShop; }
    }

    // ------------------------------------------------------------------
    // 个人中心渲染
    // ------------------------------------------------------------------
    function renderPanel() {
        var p = state.profile;
        if (!p) { return; }
        var u = p.user || {};

        var name = u.nickname || u.username || '-';
        $('#pAvatar').textContent = name.charAt(0).toUpperCase();
        $('#pName').textContent   = name;
        $('#pUserLine').textContent = u.username + ' · ' + (p.group_name || '默认用户组');

        $('#kUsername').textContent  = u.username || '-';
        $('#kGroup').textContent     = p.group_name || '-';
        $('#kVip').textContent       = p.vip_status || '-';
        $('#kExpire').textContent    = p.expire_text || '-';
        $('#kRemain').textContent    = p.remain_text || '-';
        $('#kPoints').textContent    = (u.points || 0) + ' 点';
        $('#kDevices').textContent   = (p.device.bound_count || 0) + ' / ' + (p.device.max_devices || 0);
        $('#kRegTime').textContent   = p.register_time || '-';
        $('#kLastLogin').textContent = p.last_login || '-';

        var badge = $('#pVipBadge');
        badge.textContent = p.vip_status || '未激活';
        badge.className   = 'vip-badge' + (p.activated ? ' on' : '');

        // 永久会员（vip_expire = -1）：不显示激活表单，改显示永久会员状态卡
        // 试用中 / 未激活 / 普通会员 照旧显示激活表单
        var forever    = Number(u.vip_expire) === -1 || p.remain === -1;
        var actNormal  = $('#actNormal');
        var actForever = $('#actForever');
        var actTitle   = $('#actTitle');
        if (actNormal)  { actNormal.hidden  = forever; }
        if (actForever) { actForever.hidden = !forever; }
        if (actTitle)   { actTitle.textContent = forever ? '会员状态' : '激活卡密'; }

        $('#alertNotActive').hidden = !!p.activated;

        var dc = $('#devCount');
        if (dc) { dc.textContent = (p.device.bound_count || 0) + ' / ' + (p.device.max_devices || 0) + ' 台'; }
    }

    function flushProfile(profile, justRegistered) {
        if (profile) { state.profile = profile; }
        state.logged = !!state.profile;
        renderNav();
        renderPanel();
        showView('panel');
        if (justRegistered) {
            toast('注册成功，请使用激活码激活账号', 'ok', 3600);
        }
        loadDevices();
        loadShopOrders();
        ensureDownloadMeta();
        // 登录态变化后：发布区形态、点赞/回复权限、我的反馈都要跟着刷新
        syncCompose();
        loadMessages(1);
        loadFeedback();

        // 未登录时点了「我要反馈」→ 登录成功后自动定位到反馈表单
        if (state.afterLogin === 'feedback') {
            state.afterLogin = '';
            openFeedbackForm();
        }
    }

    function enterPanel() {
        if (!state.logged) { openAuth('login'); return; }
        showView('panel');
        renderPanel();
        loadProfile();
        loadDevices();
        loadShopOrders();
        loadNotices('#noticeListPanel', { scope: 'panel' });
        ensureDownloadMeta();
        loadFeedback();
    }

    function backHome() {
        showView('home');
        loadNotices('#noticeList');
    }

    // ------------------------------------------------------------------
    // 数据加载
    // ------------------------------------------------------------------
    function loadShopOrders() {
        var tbody = $('#shopOrderBody');
        if (!tbody || !state.logged) { return; }
        api('shop_orders').then(function (res) {
            var list = (res.data || {}).orders || [];
            var counter = $('#shopOrderCount');
            if (counter) { counter.textContent = list.length ? list.length + ' 笔' : ''; }
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty">暂无发卡订单，去发卡商店逛逛吧</td></tr>';
                return;
            }
            tbody.innerHTML = list.map(function (o) {
                var stMap = {1: '<span class="tag ok">已支付</span>', 2: '<span class="tag">已关闭</span>', 3: '<span class="tag">人工处理中</span>'};
                var st = stMap[o.status] || '<span class="tag">待支付</span>';
                var codes = (o.codes || []).map(function (c) {
                    return '<div><code>' + esc(c.code) + '</code> '
                        + (c.activated ? '<span class="tag ok">已激活</span>' : '<span class="tag">未激活</span>')
                        + '</div>';
                }).join('') || '<span class="muted">暂未发货</span>';
                // 操作列：待支付订单显示「继续支付」+「取消订单」
                var actions = '';
                if (o.status === 0) {
                    if (o.pay_url) {
                        actions += '<button class="btn primary sm" type="button" data-repay="' + esc(o.pay_url) + '">继续支付</button> ';
                    }
                    actions += '<button class="btn sm" type="button" data-close-order="' + esc(o.order_no) + '">取消订单</button>';
                }
                return '<tr>'
                    + '<td>' + esc(o.plan_name) + '</td>'
                    + '<td>&yen;' + esc(o.amount_text) + '</td>'
                    + '<td>' + o.qty + '</td>'
                    + '<td>' + st + '</td>'
                    + '<td>' + codes + '</td>'
                    + '<td class="muted">' + esc(o.created_at) + '</td>'
                    + '<td class="order-actions">' + actions + '</td>'
                    + '</tr>';
            }).join('');
        }).catch(function () {
            tbody.innerHTML = '<tr><td colspan="7" class="empty">加载失败</td></tr>';
        });
    }

    function loadProfile() {
        return api('me').then(function (res) {
            state.profile = res.data.profile;
            renderPanel();
        }).catch(function (e) {
            if (e.code === 1002 || e.code === 2002) { doLocalLogout(); }
            else { toast(e.message, 'err'); }
        });
    }

    function loadDevices() {
        var tbody = $('#devBody');
        if (!tbody || !state.logged) { return; }
        api('devices').then(function (res) {
            renderDevices(res.data || {});
        }).catch(function (e) {
            if (e.code === 1002 || e.code === 2002) { doLocalLogout(); }
        });
    }

    /** 渲染设备表（devices / unbind 两个接口的返回结构一致） */
    function renderDevices(d) {
        var tbody = $('#devBody');
        if (!tbody) { return; }

        var list = d.devices || [];
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty">暂无绑定设备，客户端登录后会自动绑定</td></tr>';
        } else {
            tbody.innerHTML = list.map(function (x) {
                var off = Number(x.status) !== 1;
                var act = off
                    ? '<span class="act-none">已解绑</span>'
                    : '<button class="btn xs danger-ghost" type="button" data-unbind="' + esc(x.id) + '" ' +
                      'data-name="' + esc(x.device_name || '未知设备') + '">解绑</button>';
                return '<tr' + (off ? ' class="is-off"' : '') + '>' +
                    '<td>' + esc(x.device_name || '未知设备') + '</td>' +
                    '<td class="mono">' + esc(x.machine_id) + '</td>' +
                    '<td><span class="dot-tag ' + (x.online ? 'on' : 'off') + '">' +
                        esc(x.online ? '在线' : x.status_text) + '</span></td>' +
                    '<td>' + esc(x.last_seen) + '</td>' +
                    '<td class="col-act">' + act + '</td>' +
                    '</tr>';
            }).join('');
        }

        var dc = $('#devCount');
        if (dc) { dc.textContent = (d.bound_count || 0) + ' / ' + (d.max_devices || 0) + ' 台'; }

        // 有可解绑的设备时才显示「全部解绑」
        var all = $('#btnUnbindAll');
        if (all) { all.hidden = (d.bound_count || 0) <= 0; }

        // 行内解绑按钮
        $$('#devBody [data-unbind]').forEach(function (b) {
            b.onclick = function () {
                var name = b.dataset.name || '该设备';
                if (!window.confirm('确定要解绑「' + name + '」吗？\n解绑后该电脑需要重新登录才能使用。' + unbindLimitTip(false))) { return; }
                doUnbind({ id: b.dataset.unbind }, b);
            };
        });
    }

    // ------------------------------------------------------------------
    // 每日解绑上限提示：跟随后台「每日解绑次数上限」配置（0 = 不限制，不提示）
    // ------------------------------------------------------------------
    function unbindLimitTip(all) {
        var limit = parseInt(RT.unbindPerDay, 10) || 0;
        if (limit <= 0) { return ''; }
        return '\n（每天最多可解绑 ' + limit + ' 次' + (all ? '，全部解绑也占用次数' : '') + '）';
    }

    /** 解绑：data 传 {id} 或 {machine_id} 单台，{scope:'all'} 全部 */
    function doUnbind(data, btn) {
        setBusyBtn(btn, true, '解绑中');
        api('unbind', data)
            .then(function (res) {
                var d = res.data || {};
                if (d.profile) { state.profile = d.profile; renderPanel(); }
                renderDevices(d);
                toast(res.msg || '设备已解绑', 'ok');
            })
            .catch(function (e) {
                if (e.code === 1002 || e.code === 2002) { doLocalLogout(); return; }
                toast(e.message, 'err', 3600);
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function noticeHtml(list) {
        if (!list || !list.length) {
            return '<div class="empty">暂无公告</div>';
        }
        return list.map(function (n) {
            return '<div class="item">' +
                '<span class="tag">' + esc(n.type_text || '公告') + '</span>' +
                '<div><h4>' + esc(n.title) + '</h4>' +
                (n.content ? '<p>' + esc(n.content) + '</p>' : '') +
                '<time>' + esc(n.created_at_text) + '</time></div></div>';
        }).join('');
    }

    function loadNotices(selector, opts) {
        var box = $(selector);
        if (!box) { return; }
        // skip：不加载（旧占位用法保留兼容）
        if (opts && opts.skip) {
            box.innerHTML = '<div class="empty">暂无公告</div>';
            return;
        }
        // scope=panel：个人中心「软件公告」——客户端公告（按登录用户归属软件下发）
        var body = (opts && opts.scope === 'panel') ? { scope: 'panel' } : null;
        api('notice', body).then(function (res) {
            // 未激活账号：不下发任何公告，引导先激活（激活后才有归属软件）
            if (res.data && res.data.need_activate) {
                box.innerHTML = '<div class="empty">激活后可查看软件公告</div>';
                return;
            }
            box.innerHTML = noticeHtml(res.data.list);
        }).catch(function (e) {
            box.innerHTML = '<div class="empty error">公告加载失败：' + esc(e.message) + '</div>';
        });
    }

    // ------------------------------------------------------------------
    // 多软件切换：选中软件即跳到该软件的官网内容（?app=<app_key>，服务端记忆）
    // ------------------------------------------------------------------
    (function bindSwPicker() {
        var picker = $('swPicker');
        if (!picker) { return; }
        picker.addEventListener('change', function () {
            var v = picker.value;
            if (v) { location.href = './?app=' + encodeURIComponent(v) + location.hash; }
        });
    })();

    // ------------------------------------------------------------------
    // 在线人数（公开接口 /web/api.php?action=online）
    // 45s 轮询；切回前台且距上次超过一个周期时立即补刷；失败静默保留旧值
    // ------------------------------------------------------------------
    var ONLINE_TICK = 45000;
    var onlineTimer = null;
    var onlineLast  = 0;
    var onlineValue = null;

    function fmtNum(n) {
        n = Math.max(0, Math.floor(Number(n) || 0));
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /** 首屏数据条里含指定占位符的 <b> 节点（数据条内容后台可配，见 web_hero_stats） */
    function statNodes(token) {
        var out = [], nodes = document.querySelectorAll('.hero-stats b[data-stat]');
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].getAttribute('data-stat').indexOf(token) !== -1) { out.push(nodes[i]); }
        }
        return out;
    }

    /** 数字滚动到目标值（首次直接落值，不从 0 涨起） */
    function paintOnline(el, to) {
        if (!el) { return; }
        if (onlineValue === null) { el.textContent = fmtNum(to); return; }

        var from = onlineValue, span = to - from, t0 = Date.now();
        if (span === 0) { el.textContent = fmtNum(to); return; }

        var step = function () {
            var p = Math.min(1, (Date.now() - t0) / 500);
            el.textContent = fmtNum(Math.round(from + span * (1 - Math.pow(1 - p, 3))));
            if (p < 1) { requestAnimationFrame(step); }
        };
        requestAnimationFrame(step);
    }

    function loadOnline() {
        return api('online').then(function (res) {
            var n = (res.data && res.data.online) || 0;
            onlineLast = Date.now();

            var nodes = statNodes('{online}');
            for (var i = 0; i < nodes.length; i++) {
                paintOnline(nodes[i], n);
                nodes[i].classList.add('stat-online', 'is-live');
            }

            var p = $('#pillOnline');
            if (p) { p.textContent = fmtNum(n); }

            onlineValue = n;
        }).catch(function () {
            // 静默失败：保留上一次数字；首次即失败则保持占位符
            if (onlineValue === null) {
                var p = $('#pillOnline');
                if (p) { p.textContent = '--'; }
            }
        });
    }

    function startOnlinePolling() {
        loadOnline();
        if (onlineTimer) { clearInterval(onlineTimer); }
        onlineTimer = setInterval(loadOnline, ONLINE_TICK);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && Date.now() - onlineLast > ONLINE_TICK) { loadOnline(); }
        });
    }

    // ------------------------------------------------------------------
    // 客户端下载（地址由 /web/api.php?action=download 下发）
    // 进入个人中心时预取，点击即时跳转；未配置时给出明确提示
    // 版本号同样以此接口为准：首页展示的「最新版本」与下载包、客户端 version
    // 接口三者同源（后台版本发布表 -> config 兜底），避免多处分头维护后漂移
    // ------------------------------------------------------------------
    var dl = { loaded: false, url: '', version: '', sizeText: '' };

    function fileSizeText(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes <= 0) { return ''; }
        var unit = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (bytes >= 1024 && i < unit.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + unit[i];
    }

    /** 把接口下发的版本号同步到顶栏胶囊与首屏数据条占位 */
    function applyVersionText(version) {
        if (!version) { return; }
        var p = $('#pillVersion');
        if (p) { p.textContent = 'v' + version; }
        var nodes = statNodes('{version}');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = nodes[i].getAttribute('data-stat').replace('{version}', version);
        }
    }

    function applyDownloadMeta() {
        applyVersionText(dl.version);

        var btn = $('#btnDownload');
        if (!btn) { return; }
        btn.textContent = '下载客户端' + (dl.url && dl.version ? ' v' + dl.version : '');
        btn.title = dl.url
            ? ('版本 ' + (dl.version || '-') + (dl.sizeText ? ' · ' + dl.sizeText : '') + ' · 点击下载')
            : '暂未配置下载地址，请联系客服';
    }

    function loadDownloadMeta() {
        return api('download').then(function (res) {
            var d = res.data || {};
            dl.loaded   = true;
            dl.url      = d.download_url || '';
            dl.version  = d.version || '';
            dl.sizeText = fileSizeText(d.file_size);
            applyDownloadMeta();
        }).catch(function () {
            // 静默：点击按钮时再重试一次，避免打扰用户
        });
    }

    /** 已取过就只重绘文案，不重复请求接口 */
    function ensureDownloadMeta() {
        if (dl.loaded) { applyDownloadMeta(); return; }
        loadDownloadMeta();
    }

    function doDownload(btn) {
        var open = function (url) {
            var w = window.open(url, '_blank');
            if (w) { w.opener = null; }   // 断开 opener，防目标页操纵本页
        };

        if (dl.url) { open(dl.url); return; }

        setBusyBtn(btn, true, '获取中...');
        api('download')
            .then(function (res) {
                var d = res.data || {};
                dl.loaded   = true;
                dl.url      = d.download_url || '';
                dl.version  = d.version || '';
                dl.sizeText = fileSizeText(d.file_size);

                if (!dl.url) {
                    toast('暂未配置下载地址，请联系客服', 'err', 3600);
                    return;
                }
                open(dl.url);
            })
            .catch(function (e) {
                toast('获取下载地址失败：' + e.message, 'err', 3600);
            })
            .then(function () {
                setBusyBtn(btn, false);   // 先还原忙碌态，再写最终文案
                applyDownloadMeta();
            });
    }

    // ------------------------------------------------------------------
    // 登录 / 注册弹窗
    // ------------------------------------------------------------------
    function openAuth(tab) {
        if (state.logged) { enterPanel(); return; }
        $('#authModal').hidden = false;
        switchTab(tab || 'login');
        document.body.style.overflow = 'hidden';
        setTimeout(function () {
            // 登录框字段随后台配置变化，聚焦第一个存在的输入框
            var form = tab === 'register' ? $('#formRegister') : $('#formLogin');
            var first = form ? form.querySelector('input') : null;
            if (first) { first.focus(); }
        }, 60);
    }

    function closeAuth() {
        var m = $('#authModal');
        if (m.hidden || m.classList.contains('closing')) return;
        m.classList.add('closing');
        // 等退出动画播完再隐藏，避免瞬间消失的生硬感
        setTimeout(function () {
            m.hidden = true;
            m.classList.remove('closing');
            document.body.style.overflow = '';
        }, 150);
    }

    function switchTab(tab) {
        if (tab === 'register' && SITE.register_enable === false) {
            toast('当前未开放注册，请联系管理员', 'err');
            return;
        }
        if (tab === 'register' && (SITE.login || {}).method === 'code') {
            toast('当前仅支持激活码登录，无需注册', 'err');
            return;
        }
        $$('#authTabs .tab').forEach(function (t) {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        $('#formLogin').hidden    = tab !== 'login';
        $('#formRegister').hidden = tab !== 'register';
        var rcForm = $('#formReclaim');
        if (rcForm) { rcForm.hidden = tab !== 'reclaim'; }
        if (tab === 'register') { ensureCaptcha('reg'); }
        if (tab === 'reclaim') { ensureCaptcha('rc'); }
        if (tab === 'login')   { ensureCaptcha('lg'); }

        var note = $('#modalNotice');
        if (SITE.register_enable === false && tab === 'login') {
            note.hidden = false;
            note.textContent = '当前未开放注册，如需账号请联系管理员';
        } else if (SITE.maintain_mode) {
            note.hidden = false;
            note.textContent = SITE.maintain_msg || '服务器维护中';
        } else {
            note.hidden = true;
        }
    }

    function doLocalLogout() {
        state.logged  = false;
        state.profile = null;
        renderNav();
        // 退出后回到访客形态：发布区收起、点赞置灰
        state.replyTo = { id: 0, name: '' };
        closeReply();
        syncCompose();
        loadMessages(1);
        renderFeedback([]);
        backHome();
    }

    function submitLogin(e) {
        e.preventDefault();
        var btn = $('#lgBtn');
        // 登录字段由后台「登录方式」决定，这里按规格组装，不做多字段兼容推断
        var lm = SITE.login || { method: 'password', need_username: true, need_password: true, need_code: false };
        var payload = {};

        if (lm.need_username) {
            var uEl = $('#lgUser');
            payload.username = uEl ? uEl.value.trim() : '';
            if (!payload.username) { toast('请输入用户名', 'err'); return; }
        }
        if (lm.need_password) {
            var pEl = $('#lgPass');
            payload.password = pEl ? pEl.value : '';
            if (!payload.password) { toast('请输入密码', 'err'); return; }
        }
        if (lm.need_code) {
            var cEl = $('#lgCode');
            payload.code = cEl ? cEl.value.trim() : '';
            if (!payload.code) { toast('请输入激活码', 'err'); return; }
        }
        var capEl = $('#lgCaptcha');
        payload.captcha = capEl ? capEl.value.trim() : '';
        if (!payload.captcha) { toast('请输入验证码', 'err'); return; }

        setBusyBtn(btn, true, '登录中...');
        api('login', payload)
            .then(function (res) {
                refreshCaptcha('lg');
                $('#formLogin').reset();
                closeAuth();
                flushProfile(res.data.profile, false);
                toast(res.data.account_created ? '账号已开通，登录成功' : '登录成功', 'ok');
            })
            .catch(function (err) {
                toast(err.message, 'err', 3600);
                refreshCaptcha('lg');
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    /* 激活码找回密码：激活码 + 用户名（须一致）+ 新密码 + 验证码 */
    function submitReclaim(e) {
        e.preventDefault();
        var btn = $('#rcBtn');
        var code = $('#rcCode').value.trim();
        var username = $('#rcUser').value.trim();
        var password = $('#rcPass').value;
        var confirm = $('#rcPass2').value;
        var captcha = $('#rcCaptcha').value.trim();

        if (!code) { toast('请输入激活码', 'err'); return; }
        if (!username) { toast('请填写用户名（可先点「查询用户名」自动填入）', 'err'); return; }
        if (password.length < 6) { toast('新密码至少 6 位', 'err'); return; }
        if (password !== confirm) { toast('两次输入的密码不一致', 'err'); return; }
        if (!captcha) { toast('请输入验证码', 'err'); return; }

        setBusyBtn(btn, true, '提交中...');
        api('reclaim_save', { code: code, username: username, password: password, password2: confirm, captcha: captcha })
            .then(function () {
                $('#formReclaim').reset();
                switchTab('login');
                toast('密码已重置，请使用新密码登录', 'ok');
            })
            .catch(function (err) {
                toast(err.message, 'err', 3600);
                refreshCaptcha('rc');
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function submitRegister(e) {
        e.preventDefault();
        var btn = $('#rgBtn');
        var username = $('#rgUser').value.trim();
        var email    = $('#rgMail').value.trim();
        var password = $('#rgPass').value;
        var confirm  = $('#rgPass2').value;

        if (!username) { toast('请输入用户名', 'err'); return; }
        if (password.length < 6) { toast('密码至少 6 位', 'err'); return; }
        if (password !== confirm) { toast('两次输入的密码不一致', 'err'); return; }
        var captcha = $('#rgCaptcha').value.trim();
        if (!captcha) { toast('请输入验证码', 'err'); return; }

        setBusyBtn(btn, true, '注册中...');
        api('register', { username: username, password: password, password2: confirm, email: email, captcha: captcha })
            .then(function (res) {
                $('#formRegister').reset();
                closeAuth();
                flushProfile(res.data.profile, true);
            })
            .catch(function (err) {
                toast(err.message, 'err', 3600);
                refreshCaptcha('reg');
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function doLogout() {
        api('logout', {}).catch(function () { /* 忽略 */ }).then(function () {
            doLocalLogout();
            toast('已退出登录', 'info');
        });
    }

    // ------------------------------------------------------------------
    // 激活
    // ------------------------------------------------------------------
    function submitActivate(e) {
        e.preventDefault();
        var btn  = $('#actBtn');
        var box  = $('#actResult');
        var code = $('#actCode').value.trim();

        if (!code) { toast('请输入激活码', 'err'); return; }

        setBusyBtn(btn, true, '激活中...');
        api('activate', { code: code })
            .then(function (res) {
                var d = res.data || {};
                if (d.profile) { state.profile = d.profile; renderPanel(); }
                $('#actCode').value = '';
                box.hidden = false;
                box.className = 'act-result';
                box.textContent = res.msg || '激活成功';
                toast('激活成功', 'ok');
            })
            .catch(function (err) {
                box.hidden = false;
                box.className = 'act-result err';
                box.textContent = err.message;
                toast(err.message, 'err', 3600);
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    // ------------------------------------------------------------------
    // 图形验证码
    // 懒加载：验证码图所在表单可见时才请求（避免首屏隐藏表单白拉图）。
    // 一次性使用：提交成功/失败后都要刷新图并清空输入（后端已消费答案）。
    // ------------------------------------------------------------------
    function captchaUrl() {
        return API + '?action=captcha&ts=' + Date.now();
    }

    function refreshCaptcha(which) {
        var map = { msg: '#msgCaptcha', reply: '#replyCaptcha', fb: '#fbCaptcha', reg: '#rgCaptcha', rc: '#rcCaptcha', lg: '#lgCaptcha' };
        var img = $(map[which] + 'Img');
        if (img) { setCaptchaImg(img); }
        var inp = $(map[which]);
        if (inp) { inp.value = ''; }
    }

    /** 设置验证码图：加载失败（网络抖动/限流异常）自动重试一次 */
    function setCaptchaImg(img) {
        img.onerror = function () {
            img.onerror = null;
            setTimeout(function () { img.src = captchaUrl() + '&r=' + Math.random().toString(36).slice(2); }, 1200);
        };
        img.src = captchaUrl();
    }

    /** 表单显示后调用：首次拉图（只拉一次，重复显示不刷） */
    function ensureCaptcha(which) {
        var map = { msg: '#msgCaptcha', reply: '#replyCaptcha', fb: '#fbCaptcha', reg: '#rgCaptcha', rc: '#rcCaptcha', lg: '#lgCaptcha' };
        var img = $(map[which] + 'Img');
        if (img && !img.getAttribute('src')) { refreshCaptcha(which); }
    }

    function bindCaptchaImgs() {
        $$('.captcha-img').forEach(function (img) {
            img.onclick = function () {
                // id 形如 msgCaptchaImg / replyCaptchaImg / fbCaptchaImg
                var which = (img.id || '').replace(/CaptchaImg$/i, '').toLowerCase();
                if (which) { refreshCaptcha(which); }
            };
        });
    }

    // ------------------------------------------------------------------
    // 留言板
    // 结构：主楼 + 两层回复。发布/回复均「先审后显示」，
    // 提交成功后明确告知用户"待审核"，避免以为没发出去。
    // ------------------------------------------------------------------
    function msgText(s) {
        return esc(s).replace(/\n/g, '<br>');
    }

    function msgItemsHtml(list) {
        if (!list || !list.length) {
            return '<div class="empty">暂无留言，来发布第一条吧</div>';
        }
        return list.map(function (m) {
            var replies = (m.replies || []).map(function (r) {
                return '<div class="reply">' +
                    '<b>' + esc(r.username) + '</b>' +
                    (r.reply_to ? '<em>回复 ' + esc(r.reply_to) + '</em>' : '') +
                    '<p>' + msgText(r.content) + '</p>' +
                    '<time>' + esc(r.created_at) + '</time>' +
                    '</div>';
            }).join('');

            return '<article class="msg" data-id="' + m.id + '">' +
                '<i class="msg-avatar">' + esc(String(m.username || '?').charAt(0).toUpperCase()) + '</i>' +
                '<div class="msg-main">' +
                    '<div class="msg-head">' +
                        '<b class="msg-name">' + esc(m.username) + '</b>' +
                        (m.mine ? '<span class="msg-tag">我</span>' : '') +
                        '<time>' + esc(m.created_at) + '</time>' +
                    '</div>' +
                    '<p class="msg-text">' + msgText(m.content) + '</p>' +
                    '<div class="msg-acts">' +
                        '<button class="act-like' + (m.liked ? ' on' : '') + '" type="button" ' +
                            'data-like="' + m.id + '"><i>♥</i>' +
                            '<span class="like-n">' + m.likes + '</span></button>' +
                        '<button class="act-reply" type="button" data-reply="' + m.id + '" ' +
                            'data-name="' + esc(m.username) + '">回复</button>' +
                    '</div>' +
                    (replies ? '<div class="msg-replies">' + replies + '</div>' : '') +
                '</div></article>';
        }).join('');
    }

    function pagerHtml(d) {
        if (!d || d.pages <= 1) { return ''; }
        return '<button class="btn ghost xs" type="button" data-msg-page="' + (d.page - 1) + '"' +
                (d.page <= 1 ? ' disabled' : '') + '>上一页</button>' +
            '<span class="pager-info">第 ' + d.page + ' / ' + d.pages + ' 页 · 共 ' + d.total + ' 条</span>' +
            '<button class="btn ghost xs" type="button" data-msg-page="' + (d.page + 1) + '"' +
                (d.page >= d.pages ? ' disabled' : '') + '>下一页</button>';
    }

    function renderMessages(d) {
        if (d) { state.msgData = d; state.msgPage = d.page || 1; }
        var box = $('#msgList');
        if (box) { box.innerHTML = msgItemsHtml(state.msgData.list); }
        var pg = $('#msgPager');
        if (pg) { pg.innerHTML = pagerHtml(state.msgData); }
        bindMsgActions();
    }

    function bindMsgActions() {
        var root = $('#boardRoot');
        if (!root) { return; }

        // 点赞 / 取消点赞（登录后可用）
        $$('[data-like]', root).forEach(function (b) {
            b.onclick = function () {
                if (!state.logged) { openAuth('login'); return; }
                doLike(b, Number(b.dataset.like));
            };
        });

        // 回复：展开回复框并聚焦
        $$('[data-reply]', root).forEach(function (b) {
            b.onclick = function () {
                if (!state.logged) { openAuth('login'); return; }
                openReply(Number(b.dataset.reply), b.dataset.name || '');
            };
        });

        // 分页
        $$('[data-msg-page]', root).forEach(function (b) {
            b.onclick = function () {
                var p = Number(b.dataset.msgPage);
                if (!p || p < 1) { return; }
                loadMessages(p);
            };
        });

        // 未登录时的登录引导
        $$('[data-board-login]', root).forEach(function (b) {
            b.onclick = function () { openAuth('login'); };
        });
    }

    function loadMessages(page) {
        var box = $('#msgList');
        if (box) { box.innerHTML = '<div class="empty">正在加载...</div>'; }
        api('msg_list', { page: page || 1 }).then(function (res) {
            renderMessages(res.data);
        }).catch(function (e) {
            if (box) { box.innerHTML = '<div class="empty error">留言加载失败：' + esc(e.message) + '</div>'; }
        });
    }

    function doLike(btn, id) {
        setBusyBtn(btn, true);
        api('msg_like', { id: id })
            .then(function (res) {
                var d = res.data || {};
                btn.classList.toggle('on', !!d.liked);
                var n = $('.like-n', btn);
                if (n) { n.textContent = d.likes; }
            })
            .catch(function (e) {
                if (e.code === 1002 || e.code === 2002) { doLocalLogout(); return; }
                toast(e.message, 'err', 3200);
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function openReply(id, name) {
        state.replyTo = { id: id, name: name };
        var box = $('#replyBox');
        if (!box) { return; }
        box.hidden = false;
        $('#replyWho').textContent = name || '该留言';
        var ta = $('#replyText');
        if (ta) { ta.value = ''; ta.focus(); }
        $('#replyCount').textContent = '0';
        var cb = $('#composeBox');
        if (cb) { cb.hidden = true; }
        // 回复框可见时才拉验证码图
        ensureCaptcha('reply');
    }

    function closeReply() {
        state.replyTo = { id: 0, name: '' };
        var box = $('#replyBox');
        if (box) { box.hidden = true; }
        var ta = $('#replyText');
        if (ta) { ta.value = ''; }
        syncCompose();
    }

    /** 按登录态切换发布区形态 */
    function syncCompose() {
        var ask = $('#composeAsk');
        var box = $('#composeBox');
        if (!ask || !box) { return; }
        ask.hidden = state.logged;
        box.hidden = !state.logged || !!state.replyTo.id;
        // 留言框可见时才拉验证码图（懒加载）
        if (!box.hidden) { ensureCaptcha('msg'); }
    }

    function submitMessage(content, btn, done) {
        setBusyBtn(btn, true, '提交中...');
        api('msg_post', content)
            .then(function (res) {
                toast(res.msg || '留言已提交，通过审核后展示', 'ok', 3600);
                if (done) { done(); }
            })
            .catch(function (e) {
                if (e.code === 1002 || e.code === 2002) { doLocalLogout(); return; }
                toast(e.message, 'err', 3600);
                // 验证码已消费或失效，刷新图并清空输入
                refreshCaptcha('msg');
                refreshCaptcha('reply');
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function submitReply(e) {
        e.preventDefault();
        var ta = $('#replyText');
        var text = ta ? ta.value.trim() : '';
        if (!text) { toast('请输入回复内容', 'err'); return; }
        if (!state.replyTo.id) { toast('请选择要回复的留言', 'err'); return; }

        var cap = $('#replyCaptcha');
        var capVal = cap ? cap.value.trim() : '';
        if (!capVal) { toast('请输入验证码', 'err'); return; }

        submitMessage(
            { content: text, parent_id: state.replyTo.id, captcha: capVal },
            $('#replySubmit'),
            function () { ta.value = ''; closeReply(); }
        );
    }

    function submitPost() {
        var ta = $('#msgText');
        var text = ta ? ta.value.trim() : '';
        if (!text) { toast('请输入留言内容', 'err'); return; }

        var cap = $('#msgCaptcha');
        var capVal = cap ? cap.value.trim() : '';
        if (!capVal) { toast('请输入验证码', 'err'); return; }

        submitMessage({ content: text, parent_id: 0, captcha: capVal }, $('#msgSubmit'), function () {
            ta.value = '';
            $('#msgCount').textContent = '0';
            refreshCaptcha('msg');
        });
    }

    /** 输入框计数（中文按字符算，与后端 mb_strlen 同口径） */
    function bindCounter(taSel, cntSel) {
        var ta = $(taSel);
        if (!ta) { return; }
        ta.addEventListener('input', function () {
            var c = $(cntSel);
            if (c) { c.textContent = String(ta.value.length); }
        });
    }

    function bindBoard() {
        var root = $('#boardRoot');
        if (!root || SITE.message_board === false || RT.messageOpen === false) {
            if (root) { root.hidden = true; }
            return;
        }
        // 首屏由 PHP 渲染，这里只补事件绑定
        bindMsgActions();

        var q = $('#msgSubmit');
        if (q) { q.onclick = submitPost; }
        var rb = $('#replyBox');
        if (rb) { rb.addEventListener('submit', submitReply); }
        var rc = $('#replyCancel');
        if (rc) { rc.onclick = closeReply; }

        bindCounter('#msgText', '#msgCount');
        bindCounter('#replyText', '#replyCount');
        syncCompose();
    }

    // ------------------------------------------------------------------
    // 我的反馈
    // 隐私：未回复前接口不下发 reply 内容，这里同样按状态判断
    // ------------------------------------------------------------------
    var FB_STATUS = {
        0: ['待处理', 'wait'],
        1: ['处理中', 'doing'],
        2: ['已回复', 'done'],
        3: ['已关闭', 'closed']
    };

    function fbItemsHtml(list) {
        if (!list || !list.length) {
            return '<div class="empty">还没有提交过反馈，点击右上角「提交反馈」开始</div>';
        }
        return list.map(function (f) {
            var st = FB_STATUS[f.status] || ['未知', 'wait'];
            var reply = '';
            if (f.reply) {
                reply = '<div class="fb-reply">' +
                    '<div class="fb-reply-head">客服回复' +
                        (f.reply_admin ? ' · ' + esc(f.reply_admin) : '') +
                        (f.replied_at ? ' · ' + esc(f.replied_at) : '') +
                    '</div>' +
                    '<p>' + msgText(f.reply) + '</p></div>';
            }
            return '<article class="fb-item">' +
                '<div class="fb-head">' +
                    '<span class="fb-type">' + esc(f.type_text) + '</span>' +
                    '<b class="fb-title">' + esc(f.title) + '</b>' +
                    '<span class="fb-status ' + st[1] + '">' + st[0] + '</span>' +
                '</div>' +
                '<p class="fb-content">' + msgText(f.content) + '</p>' +
                reply +
                '<div class="fb-meta">提交于 ' + esc(f.created_at) +
                    (f.contact ? ' · 联系方式 ' + esc(f.contact) : '') + '</div>' +
                '</article>';
        }).join('');
    }

    function renderFeedback(list) {
        var box = $('#fbList');
        if (box) { box.innerHTML = fbItemsHtml(list); }
        var c = $('#fbCount');
        if (c) { c.textContent = list && list.length ? list.length + ' 条' : '暂无'; }
    }

    function loadFeedback() {
        if (!state.logged) { return; }
        api('fb_list').then(function (res) {
            renderFeedback((res.data || {}).list);
        }).catch(function (e) {
            if (e.code === 1002 || e.code === 2002) { doLocalLogout(); return; }
            var box = $('#fbList');
            if (box) { box.innerHTML = '<div class="empty error">反馈加载失败：' + esc(e.message) + '</div>'; }
        });
    }

    function submitFeedback(e) {
        e.preventDefault();
        var title   = ($('#fbTitle').value || '').trim();
        var content = ($('#fbContent').value || '').trim();
        var contact = ($('#fbContact').value || '').trim();
        var type    = parseInt($('#fbType').value, 10) || 1;
        var capVal  = ($('#fbCaptcha').value || '').trim();

        if (!title) { toast('请填写反馈标题', 'err'); return; }
        if (!content) { toast('请填写反馈内容', 'err'); return; }
        if (!capVal) { toast('请输入验证码', 'err'); return; }

        var btn = $('#fbSubmit');
        setBusyBtn(btn, true, '提交中...');
        api('fb_post', { type: type, title: title, content: content, contact: contact, captcha: capVal })
            .then(function (res) {
                toast(res.msg || '反馈已提交', 'ok');
                $('#fbForm').reset();
                $('#fbFormWrap').hidden = true;
                renderFeedback((res.data || {}).list);
            })
            .catch(function (err) {
                if (err.code === 1002 || err.code === 2002) { doLocalLogout(); return; }
                toast(err.message, 'err', 3600);
                // 验证码已消费或失效，刷新图并清空输入
                refreshCaptcha('fb');
            })
            .then(function () { setBusyBtn(btn, false); });
    }

    function bindFeedback() {
        var card = $('#fbCard');
        if (!card) { return; }

        var nb = $('#fbNewBtn');
        if (nb) {
            nb.onclick = function () {
                var w = $('#fbFormWrap');
                if (!w) { return; }
                w.hidden = !w.hidden;
                if (!w.hidden) {
                    ensureCaptcha('fb');
                    var t = $('#fbTitle');
                    if (t) { t.focus(); }
                }
            };
        }
        var fc = $('#fbCancel');
        if (fc) { fc.onclick = function () { $('#fbFormWrap').hidden = true; }; }

        var f = $('#fbForm');
        if (f) { f.addEventListener('submit', submitFeedback); }
    }

    // ------------------------------------------------------------------
    // 「我要反馈」入口（顶部导航）
    // 未登录：先弹登录弹窗并暂存意图，登录成功后由 flushProfile 自动
    //         切到个人中心并定位反馈表单。
    // 已登录：直接切到个人中心，打开反馈表单并滚动定位。
    // ------------------------------------------------------------------
    function enterFeedback() {
        if (!state.logged) {
            state.afterLogin = 'feedback';
            openAuth('login');
            return;
        }
        showView('panel');
        renderPanel();
        loadProfile();
        loadDevices();
        loadShopOrders();
        loadNotices('#noticeListPanel', { scope: 'panel' });
        ensureDownloadMeta();
        loadFeedback();
        openFeedbackForm();
    }

    function openFeedbackForm() {
        var w = $('#fbFormWrap');
        if (w) {
            w.hidden = false;
            ensureCaptcha('fb');
            var t = $('#fbTitle');
            if (t) { setTimeout(function () { t.focus(); }, 160); }
        }
        var card = $('#fbCard');
        if (card) {
            requestAnimationFrame(function () {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    }

    // ------------------------------------------------------------------
    // 首页：价格套餐 / 截图
    // 首屏由 PHP 渲染，这里只补「立即购买」的交互与截图灯箱
    // ------------------------------------------------------------------
    function bindPlans() {
        $$('#planGrid [data-buy]').forEach(function (b) {
            b.onclick = function () {
                var name = b.dataset.buy || '该套餐';
                buyInquiry(name);
            };
        });
    }

    // ------------------------------------------------------------------
    // 首页：购买商家。首屏由 PHP 渲染，这里只补「联系商家」的弹窗交互；
    // 「进入店铺」是新窗口链接，无需 JS。
    // ------------------------------------------------------------------
    function bindSellers() {
        $$('#sellerGrid [data-seller-contact]').forEach(function (b) {
            b.onclick = function () {
                var id = parseInt(b.dataset.sellerContact, 10);
                var s = (SITE.sellers || []).find(function (x) { return x.id === id; });
                if (!s) { return; }
                openContact('「' + s.name + '」联系方式：', s.contact);
            };
        });
    }

    /**
     * 发卡商店跳转：外部发卡站新窗口打开，内置 /shop/ 本页跳转。
     * 导航栏「发卡商店」按钮与套餐「立即购买」共用此口径。
     */
    function gotoShop() {
        var shop = SITE.shop || {};
        if (!(shop.enabled && shop.url)) {
            toast('发卡商店暂未开放', 'info');
            return;
        }
        if (/^https?:\/\//i.test(shop.url)) {
            window.open(shop.url, '_blank', 'noopener');
        } else {
            window.location.href = shop.url;
        }
    }

    /**
     * 购买咨询：发卡商店开启时直接跳转商店（内置 /shop/ 或外部发卡站链接）；
     * 未开启则引导到客服联系方式。联系方式取自站点设置，未配置时提示联系客服，
     * 避免点了没反应。
     */
    function buyInquiry(name) {
        var shop = SITE.shop || {};
        if (shop.enabled && shop.url) {
            gotoShop();
            return;
        }
        var contact = (SITE.contact || '').trim();
        var tip = '「' + name + '」购买咨询：';
        if (contact) {
            openContact(tip, contact);
        } else {
            toast(tip + '请联系客服获取购买方式', 'info', 4200);
        }
    }

    function openContact(tip, contact) {
        var box = $('#contactModal');
        if (!box) { toast(tip + contact, 'info', 4200); return; }
        $('#contactTip').textContent = tip;
        var body = $('#contactBody');
        // 邮箱/网址给出可点击链接，其余（QQ/微信）直接复制到剪贴板
        if (/^https?:\/\//i.test(contact)) {
            body.innerHTML = '<a href="' + esc(contact) + '" target="_blank" rel="noopener noreferrer">' + esc(contact) + '</a>';
        } else if (/^[\w.+-]+@[\w-]+\.[\w.-]+$/.test(contact)) {
            body.innerHTML = '<a href="mailto:' + esc(contact) + '">' + esc(contact) + '</a>';
        } else {
            body.innerHTML = '<code class="contact-code">' + esc(contact) + '</code>' +
                '<button class="btn ghost xs" type="button" id="contactCopy">复制</button>';
            var cp = $('#contactCopy');
            if (cp) {
                cp.onclick = function () {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(contact).then(function () {
                            toast('已复制到剪贴板', 'ok', 1800);
                        }).catch(function () { toast('复制失败，请手动选中复制', 'err'); });
                    } else {
                        toast('请手动选中复制', 'info');
                    }
                };
            }
        }
        box.hidden = false;
    }

    function closeContact() {
        var box = $('#contactModal');
        if (!box || box.hidden || box.classList.contains('closing')) return;
        box.classList.add('closing');
        setTimeout(function () {
            box.hidden = true;
            box.classList.remove('closing');
        }, 150);
    }

    /** 截图灯箱：点缩略图放大查看，Esc 或点遮罩关闭 */
    function bindShots() {
        var grid = $('#shotGrid');
        if (!grid) { return; }

        var imgs = $$('img', grid);
        if (!imgs.length) { return; }

        // 图片加载失败（地址失效 / 防盗链）时标记，避免展示破图
        imgs.forEach(function (img) {
            img.addEventListener('error', function () {
                var fig = img.closest('.shot');
                if (fig) { fig.classList.add('shot-bad'); }
            });
            img.addEventListener('click', function () {
                openLightbox(img.getAttribute('src'), img.getAttribute('alt') || '');
            });
        });
    }

    function openLightbox(src, alt) {
        if (!src) { return; }
        var box = $('#lightbox');
        if (!box) { window.open(src, '_blank'); return; }
        var img = $('#lightboxImg');
        if (img) { img.src = src; img.alt = alt; }
        var cap = $('#lightboxCap');
        if (cap) { cap.textContent = alt; }
        box.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        var box = $('#lightbox');
        if (!box) { return; }
        box.hidden = true;
        var img = $('#lightboxImg');
        if (img) { img.src = ''; }
        if ($('#authModal').hidden) { document.body.style.overflow = ''; }
    }

    function bindModals() {
        // 遮罩点击关闭：按下与松开都在遮罩上才关，防止输入框内选择文字误关
        function maskClose(el, fn) {
            var down = false;
            el.addEventListener('mousedown', function (ev) { down = ev.target === el; });
            el.addEventListener('click', function (ev) { if (ev.target === el && down) { down = false; fn(); } });
        }
        var c = $('#contactModal');
        if (c) {
            maskClose(c, closeContact);
            var cc = $('#contactClose');
            if (cc) { cc.onclick = closeContact; }
        }
        // 通用确认弹窗：遮罩点击 = 取消，按钮点击分别确定/取消
        var cm = $('#confirmModal');
        if (cm) {
            maskClose(cm, function () { closeConfirm(false); });
            var cOk = $('#confirmOk');
            if (cOk) { cOk.onclick = function () { closeConfirm(true); }; }
            var cCancel = $('#confirmCancel');
            if (cCancel) { cCancel.onclick = function () { closeConfirm(false); }; }
        }
        var l = $('#lightbox');
        if (l) {
            l.addEventListener('click', function (ev) {
                if (ev.target === l || ev.target.id === 'lightboxImg') { closeLightbox(); }
            });
            var lc = $('#lightboxClose');
            if (lc) { lc.onclick = closeLightbox; }
        }
    }

    // ------------------------------------------------------------------
    // 启动
    // ------------------------------------------------------------------
    function boot() {
        renderNav();

        // 在线人数：所有视图都展示，独立于登录态
        startOnlinePolling();

        // 版本号 / 下载地址：公开接口，未登录也要刷新首页展示
        ensureDownloadMeta();

        // 默认停在首页；只有当用户此前就在个人中心（地址栏带 #panel）
        // 刷新时才恢复个人中心视图。首页刷新不再被强行拉进个人页。
        if (state.logged && location.hash === '#panel') {
            showView('panel');
            renderPanel();
            loadProfile();
            loadDevices();
            loadShopOrders();
            loadNotices('#noticeListPanel', { scope: 'panel' });
            loadFeedback();
        } else {
            showView('home');
            loadNotices('#noticeList');
        }

        // 公告在两个视图里都要有内容
        var _onPanel = state.logged && location.hash === '#panel';
        loadNotices(_onPanel ? '#noticeListPanel' : '#noticeList', _onPanel ? {skip: true} : null);

        // 首页互动区（留言板 / 套餐 / 截图）与个人中心反馈
        bindBoard();
        bindPlans();
        bindSellers();
        bindShots();
        bindFeedback();
        bindModals();

        // 验证码图片：点击换一张（覆盖留言 / 回复 / 反馈三处）
        bindCaptchaImgs();

        // 弹窗
        $('#authClose').onclick = closeAuth;
        // 遮罩点击关闭：按下与松开都在遮罩上才关，防止输入框内选择文字误关
        (function (el) {
            var down = false;
            el.addEventListener('mousedown', function (ev) { down = ev.target === el; });
            el.addEventListener('click', function (ev) { if (ev.target === el && down) { down = false; closeAuth(); } });
        })($('#authModal'));
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') { return; }
            if (!$('#confirmModal').hidden)  { closeConfirm(false); return; }
            if (!$('#authModal').hidden)     { closeAuth(); return; }
            if (!$('#contactModal').hidden)  { closeContact(); return; }
            if (!$('#lightbox').hidden)      { closeLightbox(); }
        });
        $$('#authTabs .tab').forEach(function (t) {
            t.onclick = function () { switchTab(t.dataset.tab); };
        });
        $$('[data-switch]').forEach(function (a) {
            a.onclick = function () { switchTab(a.dataset.switch); };
        });

        // 表单
        $('#formLogin').addEventListener('submit', submitLogin);
        $('#formRegister').addEventListener('submit', submitRegister);
        $('#actForm').addEventListener('submit', submitActivate);

        // 激活码找回密码（后台开关开启时表单才存在）
        var rcForm = $('#formReclaim');
        if (rcForm) {
            var rcLink = $('#lgReclaimLink');
            if (rcLink) { rcLink.onclick = function () { switchTab('reclaim'); }; }
            $('#rcLookup').addEventListener('click', function () {
                var code = $('#rcCode').value.trim();
                if (!code) { toast('请先输入激活码', 'err'); return; }
                var btn = $('#rcLookup');
                btn.disabled = true;
                api('reclaim_lookup', { code: code })
                    .then(function (res) {
                        $('#rcUser').value = res.data.username || '';
                        toast('已找到绑定账号：' + (res.data.username || ''), 'ok');
                    })
                    .catch(function (err) { toast(err.message, 'err', 3600); })
                    .then(function () { btn.disabled = false; });
            });
            rcForm.addEventListener('submit', submitReclaim);
        }

        // 已登录时顶部与个人中心按钮
        var bl = $('#btnLogout');
        if (bl) { bl.onclick = doLogout; }
        var br = $('#btnRefresh');
        if (br) {
            br.onclick = function () {
                loadProfile();
                loadDevices();
                loadNotices('#noticeListPanel', { scope: 'panel' });
                loadDownloadMeta();
                toast('已刷新', 'info', 1400);
            };
        }
        var bd = $('#btnDownload');
        if (bd) { bd.onclick = function () { doDownload(bd); }; }

        // 全部解绑
        var ba = $('#btnUnbindAll');
        if (ba) {
            ba.onclick = function () {
                if (!window.confirm('确定要解绑全部设备吗？\n解绑后所有电脑都需要重新登录才能使用。' + unbindLimitTip(true))) { return; }
                doUnbind({ scope: 'all' }, ba);
            };
        }

        // 发卡订单操作（事件委托：继续支付 / 取消订单）
        var sob = $('#shopOrderBody');
        if (sob) {
            sob.addEventListener('click', function (ev) {
                var t = ev.target.closest && ev.target.closest('[data-repay],[data-close-order]');
                if (!t) { return; }
                if (t.dataset.repay) {
                    location.href = t.dataset.repay;
                    return;
                }
                if (t.dataset.closeOrder) {
                    showConfirm({
                        title: '取消订单',
                        desc: '确定要取消该订单吗？取消后不可恢复。',
                        okText: '确定取消',
                        cancelText: '再想想',
                        danger: true
                    }).then(function (ok) {
                        if (!ok) { return; }
                        var btn = t;
                        btn.disabled = true;
                        api('shop_order_close', { order_no: t.dataset.closeOrder }).then(function () {
                            toast('订单已取消', 'ok');
                            loadShopOrders();
                        }).catch(function (err) {
                            toast(err.message || '操作失败', 'err', 3600);
                        }).then(function () { btn.disabled = false; });
                    });
                }
            });
        }

        // 导航 / 页脚锚点：已登录时也能跳回首页对应区块
        bindNavLinks();

        // 激活码输入框：自动补全连字符
        var codeInput = $('#actCode');
        if (codeInput) {
            codeInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
