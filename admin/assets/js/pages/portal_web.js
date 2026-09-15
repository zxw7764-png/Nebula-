/* ======================================================================
   pages/portal_web.js — 官网内容（总站官网 + 分软件独立覆盖）
   ------------------------------------------------------------------
   · 页签一「总站官网内容」：官网默认文案 + 主题色
     （原「系统设置 → 站点」的官网文案整体迁入，存储走 setting_get/save）
   · 页签二「分软件官网内容」：按软件独立覆盖，留空回落总站
     （software_web_get / software_web_save，Setting key: web_sw_<id>）
   ====================================================================== */

import { api, uploadHeaders } from '../core/api.js';
import { API_ENTRY } from '../core/state.js';
import { register } from '../core/router.js';
import { loading, esc } from '../core/util.js';
import { toast } from '../core/ui.js';
import { bindImageUpload } from '../core/uploader.js';

register('portal_web', render);

/* 官网首页区块内置默认文案（与 web/inc/portal.php 保持一致，仅用于 placeholder 提示） */
const DFT_FLOW = "注册账号|使用用户名和密码注册，注册成功自动登录并进入个人中心。\n"
    + "兑换激活码|在个人中心输入购买到的激活码，点击激活，会员时长立即到账。\n"
    + "呼出菜单|运行 Nebula Menu，登录后按 Ins 键即可呼出菜单，开始调节各项功能。";
const DFT_FAQ = "菜单怎么呼出和隐藏？|默认按 Ins 键呼出或隐藏菜单。呼出后进入\"设置\"页即可把热键改成自己习惯的按键，保存后即时生效。\n"
    + "菜单呼不出来怎么办？|请以管理员身份运行 Nebula Menu；若游戏处于全屏独占模式，建议改为无边框窗口后再试。仍不显示请查看最新公告。\n"
    + "激活码提示\"已被使用\"怎么办？|每个激活码仅能激活一个账号，请确认是否已在其他账号使用，或联系客服核对订单。\n"
    + "换电脑后提示设备数量已达上限？|登录后进入个人中心，在「已绑定设备」里点旧设备右侧的解绑按钮即可释放额度，随后在新电脑登录就会自动绑定。也可点「全部解绑」一次性清空。\n"
    + "会员到期后菜单还能用吗？|到期后菜单将无法呼出。账号、设备与剩余时长均会保留，重新激活后立即恢复使用。";
const DFT_NAV = "菜单功能|features\n效果展示|shots\n使用流程|flow\n价格套餐|pricing\n购买商家|sellers\n留言板|board\n常见问题|faq\n我要反馈|feedback";
const DFT_FOOT = "菜单功能|features\n价格套餐|pricing\n留言板|board\n常见问题|faq";

/* 主题色预设（与发卡网配置同款） */
const THEME_PRESETS = [
    ['#a78bfa', '藤紫'], ['#ff8fb1', '樱花粉'], ['#5b9dff', '天空蓝'],
    ['#35d0ba', '薄荷青'], ['#f472b6', '莓果粉'], ['#60a5fa', '水蓝'],
    ['#818cf8', '星雾蓝紫'], ['#fb7185', '珊瑚橘粉'], ['#2dd4bf', '青柠汽水'],
    ['#93c5fd', '雾白蓝'],
];

/* 界面模板已统一收口到「界面模板」（含分软件设置），此处不再重复提供下拉 */

/**
 * 表单布局定义（row2 双列 + 整行 textarea + hint）
 * sec  = 分组标题（自动开启一张 .pw-group 子面板）；rows 有值 = textarea；full = 整行
 */
const FORM_LAYOUT = [
    { sec: '站点名与副标语' },
    { row: [
        { k: 'site_name', label: '站点名', ph: 'Nebula Menu' },
        { k: 'site_sub',  label: '首页副标语一', ph: '次世代游戏增强菜单' },
    ]},
    { sec: '首屏文案' },
    { row: [
        { k: 'web_hero_title', label: '首页主标题（第一行）', ph: '极致流畅的' },
        { k: 'web_hero_em',    label: '首页主标题（强调行）', ph: '游戏增强菜单' },
    ]},
    { full: { k: 'web_hero_lead2', label: '首页副标语二', ph: '默认 Ins 键一键呼出 · 功能模块自由开关 · 随开随用不打断操作。' } },
    { full: { k: 'web_hero_stats', label: '首屏数据条（每行一条：数值|标签，最多 6 行）', rows: 4,
        ph: '{online}|当前在线\n<50ms|呼出延迟\nIns|默认热键\nv{version}|最新版本',
        hint: '占位符：{online}=当前在线（实时刷新）、{version}=最新版本号；删掉整行即隐藏该格，全部清空保存恢复默认四格' } },
    { sec: '功能区' },
    { full: { k: 'web_features_sub', label: '功能区副标题', ph: '模块化设计，想要的功能在菜单里一键开关' } },
    { full: { k: 'web_features', label: '功能卡片（每行一条：图标|标题|内容）', rows: 5, ph: '🎮|功能模块|自瞄、透视……', hint: '全部清空保存则恢复内置默认四张' } },
    { sec: '使用流程' },
    { full: { k: 'web_flow_sub', label: '使用流程副标题', ph: '三步开启菜单，全程不超过一分钟' } },
    { full: { k: 'web_flow', label: '使用流程步骤（每行一条：标题|内容，编号自动生成）', rows: 4, ph: DFT_FLOW, hint: '官网首页「使用流程」区' } },
    { sec: '常见问题' },
    { full: { k: 'web_faq_sub', label: '常见问题副标题', ph: '还有其他疑问？联系客服处理' } },
    { full: { k: 'web_faq', label: '常见问题（每行一条：问题|答案）', rows: 6, ph: DFT_FAQ, hint: '官网首页「常见问题」折叠列表' } },
    { sec: '导航栏与页脚' },
    { full: { k: 'web_nav_links', label: '导航栏链接（每行一条：文本|锚点或链接）', rows: 4, ph: DFT_NAV, hint: '导航双重职责：顶部导航 + 首页区块开关与标题 —— 锚点（features/shots/flow/pricing/sellers/faq）出现在导航里才显示对应区块，区块大标题同步用导航文本；删除该行 = 隐藏区块和导航项；我要反馈 = 反馈弹窗入口。留言板例外：显示与名称均固定，只由「系统设置 → 站点 → 开启留言」控制，导航行仅控制是否在导航显示（改名无效）' } },
    { row: [
        { k: 'web_foot_slogan', label: '页脚标语', ph: '专业游戏增强菜单 · 稳定持续更新' },
        { k: 'web_copyright',   label: '版权文字（留空用默认 © 年份 站名）', ph: '© 2026 Nebula. All rights reserved.' },
    ]},
    { full: { k: 'web_foot_links', label: '页脚链接（每行一条：文本|锚点或链接）', rows: 4, ph: DFT_FOOT, hint: '官网底部链接区' } },
];

const ALL_KEYS = [
    ...new Set(FORM_LAYOUT.flatMap(b =>
        b.row ? b.row.map(f => f.k) : (b.full ? [b.full.k] : []))),
];

/**
 * 官网图片上传：点按钮 → 选本地图 → 传 web_upload → 把返回地址填入输入框
 * （走 multipart/form-data，与系统设置 Logo 上传同款链路）
 */
function uploadWebImage(btnId, inputId) {
    const btn = document.getElementById(btnId);
    const inp = document.getElementById(inputId);
    if (!btn || !inp) return;
    btn.addEventListener('click', () => {
        const pick = document.createElement('input');
        pick.type = 'file';
        pick.accept = 'image/jpeg,image/png,image/gif,image/webp';
        pick.onchange = async () => {
            const f = pick.files && pick.files[0];
            if (!f) return;
            btn.disabled = true;
            btn.textContent = '上传中…';
            try {
                const fd = new FormData();
                fd.append('file', f);
                const res = await fetch(API_ENTRY + '?action=web_upload', {
                    method: 'POST',
                    headers: uploadHeaders(),
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await res.json();
                if (j.code !== 0) throw new Error(j.msg || '上传失败');
                inp.value = (j.data || {}).url || '';
                toast('图片已上传，记得点保存');
            } catch (e) {
                toast(e.message || '上传失败', 'err');
            } finally {
                btn.disabled = false;
                btn.textContent = '上传背景图';
            }
        };
        pick.click();
    });
}

/** 生成表单 HTML（prefix 区分总站/分软件的控件 id；每个分组渲染为一张浅色子面板） */function formHtml(prefix, values, hint) {
    const field = f => {
        const ctl = f.rows
            ? `<textarea id="${prefix}_${f.k}" rows="${f.rows}" placeholder="${esc(f.ph)}">${esc(values[f.k] || '')}</textarea>`
            : `<input id="${prefix}_${f.k}" value="${esc(values[f.k] || '')}" placeholder="${esc(f.ph)}">`;
        return `<div class="field"><label>${esc(f.label)}</label>${ctl}${f.hint ? `<div class="hint">${esc(f.hint)}</div>` : ''}</div>`;
    };
    let html = '', open = false;
    for (const b of FORM_LAYOUT) {
        if (b.sec) {
            if (open) html += '</div>';
            html += `<div class="pw-group"><div class="pw-sec">${esc(b.sec)}</div>`;
            open = true;
        } else if (b.row) {
            html += `<div class="row2">${b.row.map(f => field(f)).join('')}</div>`;
        } else if (b.full) {
            html += field(b.full);
        }
    }
    if (open) html += '</div>';
    return html + (hint ? `<div class="hint" style="margin-top:2px">${esc(hint)}</div>` : '');
}

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    let sRes, swRes;
    try {
        [sRes, swRes] = await Promise.all([api('setting_get'), api('software_list')]);
    } catch (e) {
        return; // api() 已 toast 具体错误
    }
    if (sRes.code !== 0) return;
    const s = sRes.data.settings || {};
    const softwares = swRes.code === 0 ? (swRes.data.options || []) : [];

    const swOpts = softwares.map(sw =>
        `<option value="${sw.id}">${esc(sw.name)}${sw.status === 1 ? '' : '（停用）'}</option>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>官网内容</h3>
            <div class="pw-tabs">
                <button type="button" class="on" data-view="total">总站官网内容</button>
                <button type="button" data-view="sw">分软件官网内容</button>
            </div>
        </div>

        <!-- ==================== 页签一：总站官网内容 ==================== -->
        <div id="pwTotal" class="card-body">
            <div class="hint" style="margin-bottom:16px">
                总站 / 单软件模式官网展示的默认文案。某软件在「分软件官网内容」里设置了对应字段时，访问该软件官网优先用分软件版本。
            </div>
            <div class="pw-group">
                <div class="pw-sec">官网主题色与背景</div>
                <div class="row2">
                    <div class="field"><label>官网主题色（留空用默认紫）</label>
                        <input id="pw_theme" value="${esc(s.web_theme || '')}" placeholder="#7c5cff">
                        <div class="theme-pick" id="pwThemePick">
                            ${THEME_PRESETS.map(([v, name]) =>
                                `<button type="button" data-v="${v}" title="${esc(name)}"><i style="background:linear-gradient(135deg,${v},#ffffff88)"></i>${esc(name)}</button>`).join('')}
                        </div>
                    </div>
                    <div class="field"><label>背景图地址（留空用默认深空背景）</label>
                        <input id="pw_web_bg_url" value="${esc(s.web_bg_url || '')}" placeholder="https://example.com/bg.jpg">
                        <button class="btn ghost xs" id="pwBgUpload" type="button" style="margin-top:8px">上传背景图</button>
                        <div class="hint">上传后需点击下方「保存总站官网内容」才会生效；注意：界面模板激活时背景图不显示（模板自带装饰背景），切回「默认深空」后恢复</div>
                    </div>
                </div>
                <div class="hint">主题色官网全站换色（按钮/高亮/渐变联动），点选预设或手填 #RRGGBB；背景图自动等比铺满并叠加暗色遮罩，支持上传或外链（外链图床不稳定时推荐上传），分软件可单独覆盖二者。模板右下角小游戏与排行榜在「内容运营 → 小游戏与排行榜」单独管理。</div>
            </div>
            ${formHtml('pw', s)}
            <button class="btn" id="pwSave" style="margin-top:2px">保存总站官网内容</button>
        </div>

        <!-- ==================== 页签二：分软件官网内容 ==================== -->
        <div id="pwSw" class="card-body" hidden>
            ${softwares.length ? `
            <div class="hint" style="margin-bottom:16px">
                为单个软件单独设置官网展示文案，访问该软件官网（链接带 ?app= 标识）时生效。<b>留空的字段使用总站内容</b>；全部清空保存即恢复总站。
            </div>
            <div class="pw-group">
                <div class="pw-sec">选择软件</div>
                <div class="row2">
                    <div class="field"><label>软件</label>
                        <select id="pwSwSel">${swOpts}</select>
                        <div class="hint">切换软件会自动加载该软件已保存的覆盖内容</div>
                    </div>
                    <div class="field"><label>当前覆盖状态</label>
                        <input id="pwSwState" readonly value="加载中…">
                        <div class="hint">统计该软件已单独设置的官网字段数</div>
                    </div>
                </div>
            </div>
            <div class="pw-group">
                <div class="pw-sec">分站 Logo 与 Favicon</div>
                <div class="row2">
                    <div class="field"><label>分站 Logo 图片地址（留空用总站 Logo）</label>
                        <div class="logo-row">
                            <input id="pws_web_logo" placeholder="https://example.com/logo.png 或上传">
                            <button type="button" class="btn ghost sm" id="pwsLogoUpload">上传图片</button>
                        </div>
                    </div>
                    <div class="field"><label>Favicon 地址（留空沿用分站 Logo）</label>
                        <div class="logo-row">
                            <input id="pws_web_favicon" placeholder="https://example.com/favicon.png 或上传">
                            <button type="button" class="btn ghost sm" id="pwsFaviconUpload">上传图片</button>
                        </div>
                    </div>
                </div>
                <div class="hint">分站 Logo 生效于该软件官网的顶栏与页脚；Favicon 留空自动沿用分站 Logo（也可单独填图标直链，建议 png/ico）。二者均支持填 http/https 图片直链或直接上传。</div>
            </div>
            <div class="pw-group">
                <div class="pw-sec">分站功能开关与购买入口</div>
                <div class="row2">
                    <div class="field"><label>留言板开关</label>
                        <select id="pws_web_message_board">
                            <option value="">跟随总站设置</option>
                            <option value="1">开启</option>
                            <option value="0">关闭</option>
                        </select>
                    </div>
                    <div class="field"><label>客服联系方式（留空用总站）</label>
                        <input id="pws_contact" placeholder="QQ / 微信 / TG 等">
                    </div>
                </div>
                <div class="field"><label>发卡商店地址（留空用总站商店设置）</label>
                    <input id="pws_web_shop_url" placeholder="https://... 或站内 /shop/ 路径">
                </div>
                <div class="hint">该软件官网的购买按钮跳转、客服咨询弹窗与留言板显隐将使用此处设置；只影响该软件官网。</div>
            </div>
            <div class="pw-group">
                <div class="pw-sec">分站主题色与背景</div>
                <div class="row2">
                    <div class="field"><label>分站主题色（留空用总站主题色）</label>
                        <input id="pws_web_theme" value="" placeholder="#5b9dff">
                        <div class="theme-pick" id="pwsThemePick">
                            ${THEME_PRESETS.map(([v, name]) =>
                                `<button type="button" data-v="${v}" title="${esc(name)}"><i style="background:linear-gradient(135deg,${v},#ffffff88)"></i>${esc(name)}</button>`).join('')}
                        </div>
                    </div>
                    <div class="field"><label>分站背景图（留空用总站背景）</label>
                        <input id="pws_web_bg_url" value="" placeholder="https://example.com/bg.jpg">
                        <button class="btn ghost xs" id="pwsBgUpload" type="button" style="margin-top:8px">上传背景图</button>
                    </div>
                </div>
                <div class="hint">分站主题色生效后，该软件官网的按钮、链接、徽章、渐变、光斑、表单聚焦等全部主色元素同步换色；背景图同样自动叠加遮罩。分站界面模板请到「界面模板 → 分软件官网模板」设置。</div>
            </div>
            <div id="pwSwForm"></div>
            <button class="btn" id="pwSwSave" style="margin-top:2px">保存该软件官网内容</button>`
            : `<div class="hint">暂无软件。请先到「软件管理」新增软件，再回到这里为每个软件单独设置官网内容。</div>`}
        </div>
    </div>`;

    /* ---- 页签切换 ---- */
    c.querySelectorAll('.pw-tabs button').forEach(btn => {
        btn.addEventListener('click', () => {
            c.querySelectorAll('.pw-tabs button').forEach(x => x.classList.toggle('on', x === btn));
            const isTotal = btn.dataset.view === 'total';
            document.getElementById('pwTotal').hidden = !isTotal;
            document.getElementById('pwSw').hidden = isTotal;
        });
    });

    /* ---- 总站保存 ---- */
    document.getElementById('pwSave').addEventListener('click', async () => {
        const settings = {};
        ALL_KEYS.forEach(k => {
            const el = document.getElementById('pw_' + k);
            if (el) settings[k] = el.value.trim();
        });
        const themeEl = document.getElementById('pw_theme');
        if (themeEl) settings.web_theme = themeEl.value.trim();
        const bgUrlEl = document.getElementById('pw_web_bg_url');
        if (bgUrlEl) settings.web_bg_url = bgUrlEl.value.trim();
        const res = await api('setting_save', { settings });
        if (res.code === 0) toast('总站官网内容已保存');
    });

    /* ---- 主题色预设点选 ---- */
    const pick = document.getElementById('pwThemePick');
    if (pick) {
        pick.querySelectorAll('button').forEach(b =>
            b.addEventListener('click', () => {
                const inp = document.getElementById('pw_theme');
                if (inp) inp.value = b.dataset.v;
                pick.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            }));
    }

    /* ---- 背景图上传（总站 / 分软件共用一条链路） ---- */
    uploadWebImage('pwBgUpload', 'pw_web_bg_url');
    uploadWebImage('pwsBgUpload', 'pws_web_bg_url');

    /* ---- 分站 Logo / Favicon 上传 ---- */
    bindImageUpload('pwsLogoUpload', 'pws_web_logo');
    bindImageUpload('pwsFaviconUpload', 'pws_web_favicon');

    /* ---- 分站主题色预设点选（控件在静态区，切换软件后仍保留手填值） ---- */
    const pickSw = document.getElementById('pwsThemePick');
    if (pickSw) {
        pickSw.querySelectorAll('button').forEach(b =>
            b.addEventListener('click', () => {
                const inp = document.getElementById('pws_web_theme');
                if (inp) inp.value = b.dataset.v;
                pickSw.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            }));
    }

    /* ---- 分软件：加载 / 切换 / 保存 ---- */
    if (!softwares.length) return;
    const sel = document.getElementById('pwSwSel');
    const formBox = document.getElementById('pwSwForm');
    const stateInp = document.getElementById('pwSwState');

    function paintState(ov) {
        const n = Object.values(ov).filter(v => typeof v === 'string' && v.trim() !== '').length;
        stateInp.value = n > 0 ? `已覆盖 ${n} 项（其余用总站内容）` : '未覆盖（全部使用总站内容）';
    }

    async function loadSw() {
        const id = parseInt(sel.value, 10);
        formBox.innerHTML = '<div class="hint">加载中…</div>';
        stateInp.value = '…';
        try {
            const res = await api('software_web_get', { id });
            if (res.code !== 0) {
                formBox.innerHTML = '';
                stateInp.value = '读取失败';
                return toast(res.msg || '读取失败', 'err');
            }
            const ov = res.data.overrides || {};
            formBox.innerHTML = formHtml('pws', ov, '留空的字段使用总站内容');
            paintState(ov);
            // Logo / Favicon 与功能开关不在通用表单布局里，单独回填
            const logoEl = document.getElementById('pws_web_logo');
            const favEl  = document.getElementById('pws_web_favicon');
            if (logoEl) logoEl.value = ov.web_logo || '';
            if (favEl)  favEl.value  = ov.web_favicon || '';
            const boardEl = document.getElementById('pws_web_message_board');
            const contactEl = document.getElementById('pws_contact');
            const shopEl = document.getElementById('pws_web_shop_url');
            if (boardEl) boardEl.value = ov.web_message_board || '';
            if (contactEl) contactEl.value = ov.contact || '';
            if (shopEl) shopEl.value = ov.web_shop_url || '';
            const themeEl = document.getElementById('pws_web_theme');
            const swBgUrlEl = document.getElementById('pws_web_bg_url');
            if (themeEl) themeEl.value = ov.web_theme || '';
            if (swBgUrlEl) swBgUrlEl.value = ov.web_bg_url || '';
        } catch (e) {
            formBox.innerHTML = '';
            stateInp.value = '读取失败';
        }
    }

    sel.addEventListener('change', loadSw);
    await loadSw();

    document.getElementById('pwSwSave').addEventListener('click', async () => {
        const payload = { id: parseInt(sel.value, 10) };
        ALL_KEYS.forEach(k => {
            const el = document.getElementById('pws_' + k);
            if (el) payload[k] = el.value.trim();
        });
        // Logo / Favicon 与功能开关、商店入口一起保存
        const logoEl = document.getElementById('pws_web_logo');
        const favEl  = document.getElementById('pws_web_favicon');
        if (logoEl) payload.web_logo    = logoEl.value.trim();
        if (favEl)  payload.web_favicon = favEl.value.trim();
        const boardEl = document.getElementById('pws_web_message_board');
        const contactEl = document.getElementById('pws_contact');
        const shopEl = document.getElementById('pws_web_shop_url');
        if (boardEl) payload.web_message_board = boardEl.value;
        if (contactEl) payload.contact = contactEl.value.trim();
        if (shopEl) payload.web_shop_url = shopEl.value.trim();
        const swThemeEl = document.getElementById('pws_web_theme');
        const swBgUrlEl = document.getElementById('pws_web_bg_url');
        if (swThemeEl) payload.web_theme = swThemeEl.value.trim();
        if (swBgUrlEl) payload.web_bg_url = swBgUrlEl.value.trim();
        const res = await api('software_web_save', payload);
        if (res.code === 0) {
            toast('该软件官网内容已保存');
            const ov = res.data.overrides || {};
            paintState(ov);
        }
    });
}
