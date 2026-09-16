<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends Model
{
    protected $fillable = ['username', 'password'];

    protected $hidden = ['password'];

    /**
     * 该管理员的登录令牌
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }
}
