<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportOrderDaily extends Model
{
    use HasFactory;

    protected $table = 'report_order_daily';

    protected $fillable = [
        'report_key',
        'date_at',
        'user_id',
        'service_id',
        'order_pending',
        'order_processing',
        'order_in_progress',
        'order_completed',
        'order_partial',
        'order_canceled',
        'order_refunded',
        'order_failed',
        'total_charge',
        'total_cost',
        'total_profit',
        'total_refund',
        'total_quantity',
    ];

    protected $casts = [
        'date_at' => 'integer',
        'order_pending' => 'integer',
        'order_processing' => 'integer',
        'order_in_progress' => 'integer',
        'order_completed' => 'integer',
        'order_partial' => 'integer',
        'order_canceled' => 'integer',
        'order_refunded' => 'integer',
        'order_failed' => 'integer',
        'total_charge' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'total_quantity' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
