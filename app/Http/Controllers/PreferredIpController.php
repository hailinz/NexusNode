<?php

namespace App\Http\Controllers;

use App\Models\PreferredIp;
use App\Services\OnlinePreferredFetcher;
use App\Services\PreferredIpImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * CF 优选 IP 池 API（/api/v1/preferred-ips）
 */
class PreferredIpController extends Controller
{
    public function __construct(
        private readonly PreferredIpImporter $importer,
        private readonly OnlinePreferredFetcher $fetcher,
    ) {
    }

    /**
     * IP 列表数据（JSON，供前端渲染；支持 sort=latency|created × dir=asc|desc）
     */
    public function list(Request $request): JsonResponse
    {
        $sort = $request->query('sort') === 'latency' ? 'latency' : 'created';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $query = PreferredIp::query();
        if ($sort === 'latency') {
            // 未测延迟（NULL）恒排在有值之后
            $query->orderByRaw('latency_ms IS NULL, latency_ms ' . $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderByDesc('id');
        }

        return response()->json([
            'ips' => $query->get()->map(fn (PreferredIp $i) => $this->present($i)),
        ]);
    }

    private function present(PreferredIp $i): array
    {
        return [
            'ip' => $i->ip,
            'remarks' => $i->remarks,
            'latency_ms' => $i->latency_ms,
            'loss_rate' => $i->loss_rate,
            'download_speed' => $i->download_speed,
            'enabled' => $i->enabled,
            'created_at' => $i->created_at->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * 文本批量添加：每行一个 IP，可选备注（"IP#备注" / "IP 备注" / "IP,备注"）
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['content' => ['required', 'string', 'max:1000000']]);

        $stats = $this->importer->importText($request->input('content'));

        return response()->json([
            'message' => "IP 导入完成：新增 {$stats['created']} 个，更新 {$stats['updated']} 个，失败 {$stats['failed']} 个",
            'stats' => $stats,
        ]);
    }

    /**
     * 导入 CloudflareSpeedTest 的 result.csv（前端读取文件文本后提交 content）
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate(['content' => ['required', 'string', 'max:1000000']]);

        $stats = $this->importer->importCsv($request->input('content'));

        return response()->json([
            'message' => "CSV 导入完成：新增 {$stats['created']} 个，更新 {$stats['updated']} 个，失败 {$stats['failed']} 个",
            'stats' => $stats,
        ]);
    }

    /**
     * 批量删除选中的 IP（IP 列表复选框入口）
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ips' => ['required', 'array', 'min:1'],
            'ips.*' => ['string', 'max:64'],
        ]);

        $deleted = PreferredIp::whereIn('ip', $validated['ips'])->delete();

        return response()->json(['deleted' => $deleted, 'message' => "已删除 {$deleted} 个 IP"]);
    }

    public function toggle(PreferredIp $preferredIp): JsonResponse
    {
        $preferredIp->update(['enabled' => !$preferredIp->enabled]);

        return response()->json([
            'message' => $preferredIp->enabled ? 'IP 已启用' : 'IP 已停用',
            'enabled' => $preferredIp->enabled,
        ]);
    }

    public function destroy(PreferredIp $preferredIp): JsonResponse
    {
        $preferredIp->delete();

        return response()->json(['message' => "IP {$preferredIp->ip} 已删除"]);
    }

    /**
     * 在线优选：获取候选 IP 库（JSON，供浏览器测延迟）
     */
    public function onlinePool(Request $request): JsonResponse
    {
        $poolKey = $request->query('pool') ?? '';

        // local：使用现有 IP 池中启用的 IP 作为候选
        if ($poolKey === 'local') {
            $ips = PreferredIp::query()
                ->enabled()
                ->pluck('ip')
                ->map(fn (string $ip) => str_contains($ip, ':') && !str_starts_with($ip, '[') ? "[{$ip}]" : $ip)
                ->all();

            return response()->json(['pool' => 'local', 'name' => '当前 IP 池', 'lines' => $ips]);
        }

        if (!isset(OnlinePreferredFetcher::POOLS[$poolKey])) {
            return response()->json(['message' => "未知的 IP 库: {$poolKey}"], 422);
        }

        try {
            $text = $this->fetcher->fetchPool($poolKey);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'IP 库拉取失败：' . $e->getMessage()], 502);
        }

        // fetchPool 返回原始清单文本，此处拆为行数组供前端展开处理
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", (string) $text)),
            fn (string $line): bool => $line !== ''
        ));

        return response()->json([
            'pool' => $poolKey,
            'name' => OnlinePreferredFetcher::POOLS[$poolKey]['name'],
            'lines' => $lines,
        ]);
    }

    /**
     * 在线优选：机房地理映射（IATA → 国家/城市），供优选结果反查机房国家（缓存 1 天）
     */
    public function onlineLocations(): JsonResponse
    {
        $data = Cache::remember('oo:locations', 86400, function () {
            $response = Http::timeout(8)
                ->withOptions(['verify' => base_path('resources/certs/cacert.pem')])
                ->get('https://cf.090227.xyz/locations');

            return $response->successful() ? $response->json() : [];
        });

        if (!is_array($data) || $data === []) {
            return response()->json(['message' => 'locations 拉取失败'], 502);
        }

        return response()->json(['locations' => $data]);
    }

    /**
     * 在线优选：浏览器测得的延迟批量入库（仅更新延迟，备注等原值保留）
     * 可选 remarks：仅在现有备注为空时写入（用于回填探测得到的「国家/数据中心」）
     */
    public function storeLatencyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.ip' => ['required', 'string', 'max:64'],
            'entries.*.latency_ms' => ['required', 'numeric', 'min:1', 'max:10000'],
            'entries.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $saved = 0;
        foreach ($validated['entries'] as $entry) {
            $ip = trim($entry['ip']);
            if (!filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP)) {
                continue;
            }
            $data = ['latency_ms' => (float) $entry['latency_ms'], 'enabled' => true];
            $existing = PreferredIp::where('ip', $ip)->first();
            // 新建 IP 或现有备注为空时，回填探测得到的「国家/地区 · 数据中心」
            if ((!$existing || $existing->remarks === null) && !empty($entry['remarks'])) {
                $data['remarks'] = $entry['remarks'];
            }
            PreferredIp::updateOrCreate(['ip' => $ip], $data);
            $saved++;
        }

        return response()->json(['saved' => $saved]);
    }

    /**
     * IP 列表测速：浏览器测得的延迟 + 丢包率批量回写（仅更新已存在的 IP，不改动启用状态）
     */
    public function storeMetricsBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.ip' => ['required', 'string', 'max:64'],
            'entries.*.latency_ms' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'entries.*.loss_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $saved = 0;
        foreach ($validated['entries'] as $entry) {
            $ip = trim($entry['ip']);
            if (!filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP)) {
                continue;
            }

            $preferredIp = PreferredIp::where('ip', $ip)->first();
            if (!$preferredIp) {
                continue;
            }

            // 只写入本次测得的项：全超时（latency 为 null）时仅更新丢包率，保留旧延迟
            $data = [];
            if ($entry['latency_ms'] !== null) {
                $data['latency_ms'] = (float) $entry['latency_ms'];
            }
            if ($entry['loss_rate'] !== null) {
                $data['loss_rate'] = (float) $entry['loss_rate'];
            }

            if ($data !== []) {
                $preferredIp->update($data);
                $saved++;
            }
        }

        return response()->json(['saved' => $saved]);
    }
}
