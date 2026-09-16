<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeIsCfTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $address, ?string $sni, int $port = 443): Node
    {
        return Node::create([
            'name' => "T-{$address}", 'protocol' => 'vless', 'uuid' => 'u',
            'address' => $address, 'port' => $port, 'security' => 'tls',
            'sni' => $sni, 'network' => 'ws', 'enabled' => true,
        ]);
    }

    public function test_IP加域名SNI判定为CF(): void
    {
        $this->assertTrue($this->make('172.64.229.31', 'bak.example.com')->is_cf);
        $this->assertTrue($this->make('1.1.1.1', 'visa.cn')->is_cf);
    }

    public function test_非443端口不构成CF(): void
    {
        // 条件一：端口必须是 443，地址与 SNI 再不同也不算
        $this->assertFalse($this->make('104.16.1.1', 'example.com', 8443)->is_cf);
        $this->assertFalse($this->make('hk1.example.com', 'bak.example.com', 2053)->is_cf);
        $this->assertFalse($this->make('1.2.3.4', 'example.com', 80)->is_cf);
    }

    public function test_IPv6地址判定为CF(): void
    {
        $this->assertTrue($this->make('2606:4700::1', 'example.com')->is_cf);
    }

    public function test_域名与SNI不同视为CF_域名前置(): void
    {
        // 域名前置：地址与 SNI 均为域名但不同
        $this->assertTrue($this->make('hk1.example.com', 'bak.example.com')->is_cf);
        // SNI 优选域名：用大公司域名做伪装
        $this->assertTrue($this->make('www.shopify.com', 'bak.vesven.com')->is_cf);
    }

    public function test_地址与SNI相同不是CF(): void
    {
        $this->assertFalse($this->make('v2.timht.com', 'v2.timht.com')->is_cf);
        $this->assertFalse($this->make('bak.vesven.com', 'bak.vesven.com')->is_cf);
    }

    public function test_域名比较不区分大小写与末尾点(): void
    {
        $this->assertFalse($this->make('HK1.Example.com', 'hk1.example.com')->is_cf);
        $this->assertFalse($this->make('v2.timht.com.', 'v2.timht.com')->is_cf);
    }

    public function test_地址带443端口剥离后与SNI比较(): void
    {
        // 端口通常是 443：v2.timht.com:443 等价于 v2.timht.com
        $this->assertFalse($this->make('v2.timht.com:443', 'v2.timht.com')->is_cf);
        // 剥端口后不同 → CF
        $this->assertTrue($this->make('hk1.example.com:443', 'bak.example.com')->is_cf);
        // 非 443 端口同样剥离
        $this->assertTrue($this->make('104.16.1.1:8443', 'example.com')->is_cf);
    }

    public function test_IPv6方括号与端口组合容错(): void
    {
        $this->assertTrue($this->make('[2606:4700::1]:2053', 'example.com')->is_cf);
        $this->assertTrue($this->make('[104.16.1.1]', 'example.com')->is_cf);
    }

    public function test_SNI为纯IP时按不同判定(): void
    {
        $this->assertTrue($this->make('1.2.3.4', '5.6.7.8')->is_cf);   // 不同
        $this->assertFalse($this->make('1.2.3.4', '1.2.3.4')->is_cf);  // 相同
    }

    public function test_无SNI不构成CF(): void
    {
        $this->assertFalse($this->make('1.2.3.4', null)->is_cf);
        $this->assertFalse($this->make('example.com', null)->is_cf);
    }

    public function test_编辑地址后标记自动重算(): void
    {
        $node = $this->make('v2.timht.com', 'v2.timht.com');
        $this->assertFalse($node->is_cf);

        // 改为不同域名 → CF
        $node->update(['address' => 'hk1.example.com']);
        $this->assertTrue($node->fresh()->is_cf);

        // 改回相同域名 → 非 CF
        $node->update(['address' => 'v2.timht.com']);
        $this->assertFalse($node->fresh()->is_cf);
    }

    public function test_优选生成节点自动标记CF(): void
    {
        // 生成节点 address=IP、sni=域名，create 走 saving 事件
        $child = $this->make('104.16.1.1', 'bak.example.com');
        $child->update(['is_generated' => true, 'parent_node_id' => $child->id]);
        $this->assertTrue($child->fresh()->is_cf);
    }
}
