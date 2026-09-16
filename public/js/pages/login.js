// ==================== 登录页 ====================
import { api, setToken } from '../api.js';
import { showFieldErrors } from '../utils.js';

export function renderLogin(root) {
    root.innerHTML = `
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-500 shadow-lg shadow-indigo-500/30">
                    <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-semibold text-slate-900">NexusNode</h1>
                <p class="mt-1 text-sm text-slate-400">节点订阅管理平台</p>
            </div>
            <form id="login-form" class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">用户名</label>
                    <input type="text" name="username" required autocomplete="username"
                           class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">密码</label>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
                </div>
                <p id="login-error" class="hidden text-xs text-red-500"></p>
                <button class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">
                    登 录
                </button>
            </form>
        </div>
    </div>`;

    root.querySelector('#login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const errorEl = root.querySelector('#login-error');
        errorEl.classList.add('hidden');
        try {
            const data = await api.post('/login', {
                username: form.username.value,
                password: form.password.value,
            });
            setToken(data.token);
            location.hash = '#/';
        } catch (err) {
            if (err.errors) showFieldErrors(form, err.errors);
            else {
                errorEl.textContent = err.message;
                errorEl.classList.remove('hidden');
            }
        }
    });
}
