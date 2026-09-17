// ==================== 总览页 ====================
import { dashboardApi } from '../api.js';
import { esc, formatTime } from '../utils.js';
import { showSubQrModal } from '../ui.js';

export async function renderDashboard(root) {
    root.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';

    const data = await dashboardApi.index();
    const s = data.stats;

    const cards = [
        ['节点总数', s.total_nodes, `启用 ${s.enabled_nodes} 个`, 'indigo'],
        ['CF 优选节点', s.cf_nodes, '地址与 SNI 不同', 'sky'],
        ['优选生成节点', s.generated_nodes, `IP 池 ${s.enabled_ip_count}/${s.ip_count} 启用`, 'violet'],
        ['订阅', s.sub_count, `启用 ${s.enabled_sub_count} 个`, 'emerald'],
    ];

    root.innerHTML = `
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        ${cards.map(([label, value, hint, color]) => `
            <div class="rounded-xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-500">${label}</p>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-${color}-50">
                        <span class="h-2.5 w-2.5 rounded-full bg-${color}-500"></span>
                    </div>
                </div>
                <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">${value}</p>
                <p class="mt-1 text-xs text-slate-400">${hint}</p>
            </div>`).join('')}
    </div>

    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        ${[
            ['#/nodes', '节点管理'],
            ['#/preferred-ips', '优选 IP'],
            ['#/generate', '优选生成'],
            ['#/subscriptions', '订阅管理'],
        ].map(([href, label]) => `
            <a href="${href}" class="group flex items-center justify-center gap-2 rounded-xl border border-slate-200/80 bg-white px-4 py-3.5 text-sm font-medium text-slate-600 shadow-sm transition hover:border-indigo-200 hover:text-indigo-600 hover:shadow">
                ${label}
            </a>`).join('')}
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm xl:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">最近订阅请求</h2>
                <a href="#/subscriptions" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">订阅管理 →</a>
            </div>
            ${data.recent_requests.length === 0
                ? '<div class="px-5 py-12 text-center text-sm text-slate-400">还没有订阅请求，客户端首次更新订阅后会显示在这里</div>'
                : `<div class="overflow-x-auto"><table class="w-full text-sm">
                    <thead><tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th class="px-5 py-2.5 font-medium">订阅</th><th class="px-4 py-2.5 font-medium">时间</th>
                        <th class="px-4 py-2.5 font-medium">IP</th><th class="px-4 py-2.5 font-medium">User-Agent</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        ${data.recent_requests.map(r => `
                            <tr class="transition hover:bg-slate-50/70">
                                <td class="px-5 py-2.5 text-xs font-medium text-slate-800">${esc(r.subscription)}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500">${esc(formatTime(r.requested_at))}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-600">${esc(r.ip)}</td>
                                <td class="max-w-[240px] px-4 py-2.5"><p class="truncate font-mono text-xs text-slate-500" title="${esc(r.user_agent)}">${esc(r.user_agent || '—')}</p></td>
                            </tr>`).join('')}
                    </tbody></table></div>`}
        </div>

        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-semibold text-slate-900">订阅地址</h2></div>
            <div class="px-5 py-4">
                ${data.recent_subscriptions.length === 0
                    ? '<p class="text-sm text-slate-400">还没有订阅，到「订阅管理」创建一个即可生成订阅地址。</p>'
                    : `<ul class="space-y-3">${data.recent_subscriptions.map(sub => `
                        <li>
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-slate-700">${esc(sub.name)}</p>
                                <span class="${sub.enabled ? 'text-emerald-600' : 'text-slate-400'} text-xs">${sub.enabled ? '启用中' : '已停用'}</span>
                            </div>
                            <button data-sub-qr="${esc(sub.name)}" data-sub-url="${esc(sub.url)}"
                                    class="mt-1 block w-full truncate rounded-md bg-slate-50 px-2.5 py-1.5 text-left font-mono text-xs text-slate-500 hover:bg-slate-100"
                                    title="点击显示二维码">${esc(sub.url)}</button>
                        </li>`).join('')}</ul>`}
                <p class="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-400">
                    在代理客户端（v2rayN / V2Box / NekoBox 等）中添加订阅，粘贴订阅地址即可自动同步所有启用状态的节点。
                </p>
            </div>
        </div>
    </div>`;

    root.querySelectorAll('[data-sub-qr]').forEach(btn => {
        btn.addEventListener('click', () => showSubQrModal(btn.dataset.subQr, btn.dataset.subUrl));
    });
}
