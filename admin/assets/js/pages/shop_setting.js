/* ======================================================================
   pages/shop_setting.js — 发卡网配置（运营分区，复合页 shop_admin 的子页）
   ----------------------------------------------------------------------
   页内三个页签（与系统设置页的页签模式一致）：
     商店开关 = 总开关 / 发卡模式 / 外部发卡站地址
     支付     = 支付方式 / 易支付三件套 / 人工收款（收款码 + 联系方式）
     商店外观 = 标题 / 主题色 / 公告 / 横幅 / 页脚（/shop/ 装修）
   保存走独立 action shop_setting_save（服务端归业务档，仅超管），
   无论在哪个页签点保存，都提交全部键（服务端整表受理）。
   数据读取复用 setting_get。页签记忆 localStorage（nb_shopset_tab）。
   ====================================================================== */

import { api } from '../core/api.js';
import { register, go } from '../core/router.js';
import { loading, esc, tag } from '../core/util.js';
import { toast } from '../core/ui.js';
import { can } from '../core/state.js';
import { bindImageUpload } from '../core/uploader.js';

register('shop_setting', render);

/** 页签定义：key 与面板容器 id（ssP-<key>）对应 */
const TABS = [
    { key: 'switch', label: '商店开关' },
    { key: 'pay',    label: '支付' },
    { key: 'style',  label: '商店外观' },
];

/** 当前商品展示样式（render 时从设置回填，save 提交） */
let curLayout = 'grid';

/** 记住的页签（跨 render 保留） */
let curTab = 'switch';
try {
    const t = localStorage.getItem('nb_shopset_tab');
    if (TABS.some(x => x.key === t)) curTab = t;
} catch (e) { /* ignore */ }

function noPermBar() {
    return `<div class="hint" style="color:#f59e0b;background:rgba(245,158,11,.08);
        border:1px solid rgba(245,158,11,.25);border-radius:6px;padding:10px 12px;margin-bottom:14px">
        当前角色无权修改「发卡网配置」，以下内容仅供查看。如需变更请联系超级管理员。
    </div>`;
}

const boolSel = (id, val) => `
    <select id="${id}">
        <option value="1" ${val === '1' ? 'selected' : ''}>开启</option>
        <option value="0" ${val !== '1' ? 'selected' : ''}>关闭</option>
    </select>`;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('setting_get');
    if (res.code !== 0) return;
    const s = res.data.settings || {};

    const editable = can('settings.business');
    const on  = s.shop_enable === '1';
    const ext = s.shop_mode === 'external';
    const manual = s.shop_pay_mode === 'manual';
    const epayReady = (s.shop_epay_url || '') !== '' && (s.shop_epay_pid || '') !== '' && (s.shop_epay_key || '') !== '';
    // 支付链路状态：跟随当前选择的支付驱动
    const curDrv = manual ? 'manual' : (s.shop_pay_driver || 'epay');
    const payCfgAllS = (() => { try { return JSON.parse(s.shop_pay_cfg || '{}') || {}; } catch (e) { return {}; } })();
    const DRV_META = {
        epay:    { label: '易支付', ready: epayReady },
        codepay: { label: '码支付', ready: ['gateway', 'pid', 'key'].every(k => ((payCfgAllS.codepay || {})[k] || '').trim() !== '') },
        vmq:     { label: 'V免签', ready: ['gateway', 'key'].every(k => ((payCfgAllS.vmq || {})[k] || '').trim() !== '') },
        manual:  { label: '人工确认', ready: true },
    };
    const drvMeta = DRV_META[curDrv] || DRV_META.epay;
    curLayout = ['grid', 'compact', 'rows', 'sidebar', 'pick'].includes(s.shop_layout) ? s.shop_layout : 'grid';

    // 顶部状态条：所有页签共享，一眼看清当前形态
    const status = `
        <div class="kv" style="margin-bottom:14px">
            <span class="k">发卡网</span><span class="v">${on ? tag('已开启', 'green') : tag('已关闭', 'gray')}</span>
            <span class="k">模式</span><span class="v">${ext ? '跳转外部发卡站' : (manual ? '内置发卡 · 人工确认' : '内置发卡 · 自动发卡')}</span>
            <span class="k">商店地址</span><span class="v mono">${esc(location.origin)}/shop/</span>
            ${!ext && on ? `<span class="k">支付链路</span><span class="v">${drvMeta.ready
                ? tag(drvMeta.label + '已配置', 'green')
                : tag(drvMeta.label + '未配置齐（自动单将降级人工）', 'yellow')}</span>` : ''}
        </div>`;

    // ==================== 页签一：商店开关 ====================
    const pSwitch = `
        <div class="row2">
            <div class="field"><label>发卡网开关</label>${boolSel('ssEnable', s.shop_enable)}
                <div class="hint">关闭后官网购买入口隐藏、/shop/ 整站下线，已发卡订单与卡密不受影响</div>
            </div>
            <div class="field"><label>发卡模式</label>
                <select id="ssMode">
                    <option value="built" ${ext ? '' : 'selected'}>内置发卡（本站自动/人工发货）</option>
                    <option value="external" ${ext ? 'selected' : ''}>跳转外部发卡站</option>
                </select>
                <div class="hint">外部模式：官网购买入口直接跳转下方链接，本站不参与交易</div>
            </div>
        </div>
        <div class="field"><label>外部发卡站地址</label>
            <input id="ssExtUrl" value="${esc(s.shop_external_url || '')}" placeholder="https://你的发卡站/">
            <div class="hint">仅在「跳转外部发卡站」模式下生效；填写后官网购买按钮将以新标签页打开此链接</div>
        </div>
        <div class="field"><label>按软件过滤商品（分软件显示）</label>
            ${boolSel('ssSwFilter', s.shop_sw_filter)}
            <div class="hint">开启后按识别号显示对应商品：访客从官网（?app= 或已选择软件）进入商店时，只看到「全部软件通用」+「归属该软件」的商品；多软件且未识别时只见通用商品。商品的归属在「发卡商品」编辑弹窗的「显示归属软件」里设置（默认全部软件通用）</div>
        </div>
        <button class="btn" id="ssSaveSwitch" ${editable ? '' : 'disabled'}>保存开关设置</button>`;

    // ==================== 页签二：支付 ====================
    const pPay = `
        <div class="row2">
            <div class="field"><label>支付方式（内置发卡）</label>
                <select id="ssPayDrv">
                    <option value="epay" ${curDrv === 'epay' ? 'selected' : ''}>彩虹易支付（内置，推荐）</option>
                    <option value="codepay" ${curDrv === 'codepay' ? 'selected' : ''}>码支付（个人免签 · 支付宝/微信/QQ）</option>
                    <option value="vmq" ${curDrv === 'vmq' ? 'selected' : ''}>V免签（聚合支付 · 支付宝/微信）</option>
                    <option value="manual" ${manual ? 'selected' : ''}>人工确认（收款码 + 后台确认发货）</option>
                </select>
                <div class="hint">选择支付通道后按提示填写对应配置；人工模式不走在线支付</div>
            </div>
            <div class="field"><label>人工发货联系方式</label>
                <input id="ssContact" value="${esc(s.shop_contact || '')}" placeholder="QQ / 微信 / 邮箱">
                <div class="hint">人工模式下单页展示，买家通过它与您确认发货</div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>下单/查询凭证方式</label>
                <select id="ssContactMode">
                    <option value="phone" ${(s.shop_contact_mode || 'phone') === 'phone' ? 'selected' : ''}>手机号 + 查询密码</option>
                    <option value="email" ${s.shop_contact_mode === 'email' ? 'selected' : ''}>邮箱 + 查询密码</option>
                    <option value="custom" ${s.shop_contact_mode === 'custom' ? 'selected' : ''}>自定义内容 + 查询密码</option>
                </select>
                <div class="hint">买家下单时填写此凭证并设置查询密码，之后凭「凭证+密码」查询订单</div>
            </div>
            <div class="field"><label>系统卡发货方式（验证系统商品）</label>
                <select id="ssGenMode">
                    <option value="stock" ${(s.shop_card_gen_mode || 'stock') === 'stock' ? 'selected' : ''}>仅用库存卡密（缺货转人工，推荐）</option>
                    <option value="auto" ${s.shop_card_gen_mode === 'auto' ? 'selected' : ''}>先用库存，缺货自动生成</option>
                    <option value="gen" ${s.shop_card_gen_mode === 'gen' ? 'selected' : ''}>每单自动生成（不占用库存）</option>
                </select>
                <div class="hint">只对验证系统商品生效，外部卡密商品始终走自己的卡池；自动生成的卡一律归属官方直发（agent_id=0），与代理商库存彻底区分，规格按订单快照（类型/时长/设备数/用户组）</div>
            </div>
        </div>
        <div id="ssPayCfg"></div>
        <div class="row2">
            <div class="field"><label>站点地址（支付回跳用）</label>
                <input id="ssSiteUrl" value="${esc(s.shop_site_url || '')}" placeholder="https://www.example.com">
                <div class="hint">发卡网对外的完整域名（含 https://）。支付回跳地址以此为准，防止 Host 头伪造；留空则自动按当前访问域名生成</div>
            </div>
            <div class="field"><label>收款码图片地址（人工模式）</label>
                <div class="logo-row">
                    <input id="ssQrcode" value="${esc(s.shop_qrcode || '')}" placeholder="https://... 或上传（留空则只显示联系方式）">
                    <button type="button" class="btn ghost sm" id="ssQrcodeUpload">上传图片</button>
                </div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>自动生成卡密前缀（可选）</label>
                <input id="ssGenPrefix" value="${esc(s.shop_gen_prefix || '')}" placeholder="如 SHOP，仅字母数字，留空随机">
            </div>
            <div class="field"><label>卡密有效期（天，可选）</label>
                <input id="ssGenExpire" value="${esc(s.shop_gen_expire_days || '')}" placeholder="0 或留空 = 永久有效">
            </div>
        </div>
        <div class="field"><label>支付渠道（买家下单可选的支付方式）</label>
            <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
                <div style="display:flex;gap:12px;padding:8px 14px;background:var(--hover-wash,var(--primary-bg));font-size:12px;color:var(--text-sub,#888);border-bottom:1px solid var(--border)">
                    <span style="width:17px"></span><span style="flex:1">渠道</span><span style="width:220px">显示名称</span>
                </div>
                ${(() => {
                    const raw = (s.shop_channels || '').trim();
                    const configured = raw !== '';
                    const map = {};
                    raw.split(/\r?\n/).forEach(l => {
                        const p = l.split('|');
                        if (p[0]) map[p[0].trim().toLowerCase()] = (p[1] || '').trim();
                    });
                    return [['alipay', '支付宝', 'bi-alipay'], ['wxpay', '微信支付', 'bi-wechat'], ['qqpay', 'QQ钱包', 'bi-qq']].map(([v, def, icon], i) => `
                    <div style="display:flex;align-items:center;gap:12px;padding:9px 14px${i < 2 ? ';border-bottom:1px solid var(--border)' : ''}">
                        <input type="checkbox" id="ssChOn_${v}" ${!configured || map[v] !== undefined ? 'checked' : ''} style="width:17px;height:17px;accent-color:var(--primary,#4f7cff);cursor:pointer;flex-shrink:0">
                        <label for="ssChOn_${v}" style="flex:1;display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
                            <i class="bi ${icon}" style="color:var(--primary,#4f7cff);font-size:15px"></i>${def}
                        </label>
                        <input class="tinput" id="ssChLb_${v}" style="width:220px" value="${esc(map[v] !== undefined && map[v] !== '' ? map[v] : def)}" placeholder="${def}">
                    </div>`).join('');
                })()}
            </div>
            <div class="hint">取消勾选该渠道即不在买家端展示；显示名称可自定义（如把「QQ钱包」改成「QQ」）。至少保留一个渠道，全部取消则回退默认三项。</div>
        </div>
        <button class="btn" id="ssSavePay" ${editable ? '' : 'disabled'}>保存支付设置</button>`;

    // ==================== 页签三：商店外观 ====================
    const pStyle = `
        <div class="row2">
            <div class="field"><label>商店标题</label>
                <input id="ssTitle" value="${esc(s.shop_title || '')}" placeholder="留空显示「站点名 · 发卡商店」">
            </div>
            <div class="field"><label>主题色</label>
                <input id="ssTheme" value="${esc(s.shop_theme || '')}" placeholder="#7c5cff（留空用默认紫）">
                <div class="theme-pick" id="ssThemePick">
                    <button type="button" data-v="#a78bfa" title="藤紫（默认）"><i style="background:linear-gradient(135deg,#a78bfa,#c4b5fd)"></i>藤紫</button>
                    <button type="button" data-v="#ff8fb1" title="樱花粉"><i style="background:linear-gradient(135deg,#ff8fb1,#ffd1dc)"></i>樱花粉</button>
                    <button type="button" data-v="#5b9dff" title="天空蓝"><i style="background:linear-gradient(135deg,#5b9dff,#96c93d00)"></i>天空蓝</button>
                    <button type="button" data-v="#35d0ba" title="薄荷青"><i style="background:linear-gradient(135deg,#35d0ba,#a0f0dc)"></i>薄荷青</button>
                    <button type="button" data-v="#f472b6" title="莓果粉"><i style="background:linear-gradient(135deg,#f472b6,#a78bfa)"></i>莓果粉</button>
                    <button type="button" data-v="#60a5fa" title="水蓝"><i style="background:linear-gradient(135deg,#60a5fa,#67e8f9)"></i>水蓝</button>
                    <button type="button" data-v="#818cf8" title="星雾蓝紫"><i style="background:linear-gradient(135deg,#818cf8,#f0abfc)"></i>星雾蓝紫</button>
                    <button type="button" data-v="#fb7185" title="珊瑚橘粉"><i style="background:linear-gradient(135deg,#fb7185,#fdba74)"></i>珊瑚橘粉</button>
                    <button type="button" data-v="#2dd4bf" title="青柠汽水"><i style="background:linear-gradient(135deg,#2dd4bf,#bef264)"></i>青柠汽水</button>
                    <button type="button" data-v="#93c5fd" title="雾白蓝（浅色）"><i style="background:linear-gradient(135deg,#e0e7ff,#93c5fd)"></i>雾白蓝</button>
                </div>
                <div class="hint">点选预设主题色或手填六位十六进制色值（留空用默认紫）；浅色系按钮上文字对比度会略低</div>
            </div>
        </div>
        <div class="field"><label>发卡网小游戏开关</label>
            ${boolSel('ssGames', (s.shop_games_enabled === undefined || s.shop_games_enabled === null || s.shop_games_enabled === '') ? '1' : s.shop_games_enabled)}
            <div class="hint">界面模板（云上农场/马里奥/仙门水墨/太空站）右下角自带小游戏与全站排行榜；关闭后买家端刷新即不显示小游戏，已产生的榜单记录保留；记得点击下方「保存外观设置」</div>
        </div>
        <div class="row2">
            <div class="field"><label>浏览器标签页标题（留空用商店标题）</label>
                <input id="ssTabTitle" value="${esc(s.shop_tab_title || '')}" placeholder="如：Nebula 发卡 · 极速到卡">
            </div>
            <div class="field"><label>离开页面提醒文案（切走标签时标题闪动）</label>
                <input id="ssTabAlert" value="${esc(s.shop_tab_alert || '')}" placeholder="快回来 ~ 还有卡密等你带走！">
                <div class="hint">买家切到其他标签页时，发卡网标签标题会闪动提醒；填 none 可关闭</div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>离开页面标签图标</label>
                <select id="ssTabIcon">
                    <option value="dot" ${(s.shop_tab_icon || 'dot') === 'dot' ? 'selected' : ''}>主题色圆点（默认）</option>
                    <option value="heart" ${s.shop_tab_icon === 'heart' ? 'selected' : ''}>主题色圆点 + 爱心</option>
                    <option value="keep" ${s.shop_tab_icon === 'keep' ? 'selected' : ''}>保留原图标不变</option>
                </select>
                <div class="hint">买家切走标签页时标签图标替换为哪种样式，切回后自动恢复</div>
            </div>
            <div class="field"><label>商品详情样式</label>
                <select id="ssDetailStyle">
                    <option value="page" ${(s.shop_detail_style || 'page') === 'page' ? 'selected' : ''}>整页详情（上大图，下方左详情右购买，推荐）</option>
                    <option value="modal" ${s.shop_detail_style === 'modal' ? 'selected' : ''}>弹窗详情（上大图紧凑小窗）</option>
                </select>
                <div class="hint">买家点击「查看详情」后的展示形态：整页详情含商品大图、购买数量与商品详情区块</div>
            </div>
        </div>
        <div class="field"><label>商品展示样式</label>
            <div class="layout-pick" id="ssLayout">
                <button type="button" data-v="grid"${curLayout === 'grid' ? ' class="on"' : ''}>网格卡片<small>经典竖排大卡，适合商品较少的站点</small></button>
                <button type="button" data-v="compact"${curLayout === 'compact' ? ' class="on"' : ''}>紧凑小卡<small>窄列密集陈列，不展示卖点文字</small></button>
                <button type="button" data-v="rows"${curLayout === 'rows' ? ' class="on"' : ''}>橱窗列表<small>图标、标题与简介横向排布，适合批量陈列</small></button>
                <button type="button" data-v="sidebar"${curLayout === 'sidebar' ? ' class="on"' : ''}>分类侧栏<small>左侧竖排分类导航，右侧展示商品卡</small></button>
                <button type="button" data-v="pick"${curLayout === 'pick' ? ' class="on"' : ''}>选择式<small>分类与商品按钮选择，点选商品后于下方展开购买面板</small></button>
            </div>
            <div class="hint">保存后到 /shop/ 前台刷新查看效果</div>
        </div>
        <div class="field"><label>商店公告（顶部横条，支持 HTML）</label>
            <textarea id="ssNotice" rows="3" placeholder="显示在商品列表上方，留空不显示；可填写自定义 HTML/CSS">${esc(s.shop_notice || '')}</textarea>
        </div>
        <div class="field"><label>弹窗公告（进店弹出，支持 HTML）</label>
            <textarea id="ssPopup" rows="6" placeholder="买家打开商店时弹窗展示，留空不弹；支持 HTML 代码，内容统一在弹窗公告框内渲染（含 style 的整份 HTML 文档会自动剥壳，样式只作用于公告框内）">${esc(s.shop_popup_notice || '')}</textarea>
        </div>
        <div class="field"><label>前台 Logo</label>
            <div class="logo-row">
                <input id="ssLogo" value="${esc(s.shop_logo || '')}" placeholder="https://... 或上传（留空用默认 logo.png）">
                <button type="button" class="btn ghost sm" id="ssLogoUpload">上传图片</button>
            </div>
            <div class="hint">支持手填链接或直接上传；系统设置 → 站点 的 Logo 与此处共用同一配置</div>
        </div>
        <div class="field"><label>背景大图（铺满发卡网全站背景，支持上传 jpg / png / gif / webp）</label>
            <div class="logo-row">
                <input id="ssBg" value="${esc(s.shop_bg_url || '')}" placeholder="https://... 或上传（留空不显示）">
                <button type="button" class="btn ghost sm" id="ssBgUpload">上传图片</button>
            </div>
            <div class="hint" id="ssBgHint">作为发卡网全站固定背景铺满显示，自动叠加暗色遮罩保证文字可读；上传后需点击「保存外观设置」才会生效；界面模板激活时背景图不显示（模板自带装饰背景），切回「默认深空」后恢复</div>
        </div>
        <div class="row2">
            <div class="field"><label>横幅大图地址（支持上传 jpg / png / gif / webp 图片）</label>
                <div class="logo-row">
                    <input id="ssBanner" value="${esc(s.shop_banner || '')}" placeholder="https://... 或上传（留空不显示）">
                    <button type="button" class="btn ghost sm" id="ssBannerUpload">上传图片</button>
                </div>
                <div class="hint" id="ssBannerHint">仅限图片（含 GIF 动图），5MB 以内；上传后点下方「保存商店外观」生效</div>
            </div>
            <div class="field"><label>页脚文案</label>
                <input id="ssFooter" value="${esc(s.shop_footer || '')}" placeholder="留空显示默认版权">
            </div>
            <div class="field"><label>购买须知（每行一条，格式「标题|内容」）</label>
                <textarea id="ssNotes" rows="5" placeholder="自动发货|在线支付成功后系统即时发货，卡密直接展示在页面上。&#10;保存凭证|记牢下单凭证与查询密码，可随时查询卡密。&#10;（整块留空则前台隐藏「购买须知」板块）">${esc(s.shop_notes || '')}</textarea>
                <div class="hint">每行一条；竖线前是条目标题、后是说明文字；全部清空保存后前台不显示该板块</div>
            </div>
        </div>
        <button class="btn" id="ssSaveStyle" ${editable ? '' : 'disabled'}>保存外观设置</button>`;

    const panels = { switch: pSwitch, pay: pPay, style: pStyle };

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>发卡网配置</h3></div>
        <div class="card-body">
            ${editable ? '' : noPermBar()}
            ${status}
        </div>
        <div class="tabs" id="ssTabs" style="margin:0;padding:0 20px 12px">
            ${TABS.map(t =>
                `<button data-tab="${t.key}"${t.key === curTab ? ' class="on"' : ''}>${t.label}</button>`
            ).join('')}
        </div>
        ${TABS.map(t =>
            `<div class="card-body" id="ssP-${t.key}"${t.key === curTab ? '' : ' style="display:none"'}>${panels[t.key]}</div>`
        ).join('')}
    </div>`;

    // 页签切换
    const switchTab = (key) => {
        curTab = key;
        try { localStorage.setItem('nb_shopset_tab', key); } catch (e) { /* ignore */ }
        c.querySelectorAll('#ssTabs button').forEach(b =>
            b.classList.toggle('on', b.dataset.tab === key));
        TABS.forEach(t => {
            const p = document.getElementById('ssP-' + t.key);
            if (p) p.style.display = t.key === key ? '' : 'none';
        });
    };
    c.querySelectorAll('#ssTabs button').forEach(btn =>
        btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

    // 商品展示样式卡片选择（单选高亮，save 时随表提交）
    // 主题色预设：点击填充输入框并高亮
    const themePick = document.getElementById('ssThemePick');
    if (themePick) {
        themePick.querySelectorAll('button').forEach(b =>
            b.addEventListener('click', () => {
                const inp = document.getElementById('ssTheme');
                if (inp) inp.value = b.dataset.v;
                themePick.querySelectorAll('button').forEach(x =>
                    x.classList.toggle('on', x === b));
            }));
    }

    // 图片字段统一上传（横幅 / 背景 / 收款码 / 前台 Logo）：点按钮选图上传后回填输入框
    bindImageUpload('ssBannerUpload', 'ssBanner', {
        onDone: url => {
            const hint = document.getElementById('ssBannerHint');
            if (hint) hint.textContent = '已上传：' + url + '，点「保存商店外观」生效';
        },
    });
    bindImageUpload('ssBgUpload', 'ssBg', {
        onDone: url => {
            const hint = document.getElementById('ssBgHint');
            if (hint) hint.textContent = '已上传：' + url + '，点「保存商店外观」生效';
        },
    });
    bindImageUpload('ssQrcodeUpload', 'ssQrcode');
    bindImageUpload('ssLogoUpload', 'ssLogo');

    const layoutBox = document.getElementById('ssLayout');
    if (layoutBox) {
        layoutBox.querySelectorAll('button').forEach(b =>
            b.addEventListener('click', () => {
                curLayout = b.dataset.v;
                layoutBox.querySelectorAll('button').forEach(x =>
                    x.classList.toggle('on', x === b));
            }));
    }

    // 各页签保存按钮：都提交全部 17 个键（隐藏面板里的字段照样能取到值）
    document.getElementById('ssSaveSwitch').addEventListener('click', () => save().catch(e => toast('保存失败：' + e.message, 'err')));
    document.getElementById('ssSavePay').addEventListener('click', () => save().catch(e => toast('保存失败：' + e.message, 'err')));
    // 支付驱动配置：随下拉切换渲染对应输入项
    payCfgAll = (() => { try { return JSON.parse(s.shop_pay_cfg || '{}') || {}; } catch (e) { return {}; } })();
    renderPayCfg(curDrv, s);
    document.getElementById('ssPayDrv').addEventListener('change', e => renderPayCfg(e.target.value, s));
    document.getElementById('ssSaveStyle').addEventListener('click', () => save().catch(e => toast('保存失败：' + e.message, 'err')));
}

// 各支付驱动的字段定义（epay 特殊：映射到 shop_epay_* 独立配置键）
const PAY_DRV_FIELDS = {
    epay: null, // 用固定三件套
    codepay: [
        { key: 'gateway', label: '码支付网关地址', ph: 'https://codepay.example.com' },
        { key: 'pid', label: '商户 ID', ph: '码支付商户 ID' },
        { key: 'key', label: '通讯密钥', ph: '码支付通讯密钥', pwd: true },
    ],
    vmq: [
        { key: 'gateway', label: 'V免签网关地址', ph: 'https://pay.example.com（V免签端域名）' },
        { key: 'key', label: '通讯密钥', ph: 'V免签后台设置的通讯密钥', pwd: true },
    ],
};
let payCfgAll = {};

function renderPayCfg(drv, s) {
    const box = document.getElementById('ssPayCfg');
    if (!box) return;
    if (drv === 'manual') { box.innerHTML = ''; return; }
    if (drv === 'epay') {
        box.innerHTML = `
        <div class="row2">
            <div class="field"><label>易支付网关地址</label>
                <input id="ssEpayUrl" value="${esc(s.shop_epay_url || '')}" placeholder="https://pay.example.com/">
                <div class="hint">彩虹易支付协议，填写到域名即可</div>
            </div>
            <div class="field"><label>商户 PID</label>
                <input id="ssEpayPid" value="${esc(s.shop_epay_pid || '')}" placeholder="易支付商户 ID">
            </div>
        </div>
        <div class="field"><label>商户密钥</label>
            <input id="ssEpayKey" value="${esc(s.shop_epay_key || '')}" placeholder="易支付商户密钥" autocomplete="new-password">
            <div class="hint">异步回调地址：<code>${esc(location.origin)}/shop/notify.php</code>，请在易支付商户后台保持一致（服务端自动验签）</div>
        </div>`;
        return;
    }
    const cur = payCfgAll[drv] || {};
    const cb = `<div class="hint">异步回调地址：<code>${esc(location.origin)}/shop/notify.php</code>，请在平台侧保持一致（服务端自动验签）</div>`;
    box.innerHTML = PAY_DRV_FIELDS[drv].map(f => `
        <div class="field"><label>${esc(f.label)}</label>
            <input data-payf="${esc(f.key)}" value="${esc(cur[f.key] || '')}"
                placeholder="${esc(f.ph || '')}" ${f.pwd ? 'autocomplete="new-password"' : ''}>
        </div>`).join('') + cb;
}

async function save() {
    // 前端先做格式校验，减少一整轮无效提交
    const val = (id) => document.getElementById(id).value.trim();
    for (const [id, name] of [['ssExtUrl', '外部发卡站地址'], ['ssEpayUrl', '易支付网关地址'], ['ssQrcode', '收款码地址']]) {
        const el = document.getElementById(id);
        if (!el) continue; // 字段随支付方式动态渲染，可能不存在
        const u = el.value.trim();
        if (u !== '' && !/^https?:\/\//i.test(u)) {
            return toast(`${name}需为 http(s) 开头的完整地址`, 'warn');
        }
    }
    const theme = val('ssTheme').toLowerCase();
    if (theme !== '' && !/^#[0-9a-f]{6}$/.test(theme)) {
        return toast('主题色需为 #RRGGBB 格式，如 #0ea5e9', 'warn');
    }

    const res = await api('shop_setting_save', {
        settings: Object.assign({
            shop_enable:       document.getElementById('ssEnable').value,
            shop_mode:         document.getElementById('ssMode').value,
            shop_external_url: val('ssExtUrl'),
            shop_pay_mode:     document.getElementById('ssPayDrv').value === 'manual' ? 'manual' : 'auto',
            shop_pay_driver:   document.getElementById('ssPayDrv').value === 'manual' ? '' : document.getElementById('ssPayDrv').value,
            shop_contact:      val('ssContact'),
            shop_site_url:     val('ssSiteUrl'),
            shop_qrcode:       val('ssQrcode'),
            shop_card_gen_mode: val('ssGenMode'),
            shop_gen_prefix:   val('ssGenPrefix'),
            shop_gen_expire_days: val('ssGenExpire'),
            shop_title:        val('ssTitle'),
            shop_tab_title:    val('ssTabTitle'),
            shop_tab_alert:    val('ssTabAlert'),
            shop_tab_icon:     val('ssTabIcon'),
            shop_notice:       val('ssNotice'),
            shop_popup_notice: val('ssPopup'),
            shop_banner:       val('ssBanner'),
            shop_bg_url:       val('ssBg'),
            shop_logo:         val('ssLogo'),
            shop_theme:        theme,
            shop_games_enabled: document.getElementById('ssGames') ? document.getElementById('ssGames').value : '1',
            shop_footer:       val('ssFooter'),
            shop_notes:        val('ssNotes'),
            shop_layout:       curLayout,
            shop_contact_mode: document.getElementById('ssContactMode').value,
            shop_detail_style: document.getElementById('ssDetailStyle').value,
            shop_sw_filter:    document.getElementById('ssSwFilter').value,
        }, (() => {
            // 支付驱动字段：epay 映射到 shop_epay_*，插件驱动写进 shop_pay_cfg JSON
            const drv = document.getElementById('ssPayDrv').value;
            if (drv === 'epay') {
                return {
                    shop_epay_url: val('ssEpayUrl'),
                    shop_epay_pid: val('ssEpayPid'),
                    shop_epay_key: document.getElementById('ssEpayKey').value.trim(),
                };
            }
            if (drv === 'manual' || drv === '') return {};
            const fv = {};
            document.querySelectorAll('#ssPayCfg [data-payf]').forEach(inp => { fv[inp.dataset.payf] = inp.value.trim(); });
            payCfgAll[drv] = fv;
            return { shop_pay_cfg: JSON.stringify(payCfgAll) };
        })(), (() => {
            // 支付渠道展示配置（勾选 + 自定义名称）
            const lines = ['alipay', 'wxpay', 'qqpay'].map(v => {
                const on = document.getElementById('ssChOn_' + v);
                const lb = document.getElementById('ssChLb_' + v);
                return on && on.checked ? v + '|' + (lb ? lb.value.trim() : '') : '';
            }).filter(Boolean);
            return lines.length ? { shop_channels: lines.join('\n') } : {};
        })()),
    });
    if (res.code === 0) {
        toast('发卡网配置已保存');
        render();   // 重新拉取，刷新顶部状态条
    }
}

// ---- 前台 Logo 上传已移至「系统设置 → 站点」（pages/setting.js） ----
