<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 在线优选 API 源：移植 edgetunnel(work.js) 的"在线优选"能力，
     * 定期从社区维护的优选 API 拉取最新 IP，参考 work.js 的 请求优选API() 函数
     */
    public function up(): void
    {
        Schema::create('preferred_ip_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('源名称（作为 IP 备注的兜底来源标记）');
            $table->string('url', 500)->comment('优选 API 地址（纯文本/CSV/base64 均可）');
            $table->boolean('enabled')->default(true)->comment('是否参与"全部同步"');
            $table->timestamp('last_synced_at')->nullable()->comment('上次同步时间');
            $table->unsignedInteger('last_count')->default(0)->comment('上次同步入库 IP 数');
            $table->string('last_error', 500)->nullable()->comment('上次同步错误信息');
            $table->timestamps();
        });

        // 内置社区常用源（edgetunnel 生态公开优选 API）
        DB::table('preferred_ip_sources')->insert([
            [
                'name' => 'CM 聚合优选',
                'url' => 'https://addressesapi.090227.xyz/CloudFlareYes',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '每日优选 Top10',
                'url' => 'https://ip.164746.xyz/ipTop10.html',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preferred_ip_sources');
    }
};
