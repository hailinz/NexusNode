<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web 路由：SPA 壳 + 订阅端点（业务全部走 /api/v1）
|--------------------------------------------------------------------------
*/

// SPA 壳（前端单页应用入口）
Route::get('/', fn () => view('app'))->name('app');

// 订阅端点（无需认证，代理客户端拉取）
Route::get('/sub/{token}', [SubscriptionController::class, 'serve'])->name('subscriptions.serve');
