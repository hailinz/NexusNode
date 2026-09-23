// ==================== API 客户端 ====================
// Bearer 令牌认证；401 时清除令牌并跳转登录视图

const TOKEN_KEY = 'NexusNode_token';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY);
}

export class ApiError extends Error {
    constructor(message, status, errors) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

async function request(method, path, body = undefined) {
    const headers = { Accept: 'application/json' };
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    const res = await fetch(`/api/v1${path}`, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    // 401：未登录/令牌过期 → 清除并回到登录视图
    if (res.status === 401) {
        setToken(null);
        if (!location.hash.startsWith('#/login')) {
            location.hash = '#/login';
            throw new ApiError('登录已过期，请重新登录', 401);
        }
        throw new ApiError('未登录', 401);
    }

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new ApiError(data.message || `请求失败（${res.status}）`, res.status, data.errors);
    }

    return data;
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    patch: (path, body) => request('PATCH', path, body),
    del: (path, body) => request('DELETE', path, body),
};

// ==================== 业务 API ====================
export const nodesApi = {
    list: (params) => api.get('/nodes' + toQuery(params)),
    create: (data) => api.post('/nodes', data),
    update: (id, data) => api.put(`/nodes/${id}`, data),
    remove: (id) => api.del(`/nodes/${id}`),
    bulkRemove: (ids) => api.del('/nodes/bulk', { ids }),
    toggle: (id) => api.patch(`/nodes/${id}/toggle`),
    move: (id, direction) => api.patch(`/nodes/${id}/move/${direction}`),
    reorder: (nodeIds) => api.patch('/nodes/reorder', { node_ids: nodeIds }),
};

export const importApi = {
    nodes: (content) => api.post('/imports/nodes', { content }),
};

export const ipsApi = {
    list: (params) => api.get('/preferred-ips/list' + toQuery(params)),
    addText: (content) => api.post('/preferred-ips', { content }),
    importCsv: (content) => api.post('/preferred-ips/import-csv', { content }),
    bulkRemove: (ips) => api.del('/preferred-ips/bulk', { ips }),
    toggle: (ip) => api.patch(`/preferred-ips/${encodeURIComponent(ip)}/toggle`),
    remove: (ip) => api.del(`/preferred-ips/${encodeURIComponent(ip)}`),
    metricsBatch: (entries) => api.post('/preferred-ips/metrics-batch', { entries }),
    latencyBatch: (entries) => api.post('/preferred-ips/latency-batch', { entries }),
    pool: (pool) => api.get(`/preferred-ips/online-pool?pool=${pool}`),
};

export const sourcesApi = {
    list: () => api.get('/preferred-ips/sources'),
    create: (name, url) => api.post('/preferred-ips/sources', { name, url }),
    syncAll: () => api.post('/preferred-ips/sources/sync-all'),
    sync: (id) => api.post(`/preferred-ips/sources/${id}/sync`),
    toggle: (id) => api.patch(`/preferred-ips/sources/${id}/toggle`),
    remove: (id) => api.del(`/preferred-ips/sources/${id}`),
};

export const generateApi = {
    data: () => api.get('/generate/data'),
    run: (nodeIds, ipIds, overwrite) => api.post('/generate', { node_ids: nodeIds, ip_ids: ipIds, overwrite }),
};

export const subsApi = {
    list: () => api.get('/subscriptions'),
    create: (name, description) => api.post('/subscriptions', { name, description }),
    toggle: (id) => api.patch(`/subscriptions/${id}/toggle`),
    regenerate: (id) => api.patch(`/subscriptions/${id}/regenerate`),
    getNodes: (id, params) => api.get(`/subscriptions/${id}/nodes` + toQuery(params)),
    setNodes: (id, nodeIds) => api.put(`/subscriptions/${id}/nodes`, { node_ids: nodeIds }),
    remove: (id) => api.del(`/subscriptions/${id}`),
};

export const dashboardApi = {
    index: () => api.get('/dashboard'),
};

function toQuery(params) {
    const entries = Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '');
    return entries.length ? '?' + new URLSearchParams(entries).toString() : '';
}
