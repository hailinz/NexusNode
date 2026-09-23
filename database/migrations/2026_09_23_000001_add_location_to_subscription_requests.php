<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_requests 新增 location 字段（GeoIP 查询结果缓存）
 *
 * 写入策略：serve() 写日志时同步调用 GeoIpService->lookup()，将结果
 * 一次性写入 location（避免重复查询）。库未配置 / IP 库无记录时写 null。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->string('location', 100)->nullable()->after('user_agent')->comment('GeoIP 国家/城市/ASN（如"中国 上海 / 上海电信"）');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
