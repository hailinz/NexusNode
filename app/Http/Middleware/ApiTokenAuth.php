<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;

/**
 * API 令牌认证：校验 Authorization: Bearer <token>
 * 令牌由 POST /api/v1/login 签发并入库（存 sha256 哈希），过期自动失效
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => '未登录或令牌已过期'], 401);
        }

        $row = ApiToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return response()->json(['message' => '未登录或令牌已过期'], 401);
        }

        // 传递当前管理员 id 给控制器
        $request->attributes->set('admin_id', $row->admin_id);

        return $next($request);
    }
}
