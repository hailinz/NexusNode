// ==================== 在线优选（BestCF 同款流程） ====================
// ① IP 库导入：从本站代理拉取候选清单（IP / CIDR / IP 区间原始行）填入待选列表
// ② 开始优选：CIDR/区间随机展开取样 → 对每个 IP:端口 构造 hex 标签域名
//    https://{HEX|ipv6-label}.{best-host}:{port}/cdn-cgi/trace（DNS 解析到目标 IP，由 CF 边缘直接响应）：
//    先建连（冷连接含 DNS+TCP+TLS，宽松超时），再在同一连接上采样 OO_SAMPLES 次：
//    延迟 = 最低值（线路基础 RTT，可重复），丢包 = 超时或重传毛刺的占比（反映当前线路质量）。
//    trace 响应含 colo（数据中心 IATA 码），机房国家由 colo 反查 locations；
//    用户侧运营商（cnIspCode）对所有 IP 相同，解析探测域名时从 ip.json 取一次
// ③ 达标入库：写回 IP 池（备注回填「运营商 · 国家 · 数据中心」，仅当原备注为空）

import { api, ipsApi } from '../api.js';
import { esc, toast } from '../utils.js';

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
// 同连接采样：链路丢包会触发 TCP 重传，单次采样出现 +200 / +600 / +1400ms 的指数退避毛刺
// （实测 CN→CF 晚高峰约 25~40% 的请求，与 HTTP/1.1、HTTP/2、QUIC 无关）。
// 5 次取最低值作延迟，只有 5 次全部重传才会偏高（<1%），两轮测速结果可重复；
// 比最低值高出 OO_SPIKE_MS 以上即视为重传（TCP 重传超时下限 200ms，正常抖动远小于此）
const OO_SAMPLES = 5;
const OO_SPIKE_MS = 150;

const ooState = {
    running: false, stopped: false, results: [], controllers: new Set(),
    bestHost: '', hostPromise: null, isp: '', locationsByIata: new Map(), cursor: 0,
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

function ooFetchWithTimeout(url, { timeout = 8000 } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    ooState.controllers.add(controller);
    return fetch(url, { cache: 'no-store', signal: controller.signal })
        .finally(() => { clearTimeout(timer); ooState.controllers.delete(controller); });
}

// 单次 GET 计时：优先取 Resource Timing 的网络层耗时（不含主线程排队），取不到时退回墙钟。
// 条目名是规范化 URL（主机名小写、默认端口 :443 去掉），须用 new URL().href 匹配。
// 每个 IP 约 1 + 采样数个请求，默认 250 条的缓冲区很快写满：满了就清空（个别条目被清掉时退回墙钟）
performance.addEventListener('resourcetimingbufferfull', () => performance.clearResourceTimings());

async function ooTimedGet(url, timeout) {
    const started = performance.now();
    try {
        const res = await ooFetchWithTimeout(url, { timeout });
        if (res.status !== 200) return null;
        const text = await res.text();
        const wall = performance.now() - started;
        const entry = performance.getEntriesByName(new URL(url).href).pop();
        return { ms: Math.max(1, Math.round(entry?.duration || wall)), text };
    } catch (e) {
        return null;
    }
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
// 上次可用的 host 优先验证；验证用的 ip.json 同时带回用户侧运营商（对本轮所有 IP 相同）
async function ooResolveBestHost() {
    let cached = '';
    try { cached = localStorage.getItem(OO_HOST_CACHE_KEY) || ''; } catch (e) { /* 嵌入环境可能禁用 storage */ }
    const hosts = OO_BEST_HOSTS.includes(cached) ? [cached, ...OO_BEST_HOSTS.filter(h => h !== cached)] : OO_BEST_HOSTS;

    for (const host of hosts) {
        const endpoints = OO_DETECT_ENDPOINTS.ipv4.map(ep => ooExpandWildcard(ep.replace('{{host}}', host)));
        try {
            const data = await Promise.any(endpoints.map(ep => ooFetchWithTimeout(`${ep}/ip.json?_t=${Date.now()}`, { timeout: 6500 }).then(r => { if (r.status !== 200) throw new Error(); return r.json(); })));
            ooState.bestHost = host;
            ooState.isp = ooIspFromCode(data.cnIspCode);
            try { localStorage.setItem(OO_HOST_CACHE_KEY, host); } catch (e) { /* ignore */ }
            return true;
        } catch (e) { /* 换下一个 host */ }
    }
    return false;
}

// 并发调用（打开弹窗预解析 + 点击开始）共用同一次解析；失败后允许下次重试
async function ooEnsureHost() {
    ooState.hostPromise ||= ooResolveBestHost().then(ok => { if (!ok) ooState.hostPromise = null; return ok; });
    return ooState.hostPromise;
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
    const endpoints = [...OO_DETECT_ENDPOINTS.ipv4, ...OO_DETECT_ENDPOINTS.ipv6]
        .map(ep => ooExpandWildcard(ep.replace('{{host}}', ooState.bestHost)));
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

// ---------- 单个地址探测：建连 → 同连接多次采样（延迟取最低值，统计丢包） ----------
// 返回 { status: 'ok' | 'slow' | 'fail', result? }
//  - fail：建连失败（不可达 / 被阻断 / 非 CF 边缘）
//  - slow：可达，但所有采样都超过超时阈值
// 冷连接耗时 = DNS（每个 hex 标签都是新域名，实测 0.2~1.4s）+ TCP + TLS + 首个请求，实测 0.8~2.2s，
// 不能拿来当延迟，也不能用延迟阈值卡它，否则会把「建连慢但线路好」的 IP 误判为不可达。
async function ooMeasure(parsed, timeout, rounds, isStopped) {
    const warm = await ooTimedGet(ooBuildProbeUrl(parsed, 'cdn-cgi/trace'), timeout * 2 + 3000);
    if (!warm) return { status: 'fail' };

    const samples = [];
    for (let i = 0; i < rounds && !isStopped(); i++) {
        const r = await ooTimedGet(ooBuildProbeUrl(parsed, 'cdn-cgi/trace'), timeout);
        samples.push(r && r.ms <= timeout ? r.ms : null);
    }
    const ok = samples.filter(v => v !== null);
    if (!ok.length) return { status: 'slow' };
    const latency = Math.min(...ok);
    const lost = samples.filter(v => v === null || v - latency > OO_SPIKE_MS).length;

    return {
        status: 'ok',
        colo: (warm.text.match(/^colo=(\w+)/m) || [])[1] || '—',
        latency,
        loss: Math.round(lost / samples.length * 100),
        samples,
    };
}

async function ooTestAddress(address, timeout) {
    const parsed = ooParseAddressWithPort(address);
    if (!parsed) return { status: 'fail' };
    const { status, colo, latency, loss, samples } = await ooMeasure(parsed, timeout, OO_SAMPLES, () => ooState.stopped);
    if (status !== 'ok') return { status };
    // 入库用裸 IP（IPv6 不带方括号，与 IP 池存储格式一致）
    return { status, result: { address, ip: parsed.ip, colo, latency, loss, samples } };
}

// IP 池列表测速复用同一套探测（固定 443 端口），保证与弹窗优选入库的延迟 / 丢包同口径。
// 返回 { latency_ms, loss_rate }（不可达 / 全部超时：latency_ms = null、loss_rate = 100）；
// 探测域名不可用时返回 null，由调用方降级为旧的直连探测。
export async function measureIp(ip, rounds, timeout, isStopped) {
    if (!await ooEnsureHost()) return null;
    const parsed = ooParseAddressWithPort(ip.includes(':') ? `[${ip}]:443` : `${ip}:443`);
    if (!parsed) return null;
    const m = await ooMeasure(parsed, timeout, rounds, isStopped);
    return m.status === 'ok' ? { latency_ms: m.latency, loss_rate: m.loss } : { latency_ms: null, loss_rate: 100 };
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

// 延迟升序，同延迟丢包少的在前
function ooCompare(a, b) {
    return a.latency - b.latency || a.loss - b.loss;
}

function ooLossColor(loss) {
    return loss === 0 ? 'text-emerald-600' : (loss <= 40 ? 'text-amber-600' : 'text-red-500');
}

// 运营商对本轮所有 IP 相同，只在结果标题旁显示一次；
// 每行展示机房、延迟（最低值）、丢包与各次采样（橙色 = 重传毛刺，× = 超时）
function ooRerender() {
    const tbody = document.getElementById('oo-results');
    const timeout = +document.getElementById('oo-timeout').value || 500;
    const sorted = [...ooState.results].sort(ooCompare);
    tbody.innerHTML = sorted.map((r, i) => {
        const country = ooResultCountry(r);
        const samples = r.samples.map(v => v === null
            ? '<span class="text-red-400">×</span>'
            : (v - r.latency > OO_SPIKE_MS ? `<span class="text-amber-500">${v}</span>` : v)
        ).join('<span class="text-slate-300"> / </span>');
        return `<tr class="border-b border-slate-50">
        <td class="py-2 pr-4 text-xs text-slate-400">${i + 1}</td>
        <td class="py-2 pr-4 font-mono text-xs font-medium text-slate-800 break-all">${esc(r.address)}</td>
        <td class="py-2 pr-4 text-xs text-slate-600"><span class="font-mono">${esc(r.colo)}</span>${country !== '未知' ? ` <span class="text-slate-400">· ${esc(country)}</span>` : ''}</td>
        <td class="py-2 pr-4 font-mono text-xs font-semibold ${ooLatencyColor(r.latency, timeout)}">${r.latency} ms</td>
        <td class="py-2 pr-4 font-mono text-xs ${ooLossColor(r.loss)}">${r.loss}%</td>
        <td class="py-2 font-mono text-[11px] text-slate-400">${samples}</td>
    </tr>`;
    }).join('') || `<tr><td colspan="6" class="py-10 text-center text-sm text-slate-400">${ooState.running ? '测速中…' : '暂无可用 IP'}</td></tr>`;
    document.getElementById('oo-result-count').textContent = `${sorted.length} 个可用`;
    document.getElementById('oo-isp').textContent = ooState.isp ? `测速线路：${ooState.isp}` : '';
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

    // 先进入运行态（隐藏开始按钮），避免解析探测域名期间重复点击启动两轮
    ooState.running = true;
    ooState.stopped = false;
    ooState.results = [];
    ooState.cursor = 0;
    const toggleButtons = running => {
        document.getElementById('oo-start').classList.toggle('hidden', running);
        document.getElementById('oo-stop').classList.toggle('hidden', !running);
    };
    toggleButtons(true);
    document.getElementById('oo-footer').classList.add('hidden');
    document.getElementById('oo-footer').classList.remove('flex');
    ooRerender();

    // 解析优选探测域名；locations 若尚未就绪则并行补拉（失败不影响测速）
    if (!ooState.bestHost) {
        ooSetProgress(0, 0, '解析探测域名…');
        if (!await ooEnsureHost()) {
            ooState.running = false;
            toggleButtons(false);
            if (ooState.stopped) { ooSetProgress(0, 0, '已停止'); return; }
            ooSetProgress(0, 0, '探测域名不可达');
            alert('探测域名不可达：当前网络可能不在 CN 直连环境或无法访问 HiDNS 优选域名，无法进行在线优选。');
            return;
        }
    }
    if (!ooState.locationsByIata.size) ooLoadLocations().catch(() => {});

    // 待选列表保留原始 CIDR / 区间，每轮重新随机取样；本轮实际展开的数量显示在进度里
    const stats = { ok: 0, slow: 0, fail: 0 };
    const summary = () => `候选 ${candidates.length} · 可用 ${stats.ok} · 超标 ${stats.slow} · 不可达 ${stats.fail}`;
    let done = 0, lastPaint = 0;
    const paint = force => {
        const now = performance.now();
        if (force || now - lastPaint > 300) { ooRerender(); lastPaint = now; ooSetProgress(done, candidates.length, summary()); }
    };
    paint(true);

    const worker = async () => {
        while (ooState.cursor < candidates.length && !ooState.stopped) {
            const address = candidates[ooState.cursor++];
            const { status, result } = await ooTestAddress(address, timeout);
            if (ooState.stopped) break;
            stats[status]++;
            if (result) ooState.results.push(result);
            done++;
            paint(false);
        }
    };
    await Promise.all(Array.from({ length: Math.min(concurrency, candidates.length) }, worker));

    ooState.running = false;
    paint(true);
    for (const c of ooState.controllers) c.abort();
    ooState.controllers.clear();
    toggleButtons(false);
    ooSetProgress(done, candidates.length, (ooState.stopped ? '已停止 · ' : '优选完成 · ') + summary());

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
// 达标 = 延迟 ≤ 入库阈值 且 丢包 ≤ 上限；IP 池只存 IP（不含端口），同一 IP 多端口只保留最优的一条
function ooQualified() {
    const threshold = +document.getElementById('oo-threshold').value || 300;
    const maxLoss = +document.getElementById('oo-max-loss').value;
    const limit = +document.getElementById('oo-save-limit').value || 20;
    const best = new Map();
    for (const r of ooState.results) {
        if (r.latency > threshold || r.loss > maxLoss) continue;
        const prev = best.get(r.ip);
        if (!prev || ooCompare(r, prev) < 0) best.set(r.ip, r);
    }
    const all = [...best.values()].sort(ooCompare);
    return { all, picked: all.slice(0, limit) };
}

// 调整入库条件 → 刷新计数，并允许再次入库
function ooUpdateQualified() {
    const { all, picked } = ooQualified();
    document.getElementById('oo-qualified').textContent = `（达标 ${all.length} 个，将入库 ${picked.length} 个）`;
    const btn = document.getElementById('oo-save');
    btn.disabled = false;
    btn.textContent = '达标入库';
}

async function ooSave() {
    const entries = ooQualified().picked.map(r => {
        // 备注 = 运营商 · 机房国家 · 数据中心（跳过缺失项），例：电信 · HK · HKG
        const country = ooResultCountry(r);
        const parts = [ooState.isp, country !== '未知' ? country : null, r.colo !== '—' ? r.colo : null].filter(Boolean);
        return {
            ip: r.ip,
            latency_ms: r.latency,
            loss_rate: r.loss,
            remarks: parts.length ? parts.join(' · ') : null,
        };
    });
    if (!entries.length) { toast('没有达标的 IP，可放宽延迟或丢包条件', 'error'); return; }

    const btn = document.getElementById('oo-save');
    btn.disabled = true; btn.textContent = '入库中…';
    try {
        const data = await ipsApi.latencyBatch(entries);
        btn.textContent = '✓ 已入库';
        toast(`已入库 ${data.saved} 个 IP（含延迟与地区备注），可直接到「优选生成」使用`);
        // 刷新弹窗下方的 IP 池表格（动态导入避免与 preferredIps.js 循环依赖）
        const { refreshTable } = await import('./preferredIps.js');
        await refreshTable();
    } catch (e) {
        toast('入库失败：' + e.message, 'error');
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
    ooEnsureHost().catch(() => {});
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
            <div class="border-b border-slate-100 px-6 py-3">
                <p class="mb-1.5 text-xs font-medium text-slate-500">待选列表（支持 <code class="rounded bg-slate-100 px-1 font-mono">IP</code> / <code class="rounded bg-slate-100 px-1 font-mono">IP:端口</code> / <code class="rounded bg-slate-100 px-1 font-mono">[IPv6]:端口</code> / <code class="rounded bg-slate-100 px-1 font-mono">CIDR</code> / <code class="rounded bg-slate-100 px-1 font-mono">IP区间</code>，每行一个）</p>
                <textarea id="oo-editor" rows="6" spellcheck="false"
                          placeholder="选择 IP 库后点击「IP 库导入」自动填充，也可手动粘贴。示例：&#10;104.16.1.1&#10;104.16.2.2:8443&#10;[2606:4700::]:443&#10;103.22.200.0/22&#10;162.159.152.0-162.159.153.255"
                          class="w-full rounded-lg border-slate-200 font-mono text-xs leading-5 shadow-sm focus:border-indigo-400 focus:ring-indigo-100"></textarea>
                <div class="mt-1 flex items-center justify-between">
                    <span id="oo-line-count" class="text-xs text-slate-400">0 行</span>
                    <span class="text-xs text-slate-400">每个 IP 建连后采样 ${OO_SAMPLES} 次：延迟取最低值，超时或重传毛刺计丢包；CIDR / 区间每轮重新随机取样</span>
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
                    <div class="flex items-center gap-2">
                        <span id="oo-isp" class="text-xs text-slate-400"></span>
                        <span id="oo-result-count" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">0 个可用</span>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white">
                        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="py-2.5 pr-4 font-medium">#</th>
                            <th class="py-2.5 pr-4 font-medium">IP:端口</th>
                            <th class="py-2.5 pr-4 font-medium">数据中心</th>
                            <th class="py-2.5 pr-4 font-medium">延迟</th>
                            <th class="py-2.5 pr-4 font-medium">丢包</th>
                            <th class="py-2.5 font-medium">采样 ms</th>
                        </tr>
                    </thead>
                    <tbody id="oo-results">
                        <tr><td colspan="6" class="py-10 text-center text-sm text-slate-400">先导入候选，再点击开始优选</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="oo-footer" class="hidden items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-3">
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span>入库条件：延迟 ≤</span>
                    <input type="number" id="oo-threshold" value="500" min="20" step="20" class="w-20 rounded-lg border-slate-200 py-1 text-sm shadow-sm">
                    <span>ms，丢包 ≤</span>
                    <input type="number" id="oo-max-loss" value="40" min="0" max="100" step="20" class="w-16 rounded-lg border-slate-200 py-1 text-sm shadow-sm">
                    <span>%，取前</span>
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
    document.getElementById('oo-save').addEventListener('click', ooSave);
    document.getElementById('oo-threshold').addEventListener('input', ooUpdateQualified);
    document.getElementById('oo-max-loss').addEventListener('input', ooUpdateQualified);
    document.getElementById('oo-save-limit').addEventListener('input', ooUpdateQualified);
}
