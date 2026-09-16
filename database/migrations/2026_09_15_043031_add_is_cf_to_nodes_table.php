<?php

use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CF 优选判断物化字段：
     * is_cf = address 为合法 IP（容错剥端口/方括号）且 sni 为非空域名。
     * 保存时由模型事件自动计算，此处对存量数据回填。
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_cf')->default(false)->after('alpn')->comment('CF 优选 IP 节点：address 为 IP 且 sni 为域名');
            $table->index('is_cf');
        });

        Node::query()->orderBy('id')->chunkById(200, function ($nodes) {
            foreach ($nodes as $node) {
                $node->is_cf = Node::computeIsCf($node->address, $node->sni, $node->port);
                $node->saveQuietly();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['is_cf']);
            $table->dropColumn('is_cf');
        });
    }
};
