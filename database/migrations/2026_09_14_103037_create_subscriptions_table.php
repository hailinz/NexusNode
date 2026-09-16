<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 订阅表：每个订阅持有随机 token，作为 /sub/{token} 订阅地址
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('订阅名称');
            $table->string('token', 64)->unique()->comment('订阅令牌（URL 中使用）');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->string('description')->nullable()->comment('备注说明');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
