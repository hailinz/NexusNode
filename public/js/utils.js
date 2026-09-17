// ==================== 通用工具 ====================

// HTML 转义
export function esc(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 按浏览器时区格式化后端 ISO 时间（MM-DD HH:mm[:ss]）；解析失败原样返回
export function formatTime(iso, withSeconds = true) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso ?? '');
    const pad = n => String(n).padStart(2, '0');
    const date = `${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const time = `${pad(d.getHours())}:${pad(d.getMinutes())}${withSeconds ? ':' + pad(d.getSeconds()) : ''}`;
    return `${date} ${time}`;
}

// 轻提示（右下角浮层）
export function toast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed bottom-6 right-6 z-[60] space-y-2';
        document.body.appendChild(container);
    }
    const el = document.createElement('div');
    el.className = `rounded-lg px-4 py-2.5 text-sm font-medium shadow-lg transition-all duration-300 translate-y-2 opacity-0 ${
        type === 'error' ? 'bg-red-600 text-white' : 'bg-slate-900 text-white'
    }`;
    el.textContent = message;
    container.appendChild(el);
    requestAnimationFrame(() => { el.classList.remove('translate-y-2', 'opacity-0'); });
    setTimeout(() => {
        el.classList.add('translate-y-2', 'opacity-0');
        setTimeout(() => el.remove(), 300);
    }, 2600);
}

// 复制到剪贴板（http 环境降级 execCommand）
export async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
    } finally {
        document.body.removeChild(ta);
    }
}

// 确认弹窗（原生 confirm）
export function confirmBox(message) {
    return window.confirm(message);
}

// 读文件为文本
export function readFileText(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('文件读取失败'));
        reader.readAsText(file);
    });
}

// 表单字段错误渲染到指定容器
export function showFieldErrors(container, errors) {
    container.querySelectorAll('.field-error').forEach(el => el.remove());
    if (!errors) return;
    for (const [field, messages] of Object.entries(errors)) {
        const input = container.querySelector(`[name="${field}"]`);
        if (input) {
            const tip = document.createElement('p');
            tip.className = 'field-error mt-1 text-xs text-red-500';
            tip.textContent = messages[0];
            input.insertAdjacentElement('afterend', tip);
        }
    }
}

// 表格空状态行
export function emptyRow(colspan, text) {
    return `<tr><td colspan="${colspan}" class="py-12 text-center text-sm text-slate-400">${esc(text)}</td></tr>`;
}

// 延迟分档配色（与节点管理一致）
export function latencyClass(ms) {
    return ms < 100 ? 'text-emerald-700'
        : ms < 200 ? 'text-emerald-500'
        : ms < 500 ? 'text-amber-500'
        : ms < 1500 ? 'text-red-400'
        : 'text-slate-400';
}
