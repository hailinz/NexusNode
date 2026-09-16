<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\PreferredIp;
use App\Models\Subscription;
use App\Services\PreferredIpImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    public function test_统计数据正确(): void
    {
        // 1 个 CF 节点 + 1 个直连域名节点
        Node::create([
            'name' => 'CF', 'protocol' => 'vless', 'uuid' => 'u1',
            'address' => '1.2.3.4', 'port' => 443, 'security' => 'tls',
            'sni' => 'cf.com', 'network' => 'ws', 'enabled' => true,
        ]);
        Node::create([
            'name' => 'Direct', 'protocol' => 'vless', 'uuid' => 'u2',
            'address' => 'd.com', 'port' => 9090, 'security' => null,
            'sni' => null, 'network' => 'tcp', 'enabled' => true,
        ]);
        PreferredIp::create(['ip' => '1.1.1.1', 'enabled' => true]);
        Subscription::create(['name' => 'S1', 'token' => 't', 'enabled' => true]);

        $data = $this->getJson('/api/v1/dashboard', $this->authHeaders())->assertOk()->json('stats');

        $this->assertSame(2, $data['total_nodes']);
        $this->assertSame(1, $data['cf_nodes']);
        $this->assertSame(1, $data['ip_count']);
        $this->assertSame(1, $data['sub_count']);
    }
}
