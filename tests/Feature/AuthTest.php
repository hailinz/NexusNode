<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_默认管理员登录成功返回令牌(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'username' => 'admin',
            'password' => 'admin123',
        ]);

        $response->assertOk();
        $token = $response->json('token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_密码哈希存储(): void
    {
        $admin = Admin::first();
        $this->assertNotSame('admin123', $admin->password);
        $this->assertTrue(Hash::check('admin123', $admin->password));
    }

    public function test_错误凭据返回401(): void
    {
        $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_未知用户名返回401(): void
    {
        $this->postJson('/api/v1/login', ['username' => 'nobody', 'password' => 'x'])
            ->assertStatus(401);
    }

    public function test_缺字段返回422(): void
    {
        $this->postJson('/api/v1/login', ['username' => 'admin'])
            ->assertStatus(422);
    }

    public function test_令牌可访问me_登出后失效(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/me', $headers)
            ->assertOk()
            ->assertJson(['username' => 'admin']);

        $this->postJson('/api/v1/logout', [], $headers)->assertOk();

        $this->getJson('/api/v1/me', $headers)->assertStatus(401);
    }

    public function test_过期令牌返回401(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');

        ApiToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_无令牌访问受保护接口返回401(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_登录限流(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'bad']);
        }

        $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'admin123'])
            ->assertStatus(429);
    }

    public function test_修改密码(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->patchJson('/api/v1/profile/password', [
            'current_password' => 'admin123',
            'password' => 'new-secret-888',
            'password_confirmation' => 'new-secret-888',
        ], $headers)->assertOk();

        // 新密码可登录，旧密码失效
        $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'admin123'])
            ->assertStatus(401);
        $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'new-secret-888'])
            ->assertOk();
    }

    public function test_修改密码当前密码错误422(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');

        $this->patchJson('/api/v1/profile/password', [
            'current_password' => 'wrong-current',
            'password' => 'new-secret-888',
            'password_confirmation' => 'new-secret-888',
        ], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();

        // 原密码仍可登录
        $this->postJson('/api/v1/login', ['username' => 'admin', 'password' => 'admin123'])->assertOk();
    }

    public function test_修改密码后其他令牌失效当前保留(): void
    {
        $tokenA = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');
        $tokenB = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');

        // 设备 A 修改密码
        $this->patchJson('/api/v1/profile/password', [
            'current_password' => 'admin123',
            'password' => 'new-secret-888',
            'password_confirmation' => 'new-secret-888',
        ], ['Authorization' => "Bearer {$tokenA}"])->assertOk();

        // 设备 B 被踢出
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$tokenB}"])->assertStatus(401);
        // 设备 A 当前会话保留
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$tokenA}"])->assertOk();
    }

    public function test_两次密码不一致422(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');

        $this->patchJson('/api/v1/profile/password', [
            'current_password' => 'admin123',
            'password' => 'new-secret-888',
            'password_confirmation' => 'different-999',
        ], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();
    }

    public function test_新密码过短422(): void
    {
        $token = $this->postJson('/api/v1/login', [
            'username' => 'admin', 'password' => 'admin123',
        ])->json('token');

        $this->patchJson('/api/v1/profile/password', [
            'current_password' => 'admin123',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();
    }
}
