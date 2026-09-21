<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Services\ProxyUriBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * 订阅管理 API（/api/v1/subscriptions）+ 订阅端点（/sub/{token}，位于 web.php，供代理客户端拉取）
 */
class SubscriptionController extends Controller
{
    /**
     * 订阅列表：包含每个订阅的节点白名单数量（node_count，0 表示沿用全部启用节点）
     */
    public function index(): JsonResponse
    {
        $subs = Subscription::query()->withCount('nodes')->orderBy('id')->get();

        return response()->json([
            'subscriptions' => $subs->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'enabled' => $s->enabled,
                'node_count' => (int) $s->nodes_count,
                'url' => url('sub/'.$s->token),
            ]),
            'enabled_node_count' => Node::enabled()->count(),
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
     * 订阅端点：/sub/{token}（web.php，无需认证，代理客户端标准 base64 格式）
     *
     * 输出策略：
     *  - 若订阅配置了节点白名单（关联数 > 0）→ 只输出白名单 ∩ enabled 节点，按 subscription_node.sort_order 排序
     *  - 否则（老订阅、新订阅、清空白名单后）→ 沿用旧行为：输出所有 enabled 节点，按 nodes.sort_order 排序
     */
    public function serve(Request $request, string $token): Response
    {
        $subscription = Subscription::where('token', $token)->first();
        abort_if($subscription === null || ! $subscription->enabled, 404);

        $this->logRequest($request, $subscription);

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
