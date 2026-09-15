# Nebula 模板开发文档

给官网（portal）和发卡网（shop）做界面模板。**官网和发卡网都直接从 `web/Template/` 识别并加载模板**——在这里建好文件夹，后台模板管理立刻出现，前台立刻能选，改完刷新即生效，无需同步、无需碰其它目录。

---

## 一、放哪里：`web/Template/<模板名>/`

**唯一推荐做法**：在 `web/Template/` 下建一个以模板名命名的文件夹：

```
web/Template/
└─ wzry/                 ← 模板名 = 文件夹名（小写字母/数字/下划线）
   ├─ web.css            ← 官网样式（有则官网识别此模板，可省略）
   ├─ shop.css           ← 发卡网样式（有则发卡识别此模板，可省略）
   ├─ game.html          ← 自带小游戏页面（可选，官网 + 发卡网通用）
   ├─ game.js            ← 游戏逻辑（game.html 自己引）
   └─ bg.png …           ← 其它任何文件，game.html 里直接写相对路径引用即可
```

识别规则：
- 文件夹里有 `web.css` → 官网模板管理出现此模板；前台官网加载 `Template/<名>/web.css`
- 文件夹里有 `shop.css` → 发卡网模板管理出现此模板；前台发卡加载 `../Template/<名>/shop.css`
- 有 `game.html` → 前台右下角出现 🎮 按钮（iframe 加载，两端通用）
- 两端样式通常不一样，一般两个文件都放；只想做一端就只放对应那个

**改完没生效？** 模板 CSS 的加载地址带版本号（`?v=版本.文件修改时间`），保存文件后自动变化，浏览器会重新拉取——**直接刷新页面即可**。如果仍旧显示默认深空，按顺序检查：

1. 文件夹名是否和 CSS 里的 `body.ui-<名>` 完全一致（最容易踩的坑，见第二节）；
2. 该端有没有对应文件（官网要 `web.css`、发卡要 `shop.css`，注意别写成 `web.css.css` 这种多后缀）；
3. 后台模板管理里有没有点「保存」——只在下拉里选了但没保存，库里仍是旧值；
4. 「分软件模板」有没有给当前软件设了覆盖（覆盖优先于全局）；发卡网访客软件由 `?app=` 决定；
5. 仍是旧的：强制刷新（Ctrl+F5），或确认改的是不是 `_shared.css` 之外的模板文件。

**兼容**：各端 assets 模板文件夹（`web/assets/css/templates/`、`shop/assets/templates/`）里的旧式模板继续有效（单文件 `<id>.css` 或文件夹 `<id>/<id>.css`）。与 `web/Template` 同名时，**`web/Template` 优先**。`_` 开头的文件夹不识别；`_shared.css` 是两端共享修正样式（浅色容器修正 + 游戏面板骨架），所有模板都会先加载它。

---

## 二、命名的铁律

**文件夹名 = CSS 选择器里的 id**。模板机制是给 `<body>` 加 `ui-<文件夹名>` 类：

```
web/Template/wzry/  →  body 加 class "ui-wzry"  →  CSS 里全部选择器写 body.ui-wzry
```

文件名叫别的（比如 `123`）但选择器写 `ui-wzry`，一条样式都匹配不上，前台会看起来像默认主题——这是最容易踩的坑。

- id 只允许 `小写字母 / 数字 / 下划线`（同 CSS 类名字符集）。
- 小游戏排行榜的 `game` 字段 = 文件夹名，自动按模板分榜。

---

## 三、写样式

`<id>.css` 加载在 `_shared.css` 之后，可以覆盖共享样式。模板自带整套配色（CSS 变量）+ 装饰背景，**激活后优先于后台主题色 / 背景图**。

变量基座（`_shared.css` / site.css 里已有定义，模板里按主题重定义）：

```css
body.ui-wzry {
    --bg: #0a0610;        --bg-soft: #1a1224;
    --panel: #1e1428;     --panel-2: #241830;
    --border: #4a3520;    --border-2: #8a6d3b;
    --text: #f0e6d2;      --text-sub: #d4b878;   --text-strong: #f5e6c8;
    --primary: #c8102e;   --primary-rgb: 200, 16, 46;
}
```

---

## 四、⚠️ 霓虹禁忌（重要，审核也会按这个查）

有些区块**不允许出现霓虹效果**。默认主题的青色 `rgba(34,211,238,…)` / `#22d3ee` / 紫色光晕是默认深空主题的专属装饰，模板里出现就是残留 bug。

| 区块 | 要求 |
|---|---|
| 面板 / 卡片 / 价格套餐卡 / 商品卡 / 表单 / 弹窗 / 公告条 | **必须不透明底色**（`var(--panel)` / `var(--panel-2)` / `var(--bg-soft)` 或 color-mix 不透明结果），禁止 `rgba(var(--primary-rgb), .1~.3)` 这类半透明主色大面积铺底 |
| 分类页签 / 页签选中态 | 选中用实底主色或实底面板色，文字用白/浅色，禁止发光文字 |
| 标题 / 正文 | 禁止大面积 `text-shadow` 霓虹发光；小面积点缀（徽章、角标）可用 1~2px 微光 |
| 按钮 / 高亮 / 边框 | 主色只做小面积点缀；禁止多层 `box-shadow` 光晕叠满屏 |
| 浅色系模板 | 面板一律不透明浅底 + 深色文字，半透明主色只允许出现在徽章 / 状态点等 <20px 的小元素 |
| 动画背景 | 装饰层（云 / 星点 / 山水）`z-index:0` + `pointer-events:none`，不得盖住内容与版权 |

简单判断：**大面积 = 实底不透明；发光 = 只许小面积点缀**。

---

## 五、自带小游戏（可选）

文件夹里放 `game.html`（+ `game.js` / 图片），前台右下角出现 🎮 按钮，点击 iframe 弹出你的游戏页面，替代内置小游戏。发卡网同样支持。

### 游戏页面要做成「游戏面板」，像马里奥那样

iframe 弹出后就是你的整个游戏页，**别做成一个光秃秃的按钮页**，要和内置马里奥 / 农场那个面板同款观感——从上到下四段：

| 区块 | 内容 |
|---|---|
| 顶部标题栏 | 游戏名 + HUD（分数 / 血量 / 时间等实时数据） |
| 游戏主体 | canvas 画面，上面叠 HUD 与覆盖层 |
| 覆盖层 overlay | 未开始 / 结束时盖在画面上：游戏名、最高纪录、**排行榜 TOP5**、开始按钮 |
| 底部提示条 | 一行操作说明（如「← → 移动 · 空格射击」） |

骨架参考（配色换成你模板的主题色；想看真实效果，前台选个内置模板点开马里奥就是这种结构）：

```html
<div id="game-header">
    <div class="title">游戏名</div>
    <div class="hud"><span id="hud-score">SCORE 0</span></div>
</div>
<div id="game-board">
    <canvas id="game-canvas" width="320" height="200"></canvas>
    <div id="game-overlay">
        <h4>按空格 / 点击开始</h4>
        <p>最高纪录 0</p>
        <div class="board"></div>   <!-- 排行榜 TOP5 -->
        <!-- 昵称输入框：仅未登录显示（CFG.name 为空），已登录隐藏 -->
        <input class="name" maxlength="16" placeholder="输入昵称上榜（可留空）">
        <button class="start">开始游戏</button>
    </div>
</div>
<div id="game-hint">← → 移动 · 空格射击</div>
```

### 输入框 / 弹窗必须自定义效果，禁止原生弹窗

- **禁用 `alert()` / `prompt()` / `confirm()`** —— 原生弹窗风格突兀，和游戏面板完全不搭。提示用自定义 toast 或内嵌提示行；确认类操作用页面内按钮 / 覆盖层完成。
- **昵称输入按登录状态决定**：`CFG.name` 是当前登录用户名（未登录为空字符串）。
    - **已登录（`CFG.name` 有值）→ 不显示昵称输入框**，直接用账号名上榜（和内置马里奥一致）；
    - **未登录 → 显示自定义样式的昵称输入框**，可用 `localStorage` 记住上次输入；留空则用默认匿名（如「匿名峡谷英雄」）。

    ```js
    var LOGGED = String(CFG.name || '').trim();
    var nameInput = document.querySelector('#game-overlay .name');
    if (LOGGED) {
        nameInput.style.display = 'none';                 // 已登录：隐藏输入框
    } else {
        nameInput.value = localStorage.getItem('nb_my_name') || '';
    }
    var finalName = LOGGED || nameInput.value.trim().substring(0, 16) || '匿名峡谷英雄';
    // 未登录且填了昵称时顺手记住：localStorage.setItem('nb_my_name', finalName)
    ```
- **输入框自己写样式**：主题底色 + 主色边框，`:focus` 主色发光。
- 自定义 toast 示例（2.5 秒自动消失）：

```css
#toast {
    position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%);
    background: var(--panel, #1e1428); border: 1px solid var(--primary, #c8102e);
    color: var(--text, #f0e6d2); padding: 8px 16px; font-size: 12px;
    border-radius: 8px; opacity: 0; pointer-events: none;
    transition: opacity .25s, transform .25s;
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(-6px); }
```

```js
var toastTimer = 0;
function toast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 2500);
}
```

### 上榜接口

游戏页里拿配置与上榜（`wzry/game.js` 是接口部分的完整可运行示例）：

```js
// 1. 配置（iframe 同源可读）：{ enabled, api, name, csrf }
var CFG = window.parent.__NB_GAMES__ || {};

// 2. api 是相对官网根的路径（"api.php"），iframe 在子目录，必须基于顶层窗口解析！
var API = new URL(CFG.api || 'api.php', window.parent.location.href).toString();

// 3. 拉排行榜（game = 文件夹名，自动分榜）
fetch(API + '?action=game_top&game=wzry', { credentials: 'same-origin' })

// 4. 提交成绩
fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf },
    credentials: 'same-origin',
    body: JSON.stringify({ action: 'game_score_save', game: 'wzry', name: '玩家名', score: 100, csrf: CFG.csrf })
});
```

后台「官网运营 → 小游戏与排行榜」的总开关对模板自带小游戏同样生效。

---

## 六、模板名称 / 简介 / 色卡（css 头注释，推荐）

不写也能用（后台显示「模板 xxx · 自动识别的模板」+ 默认紫色）。在**入口 css 文件头部**写块注释即可自动识别（WordPress 主题同款）：

```css
/*
Template Name: 王者荣耀
Description: 峡谷国风 · 金红锋锐
Color: #c8102e
*/
```

- `Template Name` 显示名（30 字内）、`Description` 一句话简介、`Color` 色卡 `#RRGGBB`；三项可只写任意几项
- 改完刷新后台模板管理立即生效，无需改任何代码
- 内置四款（云上农场/马里奥/水墨/STAR RAIDER）的名字写在 `lib/UiTemplate.php` 的 `$meta` 里作后备；自己写的模板用 css 注释就够了

---

## 七、检查清单

- [ ] 文件夹名 = `body.ui-<名>` 选择器一致
- [ ] 所有面板 / 卡片 / 公告条 / 套餐卡不透明底色，无半透明主色大面积铺底
- [ ] 无默认主题残留色（`rgba(34,211,238,` / `#22d3ee` / `#67e8f9` / 紫色系 `#ddd6fe`）
- [ ] 装饰背景层 `pointer-events:none`、`z-index:0`，不遮内容、不遮版权
- [ ] 版权（页脚 `© …`）在任何模板下可见
- [ ] 游戏提交成绩的 `game` 字段 = 文件夹名
- [ ] 游戏页是面板形态（标题栏 + HUD + 覆盖层 + 提示条），无原生 `alert` / `prompt` / `confirm`
- [ ] 昵称：已登录不显示输入框直接账号上榜，未登录才显示自定义输入框
- [ ] 文件夹名 = CSS 选择器 id；入口文件命名正确（`web.css` / `shop.css`，不要多后缀）
- [ ] 浅色模板文字是深色（能看清）
