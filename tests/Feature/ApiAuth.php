<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * API 测试公共辅助：为种子管理员签发测试令牌
 */
trait ApiAuth
{
    private function authHeaders(): array
    {
        $admin = Admin::first() ?? Admin::create([
            'username' => 'admin',
            'password' => Hash::make('admin123'),
        ]);

        $token = 'test-token-' . sha1(uniqid('', true));
        ApiToken::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        return ['Authorization' => "Bearer {$token}"];
    }
}
