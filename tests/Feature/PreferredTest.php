<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\PreferredIp;
use App\Services\PreferredIpImporter;
use App\Services\PreferredNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferredTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    public function test_优选IP文本导入带备注(): void
    {
        $stats = app(PreferredIpImporter::class)->importText(implode("\n", [
            '104.16.1.1#圣何塞',
            '172.64.2.2 香港',
            '104.18.3.3',
            'not-an-ip',
        ]));

        $this->assertSame(3, $stats['created']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame('圣何塞', PreferredIp::where('ip', '104.16.1.1')->value('remarks'));
        $this->assertSame('香港', PreferredIp::where('ip', '172.64.2.2')->value('remarks'));
    }

    public function test_优选IP_CSV导入(): void
    {
        $csv = implode("\n", [
            'IP 地址,已发送,已接收,丢包率,平均延迟,下载速度 (MB/s)',
            '1.1.1.1,4,4,0.00,52.31,12.34',
            '2.2.2.2,4,4,0.00,98.77,5.67',
        ]);

        $stats = app(PreferredIpImporter::class)->importCsv($csv);

        $this->assertSame(2, $stats['created']);

        $first = PreferredIp::where('ip', '1.1.1.1')->first();
        $this->assertSame(52.31, $first->latency_ms);
        $this->assertSame(0.0, $first->loss_rate);
        $this->assertSame(12.34, $first->download_speed);
    }

    public function test_优选IP_CSV无表头按默认列序(): void
    {
        $csv = implode("\n", [
            '3.3.3.3,4,4,0.50,150.20,8.88',
        ]);

        $stats = app(PreferredIpImporter::class)->importCsv($csv);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(150.20, PreferredIp::where('ip', '3.3.3.3')->value('latency_ms'));
    }

    public function test_列表测速批量回写延迟与丢包率(): void
    {
        PreferredIp::create(['ip' => '1.1.1.1', 'latency_ms' => 999, 'loss_rate' => 100, 'enabled' => false]);
        PreferredIp::create(['ip' => '2.2.2.2', 'latency_ms' => 888, 'enabled' => true]);
        PreferredIp::create(['ip' => '4.4.4.4', 'latency_ms' => 777, 'enabled' => true]);

        $response = $this->postJson('/api/v1/preferred-ips/metrics-batch', [
            'entries' => [
                ['ip' => '1.1.1.1', 'latency_ms' => 52, 'loss_rate' => 0],
                ['ip' => '2.2.2.2', 'latency_ms' => null, 'loss_rate' => 25], // 全超时：只更新丢包率，保留旧延迟
                ['ip' => 'not-an-ip', 'latency_ms' => 10, 'loss_rate' => 0],  // 非法 IP：跳过
                ['ip' => '9.9.9.9', 'latency_ms' => 10, 'loss_rate' => 0],    // 池中不存在：跳过
            ],
        ], $this->authHeaders());

        $response->assertOk()->assertJson(['saved' => 2]);

        $first = PreferredIp::where('ip', '1.1.1.1')->first();
        $this->assertSame(52.0, $first->latency_ms);
        $this->assertSame(0.0, $first->loss_rate);
        $this->assertFalse($first->enabled); // 回写不改启用状态

        $second = PreferredIp::where('ip', '2.2.2.2')->first();
        $this->assertSame(888.0, $second->latency_ms);
        $this->assertSame(25.0, $second->loss_rate);

        // 4.4.4.4 未提交，保持原值
        $this->assertNull(PreferredIp::where('ip', '4.4.4.4')->value('loss_rate'));
    }

    public function test_优选生成替换地址保留SNI(): void
    {
        // 模板：443 + 直连自身域名（地址 = SNI）
        $base = Node::create([
            'name' => 'OC-Osaka_B CF', 'protocol' => 'vless', 'uuid' => 'uid-1',
            'address' => 'bak.example.com', 'port' => 443, 'security' => 'tls',
            'sni' => 'bak.example.com', 'host' => 'bak.example.com', 'path' => '/dl/',
            'network' => 'ws', 'enabled' => true,
        ]);

        $ip1 = PreferredIp::create(['ip' => '104.16.1.1', 'remarks' => '圣何塞', 'enabled' => true]);
        $ip2 = PreferredIp::create(['ip' => '104.16.1.2', 'enabled' => true]);
        PreferredIp::create(['ip' => '104.16.1.3', 'enabled' => false]); // 停用的不参与

        $stats = app(PreferredNodeService::class)->generate([$base->id], [$ip1->id, $ip2->id]);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, Node::where('is_generated', true)->count());

        $child = Node::where('is_generated', true)->where('address', '104.16.1.1')->first();
        $this->assertNotNull($child);
        $this->assertSame('bak.example.com', $child->sni);        // SNI 保留
        $this->assertSame($base->id, $child->parent_node_id);     // 来源可追溯
        $this->assertSame('OC-Osaka_B CF · 圣何塞', $child->name); // 命名 = 模板名 + IP 备注
        $this->assertSame('/dl/', $child->path);                   // 其余参数继承
    }

    public function test_优选生成幂等与覆盖(): void
    {
        $base = Node::create([
            'name' => 'Base', 'protocol' => 'vless', 'uuid' => 'u',
            'address' => 'x.com', 'port' => 443, 'security' => 'tls',
            'sni' => 'x.com', 'network' => 'ws', 'enabled' => true,
        ]);
        $ip = PreferredIp::create(['ip' => '2.2.2.2', 'enabled' => true]);
        $service = app(PreferredNodeService::class);

        $service->generate([$base->id], [$ip->id]);
        $stats2 = $service->generate([$base->id], [$ip->id], overwrite: true);
        $stats3 = $service->generate([$base->id], [$ip->id], overwrite: false);

        $this->assertSame(1, $stats2['updated']);
        $this->assertSame(1, $stats3['skipped']);
        $this->assertSame(1, Node::where('is_generated', true)->count()); // 不产生重复
    }

    public function test_无sni节点不能作为模板(): void
    {
        $plain = Node::create([
            'name' => 'Plain', 'protocol' => 'vless', 'uuid' => 'u',
            'address' => 'example.com', 'port' => 443, 'enabled' => true, // 无 sni
        ]);
        $ip = PreferredIp::create(['ip' => '2.2.2.2', 'enabled' => true]);

        $stats = app(PreferredNodeService::class)->generate([$plain->id], [$ip->id]);

        $this->assertSame(0, $stats['bases']);
        $this->assertSame(0, Node::where('is_generated', true)->count());
    }

    public function test_模板判定为443端口且地址与sni一致(): void
    {
        $mk = fn (string $address, int $port, string $sni) => Node::create([
            'name' => "T-{$address}-{$port}", 'protocol' => 'vless', 'uuid' => 'u',
            'address' => $address, 'port' => $port, 'security' => 'tls',
            'sni' => $sni, 'network' => 'ws', 'enabled' => true,
        ]);

        $template = $mk('bak.example.com', 443, 'bak.example.com');   // 443 + 地址=SNI → 模板
        $cfNode = $mk('104.16.1.1', 443, 'bak.example.com');          // 地址是 IP（CF 节点）→ 非模板
        $nonTls = $mk('v2.timht.com', 13356, 'v2.timht.com');         // 非 443 → 非模板
        $fronting = $mk('hk1.example.com', 443, 'bak.example.com');   // 域名前置（地址≠SNI）→ 非模板

        $templates = Node::query()->cfTemplate()->pluck('id');
        $this->assertTrue($templates->contains($template->id));
        $this->assertFalse($templates->contains($cfNode->id));
        $this->assertFalse($templates->contains($nonTls->id));
        $this->assertFalse($templates->contains($fronting->id));
    }

    public function test_按模板筛选已生成节点(): void
    {
        $base = Node::create([
            'name' => 'Base', 'protocol' => 'vless', 'uuid' => 'u',
            'address' => 'base.com', 'port' => 443, 'security' => 'tls',
            'sni' => 'base.com', 'network' => 'ws', 'enabled' => true,
        ]);
        $other = Node::create([
            'name' => 'Other', 'protocol' => 'vless', 'uuid' => 'u2',
            'address' => 'other.com', 'port' => 443, 'security' => 'tls',
            'sni' => 'other.com', 'network' => 'ws', 'enabled' => true,
        ]);
        $child = Node::create([
            'name' => 'Base · 9.9.9.9', 'protocol' => 'vless', 'uuid' => 'u',
            'address' => '9.9.9.9', 'port' => 443, 'security' => 'tls',
            'sni' => 'base.com', 'network' => 'ws', 'enabled' => true,
            'is_generated' => true, 'parent_node_id' => $base->id,
        ]);

        // 「已生成 N」入口 → /api/v1/nodes?parent=模板id 只返回该模板的生成节点
        $headers = $this->authHeaders();
        $data = $this->getJson('/api/v1/nodes?parent=' . $base->id, $headers)->assertOk()->json();

        $this->assertSame([$child->id], array_column($data['data'], 'id'));
        $this->assertSame($base->id, $data['parent']['id']);
        $this->assertSame('Base', $data['parent']['name']);
    }
}
