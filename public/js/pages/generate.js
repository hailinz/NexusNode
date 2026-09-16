// ==================== 优选生成页 ====================
import { generateApi } from '../api.js';
import { esc, toast } from '../utils.js';

const state = { nodeIds: new Set(), ipIds: new Set() };
let rootEl = null;

export async function renderGenerate(container) {
    rootEl = container;
    rootEl.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';

    const data = await generateApi.data();
    state.nodeIds.clear();
    state.ipIds.clear();

    if (!data.templates.length) {
        rootEl.innerHTML = `
        <div class="rounded-xl border border-slate-200/80 bg-white px-5 py-16 text-center shadow-sm">
            <p class="text-sm text-slate-400">没有可作为模板的节点（需要 443 端口且地址与 SNI 一致）。先在「批量导入」导入 CF 节点</p>
        </div>`;
        return;
    }
    if (!data.ips.length) {
        rootEl.innerHTML = `
        <div class="rounded-xl border border-slate-200/80 bg-white px-5 py-16 text-center shadow-sm">
            <p class="text-sm text-slate-400">IP 池为空${data.disabled_ip_count ? `（有 ${data.disabled_ip_count} 个已停用）` : ''}。先到「CF 优选 IP」添加</p>
            <a href="#/preferred-ips" class="mt-3 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-500">去添加优选 IP</a>
        </div>`;
        return;
    }

    rootEl.innerHTML = `
    <form id="gen-form">
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm xl:col-span-3">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-slate-900">① 选择模板节点 <span class="ml-1 text-xs font-normal text-slate-400">（保留 SNI / Host / Path，仅替换地址）</span></h2>
                    <button type="button" data-checkall="node_ids" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">全选 / 反选</button>
                </div>
                <div class="max-h-[480px] divide-y divide-slate-50 overflow-y-auto">
                    ${data.templates.map(t => `
                        <label class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-slate-50/70">
                            <input type="checkbox" name="node_ids" value="${t.id}" class="gen-node h-4 w-4 rounded border-slate-300 text-indigo-600">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-medium text-slate-800">${esc(t.name)}</p>
                                    ${t.is_cf ? '<span class="shrink-0 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 ring-1 ring-inset ring-sky-200">CF</span>' : ''}
                                </div>
                                <p class="mt-0.5 truncate font-mono text-xs text-slate-400">${esc(t.address)}:${t.port} → SNI ${esc(t.sni)}</p>
                            </div>
                            ${t.generated_count > 0 ? `<a href="#/nodes?parent=${t.id}" class="shrink-0 rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-medium text-violet-600 hover:bg-violet-100">已生成 ${t.generated_count} →</a>` : ''}
                        </label>`).join('')}
                </div>
            </div>

            <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm xl:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-slate-900">② 选择优选 IP</h2>
                    <button type="button" data-checkall="ip_ids" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">全选 / 反选</button>
                </div>
                <div class="max-h-[480px] divide-y divide-slate-50 overflow-y-auto">
                    ${data.ips.map(ip => `
                        <label class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-slate-50/70">
                            <input type="checkbox" name="ip_ids" value="${ip.id}" class="gen-ip h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-100">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-mono text-sm font-medium text-slate-800">${esc(ip.ip)}</p>
                                ${ip.remarks || ip.latency_ms !== null ? `<p class="mt-0.5 truncate text-xs text-slate-400">${esc(ip.remarks || '')}${ip.latency_ms !== null ? ' · ' + Math.round(ip.latency_ms) + ' ms' : ''}</p>` : ''}
                            </div>
                        </label>`).join('')}
                </div>
            </div>
        </div>

        <div class="sticky bottom-4 mt-6 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-600">将生成 <b id="gen-count" class="text-indigo-600">0</b> 个节点 <span id="gen-detail" class="text-xs text-slate-400"></span></p>
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="overwrite" checked class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                    <span class="text-xs text-slate-600">覆盖已生成的同源节点</span>
                </label>
                <button class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">开始生成</button>
            </div>
        </div>
    </form>`;

    bindGenerateEvents(rootEl);
}

function bindGenerateEvents(root) {
    const form = root.querySelector('#gen-form');
    const count = () => {
        const n = root.querySelectorAll('input[name="node_ids"]:checked').length;
        const m = root.querySelectorAll('input[name="ip_ids"]:checked').length;
        root.querySelector('#gen-count').textContent = n * m;
        root.querySelector('#gen-detail').textContent = n > 0 && m > 0 ? `（${n} 模板 × ${m} IP）` : '';
    };

    root.querySelectorAll('input[name="node_ids"], input[name="ip_ids"]').forEach(cb => cb.addEventListener('change', count));

    root.querySelectorAll('[data-checkall]').forEach(btn => btn.addEventListener('click', () => {
        const boxes = root.querySelectorAll(`input[name="${btn.dataset.checkall}"]`);
        const all = [...boxes].every(b => b.checked);
        boxes.forEach(b => { b.checked = !all; });
        count();
    }));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nodeIds = [...form.querySelectorAll('input[name="node_ids"]:checked')].map(b => +b.value);
        const ipIds = [...form.querySelectorAll('input[name="ip_ids"]:checked')].map(b => +b.value);
        if (!nodeIds.length || !ipIds.length) { toast('请选择模板节点与优选 IP', 'error'); return; }
        const overwrite = form.querySelector('input[name="overwrite"]').checked;

        const btn = form.querySelector('button[type="submit"], button:not([type])');
        btn.disabled = true; btn.textContent = '生成中…';
        try {
            const res = await generateApi.run(nodeIds, ipIds, overwrite);
            toast(res.message);
            // 刷新页面数据
            renderGenerate(rootEl);
        } catch (err) {
            toast(err.message, 'error');
            btn.disabled = false; btn.textContent = '开始生成';
        }
    });
}
