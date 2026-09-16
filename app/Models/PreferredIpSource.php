<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PreferredIpSource extends Model
{
    protected $fillable = ['name', 'url', 'enabled', 'last_synced_at', 'last_count', 'last_error'];

    protected $casts = [
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_count' => 'integer',
    ];

    /**
     * 参与全部同步的源
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
