<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\PreferredIp;
use App\Models\PreferredIpSource;
use App\Services\OnlinePreferredFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreferredIpApiTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        // 清空迁移 seed 的内置源，避免污染同步统计类断言
        PreferredIpSource::query()->delete();
    }

    private function makeSource(string $url, string $name = '测试源'): PreferredIpSource
    {
        return PreferredIpSource::create(['name' => $name, 'url' => $url, 'enabled' => true]);
    }

    private function makeIp(string $ip, array $extra = []): PreferredIp
    {
        return PreferredIp::create(['ip' => $ip, 'enabled' => true] + $extra);
    }

    // ---------- 在线优选源 API ----------

    public function test_在线源列表与添加(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/preferred-ips/sources', [
            'name' => 'CM 聚合', 'url' => 'https://addressesapi.090227.xyz/CloudFlareYes',
        ], $headers)->assertStatus(201);

        $data = $this->getJson('/api/v1/preferred-ips/sources', $headers)->assertOk()->json();
        $this->assertCount(1, $data['sources']);
        $this->assertSame('CM 聚合', $data['sources'][0]['name']);
    }

    public function test_全部同步与单源同步(): void
    {
        Http::fake([
            'good.example.com/*' => Http::response("1.1.1.1\n2.2.2.2"),
            'bad.example.com/*' => Http::response('oops', 500),
        ]);
        $good = $this->makeSource('https://good.example.com/a');
        $bad = $this->makeSource('https://bad.example.com/b');

        $stats = $this->postJson('/api/v1/preferred-ips/sources/sync-all', [], $this->authHeaders())
            ->assertOk()->json('stats');
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, PreferredIp::count());

        // 单源同步（已有数据 → 更新计数）
        $res = $this->postJson("/api/v1/preferred-ips/sources/{$good->id}/sync", [], $this->authHeaders())
            ->assertOk();
        $this->assertSame(2, $res->json('count'));
    }

    public function test_单源同步失败返回502(): void
    {
        $bad = $this->makeSource('https://bad.example.com/b');
        Http::fake(['bad.example.com/*' => Http::response('oops', 500)]);

        $this->postJson("/api/v1/preferred-ips/sources/{$bad->id}/sync", [], $this->authHeaders())
            ->assertStatus(502);
        $this->assertNotNull($bad->fresh()->last_error);
    }

    public function test_在线源启停与删除(): void
    {
        $source = $this->makeSource('https://example.com/a');

        $this->patchJson("/api/v1/preferred-ips/sources/{$source->id}/toggle", [], $this->authHeaders())->assertOk();
        $this->assertFalse($source->fresh()->enabled);

        $this->deleteJson("/api/v1/preferred-ips/sources/{$source->id}", [], $this->authHeaders())->assertOk();
        $this->assertDatabaseCount('preferred_ip_sources', 0);
    }

    // ---------- IP 池 API ----------

    public function test_文本批量添加(): void
    {
        $res = $this->postJson('/api/v1/preferred-ips', [
            'content' => "104.16.1.1#圣何塞\n172.64.2.2",
        ], $this->authHeaders())->assertOk();

        $this->assertSame(2, $res->json('stats.created'));
        $this->assertSame('圣何塞', PreferredIp::where('ip', '104.16.1.1')->value('remarks'));
    }

    public function test_CSV导入(): void
    {
        $csv = "IP 地址,已发送,已接收,丢包率,平均延迟,下载速度 (MB/s)\n1.1.1.1,4,4,0.00,52.31,12.34";

        $res = $this->postJson('/api/v1/preferred-ips/import-csv', ['content' => $csv], $this->authHeaders())
            ->assertOk();

        $this->assertSame(1, $res->json('stats.created'));
        $this->assertSame(52.31, PreferredIp::where('ip', '1.1.1.1')->value('latency_ms'));
    }

    public function test_IP列表排序_延迟升序未测排后(): void
    {
        $this->makeIp('3.3.3.3');
        $this->makeIp('2.2.2.2', ['latency_ms' => 200]);
        $this->makeIp('1.1.1.1', ['latency_ms' => 50]);

        $ips = $this->getJson('/api/v1/preferred-ips/list?sort=latency&dir=asc', $this->authHeaders())
            ->assertOk()->json('ips');

        $this->assertSame(['1.1.1.1', '2.2.2.2', '3.3.3.3'], array_column($ips, 'ip'));
    }

    public function test_IP列表排序_添加时间降序最新在前(): void
    {
        $this->makeIp('1.1.1.1');
        $this->makeIp('2.2.2.2');

        $ips = $this->getJson('/api/v1/preferred-ips/list', $this->authHeaders())->assertOk()->json('ips');

        $this->assertSame('2.2.2.2', $ips[0]['ip']);
    }

    public function test_IP启停与删除(): void
    {
        $this->makeIp('1.1.1.1');

        // 前端以 IP 字符串定位资源（路由按 ip 字段绑定）
        $this->patchJson('/api/v1/preferred-ips/1.1.1.1/toggle', [], $this->authHeaders())->assertOk();
        $this->assertFalse(PreferredIp::where('ip', '1.1.1.1')->value('enabled'));

        $this->deleteJson('/api/v1/preferred-ips/1.1.1.1', [], $this->authHeaders())->assertOk();
        $this->assertDatabaseCount('preferred_ips', 0);
    }

    public function test_批量删除选中IP(): void
    {
        $this->makeIp('1.1.1.1');
        $this->makeIp('2.2.2.2');
        $this->makeIp('3.3.3.3');

        $res = $this->deleteJson('/api/v1/preferred-ips/bulk', ['ips' => ['1.1.1.1', '2.2.2.2']], $this->authHeaders())
            ->assertOk();

        $this->assertSame(2, $res->json('deleted'));
        $this->assertDatabaseHas('preferred_ips', ['ip' => '3.3.3.3']);
    }

    public function test_批量删除空列表验证失败(): void
    {
        $this->makeIp('1.1.1.1');

        $this->deleteJson('/api/v1/preferred-ips/bulk', ['ips' => []], $this->authHeaders())
            ->assertUnprocessable();

        $this->assertDatabaseCount('preferred_ips', 1);
    }

    public function test_延迟批量回写保留备注与启用状态(): void
    {
        $ip = $this->makeIp('1.1.1.1', ['remarks' => '手写备注', 'enabled' => false]);

        $res = $this->postJson('/api/v1/preferred-ips/latency-batch', [
            'entries' => [['ip' => '1.1.1.1', 'latency_ms' => 45]],
        ], $this->authHeaders())->assertOk();

        $this->assertSame(1, $res->json('saved'));
        $fresh = $ip->fresh();
        $this->assertSame(45.0, $fresh->latency_ms);
        $this->assertSame('手写备注', $fresh->remarks);
        $this->assertTrue($fresh->enabled);
    }

    public function test_丢包率批量回写(): void
    {
        $this->makeIp('1.1.1.1');

        $res = $this->postJson('/api/v1/preferred-ips/metrics-batch', [
            'entries' => [['ip' => '1.1.1.1', 'latency_ms' => 88, 'loss_rate' => 12.5]],
        ], $this->authHeaders())->assertOk();

        $this->assertSame(1, $res->json('saved'));
        $fresh = PreferredIp::where('ip', '1.1.1.1')->first();
        $this->assertSame(88.0, $fresh->latency_ms);
        $this->assertSame(12.5, $fresh->loss_rate);
    }

    public function test_IP库代理接口(): void
    {
        Cache::flush();
        Http::fake([
            'cf.090227.xyz/ips-v4' => Http::response("104.16.1.1\n104.16.1.2"),
        ]);

        $data = $this->getJson('/api/v1/preferred-ips/online-pool?pool=cf-v4', $this->authHeaders())
            ->assertOk()->json();

        $this->assertSame(['104.16.1.1', '104.16.1.2'], $data['lines']);
        $this->assertSame('CF官方列表v4', $data['name']);
    }

    public function test_IP库代理_未知库422(): void
    {
        $this->getJson('/api/v1/preferred-ips/online-pool?pool=nope', $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_local池返回现有IP并补IPv6括号(): void
    {
        Http::fake(); // 拦截一切外部请求
        $this->makeIp('1.2.3.4');
        $this->makeIp('2606:4700::1');
        PreferredIp::create(['ip' => '5.6.7.8', 'enabled' => false]);

        $data = $this->getJson('/api/v1/preferred-ips/online-pool?pool=local', $this->authHeaders())
            ->assertOk()->json();

        $this->assertSame(['1.2.3.4', '[2606:4700::1]'], $data['lines']);
    }

    public function test_内置IP库与cf_html对齐7个(): void
    {
        $this->assertCount(7, OnlinePreferredFetcher::POOLS);
        $this->assertSame('CF官方列表v4', OnlinePreferredFetcher::POOLS['cf-v4']['name']);
        $this->assertSame('https://raw.githubusercontent.com/cmliu/cmliu/main/CF-CIDR.txt', OnlinePreferredFetcher::POOLS['cm-v4']['url']);
        $this->assertStringContainsString('as/13335/ipv6-aggregated.txt', OnlinePreferredFetcher::POOLS['as13335-v6']['url']);
        $this->assertStringContainsString('as/209242/ipv4-aggregated.txt', OnlinePreferredFetcher::POOLS['as209242-v4']['url']);
    }
}
