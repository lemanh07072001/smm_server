<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRevenueUserDaily extends Model
{
    protected $table = 'report_revenue_user_dailies';

    protected $fillable = [
        'date_at',
        'user_id',
        'system_complete',
        'system_waiting_check',
        'system_approved',
        'system_rejected',
        'system_expired',
    ];

    protected $casts = [
        'date_at'              => 'date:Y-m-d',
        'system_complete'      => 'decimal:2',
        'system_waiting_check' => 'decimal:2',
        'system_approved'      => 'decimal:2',
        'system_rejected'      => 'decimal:2',
        'system_expired'       => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
