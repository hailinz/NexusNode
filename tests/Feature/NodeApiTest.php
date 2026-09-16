<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeApiTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    private function makeNode(string $name = 'A', array $overrides = []): Node
    {
        return Node::create([
            'name' => $name, 'protocol' => 'vless', 'uuid' => 'u-' . $name,
            'address' => '10.0.0.1', 'port' => 443, 'security' => 'tls',
            'sni' => strtolower($name) . '.com', 'network' => 'ws', 'enabled' => true,
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
