<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http as HttpClient;
use Throwable;

/**
 * 订阅格式自适应：根据客户端 User-Agent / 显式 ?target= 参数
 * 决定输出 base64（mixed，本地生成）还是 Clash / sing-box / Surge /
 * Quantumult X / Loon（外部 SubConverter 转换）。
 *
 * 已知 subconverter 实例（实测 subconverter v0.9.9 backend）：
 *  ✅ mixed / clash / clashr / singbox / surge / quanx / loon / v2ray
 *  ❌ meta / mihomo / surge&ver=4 / quantumult / sing-box（别名需归一化）
 */
class SubscriptionFormat
{
    /**
     * 支持的 target 名称（与 subconverter 实例一致；变更需同步更新前端提示）
     */
    public const TARGETS = ['mixed', 'clash', 'clashr', 'singbox', 'surge', 'quanx', 'loon', 'v2ray'];

    /**
     * target → 响应 Content-Type
     */
    private const CONTENT_TYPES = [
        'mixed' => 'text/plain; charset=utf-8',
        'clash' => 'text/yaml; charset=utf-8',
        'clashr' => 'text/yaml; charset=utf-8',
        'singbox' => 'application/json; charset=utf-8',
        'surge' => 'text/plain; charset=utf-8',
        'quanx' => 'text/plain; charset=utf-8',
        'loon' => 'text/plain; charset=utf-8',
        'v2ray' => 'text/plain; charset=utf-8',
    ];

    /**
     * User-Agent 关键字 → target（命中第一个匹配）
     * 关键字应尽量短而稳定，避免长串带版本号
     */
    private const UA_RULES = [
        ['sing-box', 'singbox'],
        ['clash', 'clash'],
        ['mihomo', 'clash'],
        ['surge', 'surge'],
        ['quantumult', 'quanx'],
        ['quanx', 'quanx'],
        ['loon', 'loon'],
    ];

    /**
     * SubConverter 是否已配置（URL 非空）
     */
    public function isConfigured(): bool
    {
        $url = trim((string) config('subconverter.url'));

        return $url !== '';
    }

    /**
     * target 是否本地生成（不需要 SubConverter）
     */
    public function isLocal(string $target): bool
    {
        return $target === 'mixed';
    }

    /**
     * 决定本次请求的 target，优先级：
     *   1. 防递归标识（?b64=1 或 subconverter-request header）→ mixed
     *   2. ?target= 参数（白名单校验）
     *   3. User-Agent 关键字匹配
     *   4. 默认 mixed
     */
    public function detectTarget(Request $request): string
    {
        if ($this->isSubConverterRequest($request)) {
            return 'mixed';
        }

        $explicit = $request->query('target');
        if (is_string($explicit) && in_array($explicit, self::TARGETS, true)) {
            return $explicit;
        }

        $ua = strtolower((string) $request->userAgent());
        foreach (self::UA_RULES as [$needle, $target]) {
            if ($needle !== '' && str_contains($ua, $needle)) {
                return $target;
            }
        }

        return 'mixed';
    }

    /**
     * target 对应的响应 Content-Type
     */
    public function contentType(string $target): string
    {
        return self::CONTENT_TYPES[$target] ?? 'text/plain; charset=utf-8';
    }

    /**
     * 调用 SubConverter 把 baseUrl 转换为 target 格式。
     * 失败（网络 / 非 2xx / 空响应）返回 null，调用方降级到错误响应。
     */
    public function convert(string $baseUrl, string $target): ?string
    {
        $url = rtrim((string) config('subconverter.url'), '/');
        if ($url === '') {
            return null;
        }

        $params = [
            'target' => $target,
            'url' => $baseUrl,
            'list' => 'true',
            'emoji' => 'true',
            'udp' => 'true',
        ];

        // 远程规则集 URL（SUBCONFIG）：在 Clash / sing-box 输出中注入分组与路由规则
        $config = trim((string) config('subconverter.config'));
        if ($config !== '') {
            $params['config'] = $config;
        }

        $query = http_build_query($params);

        try {
            /** @var Response $resp */
            $resp = HttpClient::timeout((int) config('subconverter.timeout', 10))
                ->connectTimeout(5)
                ->retry(1, 200, throw: false)
                ->withHeaders(['Accept' => '*/*'])
                ->get($url.'/sub?'.$query);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $body = $resp->body();
        // 上游对纯 VLESS 节点 + 某些 target（如 surge）会输出空字符串 — 视为失败
        if (trim($body) === '') {
            return null;
        }

        return $body;
    }

    /**
     * 防递归：检测请求是否来自 SubConverter 自身
     * (它回调我们的 base 订阅时,带上这些标记,我们应强制走 mixed)
     */
    private function isSubConverterRequest(Request $request): bool
    {
        if ($request->boolean('b64') || $request->boolean('base64')) {
            return true;
        }

        $h = strtolower((string) $request->header('subconverter-request', ''));

        return $h !== '' && $h !== 'false' && $h !== '0';
    }
}
