<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Services\ProxyUriBuilder;
use App\Services\SubscriptionFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 订阅管理 API（/api/v1/subscriptions）+ 订阅端点（/sub/{token}，位于 web.php，供代理客户端拉取）
 */
class SubscriptionController extends Controller
{
    /**
     * 订阅列表：包含每个订阅的节点白名单数量（node_count，0 表示沿用全部启用节点）
     * + 24h 拉取计数（request_count_24h，用于卡片徽章）
     * + subconverter_configured：是否配置了 SubConverter（决定是否支持 clash / singbox 等外部格式）
     */
    public function index(SubscriptionFormat $format): JsonResponse
    {
        $subs = Subscription::query()
            ->withCount('nodes')
            ->withCount(['requests as request_count_24h' => fn ($q) => $q->where('requested_at', '>=', now()->subDay())])
            ->orderBy('id')
            ->get();

        return response()->json([
            'subscriptions' => $subs->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'enabled' => $s->enabled,
                'node_count' => (int) $s->nodes_count,
                'request_count_24h' => (int) $s->request_count_24h,
                'url' => url('sub/'.$s->token),
            ]),
            'enabled_node_count' => Node::enabled()->count(),
            'subconverter_configured' => $format->isConfigured(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $sub = Subscription::create($validated + ['token' => Subscription::generateToken(), 'enabled' => true]);

        return response()->json([
            'message' => "订阅「{$validated['name']}」已创建",
            'data' => ['id' => $sub->id, 'url' => url('sub/'.$sub->token)],
        ], 201);
    }

    /**
     * 启用/停用订阅
     */
    public function toggle(Subscription $subscription): JsonResponse
    {
        $subscription->update(['enabled' => ! $subscription->enabled]);

        return response()->json([
            'message' => $subscription->enabled ? '订阅已启用' : '订阅已停用',
            'enabled' => $subscription->enabled,
        ]);
    }

    /**
     * 重新生成订阅令牌（旧地址立即失效）
     */
    public function regenerate(Subscription $subscription): JsonResponse
    {
        $subscription->update(['token' => Subscription::generateToken()]);

        return response()->json([
            'message' => "订阅「{$subscription->name}」的地址已重置",
            'url' => url('sub/'.$subscription->token),
        ]);
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(['message' => "订阅「{$subscription->name}」已删除"]);
    }

    /**
     * 订阅节点白名单查询：返回全节点（不限定 enabled、便于管理）+ 已关联节点完整对象（跨分页仍可见）
     */
    public function getNodes(Subscription $subscription, Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'all');
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 50);

        $query = Node::query();
        $this->applyNodeFilter($query, $filter, $q);

        $paginator = $query->orderBy('sort_order')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

        // 已关联节点完整对象（按 sort_order 排）— 右栏渲染使用，不受分页影响
        $selected = $subscription->nodes()->orderBy('subscription_node.sort_order')->orderBy('subscription_node.node_id')->get();

        return response()->json([
            'nodes' => [
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'selected' => $selected->map(fn (Node $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'protocol' => $n->protocol,
                'address' => $n->address,
                'port' => $n->port,
                'enabled' => $n->enabled,
                'sort_order' => (int) $n->pivot->sort_order,
            ])->values(),
        ]);
    }

    /**
     * 该订阅的最近拉取请求日志（IP / UA / 时间），按 requested_at DESC。
     */
    public function requests(Subscription $subscription, Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $rows = SubscriptionRequest::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('requested_at')
            ->limit($limit)
            ->get()
            ->map(fn (SubscriptionRequest $r) => [
                'id' => $r->id,
                'ip' => $r->ip,
                'user_agent' => $r->user_agent,
                'requested_at' => $r->requested_at->toIso8601String(),
            ])
            ->values();

        $count24h = SubscriptionRequest::query()
            ->where('subscription_id', $subscription->id)
            ->where('requested_at', '>=', now()->subDay())
            ->count();

        return response()->json([
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'enabled' => $subscription->enabled,
            ],
            'count_24h' => $count24h,
            'requests' => $rows,
        ]);
    }

    /**
     * 全量替换订阅的节点白名单。
     * - 空数组 → 清空白名单（恢复「全部启用节点」行为）
     * - 非空   → 仅这些节点会出现在订阅端点输出中（且节点须 enabled=true）
     * 排序：按数组顺序写入 sort_order，便于「上移/下移」控制订阅内顺序。
     */
    public function updateNodes(Subscription $subscription, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'node_ids' => ['present', 'array'],
            'node_ids.*' => ['integer', 'distinct'],
        ]);

        $ids = $validated['node_ids'];

        // 校验 id 存在（避免脏数据/级联误删）
        if (! empty($ids)) {
            $existing = Node::whereIn('id', $ids)->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));
            if (! empty($missing)) {
                return response()->json([
                    'message' => '部分节点不存在',
                    'errors' => ['node_ids' => ['不存在的节点 id：'.implode(', ', $missing)]],
                ], 422);
            }
        }

        DB::transaction(function () use ($subscription, $ids) {
            $subscription->nodes()->detach();
            foreach ($ids as $order => $id) {
                $subscription->nodes()->attach($id, ['sort_order' => $order]);
            }
        });

        return response()->json([
            'message' => empty($ids) ? '已恢复「全部启用节点」模式' : '白名单已更新（共 '.count($ids).' 个节点）',
            'node_count' => count($ids),
        ]);
    }

    /**
     * 订阅端点：/sub/{token}（web.php，无需认证，供代理客户端拉取）
     *
     * 输出策略（按 target 决定）：
     *  - mixed（默认 / ?b64=1 / 防递归 / 未知 UA） → 本地生成 base64(vless/vmess/trojan/ss 链接列表)
     *    节点来源：白名单 ∩ enabled（按 sort_order）/ 全部 enabled（无白名单时）
     *    ?raw=1 输出明文
     *  - clash / clashr / singbox / surge / quanx / loon / v2ray → 调 SubConverter 转换
     *    SubConverter 会回调本服务的 ?target=mixed 拿 base 节点列表
     *    未配置 SubConverter → 404；上游失败 / 空响应 → 502
     *
     * 响应头：按 target 设对应 Content-Type；非本地路径加 Profile-Update-Interval
     */
    public function serve(Request $request, string $token, SubscriptionFormat $format): Response
    {
        $subscription = Subscription::where('token', $token)->first();
        abort_if($subscription === null || ! $subscription->enabled, 404);

        $this->logRequest($request, $subscription);

        $target = $format->detectTarget($request);

        if ($format->isLocal($target)) {
            return $this->serveLocal($request, $subscription);
        }

        if (! $format->isConfigured()) {
            return response()->json([
                'message' => '当前订阅未启用 SubConverter 转换服务,无法输出 '.strtoupper($target).' 格式。请使用 base64 格式的客户端或在管理面板配置 SUB_CONVERTER_URL。',
                'target' => $target,
            ], 404);
        }

        $baseUrl = url('sub/'.$token.'?target=mixed');
        $body = $format->convert($baseUrl, $target);
        if ($body === null) {
            return response()->json([
                'message' => '上游 SubConverter 转换失败或返回空响应（target='.$target.'）。请稍后重试或联系管理员。',
                'target' => $target,
            ], 502);
        }

        return response($body, 200, [
            'Content-Type' => $format->contentType($target),
            'Profile-Update-Interval' => (string) config('subconverter.update_interval', 24),
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * 本地生成 mixed（base64）输出，与升级前完全一致（向后兼容）
     */
    private function serveLocal(Request $request, Subscription $subscription): Response
    {
        $hasWhitelist = $subscription->nodes()->exists();

        if ($hasWhitelist) {
            $nodes = $subscription->nodes()
                ->where('nodes.enabled', true)
                ->orderBy('subscription_node.sort_order')
                ->orderBy('subscription_node.node_id')
                ->get();
        } else {
            $nodes = Node::enabled()->orderBy('sort_order')->orderBy('id')->get();
        }

        $uris = $nodes->map(fn (Node $node) => ProxyUriBuilder::build($node));
        $content = $uris->implode("\n");

        if ($request->boolean('raw')) {
            return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response(base64_encode($content), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * 记录订阅拉取请求（总览页「最近订阅请求」数据源）
     *
     * 真实客户端 IP 取值优先级：CF-Connecting-IP（CF CDN 强制写入、覆盖客户端伪造值）
     * → request()->ip()（需配合 TrustProxies 解析 X-Forwarded-For）。
     */
    private function logRequest(Request $request, Subscription $subscription): void
    {
        SubscriptionRequest::create([
            'subscription_id' => $subscription->id,
            'ip' => (string) ($request->header('CF-Connecting-IP') ?: $request->ip()),
            'user_agent' => (string) $request->userAgent(),
            'requested_at' => now(),
        ]);
    }

    /**
     * 节点筛选（与管理页 nodesApi.list 语义一致；保留协议/名称/地址/SNI 关键字匹配）
     */
    private function applyNodeFilter($query, string $filter, string $q): void
    {
        match ($filter) {
            'cf' => $query->cfPreferred(),
            'generated' => $query->where('is_generated', true),
            'disabled' => $query->where('enabled', false),
            default => null,
        };

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('sni', 'like', $like);
            });
        }
    }
}
