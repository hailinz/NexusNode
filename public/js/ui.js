// ==================== 全局 UI 组件 ====================

// 订阅二维码模态
export function showSubQrModal(name, url) {
    let modal = document.getElementById('sub-qr-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sub-qr-modal';
        modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm';
        modal.innerHTML = `
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl">
                <div class="flex items-center justify-between">
                    <h3 id="sub-qr-title" class="text-sm font-semibold text-slate-900">订阅二维码</h3>
                    <button type="button" data-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
                </div>
                <div class="mt-4 flex justify-center rounded-xl border border-slate-100 bg-white p-4">
                    <div id="sub-qr-code" class="[&img]:h-56 [&img]:w-56"></div>
                </div>
                <p class="mt-3 text-xs text-slate-400">手机客户端（v2rayN / V2Box / NekoBox 等）扫码即可添加订阅</p>
                <p id="sub-qr-url" class="mt-3 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600"></p>
                <button type="button" data-copy-btn class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">复制地址</button>
            </div>`;
        document.body.appendChild(modal);

        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('[data-close]')) closeModal();
        });
        modal.querySelector('[data-copy-btn]').addEventListener('click', async function () {
            const { copyText } = await import('../utils.js');
            await copyText(document.getElementById('sub-qr-url').textContent);
            this.textContent = '已复制 ✓';
            setTimeout(() => { this.textContent = '复制地址'; }, 1200);
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    }

    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
    function closeModal() { close(); }

    modal.querySelector('[data-close]').onclick = closeModal;

    document.getElementById('sub-qr-title').textContent = name + ' · 订阅二维码';
    document.getElementById('sub-qr-url').textContent = url;

    const container = document.getElementById('sub-qr-code');
    container.innerHTML = '';
    new QRCode(container, { text: url, width: 224, height: 224, correctLevel: QRCode.CorrectLevel.M });

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
