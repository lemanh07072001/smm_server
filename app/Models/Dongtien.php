<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dongtien extends Model
{
    use HasFactory;

    protected $table = 'dongtien';

    protected $fillable = [
        'sotientruoc',
        'sotienthaydoi',
        'sotiensau',
        'thoigian',
        'noidung',
        'user_id',
        'order_id',
        'type',
        'scan',
        'datas',
        'is_payment_affiliate',
        'bank_auto_id',
    ];

    protected $casts = [
        'sotientruoc' => 'integer',
        'sotienthaydoi' => 'integer',
        'sotiensau' => 'integer',
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
