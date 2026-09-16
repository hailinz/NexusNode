<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CF 优选 IP 池
     */
    public function up(): void
    {
        Schema::create('preferred_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 64)->unique()->comment('优选 IP 地址');
            $table->string('remarks')->nullable()->comment('备注（如地区：香港/圣何塞）');
            $table->float('latency_ms')->nullable()->comment('平均延迟 ms');
            $table->float('loss_rate')->nullable()->comment('丢包率 %');
            $table->float('download_speed')->nullable()->comment('下载速度 MB/s');
            $table->boolean('enabled')->default(true)->comment('是否参与优选生成');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preferred_ips');
    }
};
