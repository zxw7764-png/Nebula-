/* ======================================================================
   pages/games.js — 小游戏与排行榜（内容运营子页）
   ------------------------------------------------------------------
   · 官网小游戏开关（web_games_enabled）：关闭后前台模板右下角
     不再显示小游戏，游客只能看历史榜单不能提交新分数
   · 排行榜记录：跨用户榜单（nb_game_scores），支持按游戏筛选、
     分页、单条删除、一键清空（game_list / game_del）
   ====================================================================== */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc } from '../core/util.js';
import { toast } from '../core/ui.js';

register('games', render);

const GAME_NAMES = [
    ['farm', '云上农场'],
    ['mario', '马里奥跳跳'],
    ['ink', '御剑飞行'],
    ['space', 'STAR RAIDER'],
];

let curPage = 1;

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    let res;
    try {
        res = await api('setting_get');
    } catch (e) {
        return; // api() 已 toast 具体错误
    }
    if (res.code !== 0) return;
    const s = res.data.settings || {};

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>小游戏与排行榜</h3></div>
        <div class="card-body">
            <div class="hint" style="margin-bottom:16px">
                界面模板右下角自带一个小游戏（内置四款 + 模板自带小游戏见「界面模板」），
                成绩自动进入跨用户排行榜，官网与发卡网共用同一份榜单。
            </div>
            <div class="row2">
                <div class="field"><label>官网小游戏开关</label>
                    <select id="gmEnabled">
                        <option value="1">开启（默认）</option>
                        <option value="0">关闭</option>
                    </select>
                    <div class="hint">关闭后官网模板右下角不再显示小游戏入口；已产生的排行榜记录保留，可随时重新开启。发卡网小游戏请在「商品与交易 → 发卡网配置」里单独开关</div>
                </div>
                <div class="field"><label>榜单筛选</label>
                    <select id="gmFilter">
                        <option value="">全部游戏</option>
                        ${GAME_NAMES.map(([v, n]) => `<option value="${v}">${esc(n)}</option>`).join('')}
                    </select>
                    <div class="hint">切换后自动加载对应游戏的榜单记录</div>
                </div>
            </div>
            <button class="btn" id="gmSave">保存开关设置</button>

            <h3 style="margin:22px 0 10px;font-size:15px">游戏参数</h3>
            <div class="row2">
                <div class="field"><label>默认时长（秒）</label>
                    <input id="gmDuration" type="number" min="10" max="300" step="5">
                    <div class="hint">限时小游戏未单独配置时长时用它，10~300 秒，默认 30；内置四款游戏不适用</div>
                </div>
                <div class="field"><label>榜单显示条数</label>
                    <input id="gmTopN" type="number" min="3" max="20" step="1">
                    <div class="hint">游戏内排行榜浮层展示的条数，3~20 条，默认 10</div>
                </div>
                <div class="field"><label>提交频控（次/分钟/IP）</label>
                    <input id="gmRate" type="number" min="1" max="60" step="1">
                    <div class="hint">同一 IP 每分钟最多提交成绩的次数，防刷榜，默认 10</div>
                </div>
            </div>
            <div class="field" style="margin-top:10px"><label>各游戏时长（秒，留空 = 用默认时长）</label>
                <div id="gmDurList"><div class="hint">模板小游戏识别后显示在这里</div></div>
            </div>
            <button class="btn" id="gmSaveCfg">保存游戏配置</button>

            <h3 style="margin:22px 0 10px;font-size:15px">排行榜记录
                <button class="btn ghost xs danger-ghost" id="gmClear" type="button" style="margin-left:8px">清空该游戏榜单</button>
            </h3>
            <div class="table-wrap" id="gmBoard"><div class="empty">加载中…</div></div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:12px">
                <button class="btn ghost xs" id="gmPrev" type="button" disabled>上一页</button>
                <span class="hint" id="gmPager" style="flex:1;text-align:center"></span>
                <button class="btn ghost xs" id="gmNext" type="button" disabled>下一页</button>
            </div>
        </div>
    </div>`;

    /* ---- 开关回填（默认开启） ---- */
    const enabledEl = document.getElementById('gmEnabled');
    enabledEl.value = String(s.web_games_enabled === '0' ? '0' : '1');

    document.getElementById('gmSave').addEventListener('click', async () => {
        const r = await api('setting_save', { settings: { web_games_enabled: enabledEl.value } });
        if (r.code === 0) toast(enabledEl.value === '1' ? '小游戏已开启' : '小游戏已关闭，前台刷新即生效');
    });

    /* ---- 游戏参数回填与保存（时长 / 榜单条数 / 频控 / 分游戏时长，经 __NB_GAMES__.cfg 下发） ---- */
    const clampInt = (v, min, max, def) => Math.max(min, Math.min(max, parseInt(v, 10) || def));
    const durEl = document.getElementById('gmDuration');
    const topEl = document.getElementById('gmTopN');
    const rateEl = document.getElementById('gmRate');
    const durList = document.getElementById('gmDurList');
    const BUILTIN = ['farm', 'mario', 'ink', 'space']; // 内置四款为无尽/自结算模式，不配时长
    let perGameSaved = {};
    try { perGameSaved = JSON.parse(s.game_durations || '{}') || {}; } catch (e) { perGameSaved = {}; }
    durEl.value = String(clampInt(s.game_duration, 10, 300, 30));
    topEl.value = String(clampInt(s.game_top_n, 3, 20, 10));
    rateEl.value = String(clampInt(s.game_rate_limit, 1, 60, 10));

    // 逐游戏时长行：模板小游戏识别出来后逐个渲染（games 来自 game_list，含内置+模板）
    let durRowsBuilt = false;
    const buildDurRows = (games) => {
        if (durRowsBuilt) { return; }
        const tplGames = Object.keys(games).filter(id => !BUILTIN.includes(id));
        if (!tplGames.length) { return; }
        durRowsBuilt = true;
        durList.innerHTML = tplGames.map(id => `
            <div style="display:flex;gap:10px;align-items:center;margin:0 0 6px">
                <span style="width:180px">${esc(games[id])}<span class="hint" style="margin-left:6px">${esc(id)}</span></span>
                <input class="gm-dur" data-game="${esc(id)}" type="number" min="10" max="300" step="5"
                       value="${clampInt(perGameSaved[id], 10, 300, NaN) || ''}" placeholder="默认 ${durEl.value} 秒" style="width:110px">
                <span class="hint">秒</span>
            </div>`).join('');
    };

    document.getElementById('gmSaveCfg').addEventListener('click', async () => {
        durEl.value = String(clampInt(durEl.value, 10, 300, 30));
        topEl.value = String(clampInt(topEl.value, 3, 20, 10));
        rateEl.value = String(clampInt(rateEl.value, 1, 60, 10));
        const perGame = {};
        durList.querySelectorAll('.gm-dur').forEach(inp => {
            const v = parseInt(inp.value, 10);
            if (v >= 10 && v <= 300) { perGame[inp.dataset.game] = v; }
        });
        const r = await api('setting_save', { settings: {
            game_duration: durEl.value, game_top_n: topEl.value, game_rate_limit: rateEl.value,
            game_durations: JSON.stringify(perGame),
        } });
        if (r.code === 0) toast('游戏配置已保存，前台刷新即生效');
    });

    /* ---- 排行榜（game_list / game_del） ---- */
    const board = document.getElementById('gmBoard');
    const pager = document.getElementById('gmPager');

    const paint = (d) => {
        if (!d.list.length) {
            board.innerHTML = '<div class="empty">暂无上榜记录</div>';
            pager.textContent = '';
            document.getElementById('gmPrev').disabled = true;
            document.getElementById('gmNext').disabled = true;
            return;
        }
        board.innerHTML = `<table class="tbl"><thead><tr>
            <th>#</th><th>游戏</th><th>玩家</th><th>分数</th><th>IP</th><th>时间</th><th></th>
            </tr></thead><tbody>${d.list.map((r, i) => `<tr>
            <td>${(d.page - 1) * d.size + i + 1}</td>
            <td>${esc(r.game_text)}</td>
            <td>${esc(r.name)}</td>
            <td><b>${r.score}</b></td>
            <td>${esc(r.ip)}</td>
            <td>${esc(r.created_at)}</td>
            <td><button class="btn xs danger-ghost" data-del="${r.id}">删除</button></td>
            </tr>`).join('')}</tbody></table>`;
        pager.textContent = `第 ${d.page} / ${d.pages} 页 · 共 ${d.total} 条`;
        document.getElementById('gmPrev').disabled = d.page <= 1;
        document.getElementById('gmNext').disabled = d.page >= d.pages;
        board.querySelectorAll('[data-del]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const r = await api('game_del', { id: parseInt(btn.dataset.del, 10) });
                if (r.code === 0) { toast('已删除'); load(curPage); }
            });
        });
    };

    const load = async (p) => {
        curPage = Math.max(1, p || curPage || 1);
        board.innerHTML = '<div class="empty">加载中…</div>';
        const r = await api('game_list', {
            game: document.getElementById('gmFilter').value,
            page: curPage, size: 20,
        });
        if (r.code === 0) {
            paint(r.data);
            buildDurRows(r.data.games || {});   // 模板小游戏识别出来后渲染逐游戏时长行
            // 筛选下拉补上模板自带小游戏（game_list 返回 {id: 名称}；首次合并即可）
            const sel = document.getElementById('gmFilter');
            const games = r.data.games || {};
            const known = new Set(Array.from(sel.options).map(o => o.value));
            Object.keys(games).forEach(id => {
                if (!known.has(id)) {
                    const o = document.createElement('option');
                    o.value = id; o.textContent = games[id];
                    sel.appendChild(o);
                }
            });
        }
    };

    document.getElementById('gmFilter').addEventListener('change', () => load(1));
    document.getElementById('gmPrev').addEventListener('click', () => load(curPage - 1));
    document.getElementById('gmNext').addEventListener('click', () => load(curPage + 1));
    document.getElementById('gmClear').addEventListener('click', async () => {
        const g = document.getElementById('gmFilter').value;
        if (!g) return toast('请先在「榜单筛选」里选择要清空的游戏', 'warn');
        const opt = Array.from(document.getElementById('gmFilter').options).find(o => o.value === g);
        const name = opt ? opt.textContent : g;   // 模板自带游戏不在 GAME_NAMES 里，直接取下拉文案
        if (!confirm(`确定清空「${name}」的全部榜单记录？该操作不可恢复。`)) return;
        const r = await api('game_del', { game: g });
        if (r.code === 0) { toast(r.msg || '已清空'); load(1); }
    });

    load(1);
}
