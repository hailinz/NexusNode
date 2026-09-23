// ==================== 订阅管理页 ====================
import { subsApi } from '../api.js';
import { esc, toast, confirmBox } from '../utils.js';
import { showSubQrModal } from '../ui.js';

let rootEl = null;
let subsCache = [];
let subconverterConfigured = false;

/**
 * ISO8601 字符串 → 浏览器本地时区的可读时间（YYYY-MM-DD HH:mm:ss）
 * 输入 '2026-09-23T10:30:00+00:00' 在 UTC+8 下显示 '2026-09-23 18:30:00'
 */
function formatLocalTime(iso) {
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

export async function renderSubscriptions(container) {
    rootEl = container;
    rootEl.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';

    const data = await subsApi.list();
    subsCache = data.subscriptions;
    subconverterConfigured = !!data.subconverter_configured;

    rootEl.innerHTML = `
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">创建订阅</h2>
            </div>
            <form id="sub-create" class="space-y-4 px-5 py-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">订阅名称 *</label>
                    <input type="text" name="name" required placeholder="如：我的机场" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">备注</label>
                    <input type="text" name="description" placeholder="用途说明（可选）" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                </div>
                <div class="flex justify-end">
                    <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">创建</button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200/80 bg-gradient-to-br from-indigo-500 to-violet-600 p-5 text-white shadow-sm xl:col-span-2">
            <h2 class="text-sm font-semibold">如何使用</h2>
            <ol class="mt-3 space-y-2 text-sm text-indigo-100">
                <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs">1</span>创建订阅后点击地址打开二维码，或复制订阅地址</li>
                <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs">2</span>在 v2rayN / V2Box / NekoBox / Shadowrocket 中「添加订阅」粘贴该地址</li>
                <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs">3</span>客户端按 User-Agent 自动匹配：v2rayN → base64；Clash / sing-box / Surge / Quantumult X / Loon → 对应格式（需配置 SubConverter）</li>
            </ol>
            <p class="mt-3 border-t border-white/20 pt-3 text-xs text-indigo-200">
                <b>默认</b>输出全部启用节点；可在订阅卡片点「管理节点」配置独立白名单。
            </p>
        </div>
    </div>

    <div class="mt-6 space-y-4" id="sub-list">
        ${subsCache.length === 0
            ? '<div class="rounded-xl border border-slate-200/80 bg-white px-5 py-16 text-center text-sm text-slate-400 shadow-sm">还没有订阅，左上角创建一个即可获得订阅地址</div>'
            : subsCache.map(subCard).join('')}
    </div>`;

    bindEvents();
}

function subCard(sub) {
    const configured = sub.node_count > 0;
    const badge = configured
        ? `<span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200">已配置 ${sub.node_count} 个节点</span>`
        : `<span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200">全部启用节点</span>`;

    const formatBadges = subconverterConfigured
        ? '<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">base64 · clash · sing-box · surge · quanx · loon</span>'
        : '<span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200">base64</span>';

    return `
    <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm" data-id="${sub.id}">
        <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold text-slate-900">${esc(sub.name)}</p>
                    ${badge}
                    ${formatBadges}
                    <button data-toggle class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition ${sub.enabled
                        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100'
                        : 'bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200 hover:bg-slate-200'}">
                        <span class="inline-block h-1.5 w-1.5 rounded-full ${sub.enabled ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                        ${sub.enabled ? '启用中' : '已停用'}
                    </button>
                </div>
                <button data-qr title="点击显示二维码"
                        class="mt-2 flex max-w-full items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-left transition hover:bg-slate-100">
                    <span class="truncate font-mono text-xs text-slate-600">${esc(sub.url)}</span>
                </button>
                ${sub.description ? `<p class="mt-1.5 text-xs text-slate-400">${esc(sub.description)}</p>` : ''}
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button data-manage class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">⚙ 管理节点</button>
                <button data-requests class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
                    📊 请求记录 ${sub.request_count_24h > 0 ? `<span class="rounded-full bg-slate-900 px-1.5 text-[10px] font-semibold text-white">${sub.request_count_24h}</span>` : ''}
                </button>
                <button data-regenerate class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50">重置地址</button>
                <button data-delete class="rounded-lg border border-red-200 px-3 py-2 text-xs font-medium text-red-600 transition hover:bg-red-50">删除</button>
            </div>
        </div>
    </div>`;
}

function bindEvents() {
    rootEl.querySelector('#sub-create').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        try {
            const res = await subsApi.create(fd.get('name'), fd.get('description'));
            toast(res.message);
            await renderSubscriptions(rootEl);
        } catch (err) { toast(err.message, 'error'); }
    });

    rootEl.querySelector('#sub-list').addEventListener('click', async (e) => {
        const card = e.target.closest('[data-id]');
        if (!card) return;
        const id = +card.dataset.id;
        const sub = subsCache.find(s => s.id === id);
        if (!sub) return;

        if (e.target.closest('[data-qr]')) {
            showSubQrModal(sub.name, sub.url);
        } else if (e.target.closest('[data-toggle]')) {
            const res = await subsApi.toggle(id);
            toast(res.message);
            await renderSubscriptions(rootEl);
        } else if (e.target.closest('[data-manage]')) {
            openNodesManager(sub);
        } else if (e.target.closest('[data-requests]')) {
            openRequestsModal(sub);
        } else if (e.target.closest('[data-regenerate]')) {
            if (!confirmBox('重置后旧订阅地址立即失效，确定？')) return;
            const res = await subsApi.regenerate(id);
            toast(res.message);
            await renderSubscriptions(rootEl);
        } else if (e.target.closest('[data-delete]')) {
            if (!confirmBox(`确定删除订阅「${sub.name}」吗？关联的节点白名单将一并清理。`)) return;
            await subsApi.remove(id);
            toast('订阅已删除');
            await renderSubscriptions(rootEl);
        }
    });
}

// ==================== 节点白名单管理模态 ====================
function openNodesManager(sub) {
    let modal = document.getElementById('sub-nodes-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sub-nodes-modal';
        modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm';
        modal.innerHTML = `
        <div class="flex h-full max-h-[90vh] w-full max-w-5xl flex-col rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">管理节点 · <span id="mn-sub-name"></span></h3>
                    <p class="mt-0.5 text-xs text-slate-400">勾选节点加入白名单；右栏调整顺序决定订阅内输出顺序</p>
                </div>
                <button type="button" data-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
            </div>
            <div class="grid flex-1 min-h-0 grid-cols-1 gap-4 px-5 py-4 lg:grid-cols-2">
                <div class="flex min-h-0 flex-col rounded-xl border border-slate-200">
                    <div class="border-b border-slate-100 px-3 py-2.5">
                        <p class="text-xs font-semibold text-slate-700">可选节点</p>
                        <div class="mt-2 flex flex-wrap gap-1.5" id="mn-filters"></div>
                        <form id="mn-search" class="mt-2 flex gap-2">
                            <input type="text" name="q" placeholder="搜索名称 / 地址 / SNI" class="flex-1 rounded-lg border-slate-200 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                            <button class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-200">搜索</button>
                        </form>
                    </div>
                    <div id="mn-available" class="flex-1 overflow-y-auto"></div>
                    <div id="mn-pagination" class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-xs"></div>
                </div>
                <div class="flex min-h-0 flex-col rounded-xl border border-indigo-200 bg-indigo-50/30">
                    <div class="flex items-center justify-between border-b border-indigo-100 px-3 py-2.5">
                        <p class="text-xs font-semibold text-indigo-900">已选 <span id="mn-count">0</span> 个 · 拖动 ↑↓ 调整顺序</p>
                        <button type="button" id="mn-clear" class="rounded-lg border border-red-200 px-2 py-1 text-[11px] font-medium text-red-600 transition hover:bg-red-50">清空白名单</button>
                    </div>
                    <div id="mn-selected" class="flex-1 overflow-y-auto"></div>
                </div>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3 rounded-b-2xl">
                <p class="text-xs text-slate-500" id="mn-hint">空数组 = 沿用「全部启用节点」行为</p>
                <div class="flex gap-2">
                    <button data-close class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">取消</button>
                    <button id="mn-save" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">保存</button>
                </div>
            </div>
        </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('[data-close]')) closeModal();
        });
        document.addEventListener('keydown', escHandler);
    }

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.removeEventListener('keydown', escHandler);
    };
    function escHandler(e) { if (e.key === 'Escape') closeModal(); }

    // 模态内状态
    const state = {
        subId: sub.id,
        selected: [],            // [{ id, name, protocol, address, port, enabled, sort_order }]
        available: [],           // 当前页节点
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
        filter: 'all',
        q: '',
        page: 1,
        perPage: 20,
        initialized: false,      // 是否已从服务器拉过权威白名单(只有首次 / 切筛选 / 切搜索需要重置 selected)
    };

    modal.querySelector('#mn-sub-name').textContent = sub.name;
    modal.querySelector('#mn-hint').textContent = sub.node_count > 0
        ? `当前已配置 ${sub.node_count} 个节点；保存后立即生效`
        : '空数组 = 沿用「全部启用节点」行为';

    const renderSelected = () => {
        const container = modal.querySelector('#mn-selected');
        const count = state.selected.length;
        modal.querySelector('#mn-count').textContent = count;

        if (count === 0) {
            container.innerHTML = '<div class="px-4 py-10 text-center text-xs text-slate-400">暂无节点；左侧勾选后加入</div>';
            return;
        }

        container.innerHTML = state.selected.map((n, idx) => `
        <div class="flex items-center gap-2 border-b border-indigo-100/60 px-3 py-2 last:border-b-0" data-sel="${n.id}">
            <span class="w-6 shrink-0 text-center text-[11px] font-mono text-slate-400">${idx + 1}</span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5">
                    <p class="truncate text-xs font-medium text-slate-800">${esc(n.name)}</p>
                    ${n.enabled ? '' : '<span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">已禁用</span>'}
                </div>
                <p class="truncate font-mono text-[11px] text-slate-400">${esc(n.protocol)} · ${esc(n.address)}:${n.port}</p>
            </div>
            <div class="flex shrink-0 gap-0.5">
                <button data-move="up" title="上移" class="rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↑</button>
                <button data-move="down" title="下移" class="rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↓</button>
                <button data-remove title="移除" class="rounded p-1 text-slate-400 transition hover:bg-red-50 hover:text-red-600">✕</button>
            </div>
        </div>`).join('');
    };

    const renderAvailable = () => {
        const container = modal.querySelector('#mn-available');
        const selectedIds = new Set(state.selected.map(n => n.id));

        if (state.available.length === 0) {
            container.innerHTML = '<div class="px-4 py-10 text-center text-xs text-slate-400">没有匹配的节点</div>';
            return;
        }

        container.innerHTML = state.available.map(n => `
            <label class="flex cursor-pointer items-center gap-2 border-b border-slate-100 px-3 py-2 last:border-b-0 transition hover:bg-slate-50">
                <input type="checkbox" data-pick="${n.id}" ${selectedIds.has(n.id) ? 'checked' : ''} class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <p class="truncate text-xs font-medium text-slate-800">${esc(n.name)}</p>
                        ${n.enabled ? '' : '<span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">已禁用</span>'}
                    </div>
                    <p class="truncate font-mono text-[11px] text-slate-400">${esc(n.protocol)} · ${esc(n.address)}:${n.port}</p>
                </div>
            </label>`).join('');
    };

    const renderPagination = () => {
        const container = modal.querySelector('#mn-pagination');
        const { current_page: cur, last_page: last, total } = state.meta;
        const btn = (label, page, active = false, disabled = false) =>
            `<button data-page="${page}" ${disabled ? 'disabled' : ''} class="rounded px-2 py-0.5 ${active ? 'bg-indigo-600 text-white' : disabled ? 'text-slate-300' : 'text-slate-600 hover:bg-slate-100'}">${label}</button>`;
        let html = btn('‹', Math.max(1, cur - 1), false, cur <= 1);
        for (let p = 1; p <= last; p++) html += btn(p, p, p === cur);
        html += btn('›', Math.min(last, cur + 1), false, cur >= last);
        container.innerHTML = `<div class="flex flex-wrap gap-1">${html}</div><span class="text-slate-400">共 ${total} 个节点</span>`;
    };

    const renderFilters = () => {
        const tabs = [
            ['all', '全部'], ['cf', 'CF 优选'], ['generated', '优选生成'], ['disabled', '已禁用'],
        ];
        modal.querySelector('#mn-filters').innerHTML = tabs.map(([k, l]) =>
            `<button data-filter="${k}" class="rounded px-2 py-0.5 text-[11px] font-medium transition ${state.filter === k ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}">${l}</button>`
        ).join('');
    };

    const toggleSelected = (id, on) => {
        const idx = state.selected.findIndex(n => n.id === id);
        if (on && idx === -1) {
            const node = state.available.find(n => n.id === id);
            if (node) state.selected.push({ ...node, sort_order: state.selected.length });
        } else if (!on && idx !== -1) {
            state.selected.splice(idx, 1);
        }
        renderSelected();
    };

// reloadAvailable — 拉白名单节点数据。
// 只有首次打开 modal 时,会用服务器权威白名单覆盖本地 state.selected(初始化);
// 后续翻页 / 切筛选 / 搜索都只刷新左栏,不动右栏已选。
    const reloadAvailable = async () => {
        const data = await subsApi.getNodes(state.subId, {
            filter: state.filter, q: state.q, page: state.page, per_page: state.perPage,
        });
        state.available = data.nodes.data;
        state.meta = data.nodes.meta;

        if (!state.initialized) {
            state.selected = data.selected.map(s => ({
                id: s.id, name: s.name, protocol: s.protocol,
                address: s.address, port: s.port, enabled: s.enabled,
                sort_order: s.sort_order,
            }));
            state.initialized = true;
        }

        renderFilters();
        renderAvailable();
        renderSelected();
        renderPagination();
    };

    // 事件绑定
    modal.querySelector('#mn-filters').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-filter]');
        if (!btn) return;
        state.filter = btn.dataset.filter;
        state.page = 1;
        reloadAvailable();
    });

    modal.querySelector('#mn-search').addEventListener('submit', (e) => {
        e.preventDefault();
        state.q = e.target.q.value;
        state.page = 1;
        reloadAvailable();
    });

    modal.querySelector('#mn-pagination').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-page]');
        if (!btn || btn.disabled) return;
        state.page = +btn.dataset.page;
        reloadAvailable();
    });

    modal.querySelector('#mn-available').addEventListener('change', (e) => {
        const cb = e.target.closest('[data-pick]');
        if (!cb) return;
        toggleSelected(+cb.dataset.pick, cb.checked);
    });

    modal.querySelector('#mn-selected').addEventListener('click', (e) => {
        const row = e.target.closest('[data-sel]');
        if (!row) return;
        const id = +row.dataset.sel;
        const idx = state.selected.findIndex(n => n.id === id);
        if (idx === -1) return;

        if (e.target.closest('[data-remove]')) {
            state.selected.splice(idx, 1);
        } else if (e.target.closest('[data-move]')) {
            const dir = e.target.closest('[data-move]').dataset.move;
            const swap = dir === 'up' ? idx - 1 : idx + 1;
            if (swap < 0 || swap >= state.selected.length) return;
            [state.selected[idx], state.selected[swap]] = [state.selected[swap], state.selected[idx]];
        }
        renderSelected();
        // 同步左栏 checkbox 视觉(可选)
        const cb = modal.querySelector(`[data-pick="${id}"]`);
        if (cb && e.target.closest('[data-remove]')) cb.checked = false;
    });

    modal.querySelector('#mn-clear').addEventListener('click', () => {
        if (state.selected.length === 0) return;
        if (!confirmBox(`确定清空白名单？该订阅将恢复「全部启用节点」行为。`)) return;
        state.selected = [];
        renderSelected();
        modal.querySelectorAll('[data-pick]').forEach(cb => cb.checked = false);
    });

    modal.querySelector('#mn-save').addEventListener('click', async (e) => {
        const btn = e.target;
        btn.disabled = true;
        try {
            const ids = state.selected.map(n => n.id);
            const res = await subsApi.setNodes(state.subId, ids);
            toast(res.message);
            closeModal();
            await renderSubscriptions(rootEl);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    });

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    reloadAvailable();
}

// ==================== 请求记录弹窗 ====================
function openRequestsModal(sub) {
    let modal = document.getElementById('sub-requests-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sub-requests-modal';
        modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm';
        modal.innerHTML = `
        <div class="flex h-full max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">请求记录 · <span id="req-sub-name"></span></h3>
                    <p class="mt-0.5 text-xs text-slate-400">最近 50 次拉取记录（IP / UA / 时间）</p>
                </div>
                <button type="button" data-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
            </div>
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-2.5 text-xs text-slate-500">
                <span id="req-summary">加载中…</span>
            </div>
            <div id="req-list" class="flex-1 overflow-y-auto"></div>
            <div class="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3 rounded-b-2xl">
                <button data-close class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">关闭</button>
            </div>
        </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('[data-close]')) closeModal();
        });
        document.addEventListener('keydown', escHandler);
    }

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.removeEventListener('keydown', escHandler);
    };
    function escHandler(e) { if (e.key === 'Escape') closeModal(); }

    modal.querySelector('#req-sub-name').textContent = sub.name;
    modal.querySelector('#req-summary').textContent = '加载中…';
    modal.querySelector('#req-list').innerHTML = '<div class="px-5 py-12 text-center text-sm text-slate-400">加载中…</div>';

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    subsApi.getRequests(sub.id, 50)
        .then((data) => {
            modal.querySelector('#req-summary').textContent =
                `最近 24h: ${data.count_24h} 次 · 共 ${data.requests.length} 条记录`;
            if (data.requests.length === 0) {
                modal.querySelector('#req-list').innerHTML =
                    '<div class="px-5 py-12 text-center text-sm text-slate-400">暂无请求记录</div>';
                return;
            }
            modal.querySelector('#req-list').innerHTML = `
            <div class="divide-y divide-slate-100">
                ${data.requests.map(r => `
                    <div class="px-5 py-2.5 transition hover:bg-slate-50/60">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-xs">
                            <span class="font-mono text-slate-500" title="${esc(r.requested_at)}">${esc(formatLocalTime(r.requested_at))}</span>
                            <span class="font-mono text-slate-700">${esc(r.ip)}</span>
                        </div>
                        <div class="mt-0.5 break-all text-xs text-slate-600" title="${esc(r.user_agent || '')}">${esc(r.user_agent || '—')}</div>
                    </div>`).join('')}
            </div>`;
        })
        .catch((err) => {
            modal.querySelector('#req-list').innerHTML =
                `<div class="px-5 py-12 text-center text-sm text-red-500">加载失败：${esc(err.message)}</div>`;
        });
}