<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
