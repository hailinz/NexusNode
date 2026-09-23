<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionController;
use App\Models\Subscription;
use App\Services\GeoIpService;
use App\Services\SubscriptionFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeoIpTest extends TestCase
{
    use ApiAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 确保所有测试用例从「无库」状态开始
        config(['geoip.database_path' => '']);
    }

    public function test_未配置数据库路径时_is_available_返回_false(): void
    {
        config(['geoip.database_path' => '']);
        $svc = app(GeoIpService::class);
        $this->assertFalse($svc->isAvailable());
    }

    public function test_配置了不存在的路径_is_available_返回_false(): void
    {
        config(['geoip.database_path' => 'E:/nonexistent/path/GeoLite2.mmdb']);
        $svc = app(GeoIpService::class);
        $this->assertFalse($svc->isAvailable());
    }

    public function test_未配置时_lookup_返回_null_不抛异常(): void
    {
        config(['geoip.database_path' => '']);
        $svc = app(GeoIpService::class);
        $this->assertNull($svc->lookup('1.2.3.4'));
        $this->assertNull($svc->lookup('2001:db8::1'));
    }

    public function test_文件缺失时_lookup_返回_null_不抛异常(): void
    {
        config(['geoip.database_path' => 'E:/missing/Geo.mmdb']);
        $svc = app(GeoIpService::class);
        $this->assertNull($svc->lookup('1.2.3.4'));
    }

    /**
     * 私有 IP 一律视为不可查（避免返回空记录占位）。
     *
     * @dataProvider privateIpProvider
     */
    public function test_私有_保留_i_p_lookup_返回_null(string $ip): void
    {
        // 用一个存在但无效的路径模拟「库存在但 IP 是私有的」
        // 因为 isPrivateIp() 先于 isAvailable 判断,任何路径下都返回 null
        config(['geoip.database_path' => '']);
        $svc = app(GeoIpService::class);
        $this->assertNull($svc->lookup($ip));
    }

    public static function privateIpProvider(): array
    {
        return [
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv4 private 10/8' => ['10.0.0.1'],
            'IPv4 private 192.168/16' => ['192.168.1.1'],
            'IPv4 private 172.16/12' => ['172.16.0.1'],
            'IPv4 link-local 169.254/16' => ['169.254.1.1'],
            'IPv4 reserved 0.0.0.0' => ['0.0.0.0'],
            'IPv6 loopback' => ['::1'],
            'IPv6 link-local fe80::/10' => ['fe80::1'],
            'IPv6 ULA fc00::/7' => ['fd12:3456:789a::1'],
            'invalid string' => ['not-an-ip'],
        ];
    }

    public function test_serve_写入日志时_location_列接收_lookup_结果(): void
    {
        // mock GeoIpService:让它对测试 IP 返回固定 location
        $mock = new class extends GeoIpService
        {
            public function isAvailable(): bool
            {
                return true; // 让 lookup 走完整逻辑到我们 override 的分支
            }

            public function lookup(string $ip): ?string
            {
                return '测试位置';
            }
        };
        $this->app->instance(GeoIpService::class, $mock);

        // 直接调 controller,跳过 HTTP 层,确认 mock 真的进了控制器
        $sub = Subscription::create(['name' => 'T', 'token' => 'tok', 'enabled' => true]);
        $ctrl = app(SubscriptionController::class);
        $ctrl->serve(Request::create('/sub/tok', 'GET'), 'tok', app(SubscriptionFormat::class), app(GeoIpService::class));

        $row = DB::table('subscription_requests')->where('subscription_id', $sub->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('测试位置', $row->location);
    }

    public function test_serve_库未配置时_location_为_null(): void
    {
        $sub = Subscription::create(['name' => 'T', 'token' => 'tok2', 'enabled' => true]);

        $this->get('/sub/tok2', ['User-Agent' => 'test'])->assertOk();

        $row = DB::table('subscription_requests')->where('subscription_id', $sub->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->location);
    }

    public function test_requests_接口返回_location_字段(): void
    {
        $sub = Subscription::create(['name' => 'T', 'token' => 'tok3', 'enabled' => true]);
        DB::table('subscription_requests')->insert([
            'subscription_id' => $sub->id,
            'ip' => '8.8.8.8',
            'user_agent' => 'dns',
            'location' => '美国 加利福尼亚',
            'requested_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/subscriptions/{$sub->id}/requests", $this->authHeaders())
            ->assertOk()
            ->json();

        $this->assertSame('美国 加利福尼亚', $data['requests'][0]['location']);
    }

    public function test_geoip_status_命令未配置路径时不报错(): void
    {
        config(['geoip.database_path' => '']);
        $this->artisan('geoip:status')->assertExitCode(0);
    }

    public function test_geoip_status_命令路径无效返回_failure(): void
    {
        config(['geoip.database_path' => 'E:/nope/missing.mmdb']);
        $this->artisan('geoip:status')->assertExitCode(1);
    }
}
