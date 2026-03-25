<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportOrderDailyJob extends Model
{
    protected $table = 'report_order_dailies';

    protected $fillable = [
        'date_at',
        'order_id',
        'user_id',
        'job_pending',
        'job_complete',
        'job_error',
        'job_reject',
        'job_approved',
        'job_expired',
        'job_waiting_check',
        'pending_buyer',
        'pending_system',
        'approved_buyer',
        'approved_system',
        'rejected_buyer',
        'rejected_system',
        'waiting_buyer',
        'waiting_system',
    ];

    protected $casts = [
        'date_at'           => 'date:Y-m-d',
        'job_pending'       => 'integer',
        'job_complete'      => 'integer',
        'job_error'         => 'integer',
        'job_reject'        => 'integer',
        'job_approved'      => 'integer',
        'job_expired'       => 'integer',
        'job_waiting_check' => 'integer',
        'pending_buyer'     => 'decimal:2',
        'pending_system'    => 'decimal:2',
        'approved_buyer'    => 'decimal:2',
        'approved_system'   => 'decimal:2',
        'rejected_buyer'    => 'decimal:2',
        'rejected_system'   => 'decimal:2',
        'waiting_buyer'     => 'decimal:2',
        'waiting_system'    => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
