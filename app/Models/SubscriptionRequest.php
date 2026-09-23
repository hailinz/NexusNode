<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionRequest extends Model
{
    protected $fillable = ['subscription_id', 'ip', 'user_agent', 'location', 'requested_at'];

    protected $casts = [
        'requested_at' => 'datetime',
    ];

    /**
     * 所属订阅
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
