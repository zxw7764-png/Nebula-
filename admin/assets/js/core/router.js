import { S, can } from './state.js';
import { esc } from './util.js';
import { empty } from './util.js';

export const COMPOSITES = {
    softwares: ['software_list', 'version_list', 'client_notice_list'],
    users:    ['user_list', 'group_list', 'device_list', 'device_ban_list', 'session_list'],
    cards:    ['card_list', 'card_batch_list'],
    agents:   ['agent_list', 'agent_code_list'],

    shop_admin: ['plan_list', 'shop_goods', 'shop_setting', 'shop_order_list'],

    portal:     ['portal_web', 'notice_list', 'message_list', 'feedback_list', 'seller_list', 'screenshot_list', 'games'],
    logs:     ['log_list', 'audit_list'],
    files:    ['files_integrity', 'files_scan'],
};

export const LEGACY_MAP = {};
Object.entries(COMPOSITES).forEach(([cid, tabs]) => {
    tabs.forEach(tid => { LEGACY_MAP[tid] = cid; });
});

export const MENUS = [
    { group: '总览' },
    { id: 'dashboard',  name: '数据概览',   icon: 'bi-speedometer2', type: 'page' },
    { id: 'stat_overview', name: 'API 统计', icon: 'bi-graph-up', type: 'page', perm: 'user.read' },

    { group: '业务管理' },
    { id: 'softwares',  name: '软件管理',   icon: 'bi-window-stack', type: 'composite' },
    { id: 'users',      name: '用户与设备', icon: 'bi-people', type: 'composite' },
    { id: 'cards',      name: '卡密中心',   icon: 'bi-key', type: 'composite' },
    { id: 'agents',     name: '代理商',     icon: 'bi-diagram-3', type: 'composite' },

    { group: '经营' },
    { id: 'shop_admin',   name: '商品与交易', icon: 'bi-bag-check', type: 'composite' },
    { id: 'portal',       name: '内容运营',   icon: 'bi-globe', type: 'composite' },

    { group: '系统' },
    { id: 'templates',  name: '界面模板',   icon: 'bi-palette', type: 'page', perm: 'settings.site' },
    { id: 'logs',       name: '日志中心',   icon: 'bi-clock-history', type: 'composite' },
    { id: 'files',      name: '文件管理',   icon: 'bi-folder-check', type: 'composite' },
    { id: 'system_update', name: '系统更新', icon: 'bi-cloud-arrow-down', type: 'page', perm: 'settings.infra' },
    { id: 'setting',    name: '系统设置',   icon: 'bi-gear', type: 'page', perm: 'settings.site' },
    { id: 'profile',    name: '个人中心',   icon: 'bi-person', type: 'page' },
];

export const TITLES = {
    dashboard: '数据概览',
    stat_overview: 'API 统计',
    users:     '用户与设备',
    cards:     '卡密中心',
    agents:    '代理商',
    portal:    '内容运营',
    templates: '界面模板',
    logs:      '日志中心',
    files:     '文件管理',
    system_update: '系统更新',
    softwares: '软件管理',
    setting:   '系统设置',
    profile:   '个人中心',

    software_list: '软件列表',  version_list: '版本管理',  client_notice_list: '客户端公告',
    user_list: '用户管理',      group_list: '用户组',      device_list: '设备管理',
    device_ban_list: '设备黑名单', session_list: '在线会话',
    card_list: '卡密管理',      card_batch_list: '卡密批次',
    agent_list: '代理商',       agent_code_list: '代理商激活码',
    portal_web: '官网内容',
    notice_list: '官网公告',
    games: '小游戏与排行榜',
    message_list: '留言板',     feedback_list: '用户反馈', plan_list: '价格套餐',
    seller_list: '购买商家',    screenshot_list: '客户端截图',
    shop_admin: '商品与交易',
    shop_setting: '发卡网配置',
    shop_goods: '商品管理',
    shop_order_list: '订单管理',
    log_list: '操作日志',       audit_list: '审计日志',
    files_integrity: '完整性校验', files_scan: '挂马扫描',
};

const registry = {};

export function register(id, renderFn) {
    registry[id] = renderFn;
}

function tabVisible(tid) {
    const PERM_OF_TAB = {
        software_list: 'settings.business', version_list: 'settings.business',
        client_notice_list: 'settings.business',
        user_list: 'user.read', device_list: 'device.read', device_ban_list: 'device.read',
        session_list: 'device.read',
        group_list: 'user.read',
        card_list: 'card.read', card_batch_list: 'card.read',
        agent_list: 'agent.read', agent_code_list: 'agent.read',
        notice_list: 'content.manage',
        portal_web: 'settings.site',
        templates: 'settings.site',
        games: 'content.manage',
        message_list: 'content.manage', feedback_list: 'content.manage', plan_list: 'content.manage',
        seller_list: 'content.manage', screenshot_list: 'content.manage',
        shop_setting: 'settings.business',
        shop_goods: 'settings.business',
        shop_order_list: 'card.read',
        log_list: 'audit.read', audit_list: 'audit.read',
        files_integrity: 'settings.business', files_scan: 'settings.business',
    };
    const p = PERM_OF_TAB[tid];
    return !p || can(p);
}

function visibleTabs(cid) {
    return (COMPOSITES[cid] || []).filter(tabVisible);
}

export function visibleMenus() {
    return MENUS.filter(m => {
        if (m.group) return true;
        if (m.type === 'composite') return visibleTabs(m.id).length > 0;
        return !m.perm || can(m.perm);
    });
}

const SUB_KEY = 'nb_comp_tab_v1';
let pendingTab = null;

function readSubTab(cid) {
    if (pendingTab) {
        const t = pendingTab;
        pendingTab = null;
        if (visibleTabs(cid).includes(t)) return t;
    }
    try {
        const raw = JSON.parse(localStorage.getItem(SUB_KEY) || '{}');
        if (visibleTabs(cid).includes(raw[cid])) return raw[cid];
    } catch (e) {
 }
    return visibleTabs(cid)[0] || null;
}

function writeSubTab(cid, tid) {
    try {
        const raw = JSON.parse(localStorage.getItem(SUB_KEY) || '{}');
        raw[cid] = tid;
        localStorage.setItem(SUB_KEY, JSON.stringify(raw));
    } catch (e) {
 }
}

function renderSubTabs(cid, active) {
    const bar = document.getElementById('subTabs');
    if (!bar) return;
    const tabs = visibleTabs(cid);
    if (!tabs.length) { bar.className = 'subtabs'; bar.innerHTML = ''; return; }
    bar.className = 'subtabs on';
    bar.innerHTML = tabs.map(t => `
        <button data-tab="${t}" class="${t === active ? 'on' : ''}">${esc(TITLES[t] || t)}</button>
    `).join('');
    bar.querySelectorAll('button[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const tid = btn.dataset.tab;
            writeSubTab(cid, tid);
            bar.querySelectorAll('button').forEach(b => b.classList.toggle('on', b === btn));
            const fn = registry[tid];
            if (fn) fn();
            else document.getElementById('content').innerHTML = empty('<i class="bi bi-gear"></i>', '页面开发中');
            replayContentAnim();
        });
    });
}

function hideSubTabs() {
    const bar = document.getElementById('subTabs');
    if (bar) { bar.className = 'subtabs'; bar.innerHTML = ''; }
}

const COLLAPSE_KEY = 'nb_nav_collapsed_v2';

function groupMenus(menus) {
    const groups = [];
    menus.forEach(m => {
        if (m.group) {
            groups.push({ group: m.group, items: [] });
        } else {
            if (!groups.length) groups.push({ group: '', items: [] });
            groups[groups.length - 1].items.push(m);
        }
    });
    return groups;
}

function allGroupTitles() {
    return groupMenus(visibleMenus()).map(g => g.group).filter(Boolean);
}

function readCollapsed() {
    try {
        const raw = localStorage.getItem(COLLAPSE_KEY);
        if (raw !== null) {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return arr.filter(x => typeof x === 'string');
        }
    } catch (e) {
 }
    return allGroupTitles();
}

function writeCollapsed(list) {
    try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(list)); } catch (e) {
 }
}

export function renderNav() {
    const nav = document.getElementById('nav');
    if (!nav) return;

    const groups = groupMenus(visibleMenus());

    const activeGroup = (groups.find(g => g.items.some(it => it.id === S.page)) || {}).group;

    const collapsed = groupMenus(visibleMenus()).map(g => g.group).filter(g => g !== activeGroup);
    writeCollapsed(collapsed);

    nav.innerHTML = groups.map(g => {
        const fold = collapsed.includes(g.group);
        const items = g.items.map(m => `
            <a data-page="${m.id}" class="${S.page === m.id ? 'active' : ''}">
                <span class="ic"><i class="bi ${m.icon}"></i></span>
                <span class="tx">${esc(m.name)}</span>
            </a>`).join('');
        return `
            <div class="nav-group${fold ? ' collapsed' : ''}" data-group="${esc(g.group)}">
                <div class="group-title" data-toggle="${esc(g.group)}" title="点击收起 / 展开">
                    <span class="tx">${esc(g.group)}</span>
                    <span class="arrow"><i class="bi bi-chevron-down"></i></span>
                </div>
                <div class="group-items">${items}</div>
            </div>`;
    }).join('');

    nav.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', () => go(a.dataset.page));
    });

    nav.querySelectorAll('[data-toggle]').forEach(el => {
        el.addEventListener('click', () => {
            const t = el.dataset.toggle;
            const box = el.closest('.nav-group');
            if (!box) return;
            const opening = box.classList.contains('collapsed');

            if (opening) {
                nav.querySelectorAll('.nav-group').forEach(g => {
                    if (g !== box) g.classList.add('collapsed');
                });
            }
            box.classList.toggle('collapsed');

            const list = opening
                ? groupMenus(visibleMenus()).map(g => g.group).filter(g => g !== t)
                : readCollapsed().concat(t);
            writeCollapsed(list);
        });
    });
}

export function setAllGroups(collapsed) {
    if (collapsed) {
        const titles = groupMenus(visibleMenus()).map(g => g.group);
        writeCollapsed(titles);
    } else {
        writeCollapsed([]);
    }
    renderNav();
}

export function replayContentAnim() {
    const el = document.getElementById('content');
    if (!el) return;
    el.style.animation = 'none';
    void el.offsetWidth;
    el.style.animation = '';
}

export function go(page) {

    if (LEGACY_MAP[page]) {
        pendingTab = page;
        page = LEGACY_MAP[page];
    }

    const menus = visibleMenus();
    if (!menus.some(m => m.id === page)) page = 'dashboard';

    S.page = page;
    const titleEl = document.getElementById('pageTitle');
    if (titleEl) titleEl.textContent = TITLES[page] || page;
    renderNav();

    if (location.hash !== '#' + page) {
        history.replaceState(null, '', '#' + page);
    }

    if (COMPOSITES[page]) {
        const tab = readSubTab(page);
        renderSubTabs(page, tab);
        const fn = tab ? registry[tab] : null;
        if (fn) fn();
        else document.getElementById('content').innerHTML = empty('<i class="bi bi-gear"></i>', '暂无可查看的内容');
        replayContentAnim();
        return;
    }

    hideSubTabs();
    const fn = registry[page];
    if (fn) fn();
    else document.getElementById('content').innerHTML = empty('<i class="bi bi-gear"></i>', '页面开发中');
    replayContentAnim();
}

export function currentFromHash() {
    const h = (location.hash || '').replace(/^#/, '');
    if (registry[h] || COMPOSITES[h] || LEGACY_MAP[h]) return h;
    return 'dashboard';
}
