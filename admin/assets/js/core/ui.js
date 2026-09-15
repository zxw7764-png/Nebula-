/* ======================================================================
   core/ui.js — UI 组件：提示 / 模态框 / 分页 / 批量选择条
   ====================================================================== */

import { esc } from './util.js';

/* ------------------------- 轻提示 ------------------------- */
export function toast(msg, type = 'ok') {
    const box = document.getElementById('toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    const icon = {
        ok:   '<i class="bi bi-check-lg"></i>',
        err:  '<i class="bi bi-x-lg"></i>',
        warn: '<i class="bi bi-exclamation-triangle"></i>',
    }[type] || '<i class="bi bi-info-circle"></i>';
    el.innerHTML = `<b>${icon}</b><span>${esc(msg)}</span>`;
    box.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        el.style.transition = '.25s';
        setTimeout(() => el.remove(), 250);
    }, 2600);
}

/* ------------------------- 模态框 ------------------------- */
export function openModal(title, bodyHtml, buttons = [], size = '') {
    const root = document.getElementById('modalRoot');
    const id = 'm' + Date.now();
    const btns = buttons.map((b, i) =>
        `<button class="btn ${b.cls || ''}" data-btn="${i}">${esc(b.text)}</button>`).join('');
    root.innerHTML = `
    <div class="modal-mask" id="${id}">
        <div class="modal ${size === 'wide' ? 'wide' : ''}">
            <div class="modal-head">
                <h3>${esc(title)}</h3>
                <button class="close" data-close>&times;</button>
            </div>
            <div class="modal-body">${bodyHtml}</div>
            ${btns ? `<div class="modal-foot">${btns}</div>` : ''}
        </div>
    </div>`;
    const mask = document.getElementById(id);
    // 遮罩点击关闭：按下与松开都在遮罩上才关，防止输入框内选择文字误关
    let downOnMask = false;
    mask.addEventListener('mousedown', e => { downOnMask = e.target === mask; });
    mask.addEventListener('click', e => { if (e.target === mask && downOnMask) { downOnMask = false; closeModal(); } });
    mask.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeModal));
    buttons.forEach((b, i) => {
        const el = mask.querySelector(`[data-btn="${i}"]`);
        if (el) el.addEventListener('click', b.act);
    });
    return mask;
}

export function closeModal() {
    const root = document.getElementById('modalRoot');
    if (root) root.innerHTML = '';
}

/** 确认框 */
export function confirmBox(title, msg, onOk, danger = false) {
    openModal(title, `<p style="color:var(--text-sub)">${esc(msg)}</p>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确定', cls: danger ? 'danger' : '', act: () => { closeModal(); onOk(); } }],
        'sm');
}

/** 需要输入管理密码的二次确认（用于敏感操作） */
export function confirmPassword(title, msg, onOk) {
    openModal(title, `
        <p style="color:var(--text-sub);margin-bottom:14px">${esc(msg)}</p>
        <div class="field">
            <label>请输入你的管理密码以确认</label>
            <input id="cpPass" type="password" autocomplete="off" placeholder="管理密码">
        </div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认执行', cls: 'danger', act: () => {
             const p = document.getElementById('cpPass').value;
             if (!p) return toast('请输入密码', 'warn');
             closeModal();
             onOk(p);
         }}]);
    setTimeout(() => { const i = document.getElementById('cpPass'); if (i) i.focus(); }, 50);
}

/* ------------------------- 分页 ------------------------- */
export function pager(total, page, size) {
    const pages = Math.max(1, Math.ceil(total / size));
    let btns = '';
    const start = Math.max(1, page - 2);
    const end = Math.min(pages, start + 4);
    if (page > 1) btns += `<button data-go="${page - 1}">‹</button>`;
    for (let i = start; i <= end; i++) {
        btns += `<button class="${i === page ? 'on' : ''}" data-go="${i}">${i}</button>`;
    }
    if (page < pages) btns += `<button data-go="${page + 1}">›</button>`;

    return `<div class="pager">
        <span>共 <b>${total}</b> 条，第 ${page}/${pages} 页</span>
        <div class="pages">${btns}</div>
    </div>`;
}

export function bindPager(container, onGo) {
    container.querySelectorAll('[data-go]').forEach(b => {
        b.addEventListener('click', () => onGo(parseInt(b.dataset.go, 10)));
    });
}

/* ------------------------- 批量选择 ------------------------- */
/**
 * 创建批量选择管理器。
 * 用法：
 *   const sel = createSelection({
 *       root: 容器元素,
 *       allIds: [1,2,3],
 *       onChange: ids => {...}
 *   });
 */
export function createSelection({ root, allIds = [], onChange = null }) {
    const set = new Set();
    const notify = () => {
        syncCheckboxes();
        if (onChange) onChange([...set]);
    };

    function syncCheckboxes() {
        root.querySelectorAll('[data-row-check]').forEach(cb => {
            const id = String(cb.dataset.rowCheck);
            cb.checked = set.has(id);
            const tr = cb.closest('tr');
            if (tr) tr.classList.toggle('checked', set.has(id));
        });
        const all = root.querySelector('[data-check-all]');
        if (all) {
            all.checked = allIds.length > 0 && set.size === allIds.length;
            all.indeterminate = set.size > 0 && set.size < allIds.length;
        }
        const bar = root.querySelector('.bulk-bar');
        if (bar) {
            bar.classList.toggle('on', set.size > 0);
            const info = bar.querySelector('.sel-info');
            if (info) info.textContent = `已选 ${set.size} 项`;
        }
    }

    // 行勾选
    root.querySelectorAll('[data-row-check]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = String(cb.dataset.rowCheck);
            if (cb.checked) set.add(id); else set.delete(id);
            notify();
        });
    });

    // 全选
    const all = root.querySelector('[data-check-all]');
    if (all) {
        all.addEventListener('change', () => {
            if (all.checked) allIds.forEach(id => set.add(String(id)));
            else set.clear();
            notify();
        });
    }

    // 取消选择按钮
    root.querySelectorAll('[data-check-clear]').forEach(b => {
        b.addEventListener('click', () => { set.clear(); notify(); });
    });

    syncCheckboxes();

    return {
        // id 可能是数字（列表页传 x.id），也可能是带前缀的字符串（如分类页传 cat_0）。
        // 早期实现用 parseInt 过滤，会把非数字 id 全部丢弃 ——
        // 表现为「明明勾选了，却提示请先选择」。
        ids: () => [...set]
            .filter(x => x !== '' && x !== null && x !== undefined)
            .map(x => (/^-?\d+$/.test(String(x)) ? parseInt(x, 10) : String(x))),
        raw: () => [...set],
        size: () => set.size,
        clear: () => { set.clear(); notify(); },
        setAll: (ids) => { ids.forEach(i => set.add(String(i))); notify(); },
    };
}

/** 生成表头里的全选框 */
export function checkAllBox() {
    return '<th class="col-check"><input type="checkbox" data-check-all></th>';
}

/** 生成行内的勾选框 */
export function rowCheckBox(id) {
    return `<td class="col-check"><input type="checkbox" data-row-check="${id}"></td>`;
}
