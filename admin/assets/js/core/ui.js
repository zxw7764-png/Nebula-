import { esc } from './util.js';

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

export function confirmBox(title, msg, onOk, danger = false) {
    openModal(title, `<p style="color:var(--text-sub)">${esc(msg)}</p>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确定', cls: danger ? 'danger' : '', act: () => { closeModal(); onOk(); } }],
        'sm');
}

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

    root.querySelectorAll('[data-row-check]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = String(cb.dataset.rowCheck);
            if (cb.checked) set.add(id); else set.delete(id);
            notify();
        });
    });

    const all = root.querySelector('[data-check-all]');
    if (all) {
        all.addEventListener('change', () => {
            if (all.checked) allIds.forEach(id => set.add(String(id)));
            else set.clear();
            notify();
        });
    }

    root.querySelectorAll('[data-check-clear]').forEach(b => {
        b.addEventListener('click', () => { set.clear(); notify(); });
    });

    syncCheckboxes();

    return {

        ids: () => [...set]
            .filter(x => x !== '' && x !== null && x !== undefined)
            .map(x => (/^-?\d+$/.test(String(x)) ? parseInt(x, 10) : String(x))),
        raw: () => [...set],
        size: () => set.size,
        clear: () => { set.clear(); notify(); },
        setAll: (ids) => { ids.forEach(i => set.add(String(i))); notify(); },
    };
}

export function checkAllBox() {
    return '<th class="col-check"><input type="checkbox" data-check-all></th>';
}

export function rowCheckBox(id) {
    return `<td class="col-check"><input type="checkbox" data-row-check="${id}"></td>`;
}
