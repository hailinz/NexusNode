<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 订阅请求日志：客户端每次拉取 /sub/{token} 记录一条
     */
    public function up(): void
    {
        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('ip', 45)->comment('请求来源 IP');
            $table->string('user_agent', 500)->nullable()->comment('客户端 UA');
            $table->timestamp('requested_at')->comment('请求时间');
            $table->timestamps();

            $table->index(['subscription_id', 'requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
    }
};
