// ==================== 在线优选（BestCF 同款流程） ====================
// ① IP 库导入：从本站代理拉取候选清单（IP / CIDR / IP 区间原始行）填入待选列表
// ② 开始优选：CIDR/区间随机展开取样 → 对每个 IP:端口 构造 hex 标签域名
//    https://{HEX|ipv6-label}.{best-host}:{port}/ip.json（SNI 钉住目标 IP），
//    GET 响应耗时即延迟，响应体含 colo（数据中心 IATA 码）、cnIspCode（用户侧运营商）、ipType；
//    机房国家由 colo 反查 locations 映射表；只有延迟 ≤ 超时值的 IP 进入结果列表（同 BestCF 的"超时即剔除"）
// ③ 达标入库：写回 IP 池（备注回填「运营商 · 国家 · 数据中心」，仅当原备注为空）

import { api, ipsApi } from '../api.js';
import { esc } from '../utils.js';

const OO_BEST_HOSTS = ['bestcf.cmliussss.hidns.vip', 'ns.psb.kdns.fr'];
const OO_DETECT_ENDPOINTS = {
    ipv4: [
        'https://671F04**.{{host}}', 'https://6CA2C0**.{{host}}', 'https://BC7260**.{{host}}',
        'https://681000**.{{host}}', 'https://681800**.{{host}}', 'https://AC4000**.{{host}}',
    ],
    ipv6: [
        'https://2606-4700--**.{{host}}', 'https://2606-4700--1000-**.{{host}}',
        'https://2606-4700--2000-**.{{host}}', 'https://2606-4700--3000-**.{{host}}',
    ],
};
const OO_RANDOM_PORTS = [443, 2053, 2083, 2087, 2096, 8443];
const OO_HOST_CACHE_KEY = 'NexusNode:best-host';
const OO_LOCATIONS_CACHE_KEY = 'NexusNode:locations';
const OO_LOCATIONS_TTL_MS = 7 * 86400 * 1000; // 地理映射本地缓存 7 天

const ooState = {
    running: false, stopped: false, results: [], controllers: new Set(),
    bestHost: '', locationsByIata: new Map(), cursor: 0,
};

// ---------- 工具函数（移植 cf.html） ----------
function ooExpandWildcard(ep) {
    return ep.replace(/\*\*/g, () => Math.floor(Math.random() * 255 + 1).toString(16).padStart(2, '0'));
}

function ooIsValidIpv4(ip) {
    const parts = ip.split('.');
    return parts.length === 4 && parts.every(p => /^\d{1,3}$/.test(p) && Number(p) <= 255);
}

function ooIpv6ToBigInt(ip) {
    if (!ip || /[^0-9a-fA-F:.]/.test(ip)) return null;
    if ((ip.match(/::/g) || []).length > 1) return null;
    const sides = ip.split('::');
    let left = sides[0] ? sides[0].split(':') : [];
    let right = sides.length === 2 && sides[1] ? sides[1].split(':') : [];
    if (sides.length === 1 && left.length !== 8) return null;
    if (sides.length === 2) {
        const fill = 8 - left.length - right.length;
        if (fill < 1) return null;
        left = [...left, ...Array(fill).fill('0'), ...right];
    }
    if (left.length !== 8) return null;
    let result = 0n;
    for (const group of left) {
        if (!/^[0-9a-fA-F]{1,4}$/.test(group)) return null;
        result = (result << 16n) + BigInt(parseInt(group, 16));
    }
    return result;
}

function ooBigIntToIpv6(value) {
    const groups = [];
    for (let i = 7; i >= 0; i--) groups.push(Number((value >> BigInt(i * 16)) & 0xffffn).toString(16));
    let bestStart = -1, bestLength = 0;
    for (let i = 0; i < 8;) {
        if (groups[i] !== '0') { i++; continue; }
        let end = i;
        while (end < 8 && groups[end] === '0') end++;
        if (end - i > bestLength && end - i > 1) { bestStart = i; bestLength = end - i; }
        i = end;
    }
    if (bestStart === -1) return groups.join(':');
    const before = groups.slice(0, bestStart).join(':');
    const after = groups.slice(bestStart + bestLength).join(':');
    if (!before && !after) return '::';
    if (!before) return `::${after}`;
    if (!after) return `${before}::`;
    return `${before}::${after}`;
}

function ooNormalizeIpv6(ip) {
    const v = ooIpv6ToBigInt(ip);
    return v === null ? null : ooBigIntToIpv6(v);
}

function ooIpv4ToHexLabel(ip) {
    return ip.split('.').map(p => Number(p).toString(16).padStart(2, '0')).join('').toUpperCase();
}

function ooParseAddressWithPort(address) {
    const m4 = address.match(/^(\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})$/);
    if (m4 && ooIsValidIpv4(m4[1])) {
        const port = Number(m4[2]);
        if (port >= 1 && port <= 65535) return { family: 'ipv4', ip: m4[1], port };
    }
    const m6 = address.match(/^\[([0-9a-fA-F:.]+)]:(\d{1,5})$/);
    if (m6) {
        const ip = ooNormalizeIpv6(m6[1]);
        const port = Number(m6[2]);
        if (ip && port >= 1 && port <= 65535) return { family: 'ipv6', ip, port };
    }
    return null;
}

function ooBuildProbeUrl(parsed, path, params = {}) {
    const label = parsed.family === 'ipv4'
        ? ooIpv4ToHexLabel(parsed.ip)
        : parsed.ip.toLowerCase().replace(/:/g, '-');
    const search = new URLSearchParams({ _t: String(Date.now()), ...params });
    return `https://${label}.${ooState.bestHost}:${parsed.port}/${path}?${search}`;
}

function ooFetchWithTimeout(url, { method = 'GET', timeout = 8000 } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    ooState.controllers.add(controller);
    if (ooState.stopped) controller.abort();
    return fetch(url, { method, cache: 'no-store', signal: controller.signal })
        .finally(() => { clearTimeout(timer); ooState.controllers.delete(controller); });
}

function ooRandomBigIntBelow(max) {
    if (max <= 1n) return 0n;
    const hex = max.toString(16);
    let value;
    do {
        value = BigInt('0x' + Array.from({ length: hex.length }, () => Math.floor(Math.random() * 16).toString(16)).join(''));
    } while (value >= max);
    return value;
}

// ---------- 候选展开：IP / CIDR / IP 区间 → IP:端口 列表（同 cf.html prepareCandidates） ----------
function ooCleanLine(line) {
    return line.replace(/：/g, ':').replace(/#.*/, '').replace(/[ \t]/g, '').trim();
}

function ooChoosePort(preferred) {
    return preferred > 0 ? preferred : OO_RANDOM_PORTS[Math.floor(Math.random() * OO_RANDOM_PORTS.length)];
}

function ooNormalizeAddress(line, preferredPort) {
    const fallback = ooChoosePort(preferredPort);
    const bracketed = line.match(/^\[([0-9a-fA-F:.]+)](?::?(\d{1,5}))?$/);
    if (bracketed) {
        const ip = ooNormalizeIpv6(bracketed[1]);
        if (!ip) return null;
        const port = bracketed[2] ? Number(bracketed[2]) : fallback;
        return `[${ip}]:${port}`;
    }
    const v4 = line.match(/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?$/);
    if (v4 && ooIsValidIpv4(v4[1])) return `${v4[1]}:${v4[2] ? Number(v4[2]) : fallback}`;
    const v6 = ooNormalizeIpv6(line);
    if (v6) return `[${v6}]:${fallback}`;
    return null;
}

function ooParseCidr(line) {
    const parts = line.split('/');
    if (parts.length !== 2) return null;
    const prefix = Number(parts[1]);
    if (!Number.isInteger(prefix)) return null;
    const mask = (bits, p) => p === 0 ? 0n : ((1n << BigInt(bits)) - 1n) ^ ((1n << BigInt(bits - p)) - 1n);
    if (parts[0].includes('.')) {
        if (!ooIsValidIpv4(parts[0]) || prefix < 0 || prefix > 32) return null;
        const value = parts[0].split('.').reduce((acc, p) => (acc << 8n) + BigInt(Number(p)), 0n);
        return { family: 'ipv4', bits: 32, network: value & mask(32, prefix), size: 1n << BigInt(32 - prefix) };
    }
    const value = ooIpv6ToBigInt(parts[0]);
    if (value === null || prefix < 0 || prefix > 128) return null;
    return { family: 'ipv6', bits: 128, network: value & mask(128, prefix), size: 1n << BigInt(128 - prefix) };
}

function ooParseIpRange(line) {
    const sep = line.indexOf('-');
    if (sep <= 0 || sep !== line.lastIndexOf('-')) return null;
    const unwrap = v => { const m = v.match(/^\[([0-9a-fA-F:.]+)]$/); return m ? m[1] : v; };
    const a = unwrap(line.slice(0, sep)), b = unwrap(line.slice(sep + 1));
    const v4a = ooIsValidIpv4(a) ? a.split('.').reduce((acc, p) => (acc << 8n) + BigInt(Number(p)), 0n) : null;
    const v4b = ooIsValidIpv4(b) ? b.split('.').reduce((acc, p) => (acc << 8n) + BigInt(Number(p)), 0n) : null;
    if (v4a !== null && v4b !== null && v4a <= v4b) return { family: 'ipv4', start: v4a, size: v4b - v4a + 1n };
    const v6a = ooIpv6ToBigInt(a), v6b = ooIpv6ToBigInt(b);
    if (v6a !== null && v6b !== null && v6a <= v6b) return { family: 'ipv6', start: v6a, size: v6b - v6a + 1n };
    return null;
}

function ooBigIntToIpv4(value) {
    return [24n, 16n, 8n, 0n].map(s => Number((value >> s) & 255n)).join('.');
}

function ooRandomAddress(entry, kind, preferredPort) {
    const value = (kind === 'cidr' ? entry.network : entry.start) + ooRandomBigIntBelow(entry.size);
    const port = ooChoosePort(preferredPort);
    return entry.family === 'ipv4' ? `${ooBigIntToIpv4(value)}:${port}` : `[${ooBigIntToIpv6(value)}]:${port}`;
}

function ooPrepareCandidates(rawText, limit, preferredPort) {
    const lines = rawText.split(/\r\n|\r|\n/).map(ooCleanLine).filter(Boolean);
    const fixed = [], cidrs = [], ranges = [];
    for (const line of lines) {
        if (line.includes('/')) { const c = ooParseCidr(line); if (c) cidrs.push(c); continue; }
        const r = ooParseIpRange(line);
        if (r) { ranges.push(r); continue; }
        const n = ooNormalizeAddress(line, preferredPort);
        if (n) fixed.push(n);
    }
    const shuffle = arr => arr.sort(() => Math.random() - 0.5);
    shuffle(fixed); shuffle(cidrs); shuffle(ranges);

    const candidates = [], seen = new Set();
    const add = a => { if (a && !seen.has(a) && candidates.length < limit) { seen.add(a); candidates.push(a); } };
    fixed.forEach(add);
    let guard = 0;
    while (candidates.length < limit && cidrs.length && guard++ < limit * 16) add(ooRandomAddress(cidrs[guard % cidrs.length], 'cidr', preferredPort));
    guard = 0;
    while (candidates.length < limit && ranges.length && guard++ < limit * 16) add(ooRandomAddress(ranges[guard % ranges.length], 'range', preferredPort));
    return candidates;
}

// ---------- best host 解析 + locations 加载 ----------
async function ooResolveBestHost() {
    try {
        const cached = localStorage.getItem(OO_HOST_CACHE_KEY);
        if (cached && OO_BEST_HOSTS.includes(cached)) { ooState.bestHost = cached; return true; }
    } catch (e) { /* 嵌入环境可能禁用 storage */ }

    for (const host of OO_BEST_HOSTS) {
        ooState.bestHost = host;
        const endpoints = ooExpandWildcard(OO_DETECT_ENDPOINTS.ipv4.map(ep => ep.replace('{{host}}', host)));
        try {
            await Promise.any(endpoints.map(ep => ooFetchWithTimeout(`${ep}/ip.json?_t=${Date.now()}`, { timeout: 6500 }).then(r => { if (r.status !== 200) throw new Error(); return r.json(); })));
            try { localStorage.setItem(OO_HOST_CACHE_KEY, host); } catch (e) { /* ignore */ }
            return true;
        } catch (e) { /* 换下一个 host */ }
    }
    ooState.bestHost = '';
    return false;
}

// locations（IATA → 机房国家映射）：打开弹窗时获取一次并缓存 localStorage（7 天），优选时直接使用。
// 数据源优先级：内存 → localStorage → 本站服务端代理 → 浏览器直连竞速（兜底）
async function ooLoadLocations() {
    if (ooState.locationsByIata.size) return true;

    try {
        const raw = localStorage.getItem(OO_LOCATIONS_CACHE_KEY);
        if (raw) {
            const cache = JSON.parse(raw);
            if (Date.now() - cache.savedAt < OO_LOCATIONS_TTL_MS && Array.isArray(cache.data) && ooBuildLocations(cache.data)) return true;
        }
    } catch (e) { /* storage 可能被禁用 */ }

    const persist = data => {
        try { localStorage.setItem(OO_LOCATIONS_CACHE_KEY, JSON.stringify({ savedAt: Date.now(), data })); } catch (e) { /* ignore */ }
    };

    // ② 本站服务端代理：同源请求必然可达，浏览器连不上探测域名时仍能拿到映射
    //    （走 api.get 带 Bearer 令牌——裸 fetch 不带头会被 ApiTokenAuth 拦成 401）
    try {
        const data = await api.get('/preferred-ips/online-locations');
        if (Array.isArray(data.locations) && ooBuildLocations(data.locations)) { persist(data.locations); return true; }
    } catch (e) { /* 服务器外网受限 → 浏览器直连兜底 */ }

    // ③ 浏览器直连兜底：多端点竞速
    if (!ooState.bestHost) return false;
    const endpoints = ooExpandWildcard(
        [...OO_DETECT_ENDPOINTS.ipv4, ...OO_DETECT_ENDPOINTS.ipv6].map(ep => ep.replace('{{host}}', ooState.bestHost))
    );
    try {
        const data = await Promise.any(endpoints.map(async ep => {
            const res = await ooFetchWithTimeout(`${ep}/locations?_t=${Date.now()}`, { timeout: 8000 });
            if (res.status !== 200) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            if (!Array.isArray(json)) throw new Error('locations 响应格式无效');
            return json;
        }));
        if (ooBuildLocations(data)) { persist(data); return true; }
    } catch (e) { /* 全失败：本轮显示未知，下次打开弹窗重试 */ }
    return false;
}

function ooBuildLocations(data) {
    ooState.locationsByIata = new Map(
        data.filter(i => i && i.iata).map(i => [String(i.iata).toUpperCase(), i])
    );
    if (ooState.results.length) ooRerender();
    return ooState.locationsByIata.size > 0;
}

function ooCountryFromColo(colo) {
    if (!colo) return '未知';
    const match = ooState.locationsByIata.get(String(colo).toUpperCase());
    return match && match.cca2 ? match.cca2 : '未知';
}

// ---------- 单个地址探测（同 cf.html testLatency：OPTIONS 连通性 → GET ip.json 计时） ----------
// ip.json 的 country 是「用户出口」所在国家（国内环境恒为 CN）；
// 机房所在国家必须用 colo（边缘节点 IATA 码）反查 locations 映射表。
// cnIspCode 是用户侧运营商识别，代表本次优选结果是在谁的线路下测得的。
async function ooTestAddress(address, timeout) {
    const parsed = ooParseAddressWithPort(address);
    if (!parsed) return null;
    const url = ooBuildProbeUrl(parsed, 'ip.json');

    let connected = false;
    for (let attempt = 0; attempt < 3 && !connected && !ooState.stopped; attempt++) {
        try {
            const r = await ooFetchWithTimeout(url, { method: 'OPTIONS', timeout: timeout * 2 });
            connected = r.ok || r.status > 0; // no-cors 语义下拿到响应即视为可达
        } catch (e) { /* 重试 */ }
    }
    if (!connected) return null;

    const started = performance.now();
    const response = await ooFetchWithTimeout(url, { method: 'GET', timeout });
    if (response.status !== 200) throw new Error('GET 不可用');
    const data = await response.json();
    const latency = Math.max(1, Math.round(performance.now() - started));
    if (latency > timeout) return null; // 超时阈值之外的结果直接不显示（BestCF 行为）

    const colo = data.colo || '';
    return {
        address,
        ip: parsed.family === 'ipv4' ? parsed.ip : `[${parsed.ip}]`,
        ipType: data.ipType === 'ipv6' ? 'IPv6' : 'IPv4',
        colo: colo || '—',
        isp: ooIspFromCode(data.cnIspCode),
        latency,
    };
}

function ooResultCountry(r) {
    return r.colo && r.colo !== '—' ? ooCountryFromColo(r.colo) : '未知';
}

function ooIspFromCode(code) {
    const map = { ct: '电信', cu: '联通', cmcc: '移动' };
    return map[String(code || '').trim().toLowerCase()] || '其他';
}

// ---------- 渲染 ----------
function ooLatencyColor(ms, timeout) {
    return ms < timeout / 3 ? 'text-emerald-600' : (ms < timeout * 2 / 3 ? 'text-amber-600' : 'text-slate-500');
}

function ooRerender() {
    const tbody = document.getElementById('oo-results');
    const sorted = [...ooState.results].sort((a, b) => a.latency - b.latency);
    document.getElementById('oo-placeholder')?.remove();
    tbody.innerHTML = sorted.map((r, i) => `<tr class="border-b border-slate-50">
        <td class="py-2 pr-4 text-xs text-slate-400">${i + 1}</td>
        <td class="py-2 pr-4 font-mono text-xs font-medium text-slate-800">${esc(r.address)}</td>
        <td class="py-2 pr-4 text-xs text-slate-600">${esc(r.ipType)}</td>
        <td class="py-2 pr-4 text-xs"><span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-medium text-indigo-600 ring-1 ring-inset ring-indigo-100">${esc(r.isp)}</span></td>
        <td class="py-2 pr-4 text-xs text-slate-600">${esc(ooResultCountry(r))}</td>
        <td class="py-2 pr-4 text-xs font-mono text-slate-600">${esc(r.colo)}</td>
        <td class="py-2 font-mono text-xs font-semibold ${ooLatencyColor(r.latency, +document.getElementById('oo-timeout').value || 500)}">${r.latency} ms</td>
    </tr>`).join('') || `<tr><td colspan="7" class="py-10 text-center text-sm text-slate-400">暂无达标 IP（延迟 ≤ 超时阈值）</td></tr>`;
    document.getElementById('oo-result-count').textContent = `${sorted.length} 个可用`;
}

function ooSetProgress(done, total, statusText) {
    document.getElementById('oo-progress-wrap').classList.remove('hidden');
    document.getElementById('oo-progress-text').textContent = `${done} / ${total}`;
    document.getElementById('oo-progress-bar').style.width = total ? Math.round(done / total * 100) + '%' : '0%';
    if (statusText) document.getElementById('oo-progress-status').textContent = statusText;
}

function ooUpdateEditorCount() {
    const value = document.getElementById('oo-editor').value;
    document.getElementById('oo-line-count').textContent = `${value.length ? value.split(/\r\n|\r|\n/).length : 0} 行`;
}

function ooClearEditor() {
    document.getElementById('oo-editor').value = '';
    ooUpdateEditorCount();
}

// ---------- ① IP 库导入 ----------
async function ooImportLibrary() {
    if (ooState.running) return;
    const pool = document.getElementById('oo-pool').value;
    const btn = document.getElementById('oo-import');
    const status = document.getElementById('oo-import-status');
    btn.disabled = true;
    btn.textContent = '导入中…';
    status.textContent = '';
    try {
        const data = await ipsApi.pool(pool);
        document.getElementById('oo-editor').value = (data.lines || []).join('\n');
        ooUpdateEditorCount();
        status.textContent = `已导入「${data.name}」 ${(data.lines || []).length} 行`;
    } catch (e) {
        status.textContent = `导入失败：${e.message}`;
    } finally {
        btn.disabled = false;
        btn.textContent = '⬇️ IP 库导入';
    }
}

// ---------- ② 开始优选 ----------
async function ooStart() {
    if (ooState.running) return;
    const timeout = Math.max(100, Math.min(10000, +document.getElementById('oo-timeout').value || 500));
    const limit = Math.max(1, Math.min(2000, +document.getElementById('oo-limit').value || 128));
    const concurrency = Math.max(1, Math.min(32, +document.getElementById('oo-concurrency').value || 16));
    const port = Math.max(0, +document.getElementById('oo-port').value || 0);

    const candidates = ooPrepareCandidates(document.getElementById('oo-editor').value, limit, port);
    if (!candidates.length) {
        alert('待选列表为空或格式不符合要求，请先「IP 库导入」或手动粘贴 IP / CIDR');
        return;
    }

    // 解析优选探测域名；locations 若尚未就绪则并行补拉（失败不影响测速）
    if (!ooState.bestHost) {
        ooSetProgress(0, 0, '解析探测域名…');
        document.getElementById('oo-progress-wrap').classList.remove('hidden');
        if (!await ooResolveBestHost()) {
            alert('探测域名不可达：当前网络可能不在 CN 直连环境或无法访问 HiDNS 优选域名，无法进行在线优选。');
            return;
        }
    }
    if (!ooState.locationsByIata.size) ooLoadLocations().catch(() => {});

    ooState.running = true;
    ooState.stopped = false;
    ooState.results = [];
    ooState.cursor = 0;
    document.getElementById('oo-editor').value = candidates.join('\n');
    ooUpdateEditorCount();
    document.getElementById('oo-start').classList.add('hidden');
    document.getElementById('oo-stop').classList.remove('hidden');
    document.getElementById('oo-footer').classList.add('hidden');
    document.getElementById('oo-footer').classList.remove('flex');
    document.getElementById('oo-results').innerHTML = '';
    ooSetProgress(0, candidates.length, `正在优选 ${candidates.length} 个地址`);

    let done = 0, lastPaint = 0;
    const paint = force => {
        const now = performance.now();
        if (force || now - lastPaint > 300) { ooRerender(); lastPaint = now; ooSetProgress(done, candidates.length, ooState.stopped ? '已停止' : '优选中…'); }
    };

    const worker = async () => {
        while (ooState.cursor < candidates.length && !ooState.stopped) {
            const address = candidates[ooState.cursor++];
            try {
                const result = await ooTestAddress(address, timeout);
                if (result && !ooState.stopped) ooState.results.push(result);
            } catch (e) { /* 不可达地址静默剔除 */ }
            done++;
            paint(false);
        }
    };
    await Promise.all(Array.from({ length: Math.min(concurrency, candidates.length) }, worker));

    paint(true);
    ooState.running = false;
    for (const c of ooState.controllers) c.abort();
    ooState.controllers.clear();
    document.getElementById('oo-stop').classList.add('hidden');
    document.getElementById('oo-start').classList.remove('hidden');
    ooSetProgress(done, candidates.length, ooState.stopped ? `已停止，保留 ${ooState.results.length} 个结果` : `优选完成，${ooState.results.length} 个可用`);

    if (ooState.results.length) {
        document.getElementById('oo-footer').classList.remove('hidden');
        document.getElementById('oo-footer').classList.add('flex');
        ooUpdateQualified();
    }
}

function ooStop() {
    if (ooState.running) ooState.stopped = true;
    for (const c of ooState.controllers) c.abort();
}

// ---------- ③ 达标入库 ----------
function ooUpdateQualified() {
    const threshold = +document.getElementById('oo-threshold').value || 300;
    const limit = +document.getElementById('oo-save-limit').value || 20;
    const n = ooState.results.filter(r => r.latency <= threshold).length;
    document.getElementById('oo-qualified').textContent = `（达标 ${n} 个，将入库 ${Math.min(n, limit)} 个）`;
}

async function ooSave() {
    const threshold = +document.getElementById('oo-threshold').value || 300;
    const limit = +document.getElementById('oo-save-limit').value || 20;
    const entries = ooState.results
        .filter(r => r.latency <= threshold)
        .sort((a, b) => a.latency - b.latency)
        .slice(0, limit)
        .map(r => {
            // 备注 = 运营商 · 机房国家 · 数据中心（跳过缺失项），例：电信 · HK · HKG
            const country = ooResultCountry(r);
            const parts = [r.isp, country !== '未知' ? country : null, r.colo !== '—' ? r.colo : null].filter(Boolean);
            return {
                ip: r.ip,
                latency_ms: r.latency,
                remarks: parts.length ? parts.join(' · ') : null,
            };
        });
    if (!entries.length) { alert('没有达标的 IP'); return; }

    const btn = document.getElementById('oo-save');
    btn.disabled = true; btn.textContent = '入库中…';
    try {
        const data = await ipsApi.latencyBatch(entries);
        // 异步刷新下方表格数据（不整页刷新，保留弹窗与页面状态）
        const { refreshTable } = await import('./preferredIps.js');
        if (typeof refreshTable === 'function') await refreshTable();
        alert(`已入库 ${data.saved} 个 IP（含延迟与地区备注），可直接到「优选生成」使用`);
    } catch (e) {
        alert('入库失败：' + e.message);
        btn.disabled = false; btn.textContent = '达标入库';
    }
}

// ---------- 弹窗 ----------
export function openOnlineOptimize() {
    let modal = document.getElementById('oo-modal');
    if (!modal) {
        document.body.insertAdjacentHTML('beforeend', ooModalHtml());
        bindOoEvents();
        modal = document.getElementById('oo-modal');
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
    if (!ooState.bestHost) ooResolveBestHost().catch(() => {});
    ooLoadLocations().catch(() => {});
}

function closeOnlineOptimize() {
    if (ooState.running && !confirm('优选正在进行，确定关闭？')) return;
    ooStop();
    const modal = document.getElementById('oo-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

function ooModalHtml() {
    return `
    <div id="oo-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
        <div class="mx-auto flex h-[calc(100vh-2rem)] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <h2 class="text-base font-semibold text-slate-900">⚡ 浏览器测速优选</h2>
                <button type="button" data-oo-close class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
            </div>
            <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 bg-slate-50/60 px-6 py-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">候选 IP 库</label>
                    <select id="oo-pool" class="rounded-lg border-slate-200 text-sm shadow-sm">
                        <option value="cf-v4">CF官方列表v4</option>
                        <option value="cf-v6">CF官方列表v6</option>
                        <option value="cm-v4">CM优选列表v4</option>
                        <option value="as13335-v4">AS13335列表v4</option>
                        <option value="as13335-v6">AS13335列表v6</option>
                        <option value="as209242-v4">AS209242列表v4</option>
                        <option value="as209242-v6">AS209242列表v6</option>
                        <option value="local">当前 IP 池</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">优选端口</label>
                    <select id="oo-port" class="rounded-lg border-slate-200 text-sm shadow-sm">
                        ${[443, 2053, 2083, 2087, 2096, 8443].map(p => `<option value="${p}" ${p === 443 ? 'selected' : ''}>${p}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">待选数量上限</label>
                    <input type="number" id="oo-limit" value="128" min="1" max="2000" class="w-20 rounded-lg border-slate-200 text-sm shadow-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">并发</label>
                    <input type="number" id="oo-concurrency" value="16" min="1" max="32" class="w-16 rounded-lg border-slate-200 text-sm shadow-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">超时 ms</label>
                    <input type="number" id="oo-timeout" value="500" min="100" max="10000" step="100" class="w-20 rounded-lg border-slate-200 text-sm shadow-sm">
                </div>
                <div class="flex items-center gap-2 pb-0.5">
                    <button type="button" id="oo-import" class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-indigo-50">⬇️ IP 库导入</button>
                    <button type="button" id="oo-clear" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50">清空</button>
                    <button type="button" id="oo-start" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:bg-indigo-500">开始优选</button>
                    <button type="button" id="oo-stop" class="hidden rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">停止</button>
                </div>
                <p id="oo-import-status" class="w-full text-xs text-slate-500"></p>
            </div>
            {{-- 待选列表编辑器：IP 库导入 / 手动粘贴的落点，CIDR 区间在开始优选时随机展开 --}}
            <div class="border-b border-slate-100 px-6 py-3">
                <p class="mb-1.5 text-xs font-medium text-slate-500">待选列表（支持 <code class="rounded bg-slate-100 px-1 font-mono">IP</code> / <code class="rounded bg-slate-100 px-1 font-mono">IP:端口</code> / <code class="rounded bg-slate-100 px-1 font-mono">[IPv6]:端口</code> / <code class="rounded bg-slate-100 px-1 font-mono">CIDR</code> / <code class="rounded bg-slate-100 px-1 font-mono">IP区间</code>，每行一个）</p>
                <textarea id="oo-editor" rows="6" spellcheck="false"
                          placeholder="选择 IP 库后点击「IP 库导入」自动填充，也可手动粘贴。示例：&#10;104.16.1.1&#10;104.16.2.2:8443&#10;[2606:4700::]:443&#10;103.22.200.0/22&#10;162.159.152.0-162.159.153.255"
                          class="w-full rounded-lg border-slate-200 font-mono text-xs leading-5 shadow-sm focus:border-indigo-400 focus:ring-indigo-100"></textarea>
                <div class="mt-1 flex items-center justify-between">
                    <span id="oo-line-count" class="text-xs text-slate-400">0 行</span>
                    <span class="text-xs text-slate-400">仅显示延迟 ≤ 超时阈值的可用 IP</span>
                </div>
            </div>
            <div id="oo-progress-wrap" class="hidden px-6 pt-4">
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span>进度：<b id="oo-progress-text" class="text-slate-800">0 / 0</b></span>
                    <span id="oo-progress-status"></span>
                </div>
                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div id="oo-progress-bar" class="h-full rounded-full bg-indigo-500 transition-all" style="width:0%"></div>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">优选结果</h3>
                    <span id="oo-result-count" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">0 个可用</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white">
                        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="py-2.5 pr-4 font-medium">#</th>
                            <th class="py-2.5 pr-4 font-medium">IP:端口</th>
                            <th class="py-2.5 pr-4 font-medium">类型</th>
                            <th class="py-2.5 pr-4 font-medium">运营商</th>
                            <th class="py-2.5 pr-4 font-medium">国家</th>
                            <th class="py-2.5 pr-4 font-medium">数据中心</th>
                            <th class="py-2.5 font-medium">延迟</th>
                        </tr>
                    </thead>
                    <tbody id="oo-results">
                        <tr id="oo-placeholder"><td colspan="7" class="py-10 text-center text-sm text-slate-400">先导入候选，再点击开始优选</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="oo-footer" class="hidden items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-3">
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span>入库条件：延迟 ≤</span>
                    <input type="number" id="oo-threshold" value="500" min="20" step="20" class="w-20 rounded-lg border-slate-200 py-1 text-sm shadow-sm">
                    <span>ms，取前</span>
                    <input type="number" id="oo-save-limit" value="20" min="1" max="200" class="w-16 rounded-lg border-slate-200 py-1 text-sm shadow-sm">
                    <span>个</span>
                    <span id="oo-qualified" class="font-medium text-slate-700"></span>
                </div>
                <button type="button" id="oo-save" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-emerald-600/20 transition hover:bg-emerald-500">达标入库</button>
            </div>
            <p class="px-6 pb-3 text-[11px] text-slate-400">© 在线优选基于 <a href="https://github.com/cmliu/edgetunnel" target="_blank" rel="noopener" class="underline hover:text-slate-600">cmliu/edgetunnel</a> 与 BestCF 的思路实现 · 感谢开源社区与巨人的肩膀</p>
        </div>
    </div>`;
}

function bindOoEvents() {
    document.getElementById('oo-modal').addEventListener('click', (e) => {
        if (e.target.id === 'oo-modal') closeOnlineOptimize();
        if (e.target.closest('[data-oo-close]')) closeOnlineOptimize();
    });
    document.getElementById('oo-import').addEventListener('click', ooImportLibrary);
    document.getElementById('oo-clear').addEventListener('click', ooClearEditor);
    document.getElementById('oo-start').addEventListener('click', ooStart);
    document.getElementById('oo-stop').addEventListener('click', ooStop);
    document.getElementById('oo-editor').addEventListener('input', ooUpdateEditorCount);
    document.getElementById('oo-threshold').addEventListener('input', ooUpdateQualified);
    document.getElementById('oo-save-limit').addEventListener('input', ooUpdateQualified);
}
