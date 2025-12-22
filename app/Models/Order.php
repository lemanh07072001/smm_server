<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'service_id',
        'provider_service_id',
        'provider_order_id',
        'link',
        'quantity',
        'start_count',
        'remains',
        'status',
        'cost_rate',
        'sell_rate',
        'charge_amount',
        'cost_amount',
        'profit_amount',
        'refund_amount',
        'final_charge',
        'final_cost',
        'final_profit',
        'is_finalized',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'service_id' => 'integer',
        'provider_service_id' => 'integer',
        'quantity' => 'integer',
        'start_count' => 'integer',
        'remains' => 'integer',
        'cost_rate' => 'decimal:2',
        'sell_rate' => 'decimal:2',
        'charge_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'final_charge' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'final_profit' => 'decimal:2',
        'is_finalized' => 'boolean',
    ];

    /**
     * Get the user that owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the service that the order is for.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the provider service that the order is for.
     */
    public function providerService(): BelongsTo
    {
        return $this->belongsTo(ProviderService::class);
    }

    /**
     * Check if order is finalized.
     */
    public function isFinalized(): bool
    {
        return $this->is_finalized === true;
    }

    /**
     * Check if order can be canceled.
     */
    public function canBeCanceled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Check if order is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if order is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}

