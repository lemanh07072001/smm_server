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

    /**
     * Tạo giao dịch và cập nhật số dư user
     *
     * @param User $user
     * @param int|float $amount - Số tiền (dương = cộng, âm = trừ)
     * @param string $type - Loại giao dịch (TYPE_DEPOSIT, TYPE_CHARGE, TYPE_REFUND, TYPE_ADJUSTMENT)
     * @param string $noidung - Nội dung giao dịch
     * @param array $options - Các tùy chọn bổ sung [payment_method, payment_ref, order_id, bank_auto_id, datas, thoigian]
     * @return self
     */
    public static function createTransaction(
        User $user,
        int|float $amount,
        string $type,
        string $noidung,
        array $options = []
    ): self {
        $balanceBefore = (int) $user->balance;
        $balanceAfter = $balanceBefore + $amount;

        $dongtien = self::create([
            'balance_before'  => $balanceBefore,
            'amount'          => $amount,
            'balance_after'   => $balanceAfter,
            'thoigian'        => $options['thoigian'] ?? now(),
            'noidung'         => $noidung,
            'user_id'         => $user->id,
            'type'            => $type,
            'payment_method'  => $options['payment_method'] ?? null,
            'payment_ref'     => $options['payment_ref'] ?? null,
            'order_id'        => $options['order_id'] ?? null,
            'bank_auto_id'    => $options['bank_auto_id'] ?? null,
            'datas'           => $options['datas'] ?? null,
        ]);

        $user->balance = $balanceAfter;
        $user->save();

        return $dongtien;
    }
}
