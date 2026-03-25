<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLog extends Model
{
    protected $table = 'job_logs';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'user_id',
        'tool',
        'status',
        'price_buyer',
        'price_system',
        'date_create',
    ];

    protected $casts = [
        'date_create'  => 'datetime',
        'price_buyer'  => 'decimal:2',
        'price_system' => 'decimal:2',
    ];

    // Các trạng thái
    public const STATUS_PENDING       = 'pending';
    public const STATUS_COMPLETE      = 'complete';
    public const STATUS_ERROR         = 'error';
    public const STATUS_REJECT        = 'reject';
    public const STATUS_APPROVED      = 'approved';
    public const STATUS_EXPIRED       = 'expired';
    public const STATUS_WAITING_CHECK = 'waiting_check';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
