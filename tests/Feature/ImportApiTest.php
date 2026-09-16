<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use RefreshDatabase;
    use ApiAuth;

    public function test_导入接口(): void
    {
        $content = implode("\n", [
            'vless://uuid-a@1.2.3.4:443?security=tls&sni=a.com&type=ws&host=a.com&path=/ws#NodeA',
            'vless://uuid-b@5.6.7.8:443?security=tls&sni=b.com&type=tcp#NodeB',
            'bad-line',
        ]);

        $res = $this->postJson('/api/v1/imports/nodes', ['content' => $content], $this->authHeaders())
            ->assertOk();

        $this->assertSame(2, $res->json('stats.created'));
        $this->assertSame(1, $res->json('stats.failed'));
        $this->assertSame(2, \App\Models\Node::count());
    }

    public function test_导入接口内容为空422(): void
    {
        $this->postJson('/api/v1/imports/nodes', ['content' => '   '], $this->authHeaders())
            ->assertStatus(422);
    }
}
