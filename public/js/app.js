// ==================== SPA 入口：布局 / 路由 / 登录态 ====================
import { api, getToken, setToken } from './api.js';
import { toast } from './utils.js';
import { renderLogin } from './pages/login.js';
import { renderDashboard } from './pages/dashboard.js';
import { renderNodes } from './pages/nodes.js';
import { renderImports } from './pages/imports.js';
import { renderPreferredIps } from './pages/preferredIps.js';
import { renderGenerate } from './pages/generate.js';
import { renderSubscriptions } from './pages/subscriptions.js';

const NAV = [
    { hash: '#/', key: 'dashboard', label: '总览', match: '#/', icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75' },
    { hash: '#/nodes', key: 'nodes', label: '节点管理', match: '#/nodes', icon: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z' },
    { hash: '#/imports', key: 'imports', label: '批量导入', match: '#/imports', icon: 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5' },
    { hash: '#/preferred-ips', key: 'preferred-ips', label: 'CF 优选 IP', match: '#/preferred-ips', icon: 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m-18.432 0A8.959 8.959 0 013 12c0-.778.099-1.533.284-2.253' },
    { hash: '#/generate', key: 'generate', label: '优选生成', match: '#/generate', icon: 'M9.813 15.904L9.375 21l-.438-5.096a2.25 2.25 0 00-1.966-1.966L1.875 13.5l5.096-.438a2.25 2.25 0 001.966-1.966L9.375 6l.438 5.096a2.25 2.25 0 001.966 1.966l5.096.438-5.096.438a2.25 2.25 0 00-1.966 1.966zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z' },
    { hash: '#/subscriptions', key: 'subscriptions', label: '订阅管理', match: '#/subscriptions', icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244' },
];

const PAGES = {
    dashboard: renderDashboard,
    nodes: renderNodes,
    imports: renderImports,
    'preferred-ips': renderPreferredIps,
    generate: renderGenerate,
    subscriptions: renderSubscriptions,
};

const root = document.getElementById('app');

// 布局壳只渲染一次：登录视图与主内容互斥显示
function renderShell() {
    root.innerHTML = `
    <div id="login-view" class="hidden"></div>

    <div id="app-layout" class="hidden min-h-screen lg:pl-60">
        <header class="sticky top-0 z-30 flex items-center gap-3 bg-slate-900 px-4 py-3 lg:hidden">
            <button id="drawer-open" class="rounded-lg p-1.5 text-slate-300 transition hover:bg-slate-800" title="打开导航">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            <span class="flex items-center gap-2 text-sm font-semibold text-white">
                <svg class="h-4 w-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                NexusNode
            </span>
        </header>

        <div id="drawer-overlay" class="fixed inset-0 z-30 hidden bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-60 translate-x-[-100%] flex-col bg-slate-900 transition-transform lg:translate-x-0">
            <div class="flex items-center justify-between px-5 pt-6 pb-7">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-500 shadow-lg shadow-indigo-500/30">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[15px] font-semibold tracking-wide text-white">NexusNode</p>
                        <p class="text-xs text-slate-400">节点订阅管理</p>
                    </div>
                </div>
                <button id="sidebar-close" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-800 lg:hidden">✕</button>
            </div>
            <nav id="main-nav" class="flex-1 space-y-1 overflow-y-auto px-3">
                ${NAV.map(item => `
                    <a href="${item.hash}" data-key="${item.key}" class="nav-link group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition">
                        <svg class="nav-icon h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="${item.icon}"/>
                        </svg>
                        ${item.label}
                    </a>`).join('')}
            </nav>
            <div class="space-y-1 border-t border-slate-800 px-3 py-4">
                <button id="change-password-btn" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400 transition hover:bg-slate-800 hover:text-slate-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                    修改密码
                </button>
                <button id="logout-btn" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400 transition hover:bg-slate-800 hover:text-slate-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                    退出登录
                </button>
                <p class="flex items-center gap-2 px-3 pt-2 text-xs text-slate-500">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Laravel · SQLite 在线运行
                </p>
            </div>
        </aside>

        <main class="mx-auto max-w-[128rem] px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
            <div id="page-header" class="mb-6 lg:mb-8"></div>
            <div id="page-content"></div>
        </main>
    </div>`;

    root.querySelector('#drawer-open').addEventListener('click', openDrawer);
    root.querySelector('#sidebar-close').addEventListener('click', closeDrawer);
    root.querySelector('#drawer-overlay').addEventListener('click', closeDrawer);
    root.querySelector('#change-password-btn').addEventListener('click', showChangePasswordModal);
    root.querySelector('#logout-btn').addEventListener('click', async () => {
        try { await api.post('/logout'); } catch (e) { /* 令牌可能已失效 */ }
        setToken(null);
        location.hash = '#/login';
        route();
    });
}

// 修改密码模态：成功后其他设备令牌失效（当前会话保留）
function showChangePasswordModal() {
    let modal = document.getElementById('change-password-modal');
    if (modal) modal.remove();

    modal = document.createElement('div');
    modal.id = 'change-password-modal';
    modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm';
    modal.innerHTML = `
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">修改密码</h3>
            <button type="button" data-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
        </div>
        <form id="change-password-form" class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">当前密码</label>
                <input type="password" name="current_password" required class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">新密码（至少 8 位）</label>
                <input type="password" name="password" required minlength="8" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">确认新密码</label>
                <input type="password" name="password_confirmation" required minlength="8" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-100">
            </div>
            <p data-error class="hidden text-xs text-red-500"></p>
            <button class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">确认修改</button>
        </form>
    </div>`;
    document.body.appendChild(modal);

    // 显示模态（class 中的 hidden 需移除并启用 flex 布局）
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    const close = () => modal.remove();
    modal.addEventListener('click', (e) => {
        if (e.target === modal || e.target.closest('[data-close]')) close();
    });

    modal.querySelector('#change-password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const errorEl = modal.querySelector('[data-error]');
        errorEl.classList.add('hidden');
        if (form.password.value !== form.password_confirmation.value) {
            errorEl.textContent = '两次输入的新密码不一致';
            errorEl.classList.remove('hidden');
            return;
        }
        try {
            const res = await api.patch('/profile/password', {
                current_password: form.current_password.value,
                password: form.password.value,
                password_confirmation: form.password_confirmation.value,
            });
            toast(res.message);
            close();
        } catch (err) {
            const msg = err.errors?.current_password?.[0] || err.errors?.password?.[0] || err.message;
            errorEl.textContent = msg;
            errorEl.classList.remove('hidden');
        }
    });
}

function openDrawer() {
    document.getElementById('sidebar').classList.remove('translate-x-[-100%]');
    const overlay = document.getElementById('drawer-overlay');
    overlay.classList.remove('hidden');
    overlay.classList.add('block');
    document.body.style.overflow = 'hidden';
}

function closeDrawer() {
    document.getElementById('sidebar').classList.add('translate-x-[-100%]');
    const overlay = document.getElementById('drawer-overlay');
    overlay.classList.add('hidden');
    overlay.classList.remove('block');
    document.body.style.overflow = '';
}

function setPageHeader(title, subtitle) {
    document.getElementById('page-header').innerHTML = `
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">${title}</h1>
        ${subtitle ? `<p class="mt-1 text-sm text-slate-500">${subtitle}</p>` : ''}`;
}

function highlightNav() {
    const hash = location.hash || '#/';
    document.querySelectorAll('.nav-link').forEach(link => {
        const item = NAV.find(n => n.key === link.dataset.key);
        const active = item && (item.match === '#/' ? hash === '#/' : hash.startsWith(item.match));
        link.classList.toggle('bg-slate-800', active);
        link.classList.toggle('text-white', active);
        link.classList.toggle('font-medium', active);
        link.classList.toggle('text-slate-400', !active);
        link.querySelector('.nav-icon').classList.toggle('text-indigo-400', active);
    });
}

function route() {
    // 布局壳只渲染一次
    if (!document.getElementById('app-layout')) renderShell();

    const hash = location.hash || '#/';
    const loggedIn = !!getToken();

    const loginView = document.getElementById('login-view');
    const layout = document.getElementById('app-layout');

    // 未登录：隐藏主布局，显示登录视图
    if (!loggedIn) {
        layout.classList.add('hidden');
        loginView.classList.remove('hidden');
        renderLogin(loginView);
        return;
    }

    loginView.classList.add('hidden');
    layout.classList.remove('hidden');

    // 已登录访问 #/login 时回到总览
    if (hash === '#/login') { location.hash = '#/'; return; }

    highlightNav();
    closeDrawer();

    const item = NAV.find(n => (n.match === '#/' ? hash === '#/' : hash.startsWith(n.match)));
    const pageKey = item ? item.key : 'dashboard';
    const queryStr = hash.includes('?') ? hash.split('?')[1] : '';
    const query = Object.fromEntries(new URLSearchParams(queryStr));

    const titles = {
        dashboard: ['总览', '代理节点与订阅的核心数据一览'],
        nodes: ['节点管理', '所有节点的统一管理：筛选、搜索、启停、测速与编辑'],
        imports: ['批量导入', '支持 vless / vmess / trojan / ss 链接，自动去重'],
        'preferred-ips': ['CF 优选 IP', '优选 IP 池与浏览器测速优选'],
        generate: ['优选生成', '选择模板节点与优选 IP，批量生成新节点'],
        subscriptions: ['订阅管理', '创建订阅地址，供代理客户端拉取'],
    };
    const [title, subtitle] = titles[pageKey] || ['NexusNode', ''];
    setPageHeader(title, subtitle);

    PAGES[pageKey](document.getElementById('page-content'), query).catch(err => {
        document.getElementById('page-content').innerHTML =
            `<div class="rounded-xl border border-red-200 bg-red-50 px-5 py-6 text-sm text-red-700">${err.message}</div>`;
    });
}

window.addEventListener('hashchange', route);

// 初始路由：页面加载即根据当前 hash 渲染（未登录自动进入登录页）
route();
