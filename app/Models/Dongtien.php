<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dongtien extends Model
{
    use HasFactory;

    protected $table = 'dongtien';

    // Type constants
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_CHARGE = 'charge';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'balance_before',
        'amount',
        'balance_after',
        'thoigian',
        'noidung',
        'user_id',
        'order_id',
        'payment_method',
        'type',
        'payment_ref',
        'scan',
        'datas',
        'is_payment_affiliate',
        'bank_auto_id',
    ];

    protected $casts = [
        'balance_before' => 'decimal:6',
        'amount' => 'decimal:6',
        'balance_after' => 'decimal:6',
        'thoigian' => 'datetime',
        'scan' => 'integer',
        'is_payment_affiliate' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function bankAuto(): BelongsTo
    {
        return $this->belongsTo(BankAuto::class);
    }
}
