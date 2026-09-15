/* ======================================================================
   core/router.js — 菜单 / 路由 / 页面注册
   ------------------------------------------------------------------
   v2.31 重构：
   · 侧边栏从 22 项精简为 10 项，相关页面合并为「复合页面」，
     进入后用顶部标签页（#subTabs）在子页面之间切换
   · 子页面仍是独立模块（pages/*.js 原样渲染进 #content），
     合并只是路由层的组织方式，零业务逻辑改动
   · 旧的 hash（如 #device_list）仍然可用，会自动映射到
     对应复合页面并直接落在相应标签上
   ====================================================================== */

import { S, can } from './state.js';
import { esc } from './util.js';
import { empty } from './util.js';

/**
 * 复合页面定义：id → 子页面列表（顺序即标签顺序）。
 * 子页面必须已在 pages/*.js 中注册（register）。
 */
export const COMPOSITES = {
    softwares: ['software_list', 'version_list', 'client_notice_list'],
    users:    ['user_list', 'group_list', 'device_list', 'device_ban_list', 'session_list'],
    cards:    ['card_list', 'card_batch_list'],
    agents:   ['agent_list', 'agent_code_list'],
    // 商品与交易：官网定价（价格套餐）→ 发卡商品与挂卡规格 → 发卡网配置 → 订单
    // 价格套餐与商品管理是同一件商品的两个侧面（同一个 nb_plans），放同一页才看得出对应关系
    shop_admin: ['plan_list', 'shop_goods', 'shop_setting', 'shop_order_list'],
    // 内容运营：官网侧对外展示的一切内容
    portal:     ['portal_web', 'notice_list', 'message_list', 'feedback_list', 'seller_list', 'screenshot_list', 'games'],
    logs:     ['log_list', 'audit_list'],
    files:    ['files_integrity', 'files_scan'],
};

/** 旧单页 id → 所属复合页面（保持旧链接 / 收藏可用） */
export const LEGACY_MAP = {};
Object.entries(COMPOSITES).forEach(([cid, tabs]) => {
    tabs.forEach(tid => { LEGACY_MAP[tid] = cid; });
});

/**
 * 菜单定义（侧边栏）。
 *   type: 'page'      普通页面（id = action 名）
 *   type: 'composite' 复合页面（id ∈ COMPOSITES，内部标签页切换）
 * perm 只约束普通页面；复合页面只要任一子页可见即显示。
 */
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
    { id: 'setting',    name: '系统设置',   icon: 'bi-gear', type: 'page', perm: 'settings.site' },
    { id: 'profile',    name: '个人中心',   icon: 'bi-person', type: 'page' },
];

/** 子页面的显示名（标签文字），取自 TITLES */
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
    softwares: '软件管理',
    setting:   '系统设置',
    profile:   '个人中心',
    // 子页面（供标签页 / 兼容跳转使用）
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

/** 页面渲染函数注册表 */
const registry = {};

/** 注册一个页面 */
export function register(id, renderFn) {
    registry[id] = renderFn;
}

/* ------------------------- 权限过滤 ------------------------- */

/** 子页面是否可见 */
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

/** 复合页面对当前管理员可见的子页列表 */
function visibleTabs(cid) {
    return (COMPOSITES[cid] || []).filter(tabVisible);
}

/** 当前管理员可见的菜单 */
export function visibleMenus() {
    return MENUS.filter(m => {
        if (m.group) return true;
        if (m.type === 'composite') return visibleTabs(m.id).length > 0;
        return !m.perm || can(m.perm);
    });
}

/* ------------------------- 子标签页状态 ------------------------- */

const SUB_KEY = 'nb_comp_tab_v1';
let pendingTab = null;   // 旧 hash 直达某个子页时暂存

function readSubTab(cid) {
    if (pendingTab) {
        const t = pendingTab;
        pendingTab = null;
        if (visibleTabs(cid).includes(t)) return t;
    }
    try {
        const raw = JSON.parse(localStorage.getItem(SUB_KEY) || '{}');
        if (visibleTabs(cid).includes(raw[cid])) return raw[cid];
    } catch (e) { /* 忽略 */ }
    return visibleTabs(cid)[0] || null;
}

function writeSubTab(cid, tid) {
    try {
        const raw = JSON.parse(localStorage.getItem(SUB_KEY) || '{}');
        raw[cid] = tid;
        localStorage.setItem(SUB_KEY, JSON.stringify(raw));
    } catch (e) { /* 忽略 */ }
}

/** 渲染复合页面的顶部标签条（位于顶栏与内容区之间） */
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

/* ------------------------- 分组折叠状态 ------------------------- */
/**
 * 折叠状态存 localStorage（跨刷新保持）；存的是被折叠的「分组标题」列表。
 * 用 v2 键：与旧版（默认全展开）的存储隔离，保证「默认收起」对新老浏览器都生效。
 */
const COLLAPSE_KEY = 'nb_nav_collapsed_v2';

/** 把线性菜单切成 [{ group, items: [] }]，无标题的项归入空分组 */
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

/** 当前可见的全部分组标题 */
function allGroupTitles() {
    return groupMenus(visibleMenus()).map(g => g.group).filter(Boolean);
}

/**
 * 读取折叠状态：
 *   · 有存储记录 → 完全尊重用户上次的展开 / 收起
 *   · 首次使用（无记录）→ 返回全部分组，即**侧边栏默认收起**
 */
function readCollapsed() {
    try {
        const raw = localStorage.getItem(COLLAPSE_KEY);
        if (raw !== null) {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return arr.filter(x => typeof x === 'string');
        }
    } catch (e) { /* 隐私模式等：退回默认 */ }
    return allGroupTitles();
}

function writeCollapsed(list) {
    try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(list)); } catch (e) { /* 忽略隐私模式等写入失败 */ }
}

export function renderNav() {
    const nav = document.getElementById('nav');
    if (!nav) return;

    const groups = groupMenus(visibleMenus());
    // 当前页所在分组：始终展开，避免进了页面却看不到高亮项
    const activeGroup = (groups.find(g => g.items.some(it => it.id === S.page)) || {}).group;
    // 手风琴不变量：只展开当前页所在分组，其余一律收起——
    // 防止「上一次手风琴展开的分组」在刷新/换页后仍然挂着（如停在数据概览时业务管理还展开）
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

    // 菜单跳转
    nav.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', () => go(a.dataset.page));
    });

    // 分组折叠切换（手风琴：展开某组时自动收起其他组；就地改 class，不整表重绘，保留滚动位置）
    nav.querySelectorAll('[data-toggle]').forEach(el => {
        el.addEventListener('click', () => {
            const t = el.dataset.toggle;
            const box = el.closest('.nav-group');
            if (!box) return;
            const opening = box.classList.contains('collapsed');
            // 展开某组 → 收起其余所有组
            if (opening) {
                nav.querySelectorAll('.nav-group').forEach(g => {
                    if (g !== box) g.classList.add('collapsed');
                });
            }
            box.classList.toggle('collapsed');

            const list = opening
                ? groupMenus(visibleMenus()).map(g => g.group).filter(g => g !== t)   // 手风琴：只有 t 展开
                : readCollapsed().concat(t);
            writeCollapsed(list);
        });
    });
}

/** 展开全部分组 / 收起全部分组（供外部快捷调用） */
export function setAllGroups(collapsed) {
    if (collapsed) {
        const titles = groupMenus(visibleMenus()).map(g => g.group);
        writeCollapsed(titles);
    } else {
        writeCollapsed([]);
    }
    renderNav();
}

/** 内容区切换动画：重触发 .content 的入场动画 */
export function replayContentAnim() {
    const el = document.getElementById('content');
    if (!el) return;
    el.style.animation = 'none';
    void el.offsetWidth;   // 强制回流，重启动画
    el.style.animation = '';
}

export function go(page) {
    // 兼容旧地址：#device_list 之类直接映射到复合页面并落在对应标签
    if (LEGACY_MAP[page]) {
        pendingTab = page;
        page = LEGACY_MAP[page];
    }

    // 角色校验：不可见页面不允许进入
    const menus = visibleMenus();
    if (!menus.some(m => m.id === page)) page = 'dashboard';

    S.page = page;
    const titleEl = document.getElementById('pageTitle');
    if (titleEl) titleEl.textContent = TITLES[page] || page;
    renderNav();

    // 更新 URL hash（便于刷新保持页面、可收藏）
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

/** 从 hash 恢复页面 */
export function currentFromHash() {
    const h = (location.hash || '').replace(/^#/, '');
    if (registry[h] || COMPOSITES[h] || LEGACY_MAP[h]) return h;
    return 'dashboard';
}
