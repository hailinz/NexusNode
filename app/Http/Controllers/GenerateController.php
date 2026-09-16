<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\PreferredIp;
use App\Services\PreferredNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CF 优选生成 API（/api/v1/generate）
 */
class GenerateController extends Controller
{
    public function __construct(private readonly PreferredNodeService $service)
    {
    }

    /**
     * 生成页数据：模板节点（443 + 地址=SNI，非生成节点）、优选 IP 池、各模板已生成数量
     */
    public function data(): JsonResponse
    {
        // 模板只取原始节点（443 + 直连自身域名），优选生成的节点不作为模板
        $templates = Node::query()->cfTemplate()->where('is_generated', false)->orderBy('id')->get();

        return response()->json([
            'templates' => $templates->map(fn (Node $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'address' => $t->address,
                'port' => $t->port,
                'sni' => $t->sni,
                'is_cf' => $t->is_cf,
                'generated_count' => $t->children()->count(),
            ]),
            'ips' => PreferredIp::query()->enabled()->orderBy('latency_ms')->orderBy('id')->get()
                ->map(fn (PreferredIp $i) => [
                    'id' => $i->id,
                    'ip' => $i->ip,
                    'remarks' => $i->remarks,
                    'latency_ms' => $i->latency_ms,
                ]),
            'disabled_ip_count' => PreferredIp::where('enabled', false)->count(),
        ]);
    }

    /**
     * 执行生成：模板 × IP → 新节点
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'node_ids' => ['required', 'array', 'min:1'],
            'node_ids.*' => ['integer'],
            'ip_ids' => ['required', 'array', 'min:1'],
            'ip_ids.*' => ['integer'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        $stats = $this->service->generate(
            array_map('intval', $validated['node_ids']),
            array_map('intval', $validated['ip_ids']),
            $request->boolean('overwrite', true),
        );

        return response()->json([
            'message' => "优选生成完成：新建 {$stats['created']} 个，更新 {$stats['updated']} 个，跳过 {$stats['skipped']} 个（{$stats['bases']} 模板 × {$stats['ips']} IP）",
            'stats' => $stats,
        ]);
    }
}
