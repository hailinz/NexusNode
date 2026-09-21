# NexusNode — 节点订阅管理平台

基于 **Laravel（RESTful API）+ 前后端分离 SPA（原生 JS，无构建依赖）+ SQLite** 的代理节点管理工具：网页端管理 vless/vmess/trojan/ss 节点，一键生成订阅地址，内置 BestCF 同款浏览器实时优选与在线优选源同步。

## 功能

- **节点管理**：解析 vless/vmess/trojan/ss 链接为结构化数据；手动新建/编辑/启停/删除/排序（影响订阅内节点顺序）；非标准参数（pcs、insecure、pbk、sid 等）完整保留
- **延迟测试**：浏览器在你的真实网络环境下直连测各节点连接延迟（五档颜色显示），支持选中测/整页测/单节点测
- **批量导入**：多行文本粘贴或文件上传（自动识别整体 base64 订阅内容），按「协议+凭据+地址+端口+路径」自动去重
- **CF 优选识别**：443 端口且地址与 SNI 不同的节点自动标记为 CF 优选
- **在线优选源**：内置 CM 聚合、CF 官方列表、AS13335/AS209242 等 7 个 IP 库，一键同步最新优选 IP（自动识别纯文本/CSV/逗号分隔，兼容 base64 与 GBK）
- **浏览器测速优选**（BestCF 同款）：IP 库候选 → CIDR/区间随机展开 → hex 标签 SNI 探测测延迟 → 运营商/机房国家标注 → 达标 IP 一键入库
- **优选生成**：模板节点（443 + 直连自身域名）× 优选 IP → 批量生成新节点，幂等可覆盖，生成结果可从「已生成 N →」入口查看编辑
- **订阅管理**：`/sub/{随机token}` 输出标准 base64 格式；二维码扫码添加；`?raw=1` 明文；令牌可重置；订阅请求日志（时间/IP/UA）在总览展示；**每个订阅可独立配置节点白名单**（按订阅选节点 / 调顺序），未配置时沿用「全部启用节点」行为
- **登录认证**：Bearer 令牌，接口限流防爆破；订阅端点无需认证

## 快速开始

```bash
# 1. 安装依赖（PHP >= 8.2，需启用 pdo_sqlite、openssl、curl 扩展）
composer install

# 2. 初始化数据库（表结构 + 内置在线优选源）
php artisan migrate --force

# 3. 启动
php artisan serve
```

浏览器访问 <http://127.0.0.1:8000>，默认账号 `admin` / `admin123`（凭据存储于数据库 `admins` 表，密码哈希保存）。**首次登录后请通过侧边栏「修改密码」立即更改**，改密后其他设备的登录会自动失效。

**推荐流程**：批量导入 node.txt → CF 优选 IP 页同步在线源 / BestCF 浏览器测速 → 优选生成 → 订阅管理创建订阅 → 客户端扫码或粘贴地址。

## 架构说明

前后端分离：

- **后端**：RESTful API（`/api/v1/*`，Bearer 令牌认证），全部返回 JSON。接口文档见 [`docs/API.md`](docs/API.md)
- **前端**：`resources/views/app.blade.php`（SPA 壳）+ `public/js/`（ES Modules，无构建），hash 路由，移动端自适应（侧边栏抽屉 + 响应式表格）
- **静态资源本地化**：Tailwind 与二维码库托管于 `public/vendor/`，无外网 CDN 依赖
- **订阅端点**：`/sub/{token}` 无需认证（代理客户端拉取）

| 路径 | 说明 |
|---|---|
| `app/Services/` | 解析器 / 生成器 / 导入 / 在线优选 / BestCF 数据源 / TCP 探测等核心逻辑 |
| `app/Http/Controllers/` | RESTful API 控制器 |
| `app/Http/Middleware/ApiTokenAuth.php` | Bearer 令牌认证中间件 |
| `routes/api.php` | 全部 API 路由 |
| `public/js/` | 前端 SPA（api 客户端 / 布局路由 / 各页面模块） |
| `docs/API.md` | 接口文档 |

## 测试

```bash
php artisan test
```

覆盖：四协议解析与 round-trip 一致性、CF 判定规则、导入去重、CSV/在线源解析、优选生成幂等、订阅端点、认证与限流、各 API 接口。

## 安全说明

- 登录接口限流（每 IP 每分钟 5 次）；管理页面需登录后访问
- 订阅地址含随机令牌，重置后旧地址立即失效
- 设计为**本机或内网使用**。公网部署请务必修改默认密码，并建议启用 HTTPS（`.env` 配置 `ADMIN_*` 与反代）

## 致谢

本项目的「在线优选源」与「浏览器测速优选」功能，启发并移植自 **[cmliu/edgetunnel](https://github.com/cmliu/edgetunnel)** 项目：

- 在线优选源的多格式候选清单解析（IP / CIDR / IP 区间、CSV、base64、GBK 编码兼容）
- 浏览器测速优选的探测方式与 BestCF 页面交互流程
- hex 标签 SNI 通配域名探测（将目标 IP 编码进子域，经优选域名回源边缘节点）

以及 CloudflareSpeedTest、ipverse 等上游优选工具与数据源。在线优选得以实现，离不开巨人的肩膀，向原作者与社区贡献者致敬。

> 前端优选页脚注同此致谢（「© 致谢 cmliu/edgetunnel 与 BestCF」）。
