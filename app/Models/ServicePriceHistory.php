<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'agent_level',
        'old_price',
        'new_price',
        'changed_by',
    ];

    protected $casts = [
        'agent_level' => 'integer',
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
