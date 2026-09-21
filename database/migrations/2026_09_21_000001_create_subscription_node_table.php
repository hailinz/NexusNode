<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 订阅↔节点关联表：每个订阅可显式指定节点白名单（多对多 + 该订阅内的排序）。
     * 复合主键防重复；CASCADE 保证订阅/节点任一被删时关联自动清理。
     * 服务端按 sort_order ASC 输出白名单内的启用节点（无关联时回退全部启用节点，保持向后兼容）。
     */
    public function up(): void
    {
        Schema::create('subscription_node', function (Blueprint $table) {
            $table->foreignId('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();
            $table->foreignId('node_id')
                ->constrained('nodes')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->comment('该订阅内的节点顺序');
            $table->timestamps();

            $table->primary(['subscription_id', 'node_id']);
            $table->index(['subscription_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_node');
    }
};
