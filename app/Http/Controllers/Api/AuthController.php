<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * 登录：校验数据库中的管理员凭据，签发 Bearer 令牌（限流：每 IP 每分钟 5 次）
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json(['message' => "尝试次数过多，请 {$seconds} 秒后重试"], 429);
        }
        RateLimiter::hit($key, 60);

        $admin = Admin::where('username', $validated['username'])->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            return response()->json(['message' => '用户名或密码错误'], 401);
        }

        RateLimiter::clear($key);

        $token = bin2hex(random_bytes(32));
        $ttlMinutes = 60 * 24 * 7;
        ApiToken::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'username' => $admin->username,
        ]);
    }

    /**
     * 登出：吊销当前令牌
     */
    public function logout(Request $request): JsonResponse
    {
        ApiToken::where('token_hash', hash('sha256', (string) $request->bearerToken()))->delete();

        return response()->json(['message' => '已登出']);
    }

    /**
     * 当前登录信息
     */
    public function me(Request $request): JsonResponse
    {
        $admin = Admin::find($request->attributes->get('admin_id'));

        return response()->json(['username' => $admin?->username]);
    }

    /**
     * 修改密码：验证当前密码后更新，并吊销该管理员的其他所有令牌（当前会话保留）
     * current_password 规则使用 admin 用户的 bcrypt 校验（Laravel 内置 current_password:admin）
     */
    public function changePassword(Request $request): JsonResponse
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = Admin::findOrFail($adminId);

        // current_password:admin 需要认证用户对象，这里手动校验（单管理员无 web guard）
        if (!Hash::check((string) $request->input('current_password'), $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['当前密码不正确'],
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin->update(['password' => Hash::make($validated['password'])]);

        // 其他设备的旧令牌全部失效，当前会话保留
        $currentHash = hash('sha256', (string) $request->bearerToken());
        ApiToken::where('admin_id', $admin->id)
            ->where('token_hash', '!=', $currentHash)
            ->delete();

        return response()->json(['message' => '密码已修改，其他设备的登录已失效']);
    }
}
