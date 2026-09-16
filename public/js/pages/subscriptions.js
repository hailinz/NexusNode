// ==================== 订阅管理页 ====================
import { subsApi } from '../api.js';
import { esc, toast, confirmBox } from '../utils.js';
import { showSubQrModal } from '../ui.js';

let rootEl = null;

export async function renderSubscriptions(container) {
    rootEl = container;
    rootEl.innerHTML = '<div class="rounded-xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">加载中…</div>';

    const data = await subsApi.list();

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
                <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs">3</span>客户端更新订阅即可拉取全部启用节点（base64 标准格式）</li>
            </ol>
            <p class="mt-3 border-t border-white/20 pt-3 text-xs text-indigo-200">
                停用或删除节点、重置令牌后，客户端下次更新订阅即自动同步。
            </p>
        </div>
    </div>

    <div class="mt-6 space-y-4" id="sub-list">
        ${data.subscriptions.length === 0
            ? '<div class="rounded-xl border border-slate-200/80 bg-white px-5 py-16 text-center text-sm text-slate-400 shadow-sm">还没有订阅，左上角创建一个即可获得订阅地址</div>'
            : data.subscriptions.map(subCard).join('')}
    </div>`;

    bindEvents(data);
}

function subCard(sub) {
    return `
    <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm" data-id="${sub.id}">
        <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold text-slate-900">${esc(sub.name)}</p>
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
            <div class="flex shrink-0 items-center gap-2">
                <button data-regenerate class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50">重置地址</button>
                <button data-delete class="rounded-lg border border-red-200 px-3 py-2 text-xs font-medium text-red-600 transition hover:bg-red-50">删除</button>
            </div>
        </div>
    </div>`;
}

function bindEvents(data) {
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
        const sub = data.subscriptions.find(s => s.id === id);
        if (!sub) return;

        if (e.target.closest('[data-qr]')) {
            showSubQrModal(sub.name, sub.url);
        } else if (e.target.closest('[data-toggle]')) {
            const res = await subsApi.toggle(id);
            toast(res.message);
            await renderSubscriptions(rootEl);
        } else if (e.target.closest('[data-regenerate]')) {
            if (!confirmBox('重置后旧订阅地址立即失效，确定？')) return;
            const res = await subsApi.regenerate(id);
            toast(res.message);
            await renderSubscriptions(rootEl);
        } else if (e.target.closest('[data-delete]')) {
            if (!confirmBox(`确定删除订阅「${sub.name}」吗？`)) return;
            await subsApi.remove(id);
            toast('订阅已删除');
            await renderSubscriptions(rootEl);
        }
    });
}
