<?php

namespace App\Http\Controllers;

use App\Models\PreferredIpSource;
use App\Services\OnlinePreferredFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 在线优选 API 源管理（/api/v1/preferred-ips/sources）
 */
class PreferredIpSourceController extends Controller
{
    public function __construct(private readonly OnlinePreferredFetcher $fetcher)
    {
    }

    /**
     * 源列表（含最近同步状态）
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'sources' => PreferredIpSource::query()->orderBy('id')->get()->map(fn (PreferredIpSource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'enabled' => $s->enabled,
                'last_synced_at' => $s->last_synced_at?->format('Y-m-d H:i:s'),
                'last_count' => $s->last_synced_at ? $s->last_count : null,
                'last_error' => $s->last_error,
            ]),
        ]);
    }

    /**
     * 添加在线优选源
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500', 'starts_with:http://,https://'],
        ]);

        $source = PreferredIpSource::create(['name' => $validated['name'], 'url' => $validated['url'], 'enabled' => true]);

        return response()->json([
            'message' => "在线源「{$validated['name']}」已添加，点击同步即可拉取",
            'data' => ['id' => $source->id],
        ], 201);
    }

    /**
     * 同步全部启用的源
     */
    public function syncAll(): JsonResponse
    {
        $stats = $this->fetcher->syncAll();

        if ($stats['synced'] + $stats['failed'] === 0) {
            return response()->json(['message' => '没有启用的在线源'], 422);
        }

        return response()->json([
            'message' => "在线同步完成：成功 {$stats['synced']} 个源，失败 {$stats['failed']} 个，共入库 {$stats['total']} 个 IP",
            'stats' => $stats,
        ]);
    }

    /**
     * 同步单个源
     */
    public function sync(PreferredIpSource $source): JsonResponse
    {
        $count = $this->fetcher->syncSource($source);
        $source->refresh();

        if ($count === 0) {
            return response()->json([
                'message' => "「{$source->name}」同步失败：" . ($source->last_error ?: '未知错误'),
            ], 502);
        }

        return response()->json(['message' => "「{$source->name}」同步完成，入库 {$count} 个 IP", 'count' => $count]);
    }

    /**
     * 启用/停用源（停用后不参与"全部同步"）
     */
    public function toggle(PreferredIpSource $source): JsonResponse
    {
        $source->update(['enabled' => !$source->enabled]);

        return response()->json([
            'message' => $source->enabled ? '源已启用' : '源已停用',
            'enabled' => $source->enabled,
        ]);
    }

    public function destroy(PreferredIpSource $source): JsonResponse
    {
        $source->delete();

        return response()->json(['message' => "在线源「{$source->name}」已删除（已入库的 IP 不受影响）"]);
    }
}
