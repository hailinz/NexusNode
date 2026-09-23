<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeApiTest extends TestCase
{
    use ApiAuth;
    use RefreshDatabase;

    private function makeNode(string $name = 'A', array $overrides = []): Node
    {
        return Node::create([
            'name' => $name, 'protocol' => 'vless', 'uuid' => 'u-'.$name,
            'address' => '10.0.0.1', 'port' => 443, 'security' => 'tls',
            'sni' => strtolower($name).'.com', 'network' => 'ws', 'enabled' => true,
            ...$overrides,
        ]);
    }

    private function headers(): array
    {
        return $this->authHeaders();
    }

    public function test_创建与更新节点(): void
    {
        $headers = $this->headers();

        $create = $this->postJson('/api/v1/nodes', [
            'name' => 'New', 'protocol' => 'vless', 'uuid' => 'u-new',
            'address' => '1.2.3.4', 'port' => 443, 'security' => 'tls',
            'sni' => 'new.com', 'network' => 'ws', 'enabled' => true,
            'extras_text' => "insecure=0\npcs=ABC",
        ], $headers);

        $create->assertStatus(201);
        $node = Node::where('name', 'New')->first();
        $this->assertSame('ABC', $node->extras['pcs']);

        $this->putJson("/api/v1/nodes/{$node->id}", [
            'name' => 'New2', 'protocol' => 'vless', 'address' => '1.2.3.4',
            'port' => 443, 'sni' => 'new.com', 'enabled' => true,
        ], $headers)->assertOk();

        $this->assertSame('New2', $node->fresh()->name);
    }

    public function test_删除与批量删除(): void
    {
        $a = $this->makeNode('A');
        $b = $this->makeNode('B');

        $this->deleteJson('/api/v1/nodes/bulk', ['ids' => [$a->id, $b->id]], $this->headers())
            ->assertOk();

        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_启停切换(): void
    {
        $node = $this->makeNode('A', ['enabled' => false]);

        $res = $this->patchJson("/api/v1/nodes/{$node->id}/toggle", [], $this->headers())->assertOk();

        $this->assertTrue($res->json('enabled'));
    }

    public function test_移动排序归一化(): void
    {
        $a = $this->makeNode('A');
        $b = $this->makeNode('B');
        $c = $this->makeNode('C');

        $this->patchJson("/api/v1/nodes/{$a->id}/move/down", [], $this->headers())->assertOk();

        $order = Node::query()->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['B', 'A', 'C'], $order);
        $this->assertSame([0, 1, 2], Node::query()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_encryption_字段允许_255_字符(): void
    {
        $payload = 'chacha20-ietf-poly1305:'.str_repeat('a', 60);
        $this->assertGreaterThan(50, strlen($payload));
        $this->assertLessThanOrEqual(255, strlen($payload));

        $node = $this->makeNode('A');
        $this->putJson("/api/v1/nodes/{$node->id}", [
            'name' => 'A', 'protocol' => 'ss',
            'address' => '1.2.3.4', 'port' => 443,
            'encryption' => $payload,
            'enabled' => true,
        ], $this->headers())->assertOk();

        $this->assertSame($payload, $node->fresh()->encryption);
    }

    public function test_encryption_字段超过_255_字符返回_422(): void
    {
        $node = $this->makeNode('A');
        $this->putJson("/api/v1/nodes/{$node->id}", [
            'name' => 'A', 'protocol' => 'ss',
            'address' => '1.2.3.4', 'port' => 443,
            'encryption' => str_repeat('x', 256),
            'enabled' => true,
        ], $this->headers())->assertStatus(422);
    }

    public function test_reorder_按数组顺序重排_不影响其他节点(): void
    {
        $a = $this->makeNode('A', ['sort_order' => 10]);
        $b = $this->makeNode('B', ['sort_order' => 20]);
        $c = $this->makeNode('C', ['sort_order' => 30]);
        $x = $this->makeNode('X', ['sort_order' => 40]); // 不参与本次 reorder

        // 把 [A, B, C] 拖成 [C, A, B]
        $this->patchJson('/api/v1/nodes/reorder', ['node_ids' => [$c->id, $a->id, $b->id]], $this->headers())
            ->assertOk();

        $names = Node::query()->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['C', 'A', 'B', 'X'], $names);
        // X 的 sort_order 不变（40，最大）
    }

    public function test_reorder_不存在的id返回_422(): void
    {
        $a = $this->makeNode('A');
        $this->patchJson('/api/v1/nodes/reorder', ['node_ids' => [$a->id, 999999]], $this->headers())
            ->assertStatus(422);
    }

    public function test_reorder_重复id返回_422(): void
    {
        $a = $this->makeNode('A');
        $this->patchJson('/api/v1/nodes/reorder', ['node_ids' => [$a->id, $a->id]], $this->headers())
            ->assertStatus(422);
    }

    public function test_reorder_空数组返回_422(): void
    {
        $this->patchJson('/api/v1/nodes/reorder', ['node_ids' => []], $this->headers())
            ->assertStatus(422);
    }

    public function test_reorder_未认证返回_401(): void
    {
        $this->patchJson('/api/v1/nodes/reorder', ['node_ids' => [1]])->assertStatus(401);
    }

    public function test_筛选与搜索(): void
    {
        $this->makeNode('CF1', ['address' => '1.1.1.1', 'sni' => 'cf.com']);
        $this->makeNode('Plain', ['address' => 'plain.com', 'sni' => 'plain.com']);

        $cf = $this->getJson('/api/v1/nodes?filter=cf', $this->headers())->assertOk()->json();
        $this->assertSame(1, $data_count = count($cf['data']));
        $this->assertSame('CF1', $cf['data'][0]['name']);

        $search = $this->getJson('/api/v1/nodes?q=Plain', $this->headers())->assertOk()->json();
        $this->assertSame(1, count($search['data']));
        $this->assertSame('Plain', $search['data'][0]['name']);

        $this->assertSame(1, $cf['counts']['plain']);
    }

    public function test_按模板筛选生成节点(): void
    {
        $base = $this->makeNode('Base', ['address' => 'base.com', 'sni' => 'base.com']);
        $child = Node::create([
            'name' => 'Base · 9.9.9.9', 'protocol' => 'vless', 'uuid' => 'u',
            'address' => '9.9.9.9', 'port' => 443, 'security' => 'tls',
            'sni' => 'base.com', 'network' => 'ws', 'enabled' => true,
            'is_generated' => true, 'parent_node_id' => $base->id,
        ]);

        $data = $this->getJson("/api/v1/nodes?parent={$base->id}", $this->headers())->assertOk()->json();

        $this->assertSame([$child->id], array_column($data['data'], 'id'));
        $this->assertSame($base->id, $data['parent']['id']);
    }

    public function test_未认证返回401(): void
    {
        $this->getJson('/api/v1/nodes')->assertStatus(401);
    }
}
