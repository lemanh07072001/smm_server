<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAgentPrice extends Model
{
    protected $fillable = [
        'service_id',
        'agent_level',
        'sell_rate',
    ];

    protected $casts = [
        'agent_level' => 'integer',
        'sell_rate' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
