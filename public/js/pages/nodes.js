// ==================== 节点管理页 ====================
import { nodesApi } from '../api.js';
import { esc, toast, confirmBox, copyText, latencyClass, showFieldErrors, emptyRow } from '../utils.js';

const state = {
    filter: 'all', q: '', page: 1, perPage: 20,
    counts: {}, parent: null, tested: new Map(), checked: new Set(),
};

export async function renderNodes(root, query = {}) {
    state.filter = query.filter || 'all';
    state.q = query.q || '';
    state.page = +query.page || 1;
    state.perPage = +query.per_page || 20;

    root.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';
    await loadAndRender(root);
}

async function loadAndRender(root) {
    const params = { filter: state.filter, q: state.q, page: state.page, per_page: state.perPage };
    if (state.parent) params.parent = state.parent;
    const data = await nodesApi.list(params);
    state.counts = data.counts;
    state.parent = data.parent;

    const tabs = [
        ['all', `全部 ${data.counts.all}`], ['cf', `CF 优选 ${data.counts.cf}`],
        ['generated', `优选生成 ${data.counts.generated}`], ['plain', `普通节点 ${data.counts.plain}`],
        ['disabled', `已禁用 ${data.counts.disabled}`],
    ];

    root.innerHTML = `
    ${data.parent ? `
        <div class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5">
            <p class="text-sm text-violet-900">正在查看「<b>${esc(data.parent.name)}</b>」已生成的优选节点（共 ${data.meta.total} 个）</p>
            <button class="text-xs font-medium text-violet-600 hover:text-violet-500" data-clear-parent>查看全部节点 ×</button>
        </div>` : ''}
    <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-1.5">
                ${tabs.map(([key, label]) => `
                    <button data-filter="${key}" class="rounded-lg px-3 py-1.5 text-xs font-medium transition ${state.filter === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}">${label}</button>`).join('')}
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form id="search-form" class="flex gap-2">
                    <input type="hidden" name="filter" value="${esc(state.filter)}">
                    <input type="hidden" name="per_page" value="${state.perPage}">
                    <input type="text" name="q" value="${esc(state.q)}" placeholder="搜索名称 / 地址 / SNI"
                           class="w-44 rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                    <button class="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-indigo-500">搜索</button>
                </form>
                <button id="ping-btn" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-slate-700 disabled:opacity-60">
                    ⚡ <span id="ping-btn-text">延迟测试</span>
                </button>
                <span id="ping-stats" class="text-xs text-slate-400"></span>
            </div>
        </div>

        <div id="bulk-bar" class="hidden items-center justify-between border-b border-indigo-100 bg-indigo-50/70 px-5 py-2.5">
            <p class="text-sm text-indigo-900">已选中 <b id="bulk-count">0</b> 个节点 <span class="text-xs text-indigo-400">（全选作用于当前页）</span></p>
            <button id="bulk-delete" class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-red-500">批量删除</button>
        </div>

        <div class="js-node-list">
            <!-- 桌面端表格（≥640px） -->
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="w-10 px-4 py-3"><input type="checkbox" data-check-all title="全选本页" class="h-4 w-4 rounded border-slate-300 text-indigo-600"></th>
                            <th class="px-4 py-3 font-medium">节点</th>
                            <th class="px-4 py-3 font-medium">协议</th>
                            <th class="px-4 py-3 font-medium">地址</th>
                            <th class="px-4 py-3 font-medium">端口</th>
                            <th class="px-4 py-3 font-medium">SNI / 伪装</th>
                            <th class="px-4 py-3 font-medium">延迟</th>
                            <th class="px-4 py-3 font-medium">状态</th>
                            <th class="px-4 py-3 text-right font-medium">操作</th>
                        </tr>
                    </thead>
                    <tbody id="node-tbody" class="divide-y divide-slate-50">
                        ${data.data.length === 0 ? emptyRow(9, '没有匹配的节点，去「批量导入」粘贴链接吧') : data.data.map(nodeRow).join('')}
                    </tbody>
                </table>
            </div>

            <!-- 移动端卡片（<640px） -->
            <div class="sm:hidden">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-4 py-2">
                    <label class="flex items-center gap-2 text-xs text-slate-500">
                        <input type="checkbox" data-check-all class="h-4 w-4 rounded border-slate-300 text-indigo-600">全选本页
                    </label>
                    <span class="text-xs text-slate-400">${data.meta.total} 个节点</span>
                </div>
                ${data.data.length === 0
                    ? '<div class="px-4 py-12 text-center text-sm text-slate-400">没有匹配的节点，去「批量导入」粘贴链接吧</div>'
                    : `<div class="divide-y divide-slate-50">${data.data.map(nodeCard).join('')}</div>`}
            </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div id="pagination" class="flex flex-wrap items-center gap-1 text-xs"></div>
            <label class="flex items-center gap-2 text-xs text-slate-500">每页显示
                <select id="per-page" class="rounded-lg border-slate-200 py-1 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                    ${[20, 50, 100, 200].map(n => `<option value="${n}" ${state.perPage === n ? 'selected' : ''}>${n}</option>`).join('')}
                </select> 条
            </label>
        </div>
    </div>

    <div id="node-form-modal" class="fixed inset-0 z-40 hidden items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm"></div>`;

    bindEvents(root, data);
}

function nodeRow(n) {
    const tested = state.tested.get(n.id);
    const latencyHtml = tested === undefined
        ? '<span class="text-slate-300">—</span>'
        : tested === null
            ? '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-400">超时</span>'
            : `<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${latencyClass(tested) === 'text-emerald-700' ? 'bg-emerald-600 text-white' : `bg-slate-100 ${latencyClass(tested)}`}">${tested} ms</span>`;

    return `
    <tr class="pi-row-node transition hover:bg-slate-50/70" data-node-id="${n.id}">
        <td class="px-4 py-3"><input type="checkbox" data-check value="${n.id}" class="row-check h-4 w-4 rounded border-slate-300 text-indigo-600" ${state.checked.has(n.id) ? 'checked' : ''}></td>
        <td class="max-w-[220px] px-4 py-3">
            <div class="flex items-center gap-2">
                <p class="truncate font-medium text-slate-800">${esc(n.name)}</p>
                ${n.is_cf ? '<span class="shrink-0 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 ring-1 ring-inset ring-sky-200">CF</span>' : ''}
                ${n.is_generated ? '<span class="shrink-0 rounded-full bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-600 ring-1 ring-inset ring-violet-200">生成</span>' : ''}
            </div>
            <p class="mt-0.5 text-xs text-slate-400">${esc(n.network || 'tcp')}${n.path ? ' · ' + esc(n.path) : ''}</p>
        </td>
        <td class="px-4 py-3"><span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">${esc(n.protocol)}</span></td>
        <td class="px-4 py-3 font-mono text-xs text-slate-600">${esc(n.address)}</td>
        <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700">${n.port}</td>
        <td class="max-w-[160px] px-4 py-3 font-mono text-xs text-slate-500">${esc(n.sni || '—')}</td>
        <td class="latency-cell px-4 py-3 text-xs">${latencyHtml}</td>
        <td class="px-4 py-3">
            <button data-toggle class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition ${n.enabled
                ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100'
                : 'bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200 hover:bg-slate-200'}">
                <span class="inline-block h-1.5 w-1.5 rounded-full ${n.enabled ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                ${n.enabled ? '启用' : '禁用'}
            </button>
        </td>
        <td class="px-4 py-3">
            <div class="flex items-center justify-end gap-0.5">
                <button data-move="up" title="上移（影响订阅顺序）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↑</button>
                <button data-move="down" title="下移（影响订阅顺序）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↓</button>
                <button data-ping-single title="测速（当前网络环境）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600">⚡</button>
                <button data-copy="${esc(n.uri)}" title="复制链接" class="rounded-md p-1.5 text-slate-400 transition hover:bg-indigo-50 hover:text-indigo-600">⧉</button>
                <button data-edit title="编辑" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✎</button>
                <button data-delete title="删除" class="rounded-md p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600">✕</button>
            </div>
        </td>
    </tr>`;
}

function nodeCard(n) {
    const tested = state.tested.get(n.id);
    const latencyHtml = tested === undefined
        ? '<span class="text-slate-300">—</span>'
        : tested === null
            ? '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-400">超时</span>'
            : `<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${latencyClass(tested) === 'text-emerald-700' ? 'bg-emerald-600 text-white' : `bg-slate-100 ${latencyClass(tested)}`}">${tested} ms</span>`;

    return `
    <div class="p-4 transition hover:bg-slate-50/70" data-node-id="${n.id}">
        <div class="flex items-start gap-2.5">
            <input type="checkbox" data-check value="${n.id}" class="row-check mt-1 h-4 w-4 shrink-0 rounded border-slate-300 text-indigo-600" ${state.checked.has(n.id) ? 'checked' : ''}>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5">
                    <p class="truncate text-sm font-medium text-slate-800">${esc(n.name)}</p>
                    ${n.is_cf ? '<span class="shrink-0 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 ring-1 ring-inset ring-sky-200">CF</span>' : ''}
                    ${n.is_generated ? '<span class="shrink-0 rounded-full bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-600 ring-1 ring-inset ring-violet-200">生成</span>' : ''}
                </div>
                <p class="mt-0.5 truncate font-mono text-xs text-slate-600">${esc(n.address)}<span class="font-semibold">:${n.port}</span></p>
                <p class="mt-0.5 truncate text-xs text-slate-400" title="${esc(n.sni || '')}">${esc((n.protocol || '').toUpperCase())} · ${esc(n.network || 'tcp')}${n.path ? ' · ' + esc(n.path) : ''}${n.sni ? ' · SNI ' + esc(n.sni) : ''}</p>
            </div>
            <button data-toggle class="shrink-0 self-center inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition ${n.enabled
                ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100'
                : 'bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200 hover:bg-slate-200'}">
                <span class="inline-block h-1.5 w-1.5 rounded-full ${n.enabled ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                ${n.enabled ? '启用' : '禁用'}
            </button>
        </div>
        <div class="mt-2.5 flex items-center justify-between gap-2">
            <span class="latency-cell text-xs">${latencyHtml}</span>
            <div class="flex items-center gap-0.5">
                <button data-move="up" title="上移（影响订阅顺序）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↑</button>
                <button data-move="down" title="下移（影响订阅顺序）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">↓</button>
                <button data-ping-single title="测速（当前网络环境）" class="rounded-md p-1.5 text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600">⚡</button>
                <button data-copy="${esc(n.uri)}" title="复制链接" class="rounded-md p-1.5 text-slate-400 transition hover:bg-indigo-50 hover:text-indigo-600">⧉</button>
                <button data-edit title="编辑" class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✎</button>
                <button data-delete title="删除" class="rounded-md p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600">✕</button>
            </div>
        </div>
    </div>`;
}

function bindEvents(root, data) {
    // 筛选 tabs
    root.querySelectorAll('[data-filter]').forEach(btn => btn.addEventListener('click', () => {
        state.filter = btn.dataset.filter;
        state.page = 1;
        loadAndRender(root);
    }));

    // 搜索
    root.querySelector('#search-form').addEventListener('submit', (e) => {
        e.preventDefault();
        state.q = e.target.q.value;
        state.page = 1;
        loadAndRender(root);
    });

    // 勾选与批量条（表格 + 卡片两种视图通用；同一节点两视图各有一个复选框，计数按 ID 去重）
    const syncBulk = () => {
        const boxes = [...root.querySelectorAll('.row-check')];
        const checkedIds = new Set(boxes.filter(b => b.checked).map(b => +b.value));
        boxes.forEach(b => { b.checked ? state.checked.add(+b.value) : state.checked.delete(+b.value); });
        root.querySelector('#bulk-count').textContent = checkedIds.size;
        root.querySelector('#bulk-bar').classList.toggle('hidden', checkedIds.size === 0);
        root.querySelector('#bulk-bar').classList.toggle('flex', checkedIds.size > 0);
        const ids = new Set(boxes.map(b => +b.value));
        root.querySelectorAll('[data-check-all]').forEach(el => {
            el.checked = ids.size > 0 && checkedIds.size === ids.size;
            el.indeterminate = checkedIds.size > 0 && checkedIds.size < ids.size;
        });
    };
    root.querySelectorAll('.row-check').forEach(b => b.addEventListener('change', syncBulk));
    root.querySelectorAll('[data-check-all]').forEach(el => el.addEventListener('change', (e) => {
        root.querySelectorAll('.row-check').forEach(b => { b.checked = e.target.checked; });
        syncBulk();
    }));
    syncBulk();

    // 批量删除
    root.querySelector('#bulk-delete').addEventListener('click', async () => {
        const ids = [...state.checked];
        if (!ids.length) return;
        if (!confirmBox(`确定删除选中的 ${ids.length} 个节点吗？由它们生成的优选节点将一并删除。`)) return;
        const res = await nodesApi.bulkRemove(ids);
        state.checked.clear();
        toast(res.message);
        await loadAndRender(root);
    });

    // 分页
    const pagination = root.querySelector('#pagination');
    const meta = data.meta;
    const pageBtn = (label, page, active = false, disabled = false) =>
        `<button data-page="${page}" ${active ? 'class="rounded-md bg-indigo-600 px-2.5 py-1 font-medium text-white"' : ''} ${disabled ? 'disabled class="px-2.5 py-1 text-slate-300"' : `class="rounded-md px-2.5 py-1 ${active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}"`}>${label}</button>`;
    let html = pageBtn('‹', Math.max(1, meta.current_page - 1), false, meta.current_page <= 1);
    for (let p = 1; p <= meta.last_page; p++) html += pageBtn(p, p, p === meta.current_page);
    html += pageBtn('›', Math.min(meta.last_page, meta.current_page + 1), false, meta.current_page >= meta.last_page);
    pagination.innerHTML = html;
    pagination.querySelectorAll('[data-page]').forEach(btn => btn.addEventListener('click', () => {
        const p = +btn.dataset.page;
        if (p === meta.current_page) return;
        state.page = p;
        loadAndRender(root);
    }));
    root.querySelector('#per-page').addEventListener('change', (e) => {
        state.perPage = +e.target.value;
        state.page = 1;
        loadAndRender(root);
    });

    // 清除 parent 筛选
    root.querySelector('[data-clear-parent]')?.addEventListener('click', () => {
        state.parent = null;
        loadAndRender(root);
    });

    // 行操作（事件委托：表格行与移动端卡片共用同一组 data 属性）
    const nodeList = root.querySelector('.js-node-list');
    nodeList.addEventListener('click', async (e) => {
        const tr = e.target.closest('[data-node-id]');
        if (!tr) return;
        const id = +tr.dataset.nodeId;
        const node = data.data.find(n => n.id === id);
        const action = e.target.closest('[data-move], [data-ping-single], [data-copy], [data-edit], [data-delete], [data-toggle]');

        if (action?.dataset.move) {
            const res = await nodesApi.move(id, action.dataset.move);
            toast(res.message);
            await loadAndRender(root);
        } else if (action?.hasAttribute('data-ping-single')) {
            await pingOne(tr, node);
        } else if (action?.dataset.copy !== undefined) {
            await copyText(action.dataset.copy);
            toast('已复制到剪贴板');
        } else if (action?.hasAttribute('data-edit')) {
            openNodeForm(root, node, loadAndRender);
        } else if (action?.hasAttribute('data-delete')) {
            if (!confirmBox(`确定删除节点「${node.name}」吗？由它生成的优选节点将一并删除。`)) return;
            const res = await nodesApi.remove(id);
            toast(res.message);
            state.checked.delete(id);
            await loadAndRender(root);
        } else if (action?.hasAttribute('data-toggle')) {
            const res = await nodesApi.toggle(id);
            toast(res.message);
            await loadAndRender(root);
        }
    });

    // 延迟测试（选中 → 测选中；未选 → 测当前页全部）
    root.querySelector('#ping-btn').addEventListener('click', async () => {
        const anyChecked = root.querySelector('.row-check:checked') !== null;
        const trs = anyChecked
            ? [...root.querySelectorAll('.row-check:checked')].map(b => b.closest('[data-node-id]'))
            : [...root.querySelectorAll('[data-node-id]')];

        const btn = root.querySelector('#ping-btn');
        const stats = root.querySelector('#ping-stats');
        btn.disabled = true;
        root.querySelectorAll('.latency-cell').forEach(c => { c.textContent = '…'; });
        let done = 0, ok = 0;
        let cursor = 0;
        const worker = async () => {
            while (cursor < trs.length) {
                const tr = trs[cursor++];
                const id = +tr.dataset.nodeId;
                const node = data.data.find(n => n.id === id);
                const ms = await pingOneMeasure(node);
                state.tested.set(id, ms);
                renderLatencyCell(tr, ms);
                done++;
                if (ms !== null) ok++;
                stats.textContent = `测速中 ${done}/${trs.length}`;
            }
        };
        await Promise.all(Array.from({ length: Math.min(20, trs.length) }, worker));
        stats.textContent = `可达 ${ok} / 超时 ${trs.length - ok}（共 ${trs.length}）`;
        btn.disabled = false;
    });
}

function renderLatencyCell(tr, ms) {
    const cell = tr.querySelector('.latency-cell');
    if (!cell) return;
    if (ms === null) {
        cell.innerHTML = '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-400">超时</span>';
        return;
    }
    const cls = ms < 100 ? 'bg-emerald-600 text-white'
        : ms < 200 ? 'bg-emerald-100 text-emerald-700'
        : ms < 500 ? 'bg-amber-100 text-amber-600'
        : ms < 1500 ? 'bg-red-100 text-red-500'
        : 'bg-slate-100 text-slate-400';
    cell.innerHTML = `<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${cls}">${ms} ms</span>`;
}

// 浏览器直连测速：
// - HTTP 页面：IP 走 http（CF 明文 trace 在 80），域名按节点安全层走 https/http
// - HTTPS 页面：http 请求被浏览器 mixed content 阻断；IP 改走 https 直连——裸 IP 证书不匹配
//   会在 TLS 握手完成后立即 reject，reject 时刻的耗时即到该 IP 的真实往返延迟（同列表测速机制），
//   绝对值含一次 TLS 握手略偏大，但横向比较各 IP 快慢一致
function pingTarget(node) {
    const isIp = node.address.includes(':')
        || /^\d{1,3}(\.\d{1,3}){3}$/.test(node.address);
    const host = node.address.includes(':') && !node.address.startsWith('[') ? `[${node.address}]` : node.address;
    const isCf = node.is_cf;
    const path = isCf ? '/cdn-cgi/trace' : '/';
    if (isIp) {
        if (location.protocol === 'https:') {
            // CF IP 用 443 + trace；非 CF IP 用节点自身端口
            return { url: `https://${host}${isCf ? path : `:${node.port}/`}`, timeout: 3000 };
        }
        // http 页面下 IP 不用 https（SNI 为空不可靠），直接 http 不带端口
        return { url: `http://${host}${path}`, timeout: 3000 };
    }
    const scheme = ['tls', 'reality'].includes(node.security) ? 'https' : 'http';
    return { url: `${scheme}://${host}:${node.port}${path}`, timeout: 3000 };
}

async function pingOneMeasure(node) {
    const { url, timeout } = pingTarget(node);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const start = performance.now();
    try {
        await fetch(url, { mode: 'no-cors', signal: controller.signal, cache: 'no-store' });
        clearTimeout(timer);
        const elapsed = Math.round(performance.now() - start);
        return elapsed >= 5 ? elapsed : null; // 瞬间失败 = 网络层报错，连接未建立
    } catch (e) {
        clearTimeout(timer);
        const elapsed = Math.round(performance.now() - start);
        return elapsed < timeout ? elapsed : null; // 提前 reject（含证书错误）= 连接已建立，耗时有效
    }
}

async function pingOne(tr, node) {
    const cell = tr.querySelector('.latency-cell');
    if (cell) cell.textContent = '…';
    const ms = await pingOneMeasure(node);
    state.tested.set(node.id, ms);
    renderLatencyCell(tr, ms);
}

// 新建/编辑节点模态
function openNodeForm(root, node, onSaved) {
    const modal = root.querySelector('#node-form-modal');
    const isEdit = !!node?.id;
    const n = node || {};

    modal.innerHTML = `
    <div class="mx-auto mt-4 w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
            <h3 class="text-sm font-semibold text-slate-900">${isEdit ? '编辑节点' : '新建节点'}</h3>
            <button data-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
        </div>
        <form id="node-form" class="grid grid-cols-1 gap-x-6 gap-y-4 px-6 py-5 sm:grid-cols-2 lg:grid-cols-3 max-h-[70vh] overflow-y-auto">
            <div><label class="mb-1 block text-sm font-medium text-slate-700">名称 *</label>
                <input name="name" required value="${esc(n.name || '')}" class="w-full rounded-lg border-slate-200 text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">协议</label>
                <select name="protocol" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">${['vless', 'vmess', 'trojan', 'ss'].map(p => `<option ${((n.protocol || 'vless') === p) ? 'selected' : ''}>${p}</option>`).join('')}</select></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">UUID / 密码</label>
                <input name="uuid" value="${esc(n.uuid || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div class="sm:col-span-2"><label class="mb-1 block text-sm font-medium text-slate-700">地址 *</label>
                <input name="address" required value="${esc(n.address || '')}" placeholder="IP 或域名" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">端口 *</label>
                <input name="port" type="number" min="1" max="65535" required value="${n.port ?? 443}" class="w-full rounded-lg border-slate-200 text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">传输层</label>
                <select name="network" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">${['tcp', 'ws', 'grpc', 'http'].map(x => `<option ${(n.network || 'tcp') === x ? 'selected' : ''}>${x}</option>`).join('')}</select></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">TLS 安全层</label>
                <select name="security" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">${[['', '无'], ['tls', 'tls'], ['reality', 'reality']].map(([v, l]) => `<option value="${v}" ${(n.security || '') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">SNI</label>
                <input name="sni" value="${esc(n.sni || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Host</label>
                <input name="host" value="${esc(n.host || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Path</label>
                <input name="path" value="${esc(n.path || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Flow</label>
                <input name="flow" value="${esc(n.flow || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">指纹 fp</label>
                <input name="fingerprint" value="${esc(n.fingerprint || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">ALPN</label>
                <input name="alpn" value="${esc(n.alpn || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">加密</label>
                <input name="encryption" value="${esc(n.encryption || '')}" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm"></div>
            <div class="sm:col-span-2"><label class="mb-1 block text-sm font-medium text-slate-700">额外参数（每行 key=value）</label>
                <textarea name="extras_text" rows="2" class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm">${esc(n.extras_text || '')}</textarea></div>
            <div class="flex items-end pb-1"><label class="flex items-center gap-2"><input type="checkbox" name="sort_enabled" class="hidden">
                <input type="checkbox" name="enabled" ${n.enabled ?? true ? 'checked' : ''} class="h-4 w-4 rounded border-slate-300 text-indigo-600"><span class="text-sm text-slate-700">启用</span></label></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">排序权重（越小越靠前）</label>
                <input name="sort_order" type="number" min="0" max="999999" value="${n.sort_order ?? 0}" class="w-full rounded-lg border-slate-200 text-sm shadow-sm"></div>
        </form>
        <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-4 rounded-b-2xl">
            <button data-close class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">取消</button>
            <button data-save class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-500">${isEdit ? '保存修改' : '创建节点'}</button>
        </div>
    </div>`;

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); modal.innerHTML = ''; };
    modal.querySelector('[data-close]').addEventListener('click', close);
    modal.querySelector('[data-save]').addEventListener('click', async () => {
        const form = modal.querySelector('#node-form');
        const payload = {
            name: form.name.value, protocol: form.protocol.value, uuid: form.uuid.value || null,
            address: form.address.value, port: +form.port.value, security: form.security.value || null,
            sni: form.sni.value || null, host: form.host.value || null, path: form.path.value || null,
            network: form.network.value, flow: form.flow.value || null,
            fingerprint: form.fingerprint.value || null, alpn: form.alpn.value || null,
            encryption: form.encryption.value || null, extras_text: form.extras_text.value || null,
            sort_order: form.sort_order.value === '' ? null : +form.sort_order.value,
            enabled: form.enabled.checked,
        };
        try {
            const res = isEdit ? await nodesApi.update(node.id, payload) : await nodesApi.create(payload);
            toast(res.message);
            close();
            onSaved();
        } catch (err) {
            showFieldErrors(form, err.errors);
            if (!err.errors) toast(err.message, 'error');
        }
    });
}
