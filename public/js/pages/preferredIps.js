// ==================== CF 优选 IP 页 ====================
import { ipsApi, sourcesApi } from '../api.js';
import { esc, toast, confirmBox, readFileText, latencyClass, formatTime } from '../utils.js';
import { measureIp } from './onlineOptimize.js';

let rootEl = null;
const state = {
    sort: 'created', dir: 'desc', data: [], checked: new Set(),
    running: false, stopped: false, rounds: 4, timeout: 2000, concurrency: 10,
};
const MIN_VALID_MS = 5; // 小于此耗时的失败 = 网络层立即报错（连接未真正建立），判无效
const pendingWrites = [];
let saving = false;
let v6Cache = null;

export async function renderPreferredIps(container) {
    rootEl = container;
    rootEl.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';

    const [sourcesData] = await Promise.all([sourcesApi.list(), refreshTable()]);

    const sortBtn = (key, label) => `<button type="button" data-sort="${key}" class="rounded-md py-2 uppercase transition hover:text-slate-600 sm:py-1">${label} <span class="pi-arrow"></span></button>`;
    rootEl.innerHTML = `
    <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">在线优选源</h2>
                <p class="mt-0.5 text-xs text-slate-400">一键从社区优选 API 拉取最新 IP（自动识别纯文本 / CSV 测速 / CSV 地区格式）</p>
            </div>
            <div class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
                <a href="#/optimize" class="rounded-lg bg-emerald-600 px-4 py-2 text-center text-xs font-medium text-white shadow-sm shadow-emerald-600/20 transition hover:bg-emerald-500">⚡ 测速优选</a>
                <button id="src-sync-all" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">全部同步</button>
            </div>
        </div>
        <div id="src-list" class="divide-y divide-slate-50"></div>
        <form id="src-add" class="flex flex-col gap-2 rounded-b-xl border-t border-slate-100 bg-slate-50/60 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
            <input type="text" name="name" required placeholder="源名称，如：CM 聚合优选" class="w-full rounded-lg border-slate-200 text-sm shadow-sm sm:w-48">
            <input type="url" name="url" required placeholder="API 地址，如 https://addressesapi.090227.xyz/CloudFlareYes" class="w-full flex-1 rounded-lg border-slate-200 font-mono text-sm shadow-sm">
            <button class="rounded-lg bg-slate-900 px-4 py-2.5 text-xs font-medium text-white transition hover:bg-slate-700 sm:py-2">添加源</button>
        </form>
    </div>

    <div class="mt-4 rounded-xl border border-slate-200/80 bg-white shadow-sm sm:mt-6">
        <div class="space-y-3 border-b border-slate-100 px-4 py-4 sm:px-5">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900">IP 池 <span id="pi-count" class="ml-1 text-xs font-normal text-slate-400"></span></h2>
                <span id="pi-progress" class="hidden rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600"></span>
            </div>
            <p class="text-xs text-slate-400">浏览器测<b class="font-medium text-slate-600">延迟</b>与<b class="font-medium text-slate-600">丢包率</b>（建连后多轮采样：延迟取最低值，超时或重传计丢包，与测速优选同口径）</p>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="grid grid-cols-3 gap-2 sm:flex sm:gap-3">
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">轮数</span>
                        <input type="number" id="pi-rounds" value="4" min="1" max="10" inputmode="numeric" class="w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm sm:w-20"></label>
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">超时 ms</span>
                        <input type="number" id="pi-timeout" value="2000" min="500" max="10000" step="500" inputmode="numeric" class="w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm sm:w-24"></label>
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">并发</span>
                        <input type="number" id="pi-concurrency" value="10" min="1" max="32" inputmode="numeric" class="w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm sm:w-20"></label>
                </div>
                <div class="grid grid-cols-3 gap-2 sm:flex">
                    <button type="button" id="pi-test-selected" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-50">测速选中</button>
                    <button type="button" id="pi-test-page" class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-indigo-50 disabled:opacity-50">本页全测</button>
                    <button type="button" id="pi-stop" class="hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50">停止</button>
                    <button type="button" id="pi-delete" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-medium text-red-600 transition hover:bg-red-50">删除选中</button>
                </div>
            </div>
        </div>
        <!-- 移动端：全选 + 排序（桌面端在表头） -->
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-4 py-1.5 text-xs text-slate-500 sm:hidden">
            <label class="flex items-center gap-2 py-1.5"><input type="checkbox" data-check-all class="h-4 w-4 rounded border-slate-300">全选</label>
            <div class="flex items-center gap-3">排序 ${sortBtn('latency', '延迟')} ${sortBtn('created', '时间')}</div>
        </div>
        <div class="sm:overflow-x-auto">
            <!-- 同一份 DOM：移动端每行是 flex 卡片（order 重排为 IP·状态·删除 / 备注 / 指标三行），sm 起恢复表格 -->
            <table class="w-full text-sm">
                <thead class="hidden sm:table-header-group">
                    <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th class="py-3 pl-5 pr-2 font-medium"><input type="checkbox" data-check-all class="h-4 w-4 rounded border-slate-300" title="全选"></th>
                        <th class="px-4 py-3 font-medium">IP 地址</th>
                        <th class="px-4 py-3 font-medium">备注</th>
                        <th class="px-4 py-3 font-medium">${sortBtn('latency', '延迟')}</th>
                        <th class="px-4 py-3 font-medium">丢包率</th>
                        <th class="px-4 py-3 font-medium">下载速度</th>
                        <th class="px-4 py-3 font-medium">${sortBtn('created', '添加时间')}</th>
                        <th class="px-4 py-3 font-medium">状态</th>
                        <th class="px-4 py-3 text-right font-medium">操作</th>
                    </tr>
                </thead>
                <tbody id="pi-tbody" class="block divide-y divide-slate-100 sm:table-row-group sm:divide-slate-50"></tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:mt-6 sm:gap-6 xl:grid-cols-2">
        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">批量添加</h2>
                <p class="mt-0.5 text-xs text-slate-400">每行一个 IP，支持备注：<code class="rounded bg-slate-100 px-1 font-mono">1.2.3.4#香港</code></p>
            </div>
            <form id="ips-add-text" class="px-5 py-4">
                <textarea name="content" rows="7" placeholder="104.16.1.1#圣何塞&#10;172.64.2.2 香港&#10;104.18.3.3"
                          class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100"></textarea>
                <div class="mt-4 flex justify-end">
                    <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">添加 IP</button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">导入 CloudflareSpeedTest 结果</h2>
                <p class="mt-0.5 text-xs text-slate-400">上传 result.csv，自动读取延迟 / 丢包率 / 下载速度</p>
            </div>
            <form id="ips-import-csv" class="px-5 py-4">
                <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 px-6 py-9 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                    </svg>
                    <p class="mt-3 text-sm font-medium text-slate-600">选择 result.csv</p>
                    <input type="file" name="csv" class="sr-only" accept=".csv">
                </label>
                <div class="mt-4 flex justify-end">
                    <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">导入 CSV</button>
                </div>
            </form>
        </div>
    </div>`;

    renderSources(sourcesData.sources);
    renderTable();
    bindEvents();
    updateArrows();
    refreshTable();
}

// ---------- 在线优选源 ----------
function renderSources(sources) {
    const container = rootEl.querySelector('#src-list');
    if (!sources.length) {
        container.innerHTML = '<div class="px-5 py-6 text-center text-sm text-slate-400">还没有在线源，在下方添加即可一键同步</div>';
        return;
    }
    container.innerHTML = sources.map(s => `
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5" data-id="${s.id}">
            <div class="min-w-0 basis-full sm:basis-auto sm:flex-1">
                <div class="flex items-center gap-2">
                    <p class="shrink-0 text-xs font-medium text-slate-800">${esc(s.name)}</p>
                    ${s.last_error ? `<span class="truncate text-xs text-red-500" title="${esc(s.last_error)}">${esc(s.last_error)}</span>` : ''}
                </div>
                <p class="truncate font-mono text-xs text-slate-400">${esc(s.url)}</p>
            </div>
            <span class="font-mono text-xs text-slate-500">${s.last_synced_at ? esc(formatTime(s.last_synced_at, false)) : '—'}</span>
            <span class="text-xs font-medium text-slate-700">${s.last_synced_at ? s.last_count + ' 个' : '—'}</span>
            <div class="ml-auto flex items-center gap-1.5">
                <button data-src-toggle class="rounded-full px-3 py-1.5 text-xs font-medium ${s.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'}">${s.enabled ? '启用' : '停用'}</button>
                <button data-src-sync class="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-100">同步</button>
                <button data-src-delete title="删除" class="rounded-md px-2 py-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600">✕</button>
            </div>
        </div>`).join('');

    container.querySelectorAll('[data-id]').forEach(row => {
        const id = +row.dataset.id;
        row.querySelector('[data-src-sync]').addEventListener('click', async () => {
            try {
                const res = await sourcesApi.sync(id);
                toast(res.message);
                await reloadSources();
                await refreshTable();
            } catch (err) { toast(err.message, 'error'); }
        });
        row.querySelector('[data-src-toggle]').addEventListener('click', async () => {
            await sourcesApi.toggle(id);
            await reloadSources();
        });
        row.querySelector('[data-src-delete]').addEventListener('click', async () => {
            if (!confirmBox('确定删除该在线源吗？已入库的 IP 不受影响。')) return;
            await sourcesApi.remove(id);
            toast('在线源已删除');
            await reloadSources();
        });
    });
}

async function reloadSources() {
    const data = await sourcesApi.list();
    renderSources(data.sources);
}

// ---------- IP 表格 ----------
function renderTable() {
    const tbody = rootEl?.querySelector('#pi-tbody');
    if (!tbody) return; // 页面骨架尚未就绪（首屏加载中），数据已入 state，就绪后会正式渲染
    rootEl.querySelector('#pi-count').textContent = state.data.length ? `${state.data.length} 个` : '';
    if (!state.data.length) {
        tbody.innerHTML = '<tr class="block sm:table-row"><td colspan="9" class="block px-4 py-12 text-center text-sm text-slate-400 sm:table-cell">IP 池为空。先同步在线源或导入一批 IP，再用「⚡ 测速优选」筛选</td></tr>';
        return;
    }

    const factor = state.dir === 'asc' ? 1 : -1;
    const rows = [...state.data].sort((a, b) => {
        if (state.sort === 'latency') {
            const va = a.latency_ms ?? null, vb = b.latency_ms ?? null;
            if (va === null && vb === null) return 0;
            if (va === null) return 1;
            if (vb === null) return -1;
            return (va - vb) * factor;
        }
        return (Date.parse(a.created_at) - Date.parse(b.created_at)) * factor;
    });

    // 移动端：tr 为 flex 卡片，order 排成「勾选 IP 状态 删除 / 备注 / 延迟 丢包 速度 时间」三行（pl-8 = 复选框 20px + 间距 12px，与 IP 文字对齐）
    tbody.innerHTML = rows.map(ip => `
        <tr class="pi-row flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 transition hover:bg-slate-50/70 sm:table-row sm:p-0" data-ip="${esc(ip.ip)}">
            <td class="order-1 sm:py-3 sm:pl-5 sm:pr-2"><input type="checkbox" value="${esc(ip.ip)}" class="pi-check h-4 w-4 rounded border-slate-300" ${state.checked.has(ip.ip) ? 'checked' : ''}></td>
            <td class="order-2 min-w-0 flex-1 truncate font-mono text-[13px] font-medium text-slate-800 sm:px-4 sm:py-3 sm:text-xs">${esc(ip.ip)}</td>
            <td class="order-5 basis-full pl-8 text-xs text-slate-500 sm:px-4 sm:py-3 sm:text-slate-600 ${ip.remarks ? '' : 'hidden sm:table-cell'}">${esc(ip.remarks || '—')}</td>
            <td class="pi-latency order-6 pl-8 text-xs sm:px-4 sm:py-3">${ip.loss_rate === 100
                ? '<span class="text-slate-400">不可达</span>' // 最近一次测速全部失败：库里保留的是旧延迟，不再展示
                : (ip.latency_ms !== null ? `<span class="${latencyClass(ip.latency_ms)}">${ip.latency_ms} ms</span>` : '<span class="text-slate-300">—</span>')}</td>
            <td class="order-7 text-xs text-slate-600 sm:px-4 sm:py-3 ${ip.loss_rate !== null ? '' : 'hidden sm:table-cell'}"><span class="text-slate-400 sm:hidden">丢包 </span>${ip.loss_rate !== null ? ip.loss_rate.toFixed(2) + '%' : '—'}</td>
            <td class="order-8 text-xs text-slate-600 sm:px-4 sm:py-3 ${ip.download_speed !== null ? '' : 'hidden sm:table-cell'}">${ip.download_speed !== null ? ip.download_speed.toFixed(2) + ' MB/s' : '—'}</td>
            <td class="order-9 ml-auto font-mono text-xs text-slate-400 sm:ml-0 sm:px-4 sm:py-3 sm:text-slate-500">${esc(formatTime(ip.created_at, false))}</td>
            <td class="order-3 sm:px-4 sm:py-3">
                <button data-toggle class="rounded-full px-3 py-1.5 text-xs font-medium sm:px-2.5 sm:py-1 ${ip.enabled ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200'}">${ip.enabled ? '启用' : '停用'}</button>
            </td>
            <td class="order-4 sm:px-4 sm:py-3 sm:text-right"><button data-delete class="rounded-md px-2 py-1.5 text-xs text-red-500 hover:bg-red-50 sm:p-0 sm:hover:bg-transparent sm:hover:underline">删除</button></td>
        </tr>`).join('');
    syncCheckAll();
}

// 行勾选 → 同步 state.checked 与「全选」框（桌面表头 + 移动端各一个）
function syncCheckAll() {
    const boxes = [...rootEl.querySelectorAll('.pi-check')];
    const n = boxes.filter(b => b.checked).length;
    rootEl.querySelectorAll('[data-check-all]').forEach(el => {
        el.checked = boxes.length > 0 && n === boxes.length;
        el.indeterminate = n > 0 && n < boxes.length;
    });
}

async function refreshTable() {
    const data = await ipsApi.list({ sort: state.sort, dir: state.dir });
    state.data = data.ips;
    if (rootEl) renderTable();
}

// ---------- 事件 ----------
function bindEvents() {
    rootEl.querySelector('#src-sync-all').addEventListener('click', async () => {
        try {
            const res = await sourcesApi.syncAll();
            toast(res.message);
            await reloadSources();
            await refreshTable();
        } catch (err) { toast(err.message, 'error'); }
    });

    rootEl.querySelector('#src-add').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await sourcesApi.create(e.target.name.value, e.target.url.value);
            toast(res.message);
            e.target.reset();
            await reloadSources();
        } catch (err) { toast(err.message, 'error'); }
    });

    rootEl.querySelector('#ips-add-text').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await ipsApi.addText(e.target.content.value);
            toast(res.message);
            e.target.reset();
            await refreshTable();
        } catch (err) { toast(err.message, 'error'); }
    });

    rootEl.querySelector('#ips-import-csv').addEventListener('submit', async (e) => {
        e.preventDefault();
        const file = e.target.csv.files[0];
        if (!file) { toast('请先选择 CSV 文件', 'error'); return; }
        try {
            const content = await readFileText(file);
            const res = await ipsApi.importCsv(content);
            toast(res.message);
            await refreshTable();
        } catch (err) { toast(err.message, 'error'); }
    });

    // 排序（页内即时；桌面表头与移动端排序条共用 data-sort）
    rootEl.querySelectorAll('[data-sort]').forEach(btn => btn.addEventListener('click', () => setSort(btn.dataset.sort)));

    // 勾选：行复选框（委托）与全选框
    rootEl.querySelector('#pi-tbody').addEventListener('change', (e) => {
        const cb = e.target.closest('.pi-check');
        if (!cb) return;
        cb.checked ? state.checked.add(cb.value) : state.checked.delete(cb.value);
        syncCheckAll();
    });
    rootEl.querySelectorAll('[data-check-all]').forEach(el => el.addEventListener('change', () => {
        rootEl.querySelectorAll('.pi-check').forEach(cb => {
            cb.checked = el.checked;
            el.checked ? state.checked.add(cb.value) : state.checked.delete(cb.value);
        });
        syncCheckAll();
    }));

    // 测速选中 / 本页全测 / 停止 / 删除选中
    rootEl.querySelector('#pi-test-selected').addEventListener('click', () => {
        const trs = [...rootEl.querySelectorAll('.pi-check:checked')].map(c => c.closest('tr'));
        if (!trs.length) { toast('请先勾选要测速的 IP', 'error'); return; }
        piRun(trs);
    });
    rootEl.querySelector('#pi-test-page').addEventListener('click', () => {
        piRun([...rootEl.querySelectorAll('.pi-row')]);
    });
    rootEl.querySelector('#pi-stop').addEventListener('click', () => { state.stopped = true; });
    rootEl.querySelector('#pi-delete').addEventListener('click', async () => {
        const ips = [...rootEl.querySelectorAll('.pi-check:checked')].map(c => c.value);
        if (!ips.length) { toast('请先勾选要删除的 IP', 'error'); return; }
        if (!confirmBox(`确定删除选中的 ${ips.length} 个 IP 吗？`)) return;
        try {
            const res = await ipsApi.bulkRemove(ips);
            toast(res.message);
            ips.forEach(ip => state.checked.delete(ip));
            await refreshTable();
        } catch (err) { toast(err.message, 'error'); }
    });

    // 行级操作（委托）：启停 / 删除
    rootEl.querySelector('#pi-tbody').addEventListener('click', async (e) => {
        const tr = e.target.closest('tr[data-ip]');
        if (!tr) return;
        const ip = tr.dataset.ip;
        if (e.target.closest('[data-toggle]')) {
            const res = await ipsApi.toggle(ip);
            toast(res.message);
            await refreshTable();
        } else if (e.target.closest('[data-delete]')) {
            if (!confirmBox(`确定删除 IP ${ip} 吗？`)) return;
            await ipsApi.remove(ip);
            toast('已删除');
            await refreshTable();
        }
    });
}

function setSort(key) {
    if (state.sort === key) {
        state.dir = state.dir === 'asc' ? 'desc' : 'asc';
    } else {
        state.sort = key;
        state.dir = key === 'created' ? 'desc' : 'asc';
    }
    renderTable();
    updateArrows();
}

function updateArrows() {
    rootEl?.querySelectorAll('[data-sort]').forEach(btn => {
        const active = state.sort === btn.dataset.sort;
        btn.querySelector('.pi-arrow').textContent = active ? (state.dir === 'asc' ? '↑' : '↓') : '';
        btn.classList.toggle('text-indigo-600', active);
    });
}

// ---------- IP 列表测速（多轮探测：平均延迟 + 丢包率） ----------
function piSetProgress(done, total, label) {
    const el = rootEl.querySelector('#pi-progress');
    el.classList.remove('hidden');
    el.textContent = `${done} / ${total}${label ? ' · ' + label : ''}`;
}

async function piV6Probe() {
    if (v6Cache !== null) return v6Cache;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 2500);
    const start = performance.now();
    try {
        await fetch('https://[2606:4700:4700::1111]/cdn-cgi/trace', { mode: 'no-cors', signal: controller.signal, cache: 'no-store' });
        v6Cache = true;
    } catch (e) {
        v6Cache = performance.now() - start > 50;
    } finally {
        clearTimeout(timer);
    }
    return v6Cache;
}

function piIsV6(ip) {
    return ip.includes(':');
}

// 单次探测：
// - HTTP 页面：走 http://IP/cdn-cgi/trace（CF 明文 trace 在 80）
// - HTTPS 页面：http 请求被 mixed content 阻断，改走 https://IP:443/cdn-cgi/trace——
//   裸 IP 证书不匹配在 TLS 握手完成后立即 reject，reject 时刻耗时即有效延迟（与 nodes.js 同款）
// 瞬间失败（<5ms）判无效
async function piProbe(ip, timeout) {
    const target = ip.includes(':') && !ip.startsWith('[') ? `[${ip}]` : ip;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const start = performance.now();
    const url = location.protocol === 'https:'
        ? `https://${target}:443/cdn-cgi/trace`
        : `http://${target}/cdn-cgi/trace`;
    try {
        await fetch(url, { mode: 'no-cors', signal: controller.signal, cache: 'no-store' });
        clearTimeout(timer);
        return Math.round(performance.now() - start);
    } catch (e) {
        clearTimeout(timer);
        const elapsed = Math.round(performance.now() - start);
        return elapsed >= MIN_VALID_MS && elapsed < timeout ? elapsed : null;
    }
}

// 单个 IP 测速：优先复用「浏览器测速优选」的探测（建连后多轮采样，延迟取最低值、超时或重传计丢包），
// 与优选入库的数值同口径；探测域名不可用时降级为直连 IP 多轮探测取平均
async function piTestOne(ip) {
    const m = await measureIp(ip, state.rounds, state.timeout, () => state.stopped);
    if (m) return state.stopped ? null : { ip, ...m };

    const latencies = [];
    let fail = 0;
    for (let i = 0; i < state.rounds; i++) {
        if (state.stopped) break;
        const ms = await piProbe(ip, state.timeout);
        if (ms === null) fail++; else latencies.push(ms);
    }
    const tested = latencies.length + fail;
    if (!tested) return null;
    const latency = latencies.length ? Math.round(latencies.reduce((a, b) => a + b, 0) / latencies.length) : null;
    return { ip, latency_ms: latency, loss_rate: +(fail / tested * 100).toFixed(2) };
}

// 测速执行：更新行 + 数据源 + 分批回写
async function piRun(trs) {
    if (state.running || !trs.length) return;
    state.rounds = Math.max(1, Math.min(10, +rootEl.querySelector('#pi-rounds').value || 4));
    state.timeout = Math.max(500, Math.min(10000, +rootEl.querySelector('#pi-timeout').value || 2000));
    state.concurrency = Math.max(1, Math.min(32, +rootEl.querySelector('#pi-concurrency').value || 10));
    state.running = true;
    state.stopped = false;

    rootEl.querySelector('#pi-test-selected').disabled = true;
    rootEl.querySelector('#pi-test-page').disabled = true;
    rootEl.querySelector('#pi-stop').classList.remove('hidden');
    trs.forEach(tr => {
        tr.querySelector('.pi-latency').innerHTML = '<span class="animate-pulse text-slate-400">测…</span>';
    });

    let done = 0, cursor = 0;
    const worker = async () => {
        while (cursor < trs.length && !state.stopped) {
            const tr = trs[cursor++];
            const ip = tr.dataset.ip;
            if (piIsV6(ip)) {
                const ok6 = await piV6Probe();
                if (!ok6) {
                    tr.querySelector('.pi-latency').innerHTML = '<span class="text-slate-400">无 IPv6</span>';
                    done++;
                    piSetProgress(done, trs.length, '测速中');
                    continue;
                }
            }
            const m = await piTestOne(ip);
            if (m) {
                tr.querySelector('.pi-latency').innerHTML = m.latency_ms !== null
                    ? `<span class="${latencyClass(m.latency_ms)}">${m.latency_ms} ms</span>`
                    : '<span class="text-slate-400">不可达</span>';
                const entry = state.data.find(d => d.ip === m.ip);
                if (entry) {
                    if (m.latency_ms !== null) entry.latency_ms = m.latency_ms;
                    entry.loss_rate = m.loss_rate;
                }
                pendingWrites.push(m);
                if (pendingWrites.length >= 50) flushWrites();
            }
            done++;
            piSetProgress(done, trs.length, state.stopped ? '已停止' : '测速中');
        }
    };
    await Promise.all(Array.from({ length: Math.min(state.concurrency, trs.length) }, worker));

    state.running = false;
    await flushWrites();
    rootEl.querySelector('#pi-stop').classList.add('hidden');
    rootEl.querySelector('#pi-test-selected').disabled = false;
    rootEl.querySelector('#pi-test-page').disabled = false;
    piSetProgress(done, trs.length, state.stopped ? '已停止' : '完成');
}

// 分批回写数据库
async function flushWrites() {
    if (saving || !pendingWrites.length) return;
    saving = true;
    const entries = pendingWrites.splice(0, 200);
    try {
        await ipsApi.metricsBatch(entries);
    } catch (e) {
        pendingWrites.unshift(...entries);
    } finally {
        saving = false;
    }
}
