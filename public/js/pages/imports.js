// ==================== 批量导入页 ====================
import { importApi } from '../api.js';
import { esc, toast, readFileText } from '../utils.js';

export function renderImports(root) {
    root.innerHTML = `
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">粘贴导入</h2>
                <p class="mt-0.5 text-xs text-slate-400">每行一条链接，或直接粘贴 base64 订阅内容</p>
            </div>
            <form id="import-text" class="px-5 py-4">
                <textarea name="content" rows="12" placeholder="vless://uuid@1.2.3.4:443?security=tls&sni=example.com&type=ws&host=example.com&path=/ws#节点名称&#10;vmess://...&#10;trojan://...&#10;ss://..."
                          class="w-full rounded-lg border-slate-200 font-mono text-xs leading-relaxed shadow-sm focus:border-indigo-400 focus:ring-indigo-100"></textarea>
                <div class="mt-4 flex justify-end">
                    <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">解析并导入</button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">文件导入</h2>
                <p class="mt-0.5 text-xs text-slate-400">上传 txt / conf / base64 订阅文件（最大 4MB）</p>
            </div>
            <form id="import-file" class="px-5 py-4">
                <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 px-6 py-12 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/>
                    </svg>
                    <p class="mt-3 text-sm font-medium text-slate-600">点击选择文件</p>
                    <p class="mt-1 text-xs text-slate-400">例如之前管理的 node.txt</p>
                    <input type="file" name="file" class="sr-only" accept=".txt,.conf,.text,.list,.json">
                </label>
                <div class="mt-4 flex justify-end">
                    <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">上传并导入</button>
                </div>
            </form>
        </div>
    </div>

    <div id="import-result"></div>

    <div class="mt-6 rounded-xl border border-slate-200/80 bg-white px-5 py-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">导入规则</p>
        <ul class="mt-2.5 space-y-1.5 text-sm text-slate-600">
            <li class="flex gap-2"><span class="text-indigo-500">·</span>支持 vless / vmess / trojan / ss 链接，自动识别整体 base64 订阅内容</li>
            <li class="flex gap-2"><span class="text-indigo-500">·</span>无法解析的行会跳过并列出原因，其余行正常导入</li>
            <li class="flex gap-2"><span class="text-indigo-500">·</span>地址与 SNI 不同的节点自动打上 CF 标记；非标准参数（pcs、insecure、pbk 等）完整保留</li>
        </ul>
    </div>`;

    const showResult = (data) => {
        const s = data.stats;
        document.getElementById('import-result').innerHTML = `
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4">
                <p class="text-sm font-medium text-emerald-800">${esc(data.message)}</p>
                ${s.errors?.length ? `<ul class="mt-2 space-y-1 text-xs text-red-600">${s.errors.map(e => `<li>${esc(e)}</li>`).join('')}</ul>` : ''}
            </div>`;
    };

    root.querySelector('#import-text').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            showResult(await importApi.nodes(e.target.content.value));
            e.target.reset();
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    root.querySelector('#import-file').addEventListener('submit', async (e) => {
        e.preventDefault();
        const file = e.target.file.files[0];
        if (!file) { toast('请先选择文件', 'error'); return; }
        try {
            const content = await readFileText(file);
            showResult(await importApi.nodes(content));
        } catch (err) {
            toast(err.message, 'error');
        }
    });
}
