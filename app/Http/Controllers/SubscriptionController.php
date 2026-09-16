<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Services\ProxyUriBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 订阅管理 API（/api/v1/subscriptions）+ 订阅端点（/sub/{token}，位于 web.php，供代理客户端拉取）
 */
class SubscriptionController extends Controller
{
    /**
     * 订阅列表
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'subscriptions' => Subscription::query()->orderBy('id')->get()->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'enabled' => $s->enabled,
                'url' => url('sub/' . $s->token),
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
            'data' => ['id' => $sub->id, 'url' => url('sub/' . $sub->token)],
        ], 201);
    }

    /**
     * 启用/停用订阅
     */
    public function toggle(Subscription $subscription): JsonResponse
    {
        $subscription->update(['enabled' => !$subscription->enabled]);

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
            'url' => url('sub/' . $subscription->token),
        ]);
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(['message' => "订阅「{$subscription->name}」已删除"]);
    }

    /**
     * 订阅端点：/sub/{token}（web.php，无需认证，代理客户端标准 base64 格式）
     */
    public function serve(Request $request, string $token): Response
    {
        $subscription = Subscription::where('token', $token)->first();
        abort_if($subscription === null || !$subscription->enabled, 404);

        $this->logRequest($request, $subscription);

        $uris = Node::enabled()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Node $node) => ProxyUriBuilder::build($node));

        $content = $uris->implode("\n");

        // ?raw=1 输出明文列表，便于人工核对
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
}
