<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PreferredIp extends Model
{
    protected $fillable = ['ip', 'remarks', 'latency_ms', 'loss_rate', 'download_speed', 'enabled'];

    protected $casts = [
        'latency_ms' => 'float',
        'loss_rate' => 'float',
        'download_speed' => 'float',
        'enabled' => 'boolean',
    ];

    /**
     * 参与优选生成的 IP
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * 展示标签：优先备注，其次 IP 本身
     */
    public function getLabelAttribute(): string
    {
        return $this->remarks ? "{$this->remarks}（{$this->ip}）" : $this->ip;
    }
}
