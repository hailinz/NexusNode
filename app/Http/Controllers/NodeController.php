<?php

namespace App\Http\Controllers;

use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 节点管理 API（/api/v1/nodes）
 */
class NodeController extends Controller
{
    /** 每页数量可选值 */
    private const PER_PAGE_OPTIONS = [20, 50, 100, 200];

    /**
     * 节点列表：筛选（all/cf/generated/plain/disabled）、关键字搜索、按模板筛选生成节点、分页
     */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'all');
        $q = $request->string('q', '');
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE_OPTIONS, true)
            ? (int) $request->query('per_page')
            : 20;

        $base = Node::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('address', 'like', "%{$q}%")
                        ->orWhere('sni', 'like', "%{$q}%");
                });
            });

        // 按模板筛选其生成的优选节点
        $parentNode = null;
        if (ctype_digit((string) $request->query('parent'))) {
            $parentNode = Node::find((int) $request->query('parent'));
            if ($parentNode !== null) {
                $base = $base->where('parent_node_id', $parentNode->id);
            }
        }

        $query = match ($filter->toString()) {
            'cf' => (clone $base)->cfPreferred(),
            'generated' => (clone $base)->where('is_generated', true),
            'plain' => (clone $base)->where('is_cf', false)->where('is_generated', false),
            'disabled' => (clone $base)->where('enabled', false),
            default => $base,
        };

        $counts = [
            'all' => Node::count(),
            'cf' => Node::cfPreferred()->count(),
            'generated' => Node::where('is_generated', true)->count(),
            'disabled' => Node::where('enabled', false)->count(),
            'plain' => Node::query()
                ->where('enabled', true)
                ->where('is_cf', false)
                ->where('is_generated', false)
                ->count(),
        ];

        $nodes = $query->orderBy('sort_order')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Node $node) => $this->present($node));

        return response()->json([
            'data' => $nodes->items(),
            'meta' => [
                'current_page' => $nodes->currentPage(),
                'last_page' => $nodes->lastPage(),
                'per_page' => $nodes->perPage(),
                'total' => $nodes->total(),
            ],
            'filter' => $filter->toString(),
            'q' => $q->toString(),
            'counts' => $counts,
            'parent' => $parentNode ? ['id' => $parentNode->id, 'name' => $parentNode->name] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['extras'] = $this->parseExtras($request->input('extras_text'));
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $node = Node::create($data + ['is_generated' => false]);

        return response()->json(['message' => '节点已创建', 'data' => $this->present($node)], 201);
    }

    public function update(Request $request, Node $node): JsonResponse
    {
        $data = $this->validated($request);
        $data['extras'] = $this->parseExtras($request->input('extras_text'));
        if (! isset($data['sort_order'])) {
            unset($data['sort_order']);
        }

        $node->update($data);

        return response()->json(['message' => '节点已更新', 'data' => $this->present($node->fresh())]);
    }

    public function destroy(Node $node): JsonResponse
    {
        // 由该节点生成的优选节点一并删除，避免残留孤儿
        $children = $node->children()->count();
        $node->children()->delete();
        $node->delete();

        $message = "节点「{$node->name}」已删除";
        if ($children > 0) {
            $message .= "（含 {$children} 个优选生成节点）";
        }

        return response()->json(['message' => $message]);
    }

    /**
     * 批量删除：ids 为选中的节点，其优选生成节点级联删除
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_map('intval', $validated['ids']);

        $children = Node::whereIn('parent_node_id', $ids)->delete();
        $deleted = Node::whereIn('id', $ids)->delete();

        $message = "已删除 {$deleted} 个节点";
        if ($children > 0) {
            $message .= "（含 {$children} 个优选生成节点）";
        }

        return response()->json(['message' => $message, 'deleted' => $deleted]);
    }

    public function toggle(Node $node): JsonResponse
    {
        $node->update(['enabled' => ! $node->enabled]);

        return response()->json([
            'message' => $node->enabled ? '节点已启用' : '节点已禁用',
            'enabled' => $node->enabled,
        ]);
    }

    /**
     * 上移/下移节点：与排序序列中的相邻节点交换位置后归一化编号。
     * sort_order 直接决定订阅内节点输出顺序。
     */
    public function move(Request $request, Node $node, string $direction): JsonResponse
    {
        $direction = $direction === 'up' ? 'up' : 'down';

        $ordered = Node::query()->orderBy('sort_order')->orderBy('id')->get(['id']);
        $index = $ordered->search(fn ($n) => $n->id === $node->id);
        $swapWith = $index + ($direction === 'up' ? -1 : 1);

        if ($index !== false && $swapWith >= 0 && $swapWith < $ordered->count()) {
            [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];

            foreach ($ordered as $i => $n) {
                Node::where('id', $n->id)->update(['sort_order' => $i]);
            }
        }

        return response()->json(['message' => '顺序已更新']);
    }

    /**
     * 批量重排节点（拖拽排序用）。
     *
     * Body: { node_ids: [id1, id2, ...] } — 当前可见行的最终顺序。
     *
     * 算法：取出这些节点的当前 sort_order 升序排序，按 node_ids 数组顺序依次写回。
     * 优点：不触碰其他节点（不影响其他页 / 已禁用节点），不归一化全表（不重排未参与拖动的节点）。
     * 要求：node_ids 不能含重复 id；节点必须存在。
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'node_ids' => ['required', 'array'],
            'node_ids.*' => ['integer', 'distinct'],
        ]);

        $ids = $validated['node_ids'];

        $existing = Node::whereIn('id', $ids)->pluck('sort_order', 'id');
        if ($existing->count() !== count($ids)) {
            return response()->json([
                'message' => '部分节点不存在',
                'errors' => ['node_ids' => ['缺失 id：'.implode(', ', array_diff($ids, $existing->keys()->all()))]],
            ], 422);
        }

        // 按现有 sort_order 升序作为"位置槽"，按数组顺序填回
        $slots = $existing->sort()->values()->all();
        foreach ($ids as $idx => $id) {
            Node::where('id', $id)->update(['sort_order' => $slots[$idx]]);
        }

        return response()->json(['message' => '顺序已更新']);
    }

    /**
     * 输出为前端展示结构（含重建的代理链接与 CF/生成标记）
     */
    private function present(Node $node): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'protocol' => $node->protocol,
            'uuid' => $node->uuid,
            'address' => $node->address,
            'port' => $node->port,
            'security' => $node->security,
            'sni' => $node->sni,
            'host' => $node->host,
            'path' => $node->path,
            'network' => $node->network,
            'flow' => $node->flow,
            'fingerprint' => $node->fingerprint,
            'encryption' => $node->encryption,
            'alpn' => $node->alpn,
            'extras' => $node->extras,
            'extras_text' => collect($node->extras ?? [])->map(fn ($v, $k) => $k.'='.$v)->implode("\n"),
            'enabled' => $node->enabled,
            'is_cf' => $node->is_cf,
            'is_generated' => $node->is_generated,
            'sort_order' => $node->sort_order,
            'uri' => $node->uri,
        ];
    }

    /**
     * 表单字段校验
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'protocol' => ['required', Rule::in(['vless', 'vmess', 'trojan', 'ss'])],
            'uuid' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'security' => ['nullable', Rule::in(['none', 'tls', 'reality'])],
            'sni' => ['nullable', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
            'network' => ['nullable', Rule::in(['tcp', 'ws', 'grpc', 'http'])],
            'flow' => ['nullable', 'string', 'max:100'],
            'fingerprint' => ['nullable', 'string', 'max:50'],
            'encryption' => ['nullable', 'string', 'max:255'],
            'alpn' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'enabled' => ['nullable', 'boolean'],
        ]) + ['enabled' => $request->boolean('enabled')];
    }

    /**
     * 额外参数文本（每行 key=value）→ extras 数组
     */
    private function parseExtras(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $extras = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $extras[trim($key)] = trim($value);
        }

        return $extras ?: null;
    }
}
