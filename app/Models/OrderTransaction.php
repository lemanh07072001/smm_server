<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTransaction extends Model
{
    use HasFactory;

    protected $table = 'order_transactions';

    // Type constants
    public const TYPE_CHARGE = 'charge'; // Mua hàng (trừ tiền)
    public const TYPE_REFUND = 'refund'; // Hoàn tiền (cộng tiền)

    protected $fillable = [
        'order_id',
        'user_id',
        'service_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'quantity',
        'rate',
        'cost_rate',
        'profit',
        'service_name',
        'link',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:6',
        'balance_before' => 'decimal:6',
        'balance_after' => 'decimal:6',
        'quantity' => 'integer',
        'rate' => 'decimal:6',
        'cost_rate' => 'decimal:6',
        'profit' => 'decimal:6',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Tạo giao dịch mua hàng
     */
    public static function createCharge(
        Order $order,
        User $user,
        Service $service,
        float $balanceBefore,
        float $balanceAfter
    ): self {
        return self::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'type' => self::TYPE_CHARGE,
            'amount' => $order->charge_amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'quantity' => $order->quantity,
            'rate' => $order->sell_rate,
            'cost_rate' => $order->cost_rate,
            'profit' => $order->profit_amount,
            'service_name' => $service->name,
            'link' => $order->link,
            'note' => "Mua dịch vụ: {$service->name}",
        ]);
    }

    /**
     * Tạo giao dịch hoàn tiền
     */
    public static function createRefund(
        Order $order,
        User $user,
        float $refundAmount,
        float $balanceBefore,
        float $balanceAfter,
        ?string $note = null
    ): self {
        return self::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'service_id' => $order->service_id,
            'type' => self::TYPE_REFUND,
            'amount' => $refundAmount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'quantity' => $order->quantity,
            'rate' => $order->sell_rate,
            'cost_rate' => $order->cost_rate,
            'profit' => 0,
            'service_name' => $order->service->name ?? 'N/A',
            'link' => $order->link,
            'note' => $note ?? "Hoàn tiền đơn #{$order->id}",
        ]);
    }
}
