<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCompletionStat extends Model
{
    protected $fillable = [
        'order_id',
        'service_id',
        'quantity',
        'completion_minutes',
        'completed_at',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'quantity' => 'integer',
        'completion_minutes' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
