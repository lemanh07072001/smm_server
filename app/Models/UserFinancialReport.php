<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFinancialReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'total_deposit',
        'total_spending',
        'total_refund',
        'total_withdraw',
        'current_balance',
        'total_orders',
        'completed_orders',
    ];

    protected $casts = [
        'total_deposit' => 'decimal:2',
        'total_spending' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'total_withdraw' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'total_orders' => 'integer',
        'completed_orders' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
