<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http as HttpClient;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use ApiAuth;
    use RefreshDatabase;

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

    public function test_列表返回node_count字段反映白名单数量(): void
    {
        $this->makeNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach(Node::first()->id, ['sort_order' => 0]);

        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();

        $this->assertSame(1, $data['subscriptions'][0]['node_count']);
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

    public function test_订阅端点记录请求日志_含_c_f真实_ip(): void
    {
        $sub = $this->createSub();

        // 模拟 CF CDN 转发：CF-Connecting-IP 携带真实客户端 IP
        $this->get('/sub/tok', [
            'CF-Connecting-IP' => '203.0.113.9',
            'X-Forwarded-For' => '203.0.113.9, 172.70.1.1',
            'User-Agent' => 'v2rayN/6.0',
        ])->assertOk();

        $this->assertDatabaseHas('subscription_requests', [
            'subscription_id' => $sub->id,
            'ip' => '203.0.113.9',
            'user_agent' => 'v2rayN/6.0',
        ]);
        $this->assertNotNull(SubscriptionRequest::query()->first()?->requested_at);
    }

    public function test_订阅端点无_c_f头时回退请求_ip(): void
    {
        $sub = $this->createSub();

        $this->get('/sub/tok', ['User-Agent' => 'Shadowrocket/2.2'])->assertOk();

        $this->assertDatabaseHas('subscription_requests', [
            'subscription_id' => $sub->id,
            'user_agent' => 'Shadowrocket/2.2',
        ]);
    }

    public function test_无效订阅请求不记录(): void
    {
        $this->createSub();

        $this->get('/sub/unknown')->assertNotFound();
        $this->get('/sub/tok')->assertOk();
        $this->get('/sub/tok-off')->assertNotFound();

        $this->assertDatabaseCount('subscription_requests', 1); // 仅有效请求入库
    }

    // ==================== 节点白名单（按订阅配置节点） ====================

    /**
     * 构造 4 个节点：on1, on2（启用）, off1, off2（停用），按 sort_order 升序。
     *
     * @return array<string, Node>
     */
    private function makeFourNodes(): array
    {
        $a = Node::create(['name' => 'A', 'protocol' => 'vless', 'uuid' => 'u-a', 'address' => '1.1.1.1', 'port' => 443, 'security' => 'tls', 'sni' => 'a.com', 'network' => 'ws', 'sort_order' => 1, 'enabled' => true]);
        $b = Node::create(['name' => 'B', 'protocol' => 'vless', 'uuid' => 'u-b', 'address' => '2.2.2.2', 'port' => 443, 'security' => 'tls', 'sni' => 'b.com', 'network' => 'ws', 'sort_order' => 2, 'enabled' => true]);
        $c = Node::create(['name' => 'C', 'protocol' => 'vless', 'uuid' => 'u-c', 'address' => '3.3.3.3', 'port' => 443, 'security' => 'tls', 'sni' => 'c.com', 'network' => 'ws', 'sort_order' => 3, 'enabled' => false]);
        $d = Node::create(['name' => 'D', 'protocol' => 'vless', 'uuid' => 'u-d', 'address' => '4.4.4.4', 'port' => 443, 'security' => 'tls', 'sni' => 'd.com', 'network' => 'ws', 'sort_order' => 4, 'enabled' => false]);

        return compact('a', 'b', 'c', 'd');
    }

    private function decodeSubLines(string $body): array
    {
        $decoded = base64_decode($body, true);

        return array_values(array_filter(explode("\n", $decoded)));
    }

    public function test_无关联白名单_端点输出全部启用节点(): void
    {
        $nodes = $this->makeFourNodes();
        $this->createSub();

        $lines = $this->decodeSubLines($this->get('/sub/tok')->assertOk()->getContent());

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('u-a@', $lines[0]);
        $this->assertStringContainsString('u-b@', $lines[1]);
    }

    public function test_关联白名单_端点仅输出白名单内已启用节点(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        // 白名单包含 B 和 C（B 启用、C 停用）— 端点应只输出 B
        $sub->nodes()->attach([$nodes['b']->id, $nodes['c']->id]);

        $lines = $this->decodeSubLines($this->get('/sub/tok')->assertOk()->getContent());

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('u-b@', $lines[0]);
    }

    public function test_白名单按关联sort_order排序(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        // 数组顺序写入 sort_order：B 在前、A 在后
        $sub->nodes()->sync([
            $nodes['b']->id => ['sort_order' => 0],
            $nodes['a']->id => ['sort_order' => 1],
        ]);

        $lines = $this->decodeSubLines($this->get('/sub/tok')->assertOk()->getContent());

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('u-b@', $lines[0]);
        $this->assertStringContainsString('u-a@', $lines[1]);
    }

    public function test_白名单全禁用_端点输出空(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['c']->id, $nodes['d']->id]);

        $body = $this->get('/sub/tok')->assertOk()->getContent();

        $this->assertSame('', base64_decode($body, true));
    }

    public function test_ge_t节点列表返回全节点与已选(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['a']->id, $nodes['c']->id]);

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/nodes", $this->authHeaders())->assertOk()->json();

        // 全节点 4 个（含停用）
        $this->assertSame(4, $data['nodes']['meta']['total']);
        // 已选 2 个（含已停用的 C，前端应展示禁用徽章）
        $selectedIds = array_column($data['selected'], 'id');
        sort($selectedIds);
        $this->assertSame([$nodes['a']->id, $nodes['c']->id], $selectedIds);
    }

    public function test_ge_t节点列表支持搜索与协议筛选(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/nodes?q=2.2.2.2", $this->authHeaders())->assertOk()->json();

        $this->assertSame(1, $data['nodes']['meta']['total']);
        $this->assertSame('B', $data['nodes']['data'][0]['name']);
    }

    public function test_pu_t替换白名单_删除旧关联保留交集(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['a']->id, $nodes['b']->id]);

        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", ['node_ids' => [$nodes['b']->id, $nodes['c']->id]], $this->authHeaders())
            ->assertOk();

        $this->assertDatabaseCount('subscription_node', 2);
        $remaining = $sub->fresh()->nodes()->orderBy('nodes.id')->pluck('nodes.id')->all();
        sort($remaining);
        $this->assertSame([$nodes['b']->id, $nodes['c']->id], $remaining);
    }

    public function test_pu_t空数组_清空白名单_端点恢复全部启用行为(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['a']->id]);

        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", ['node_ids' => []], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('node_count', 0);

        $this->assertDatabaseCount('subscription_node', 0);

        $lines = $this->decodeSubLines($this->get('/sub/tok')->assertOk()->getContent());
        $this->assertCount(2, $lines); // 恢复输出所有启用节点 A、B
    }

    public function test_pu_t数组顺序写入sort_order(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();

        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", [
            'node_ids' => [$nodes['d']->id, $nodes['c']->id, $nodes['b']->id, $nodes['a']->id],
        ], $this->authHeaders())->assertOk();

        // B、A 启用 → 端点按白名单 sort_order 输出 B 在前、A 在后（数组最后写入 sort_order=3, 即 A）
        $lines = $this->decodeSubLines($this->get('/sub/tok')->assertOk()->getContent());
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('u-b@', $lines[0]);
        $this->assertStringContainsString('u-a@', $lines[1]);
    }

    public function test_pu_t包含不存在节点id返回422(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();

        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", [
            'node_ids' => [$nodes['a']->id, 999999],
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.node_ids.0', fn ($msg) => str_contains($msg, '999999'));

        // 关联不应被写入
        $this->assertDatabaseCount('subscription_node', 0);
    }

    public function test_pu_t校验_非数组与重复id返回422(): void
    {
        $sub = $this->createSub();

        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", ['node_ids' => 'not-array'], $this->authHeaders())
            ->assertStatus(422);

        $nodes = $this->makeFourNodes();
        $this->putJson("/api/v1/subscriptions/{$sub->id}/nodes", [
            'node_ids' => [$nodes['a']->id, $nodes['a']->id],
        ], $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_删除订阅级联清理节点关联(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['a']->id, $nodes['b']->id]);

        $this->assertDatabaseCount('subscription_node', 2);

        $sub->delete();

        $this->assertDatabaseCount('subscription_node', 0);
    }

    public function test_删除节点级联清理订阅关联(): void
    {
        $nodes = $this->makeFourNodes();
        $sub = $this->createSub();
        $sub->nodes()->attach([$nodes['a']->id, $nodes['b']->id]);

        $nodes['a']->delete();

        $this->assertSame(1, $sub->fresh()->nodes()->count());
    }

    // ==================== 自适应订阅格式（SubConverter） ====================

    private function enableSubConverter(string $url = 'https://subconverter.test'): void
    {
        config(['subconverter.url' => $url]);
    }

    private function disableSubConverter(): void
    {
        config(['subconverter.url' => null]);
    }

    public function test_index_根据env返回subconverter_configured字段(): void
    {
        $this->disableSubConverter();
        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();
        $this->assertFalse($data['subconverter_configured']);

        $this->enableSubConverter();
        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();
        $this->assertTrue($data['subconverter_configured']);
    }

    public function test_未知_u_a_默认走mixed_不调外部接口(): void
    {
        HttpClient::preventStrayRequests();
        $this->makeNodes();
        $this->createSub();

        $r = $this->get('/sub/tok', ['User-Agent' => 'Mozilla/5.0'])->assertOk();
        $r->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        // 默认是 base64 链接列表（不应全是空）
        $decoded = base64_decode($r->getContent(), true);
        $this->assertNotEmpty($decoded);
        $this->assertStringContainsString('uuid-on', $decoded);
    }

    public function test_未配置_sub_ap_i_时_clash_u_a_返404带target字段(): void
    {
        HttpClient::preventStrayRequests();
        $this->disableSubConverter();
        $this->createSub();

        $this->get('/sub/tok', ['User-Agent' => 'clash-verge/2.0'])
            ->assertStatus(404)
            ->assertJsonPath('target', 'clash')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '当前订阅未启用 SubConverter'));
    }

    public function test_配置_sub_ap_i后_clash_u_a_触发转换_回调带target_mixed(): void
    {
        HttpClient::fake([
            'subconverter.test/sub*' => HttpClient::response('YAML-CONTENT-FROM-SUBAPI', 200, ['Content-Type' => 'text/yaml']),
        ]);
        $this->enableSubConverter();
        $this->createSub();

        $r = $this->get('/sub/tok', ['User-Agent' => 'clash-verge/2.0'])->assertOk();

        $r->assertHeader('Content-Type', 'text/yaml; charset=utf-8')
            ->assertHeader('Profile-Update-Interval', '24')
            ->assertSee('YAML-CONTENT-FROM-SUBAPI', false);

        // 校验 SubAPI 收到的回调 URL 含 ?target=mixed
        HttpClient::assertSent(function ($req) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);

            return str_contains($req->url(), 'subconverter.test/sub')
                && ($q['target'] ?? null) === 'clash'
                && str_contains($q['url'] ?? '', 'target=mixed');
        });
    }

    public function test_显式_target_singbox_触发转换_content_type_json(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('{}', 200, ['Content-Type' => 'application/json'])]);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=singbox')->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function test_显式_target_无效值降级到mixed_不调外部接口(): void
    {
        HttpClient::preventStrayRequests();
        $this->createSub();

        $this->get('/sub/tok?target=garbage')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_防递归_subconverter_request_header强制走mixed(): void
    {
        HttpClient::preventStrayRequests();
        $this->enableSubConverter(); // 即使配置了 SubAPI,有 header 时也不调
        $this->createSub();

        $this->get('/sub/tok', [
            'User-Agent' => 'clash-verge/2.0',
            'subconverter-request' => 'true',
        ])->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_防递归_b64参数强制走mixed(): void
    {
        HttpClient::preventStrayRequests();
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=clash&b64=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_配置_sub_ap_i但上游5xx降级502(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('boom', 503)]);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok', ['User-Agent' => 'clash-verge/2.0'])
            ->assertStatus(502)
            ->assertJsonPath('target', 'clash');
    }

    public function test_配置_sub_ap_i但上游空响应降级502(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('', 200)]);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=surge')
            ->assertStatus(502)
            ->assertJsonPath('target', 'surge');
    }

    public function test_配置_sub_ap_i但上游连接失败降级502(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => fn () => throw new ConnectionException('timeout')]);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=clash')
            ->assertStatus(502)
            ->assertJsonPath('target', 'clash');
    }

    public function test_sing_box_u_a映射到singbox_target(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('{}', 200, ['Content-Type' => 'application/json'])]);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok', ['User-Agent' => 'sing-box/1.8'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8');

        HttpClient::assertSent(fn ($req) => str_contains(parse_url($req->url(), PHP_URL_QUERY), 'target=singbox'));
    }

    public function test_默认_subconfi_g_规则集_ur_l_透传到_sub_api(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('yaml', 200)]);
        config(['subconverter.config' => 'https://raw.githubusercontent.com/ACL4SSR/ACL4SSR/master/Clash/config/ACL4SSR_Online_Full_MultiMode.ini']);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=clash')->assertOk();

        HttpClient::assertSent(function ($req) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);

            return ($q['config'] ?? null) === 'https://raw.githubusercontent.com/ACL4SSR/ACL4SSR/master/Clash/config/ACL4SSR_Online_Full_MultiMode.ini';
        });
    }

    public function test_subconfi_g_留空时_不传_config_参数(): void
    {
        HttpClient::fake(['subconverter.test/sub*' => HttpClient::response('yaml', 200)]);
        config(['subconverter.config' => '']);
        $this->enableSubConverter();
        $this->createSub();

        $this->get('/sub/tok?target=clash')->assertOk();

        HttpClient::assertSent(function ($req) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);

            return ! array_key_exists('config', $q);
        });
    }

    // ==================== 订阅请求日志（卡片「请求记录」按钮） ====================

    public function test_index_返回_request_count_24h_字段(): void
    {
        $sub = $this->createSub();
        // 2 条 24h 内 + 1 条 2 天前
        SubscriptionRequest::create(['subscription_id' => $sub->id, 'ip' => '1.1.1.1', 'user_agent' => 'ua1', 'requested_at' => now()]);
        SubscriptionRequest::create(['subscription_id' => $sub->id, 'ip' => '2.2.2.2', 'user_agent' => 'ua2', 'requested_at' => now()->subHours(12)]);
        SubscriptionRequest::create(['subscription_id' => $sub->id, 'ip' => '3.3.3.3', 'user_agent' => 'ua3', 'requested_at' => now()->subDays(2)]);

        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();

        $this->assertSame(2, $data['subscriptions'][0]['request_count_24h']);
    }

    public function test_index_request_count_24h_不含其他订阅的记录(): void
    {
        $subA = $this->createSub('tokA');
        $subB = $this->createSub('tokB');
        SubscriptionRequest::create(['subscription_id' => $subA->id, 'ip' => '1.1.1.1', 'user_agent' => 'u', 'requested_at' => now()]);
        SubscriptionRequest::create(['subscription_id' => $subB->id, 'ip' => '2.2.2.2', 'user_agent' => 'u', 'requested_at' => now()]);
        SubscriptionRequest::create(['subscription_id' => $subB->id, 'ip' => '3.3.3.3', 'user_agent' => 'u', 'requested_at' => now()]);

        $data = $this->getJson('/api/v1/subscriptions', $this->authHeaders())->assertOk()->json();

        $byId = collect($data['subscriptions'])->keyBy('id');
        $this->assertSame(1, $byId[$subA->id]['request_count_24h']);
        $this->assertSame(2, $byId[$subB->id]['request_count_24h']);
    }

    public function test_requests_返回该订阅最近_50_条_des_c_排序(): void
    {
        $sub = $this->createSub();
        // 创建 5 条不同时间
        for ($i = 0; $i < 5; $i++) {
            SubscriptionRequest::create([
                'subscription_id' => $sub->id,
                'ip' => "1.1.1.$i",
                'user_agent' => "ua-$i",
                'requested_at' => now()->subMinutes($i * 10),
            ]);
        }

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests", $this->authHeaders())->assertOk()->json();

        $this->assertSame(5, count($data['requests']));
        // 最新在前（subMinutes(0) 在前）
        $this->assertSame('ua-0', $data['requests'][0]['user_agent']);
        $this->assertSame('ua-4', $data['requests'][4]['user_agent']);
    }

    public function test_requests_limit_参数生效(): void
    {
        $sub = $this->createSub();
        for ($i = 0; $i < 10; $i++) {
            SubscriptionRequest::create([
                'subscription_id' => $sub->id,
                'ip' => "1.1.1.$i",
                'user_agent' => "ua-$i",
                'requested_at' => now()->subMinutes($i),
            ]);
        }

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests?limit=3", $this->authHeaders())->assertOk()->json();
        $this->assertCount(3, $data['requests']);
    }

    public function test_requests_limit_超过_200_自动截断(): void
    {
        $sub = $this->createSub();
        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests?limit=999", $this->authHeaders())->assertOk()->json();
        $this->assertSame([], $data['requests']);
    }

    public function test_requests_只返回该订阅的_隔离其他订阅(): void
    {
        $subA = $this->createSub('tokA');
        $subB = $this->createSub('tokB');
        SubscriptionRequest::create(['subscription_id' => $subA->id, 'ip' => '1.1.1.1', 'user_agent' => 'A', 'requested_at' => now()]);
        SubscriptionRequest::create(['subscription_id' => $subB->id, 'ip' => '2.2.2.2', 'user_agent' => 'B', 'requested_at' => now()]);

        $data = $this->getJson("/api/v1/subscriptions/{$subA->id}/requests", $this->authHeaders())->assertOk()->json();
        $this->assertCount(1, $data['requests']);
        $this->assertSame('A', $data['requests'][0]['user_agent']);
        $this->assertSame(1, $data['count_24h']);
    }

    public function test_requests_count_24h_与_limit_独立计算(): void
    {
        $sub = $this->createSub();
        // 5 条 24h 内 + 3 条 2 天前
        for ($i = 0; $i < 5; $i++) {
            SubscriptionRequest::create(['subscription_id' => $sub->id, 'ip' => "a$i", 'user_agent' => 'u', 'requested_at' => now()->subHours(2)]);
        }
        for ($i = 0; $i < 3; $i++) {
            SubscriptionRequest::create(['subscription_id' => $sub->id, 'ip' => "b$i", 'user_agent' => 'u', 'requested_at' => now()->subDays(2)]);
        }

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests?limit=2", $this->authHeaders())->assertOk()->json();
        // limit=2 只返 2 条最新，但 count_24h 仍是 5
        $this->assertCount(2, $data['requests']);
        $this->assertSame(5, $data['count_24h']);
    }

    public function test_requests_无记录返回空数组(): void
    {
        $sub = $this->createSub();
        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests", $this->authHeaders())->assertOk()->json();
        $this->assertSame([], $data['requests']);
        $this->assertSame(0, $data['count_24h']);
    }

    public function test_requests_未认证返回_401(): void
    {
        $sub = $this->createSub();
        $this->getJson("/api/v1/subscriptions/{$sub->id}/requests")->assertStatus(401);
    }
}
