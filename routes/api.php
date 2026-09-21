<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\PreferredIpController;
use App\Http\Controllers\PreferredIpSourceController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API 路由（/api/v1，RESTful，Bearer 令牌认证）
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // 认证（无需令牌）
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('api.token')->group(function () {
        // 认证
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('profile/password', [AuthController::class, 'changePassword']);

        // 总览
        Route::get('dashboard', [DashboardController::class, 'index']);

        // 节点管理
        Route::delete('nodes/bulk', [NodeController::class, 'bulkDestroy']);
        Route::patch('nodes/{node}/move/{direction}', [NodeController::class, 'move'])
            ->whereIn('direction', ['up', 'down']);
        Route::patch('nodes/{node}/toggle', [NodeController::class, 'toggle']);
        Route::apiResource('nodes', NodeController::class)->except(['show']);

        // 批量导入
        Route::post('imports/nodes', [ImportController::class, 'store']);

        // CF 优选 IP 池
        Route::get('preferred-ips/list', [PreferredIpController::class, 'list']);
        Route::get('preferred-ips/online-pool', [PreferredIpController::class, 'onlinePool']);
        Route::get('preferred-ips/online-locations', [PreferredIpController::class, 'onlineLocations']);
        Route::post('preferred-ips/import-csv', [PreferredIpController::class, 'importCsv']);
        Route::delete('preferred-ips/bulk', [PreferredIpController::class, 'bulkDestroy']);
        Route::post('preferred-ips/latency-batch', [PreferredIpController::class, 'storeLatencyBatch']);
        Route::post('preferred-ips/metrics-batch', [PreferredIpController::class, 'storeMetricsBatch']);
        // 按 ip 字段绑定（前端以 IP 字符串定位资源）
        Route::patch('preferred-ips/{preferredIp:ip}/toggle', [PreferredIpController::class, 'toggle']);
        Route::delete('preferred-ips/{preferredIp:ip}', [PreferredIpController::class, 'destroy']);
        Route::post('preferred-ips', [PreferredIpController::class, 'store']);

        // 在线优选源
        Route::get('preferred-ips/sources', [PreferredIpSourceController::class, 'index']);
        Route::post('preferred-ips/sources', [PreferredIpSourceController::class, 'store']);
        Route::post('preferred-ips/sources/sync-all', [PreferredIpSourceController::class, 'syncAll']);
        Route::post('preferred-ips/sources/{source}/sync', [PreferredIpSourceController::class, 'sync']);
        Route::patch('preferred-ips/sources/{source}/toggle', [PreferredIpSourceController::class, 'toggle']);
        Route::delete('preferred-ips/sources/{source}', [PreferredIpSourceController::class, 'destroy']);

        // 优选生成
        Route::get('generate/data', [GenerateController::class, 'data']);
        Route::post('generate', [GenerateController::class, 'store']);

        // 订阅管理
        Route::get('subscriptions', [SubscriptionController::class, 'index']);
        Route::post('subscriptions', [SubscriptionController::class, 'store']);
        Route::patch('subscriptions/{subscription}/toggle', [SubscriptionController::class, 'toggle']);
        Route::patch('subscriptions/{subscription}/regenerate', [SubscriptionController::class, 'regenerate']);
        Route::get('subscriptions/{subscription}/nodes', [SubscriptionController::class, 'getNodes']);
        Route::put('subscriptions/{subscription}/nodes', [SubscriptionController::class, 'updateNodes']);
        Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy']);
    });
});
