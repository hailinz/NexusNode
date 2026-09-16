<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 管理员与登录令牌表。
     * 默认管理员 admin / admin123（密码哈希存储），首次登录后请尽快修改密码。
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique()->comment('令牌的 sha256 哈希（明文不落库）');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });

        // 默认管理员
        DB::table('admins')->insert([
            'username' => 'admin',
            'password' => Hash::make('admin123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('admins');
    }
};
