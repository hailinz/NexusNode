# NexusNode API 接口文档

基础地址：`http://<host>:<port>/api/v1`

认证方式：除登录接口外，所有接口需携带 `Authorization: Bearer <token>` 请求头。管理员凭据存储于数据库 `admins` 表（迁移自动创建默认账号 `admin / admin123`，密码哈希存储，**首次登录后请修改密码**）。令牌记录存储于 `api_tokens` 表（存 sha256 哈希，默认 7 天过期），修改密码后其他设备的令牌自动失效。

错误格式：校验失败返回 `422` 与 `{ message, errors: { 字段: [错误信息] } }`；未认证 `401`；业务失败 `402/422/502` 携带 `{ message }`。

---

## 认证

### POST /api/v1/login

登录并获取令牌（限流：每 IP 每分钟 5 次）。

```json
// 请求
{ "username": "admin", "password": "your-password" }

// 响应 200
{
  "token": "64位十六进制随机令牌",
  "token_type": "Bearer",
  "expires_in": 604800,
  "username": "admin"
}
```

### POST /api/v1/logout

登出并吊销当前令牌。响应：`{ "message": "已登出" }`

### GET /api/v1/me

当前登录信息。响应：`{ "username": "admin" }`

### PATCH /api/v1/profile/password

修改当前管理员密码（需登录）。其他设备的旧令牌将全部失效，当前会话保留。

```json
// 请求
{
  "current_password": "当前密码",
  "password": "新密码（至少 8 位）",
  "password_confirmation": "再次输入新密码"
}
```

校验失败返回 422；当前密码不符在 `errors.current_password` 中提示。

---

## 节点管理

### GET /api/v1/nodes

| 参数 | 说明 |
|---|---|
| filter | `all`（默认）/ `cf`（CF 优选）/ `generated`（优选生成）/ `plain`（普通）/ `disabled`（已禁用） |
| q | 关键字：匹配名称 / 地址 / SNI |
| parent | 模板节点 id：仅返回该模板生成的节点 |
| page / per_page | 分页，per_page 可选 20/50/100/200 |

响应：

```json
{
  "data": [{
    "id": 1, "name": "OC-Osaka_CF", "protocol": "vless", "uuid": "...",
    "address": "172.64.144.246", "port": 443, "security": "tls",
    "sni": "bak.vesven.com", "host": "...", "path": "...", "network": "ws",
    "flow": null, "fingerprint": null, "encryption": null, "alpn": null,
    "extras": { "insecure": "0" }, "extras_text": "insecure=0",
    "enabled": true, "is_cf": true, "is_generated": false,
    "sort_order": 0, "uri": "vless://..."
  }],
  "meta": { "current_page": 1, "last_page": 2, "per_page": 20, "total": 25 },
  "filter": "all", "q": "",
  "counts": { "all": 25, "cf": 20, "generated": 9, "disabled": 0, "plain": 5 },
  "parent": null
}
```

### POST /api/v1/nodes

新建节点。必填：`name`、`address`、`port`（1-65535）、`protocol`（vless/vmess/trojan/ss）。可选：`uuid`、`security`（none/tls/reality）、`sni`、`host`、`path`、`network`（tcp/ws/grpc/http）、`flow`、`fingerprint`、`encryption`、`alpn`、`extras_text`（每行 key=value）、`sort_order`、`enabled`。

响应 201：`{ "message": "节点已创建", "data": { ...节点 } }`

### PUT /api/v1/nodes/{id}

更新节点（字段同上，均可选）。响应 200：`{ "message": "节点已更新", "data": { ...节点 } }`

### DELETE /api/v1/nodes/{id}

删除节点（其生成的优选节点级联删除）。

### DELETE /api/v1/nodes/bulk

批量删除。请求：`{ "ids": [1, 2, 3] }`。响应：`{ "message": "...", "deleted": 3 }`

### PATCH /api/v1/nodes/{id}/toggle

启用/禁用切换。响应：`{ "message": "...", "enabled": true }`

### PATCH /api/v1/nodes/{id}/move/{direction}

上移/下移（`direction` = `up` | `down`），序号自动归一化。`sort_order` 决定订阅内节点输出顺序。

---

## 批量导入

### POST /api/v1/imports/nodes

```json
{ "content": "vless://...\nvless://..." }
```

响应：`{ "message": "导入完成：...", "stats": { "total": 3, "created": 2, "skipped": 1, "failed": 0, "errors": [] } }`

支持 vless/vmess/trojan/ss 链接、整体 base64 订阅内容；按「协议+凭据+地址+端口+路径」自动去重。

---

## CF 优选 IP 池

### GET /api/v1/preferred-ips/list

全量 IP 列表。参数：`sort` = `created`（默认，最新在前）/ `latency`；`dir` = `desc`（默认）/ `asc`。延迟排序时未测 IP 恒排最后。

响应：`{ "ips": [{ "ip", "remarks", "latency_ms", "loss_rate", "download_speed", "enabled", "created_at" }] }`

### POST /api/v1/preferred-ips

文本批量添加。`{ "content": "104.16.1.1#圣何塞\n172.64.2.2" }`（备注分隔支持 `#`/空格/逗号）

### POST /api/v1/preferred-ips/import-csv

导入 CloudflareSpeedTest result.csv：`{ "content": "<csv 文本>" }`（表头自动识别，兼容 GBK）

### DELETE /api/v1/preferred-ips/bulk

批量删除：`{ "ips": ["1.1.1.1", "2.2.2.2"] }`

### PATCH /api/v1/preferred-ips/{ip}/toggle — 启停
### DELETE /api/v1/preferred-ips/{ip} — 删除单个

### GET /api/v1/preferred-ips/online-pool?pool={key}

候选 IP 库代理（服务端缓存 10 分钟）。`pool` 可选：`cf-v4` / `cf-v6` / `cm-v4` / `as13335-v4` / `as13335-v6` / `as209242-v4` / `as209242-v6` / `local`（当前池）。

响应：`{ "pool": "cf-v4", "name": "CF官方列表v4", "lines": ["原始行", "..."] }`（IP/CIDR/区间原始行，由前端展开）

### GET /api/v1/preferred-ips/online-locations

机房地理映射（IATA → 国家/城市），服务端缓存 1 天。

### POST /api/v1/preferred-ips/latency-batch

浏览器优选测速结果入库（≤200 条）。仅更新延迟与启用状态；备注仅在原值为空时写入。

```json
{ "entries": [{ "ip": "1.2.3.4", "latency_ms": 52, "remarks": "电信 · HK · HKG" }] }
```

### POST /api/v1/preferred-ips/metrics-batch

IP 列表测速回写（仅更新已存在 IP 的延迟/丢包率，不改启用状态）。

```json
{ "entries": [{ "ip": "1.2.3.4", "latency_ms": 88, "loss_rate": 12.5 }] }
```

---

## 在线优选源

### GET /api/v1/preferred-ips/sources

```json
{ "sources": [{ "id": 1, "name": "CM 聚合", "url": "https://...", "enabled": true, "last_synced_at": "09-15 12:00", "last_count": 15, "last_error": null }] }
```

### POST /api/v1/preferred-ips/sources — `{ "name": "...", "url": "https://..." }`
### POST /api/v1/preferred-ips/sources/sync-all — 同步全部启用源，返回 `{ "message", "stats": { synced, failed, total } }`
### POST /api/v1/preferred-ips/sources/{id}/sync — 同步单个（失败返回 502 + last_error）
### PATCH /api/v1/preferred-ips/sources/{id}/toggle — 启停
### DELETE /api/v1/preferred-ips/sources/{id} — 删除（已入库 IP 不受影响）

---

## 优选生成

### GET /api/v1/generate/data

```json
{
  "templates": [{ "id": 1, "name": "...", "address": "...", "port": 443, "sni": "...", "is_cf": false, "generated_count": 9 }],
  "ips": [{ "id": 1, "ip": "...", "remarks": "...", "latency_ms": 52 }],
  "disabled_ip_count": 0
}
```

模板判定：443 端口且地址与 SNI 一致（非生成节点）。

### POST /api/v1/generate

```json
{ "node_ids": [1, 2], "ip_ids": [3, 4], "overwrite": true }
```

响应：`{ "message": "优选生成完成：...", "stats": { "created", "updated", "skipped", "bases", "ips" } }`

---

## 订阅管理

### GET /api/v1/subscriptions

```json
{
  "subscriptions": [{
    "id": 1, "name": "我的订阅", "description": null,
    "enabled": true, "node_count": 5,
    "url": "http://host/sub/{token}"
  }],
  "enabled_node_count": 12
}
```

`node_count`：该订阅配置的节点白名单数量。`0` 表示沿用「全部启用节点」行为；`> 0` 表示订阅端点只输出这些节点（按白名单 sort_order 排序）。

### POST /api/v1/subscriptions — `{ "name": "...", "description": "..." }` → 201
### PATCH /api/v1/subscriptions/{id}/toggle — 启停
### PATCH /api/v1/subscriptions/{id}/regenerate — 重置令牌（旧地址失效），响应含新 `url`
### DELETE /api/v1/subscriptions/{id} — 删除（级联清理节点白名单关联）

### GET /api/v1/subscriptions/{id}/nodes

查询订阅的节点白名单配置。参数同 `/nodes`：`filter`（`all` / `cf` / `generated` / `disabled`）、`q`（名称 / 地址 / SNI 关键字）、`page`、`per_page`。

```json
{
  "nodes": {
    "data": [{ "id": 1, "name": "A", "enabled": true, ... }],
    "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 12 }
  },
  "selected": [
    { "id": 3, "name": "B", "protocol": "vless", "address": "...", "port": 443, "enabled": true, "sort_order": 0 },
    { "id": 5, "name": "C", "protocol": "vless", "address": "...", "port": 443, "enabled": false, "sort_order": 1 }
  ]
}
```

`selected` 跨分页返回完整已选节点（含已禁用的，前端用徽章标注），用于右栏渲染与排序调整。

### PUT /api/v1/subscriptions/{id}/nodes

全量替换白名单。Body：

```json
{ "node_ids": [3, 5, 1] }
```

- **空数组 `[]`**：清空白名单，订阅恢复「全部启用节点」行为
- **数组顺序**：写入 `subscription_node.sort_order`，决定订阅内节点输出顺序
- **校验**：所有 id 必须存在（否则 422 + `errors.node_ids`）；id 不允许重复

响应：`{ "message", "node_count": 3 }`。

---

## 订阅端点（供代理客户端，无需认证）

### GET /sub/{token}

返回 base64(节点链接)，`Content-Type: text/plain; charset=utf-8`。附加 `?raw=1` 输出明文列表。停用的订阅返回 404。

**输出策略**：

- 若订阅配置了白名单（`subscription_node` 关联数 > 0）→ 只输出白名单 ∩ `enabled=true` 的节点，按 `subscription_node.sort_order` 排序
- 否则 → 输出所有启用节点，按 `nodes.sort_order` 排序（向后兼容老订阅）

白名单内全部节点被禁用时，端点输出空内容（base64 空串），属预期行为。

---

## 总览

### GET /api/v1/dashboard

```json
{
  "stats": { "total_nodes", "enabled_nodes", "cf_nodes", "generated_nodes", "ip_count", "enabled_ip_count", "sub_count", "enabled_sub_count" },
  "recent_requests": [{ "subscription", "ip", "user_agent", "requested_at" }],
  "recent_subscriptions": [{ "name", "enabled", "url" }]
}
```
