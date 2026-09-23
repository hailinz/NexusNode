<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 放宽 nodes.encryption 字段长度 50 → 255
 *
 * 背景：SS 节点加密字段为 `method:password` 拼接（如
 *   `chacha20-ietf-poly1305:xxxxxxxxxxxxxxxxx`），
 * 50 字符在密码稍长时即触发 422。原 schema 在 MySQL / PostgreSQL 下是硬约束；
 * SQLite 下 VARCHAR(N) 是 TEXT 别称，长度被忽略，但为数据库一致性仍走迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite TEXT 不存长度信息，no-op
            return;
        }

        DB::statement('ALTER TABLE nodes MODIFY COLUMN encryption VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // MySQL VARCHAR(255) → VARCHAR(50)：仅在字段现有长度 ≤ 50 时安全
        // （实际项目升级后新写入的 encryption 可能 > 50 字符，回滚会失败）
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE nodes MODIFY COLUMN encryption VARCHAR(50) NULL');
    }
};
