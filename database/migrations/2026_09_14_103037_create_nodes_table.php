<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 节点表：存储 vless/vmess/trojan/ss 等代理节点的结构化信息
     */
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('节点名称');
            $table->string('protocol', 20)->default('vless')->comment('协议：vless/vmess/trojan/ss');
            $table->string('uuid')->nullable()->comment('用户ID（vless）或密码（trojan/ss）');
            $table->string('address')->comment('服务器地址（IP 或域名）');
            $table->unsignedInteger('port')->comment('端口');
            $table->string('security', 20)->nullable()->comment('传输安全：tls/none/reality');
            $table->string('sni')->nullable()->comment('SNI 域名');
            $table->string('host')->nullable()->comment('伪装域名（ws host）');
            $table->string('path')->nullable()->comment('路径（ws path）');
            $table->string('network', 20)->nullable()->comment('传输层：tcp/ws/grpc');
            $table->string('flow')->nullable()->comment('流控（xtls-rprx-vision 等）');
            $table->string('fingerprint', 50)->nullable()->comment('TLS 指纹 fp');
            $table->string('encryption', 50)->nullable()->comment('加密方式（vless 为 none，ss 为 method）');
            $table->string('alpn', 100)->nullable()->comment('ALPN');
            $table->json('extras')->nullable()->comment('其他未知查询参数（保证链接 round-trip 不丢失）');
            $table->boolean('enabled')->default(true)->comment('是否加入订阅');
            $table->boolean('is_generated')->default(false)->comment('是否为 CF 优选生成的节点');
            $table->foreignId('parent_node_id')->nullable()->comment('生成来源节点')->constrained('nodes')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->comment('排序权重');
            $table->timestamps();

            $table->index(['protocol', 'enabled']);
            $table->index(['is_generated', 'parent_node_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
