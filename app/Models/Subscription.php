<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = ['name', 'token', 'enabled', 'description'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * 订阅请求记录
     */
    public function requests(): HasMany
    {
        return $this->hasMany(SubscriptionRequest::class);
    }

    /**
     * 订阅的节点白名单（多对多 + 排序）。
     * 关联为空 → 订阅端点输出全部启用节点（向后兼容）。
     */
    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'subscription_node')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * 该订阅配置的节点数（含禁用节点）。0 表示使用默认「全部启用节点」行为。
     */
    public function getNodeCountAttribute(): int
    {
        return $this->nodes()->count();
    }

    /**
     * 生成不重复的随机订阅令牌
     */
    public static function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (self::where('token', $token)->exists());

        return $token;
    }
}
