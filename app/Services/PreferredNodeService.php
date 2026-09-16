<?php

namespace App\Services;

use App\Models\Node;
use App\Models\PreferredIp;
use Illuminate\Support\Collection;

/**
 * CF 优选生成服务：模板节点（SNI 非空）× 优选 IP 池 → 批量生成新节点
 *
 * 生成规则：将模板节点的 address 替换为优选 IP，其余连接参数（sni/host/path 等）原样继承，
 * 同一模板+同一 IP 的生成结果唯一（幂等），可选覆盖重建。
 */
class PreferredNodeService
{
    /**
     * @param  array<int,int>  $baseNodeIds  模板节点 ID（要求 SNI 非空）
     * @param  array<int,int>  $ipIds        优选 IP ID
     * @param  bool  $overwrite              已存在同源生成节点时更新其内容
     * @return array{created:int, updated:int, skipped:int, bases:int, ips:int}
     */
    public function generate(array $baseNodeIds, array $ipIds, bool $overwrite = true): array
    {
        $bases = Node::query()->cfTemplate()->whereIn('id', $baseNodeIds)->get();
        $ips = PreferredIp::query()->enabled()->whereIn('id', $ipIds)->orderBy('id')->get();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'bases' => $bases->count(), 'ips' => $ips->count()];

        foreach ($bases as $base) {
            // 同一模板下按 IP 预取已有生成节点，避免循环内查询
            $existing = Node::query()
                ->where('is_generated', true)
                ->where('parent_node_id', $base->id)
                ->whereIn('address', $ips->pluck('ip'))
                ->get()
                ->keyBy('address');

            foreach ($ips as $ip) {
                /** @var Node|null $child */
                $child = $existing->get($ip->ip);

                if ($child !== null && !$overwrite) {
                    $stats['skipped']++;
                    continue;
                }

                $payload = $this->templatePayload($base, $ip);

                if ($child !== null) {
                    $child->fill($payload)->save();
                    $stats['updated']++;
                } else {
                    Node::create($payload);
                    $stats['created']++;
                }
            }
        }

        return $stats;
    }

    /**
     * 模板节点 + 优选 IP → 新节点字段
     */
    private function templatePayload(Node $base, PreferredIp $ip): array
    {
        return [
            'name' => $base->name . ' · ' . ($ip->remarks ?: $ip->ip),
            'protocol' => $base->protocol,
            'uuid' => $base->uuid,
            'address' => $ip->ip,
            'port' => $base->port,
            'security' => $base->security,
            'sni' => $base->sni,
            'host' => $base->host,
            'path' => $base->path,
            'network' => $base->network,
            'flow' => $base->flow,
            'fingerprint' => $base->fingerprint,
            'encryption' => $base->encryption,
            'alpn' => $base->alpn,
            'extras' => $base->extras,
            'enabled' => true,
            'is_generated' => true,
            'parent_node_id' => $base->id,
            'sort_order' => $base->sort_order,
        ];
    }

    /**
     * 统计各模板已生成的节点数量（用于展示）
     *
     * @return Collection<int, object{parent_node_id:int, total:int}>
     */
    public function generatedCounts(Collection $baseIds): Collection
    {
        if ($baseIds->isEmpty()) {
            return collect();
        }

        return Node::query()
            ->selectRaw('parent_node_id, count(*) as total')
            ->where('is_generated', true)
            ->whereIn('parent_node_id', $baseIds)
            ->groupBy('parent_node_id')
            ->get();
    }
}
