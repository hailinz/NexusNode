<?php

namespace Tests\Feature;

use App\Services\ProxyUriBuilder;
use App\Services\ProxyUriParser;
use PHPUnit\Framework\TestCase;

class ProxyUriTest extends TestCase
{
    private ProxyUriParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ProxyUriParser();
    }

    /**
     * 解析 → 重建 → 再解析 应完全一致（round-trip）
     */
    private function assertRoundTrip(string $uri): array
    {
        $first = $this->parser->parse($uri);
        $rebuilt = ProxyUriBuilder::build($first);
        $second = $this->parser->parse($rebuilt);

        foreach ($first as $key => $value) {
            $this->assertSame(
                $value ?? '',
                $second[$key] ?? '',
                "round-trip 后字段 [{$key}] 不一致"
            );
        }

        return $first;
    }

    public function test_vless_cf节点解析与round_trip(): void
    {
        // 来自用户真实 node.txt 的节点
        $uri = 'vless://f59623e2-619c-4b76-bf5d-fafd59cad575@172.64.144.246:443?encryption=none&security=tls&sni=bak.vesven.com&alpn=http%2F1.1&insecure=0&allowInsecure=0&type=ws&host=bak.vesven.com&path=%2Fp2p%2Fdownload%2F#OC-Osaka_CF%20260901';

        $data = $this->assertRoundTrip($uri);

        $this->assertSame('vless', $data['protocol']);
        $this->assertSame('172.64.144.246', $data['address']);
        $this->assertSame(443, $data['port']);
        $this->assertSame('bak.vesven.com', $data['sni']);
        $this->assertSame('/p2p/download/', $data['path']);
        $this->assertSame('OC-Osaka_CF 260901', $data['name']);
        // 非标准参数必须完整保留
        $this->assertSame('0', $data['extras']['insecure']);
        $this->assertSame('0', $data['extras']['allowInsecure']);
    }

    public function test_vless_reality参数保留在extras(): void
    {
        $uri = 'vless://e2b6c0d0-1f2a-4c3b-9d8e-7f6a5b4c3d2@104.16.0.1:2053?encryption=none&security=reality&sni=www.microsoft.com&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=6ba85179&type=tcp&flow=xtls-rprx-vision#Reality-Test';

        $data = $this->assertRoundTrip($uri);

        $this->assertSame('reality', $data['security']);
        $this->assertSame('xtls-rprx-vision', $data['flow']);
        $this->assertSame('SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc', $data['extras']['pbk']);
        $this->assertSame('6ba85179', $data['extras']['sid']);
    }

    public function test_vmess解析与round_trip(): void
    {
        $json = json_encode([
            'v' => '2', 'ps' => '测试HK', 'add' => 'hk.example.com', 'port' => '443',
            'id' => 'b831381d-6324-4d53-ad4f-8cda48b30811', 'aid' => '0', 'scy' => 'auto',
            'net' => 'ws', 'type' => 'none', 'host' => 'cdn.example.com', 'path' => '/ws',
            'tls' => 'tls', 'sni' => 'cdn.example.com', 'alpn' => '', 'fp' => 'chrome',
        ], JSON_UNESCAPED_UNICODE);

        $data = $this->assertRoundTrip('vmess://' . base64_encode($json));

        $this->assertSame('vmess', $data['protocol']);
        $this->assertSame('测试HK', $data['name']);
        $this->assertSame('hk.example.com', $data['address']);
        $this->assertSame('tls', $data['security']);
        $this->assertSame('auto', $data['encryption']);
    }

    public function test_trojan解析与round_trip(): void
    {
        $uri = 'trojan://passw0rd@hk1.example.com:443?security=tls&sni=hk1.example.com&type=tcp#TR-HK-01';

        $data = $this->assertRoundTrip($uri);

        $this->assertSame('trojan', $data['protocol']);
        $this->assertSame('passw0rd', $data['uuid']);
        $this->assertSame(443, $data['port']);
    }

    public function test_ss旧版整体base64格式(): void
    {
        $plain = 'aes-256-gcm:secret123@1.2.3.4:8388';
        $uri = 'ss://' . base64_encode($plain) . '#SS-JP-01';

        $data = $this->assertRoundTrip($uri);

        $this->assertSame('ss', $data['protocol']);
        $this->assertSame('aes-256-gcm', $data['encryption']);
        $this->assertSame('secret123', $data['uuid']);
        $this->assertSame('1.2.3.4', $data['address']);
        $this->assertSame('SS-JP-01', $data['name']);
    }

    public function test_ss_SIP002格式(): void
    {
        $userinfo = $this->base64UrlSafe('aes-256-gcm:secret123');
        $uri = "ss://{$userinfo}@1.2.3.4:8388#SS-SIP002";

        $data = $this->assertRoundTrip($uri);

        $this->assertSame('ss', $data['protocol']);
        $this->assertSame('secret123', $data['uuid']);
    }

    private function base64UrlSafe(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function test_无sni的普通节点不标记cf(): void
    {
        $data = $this->parser->parse('vless://uuid-1@example.com:443?type=tcp#Plain');

        $this->assertNull($data['sni']);
    }

    public function test_不支持的协议抛出异常(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('ssr://xxxxxxxx');
    }

    public function test_缺端口抛出异常(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('vless://uuid@example.com#NoPort');
    }
}
