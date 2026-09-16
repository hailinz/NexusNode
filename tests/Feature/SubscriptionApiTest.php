<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Subscription;
use App\Services\ProxyUriBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    private function makeNodes(): void
    {
        Node::create([
            'name' => 'NodeOn', 'protocol' => 'vless', 'uuid' => 'uuid-on',
            'address' => '1.2.3.4', 'port' => 443, 'security' => 'tls',
            'sni' => 'a.com', 'network' => 'ws', 'enabled' => true,
        ]);
        Node::create([
            'name' => 'NodeOff', 'protocol' => 'vless', 'uuid' => 'uuid-off',
            'address' => '5.6.7.8', 'port' => 443, 'security' => 'tls',
            'sni' => 'b.com', 'network' => 'ws', 'enabled' => false,
        ]);
    }

    private function createSub(string $token = 'tok', bool $enabled = true): Subscription
    {
        return Subscription::create(['name' => 'T', 'token' => $token, 'enabled' => $enabled]);
    }

    public function test_列表返回订阅与启用节点数(): void
    {
        $this->makeNodes();
        $this->createSub();

        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();

        $this->assertSame(1, $data['enabled_node_count']);
        $this->assertCount(1, $data['subscriptions']);
        $this->assertStringContainsString('/sub/', $data['subscriptions'][0]['url']);
    }

    public function test_创建订阅自动生成令牌(): void
    {
        $res = $this->postJson('/api/v1/subscriptions', ['name' => '我的订阅'], $this->authHeaders())
            ->assertStatus(201);

        $this->assertSame('我的订阅', $res->json('data.id') !== null ? Subscription::first()->name : null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Subscription::first()->token);
    }

    public function test_启停与重置令牌(): void
    {
        $sub = $this->createSub();

        $this->patchJson("/api/v1/subscriptions/{$sub->id}/toggle", [], $this->authHeaders())->assertOk();
        $this->assertFalse($sub->fresh()->enabled);

        $oldToken = $sub->token;
        $res = $this->patchJson("/api/v1/subscriptions/{$sub->id}/regenerate", [], $this->authHeaders())->assertOk();
        $this->assertNotSame($oldToken, $sub->fresh()->token);
        $this->assertStringContainsString($sub->fresh()->token, $res->json('url'));
    }

    public function test_删除订阅(): void
    {
        $sub = $this->createSub();

        $this->deleteJson("/api/v1/subscriptions/{$sub->id}", [], $this->authHeaders())->assertOk();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_订阅端点输出base64且排除停用节点(): void
    {
        $this->makeNodes();
        $this->createSub();

        $content = $this->get('/sub/tok')->assertOk()->getContent();
        $decoded = base64_decode($content, true);
        $lines = array_filter(explode("\n", $decoded));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('uuid-on', $lines[0]);
    }

    public function test_停用订阅与未知令牌返回404(): void
    {
        $this->createSub('tok-off', false);

        $this->get('/sub/tok-off')->assertNotFound();
        $this->get('/sub/unknown')->assertNotFound();
    }

    public function test_raw模式输出明文(): void
    {
        $this->makeNodes();
        Subscription::create(['name' => 'T', 'token' => 'tok', 'enabled' => true]);

        $content = $this->get('/sub/tok?raw=1')->assertOk()->getContent();

        $this->assertStringStartsWith('vless://uuid-on@1.2.3.4:443?', $content);
    }
}
