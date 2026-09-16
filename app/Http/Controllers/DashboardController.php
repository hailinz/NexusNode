<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\PreferredIp;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use Illuminate\Http\JsonResponse;

/**
 * 总览 API（/api/v1/dashboard）
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $recentRequests = SubscriptionRequest::query()
            ->with('subscription')
            ->orderByDesc('requested_at')
            ->limit(8)
            ->get()
            ->map(fn (SubscriptionRequest $r) => [
                'id' => $r->id,
                'subscription' => $r->subscription?->name ?? '（已删除）',
                'ip' => $r->ip,
                'user_agent' => $r->user_agent,
                'requested_at' => $r->requested_at->format('Y-m-d H:i:s'),
            ]);

        $recentSubs = Subscription::query()->latest('id')->limit(3)->get()
            ->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'enabled' => $s->enabled,
                'url' => url('sub/' . $s->token),
            ]);

        return response()->json([
            'stats' => [
                'total_nodes' => Node::count(),
                'enabled_nodes' => Node::enabled()->count(),
                'cf_nodes' => Node::cfPreferred()->count(),
                'generated_nodes' => Node::where('is_generated', true)->count(),
                'ip_count' => PreferredIp::count(),
                'enabled_ip_count' => PreferredIp::enabled()->count(),
                'sub_count' => Subscription::count(),
                'enabled_sub_count' => Subscription::where('enabled', true)->count(),
            ],
            'recent_requests' => $recentRequests,
            'recent_subscriptions' => $recentSubs,
        ]);
    }
}
