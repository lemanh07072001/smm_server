<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    const KEY_ID_REDIS_ORDER = 'key_id_redis_order';

    public const STATUS_PENDING = 'pending';  // Khởi tạo
    public const STATUS_PROCESSING = 'processing'; // Đang chạy
    public const STATUS_IN_PROGRESS = 'in_progress'; // Xong
    public const STATUS_COMPLETED = 'completed'; // Hoàn thành
    public const STATUS_PARTIAL = 'partial'; // Hoàn thành một phần
    public const STATUS_CANCELED = 'canceled'; // Hoàn toàn bộ
    public const STATUS_REFILLING = 'refilling'; // Hoàn toàn bộ
    public const STATUS_BEFORE_COMPLATE = 'before_complete'; // Hoàn toàn bộ
    public const STATUS_FAILED = 'failed'; // Thất bại

    /**
     * Mapping status từ text sang số (reverse của STATUS_TEXT_V2)
     */
    public const STATUS_NUMBER_MAP = [
        'pending' => 0,
        'in_progress' => 1,
        'completed' => 2,
        'processing' => 3,
        'partial' => 4,
        'canceled' => 5,
        'refilling' => 6,
        'before_complete' => 7,
    ];

  

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
        'comments',
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

    /**
     * Map provider status to system status
     */
    public static function mapProviderStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'pending'                               => self::STATUS_PENDING,
            'processing'                            => self::STATUS_PROCESSING,
            'in_progress'                           => self::STATUS_IN_PROGRESS,
            'completed'                             => self::STATUS_COMPLETED,
            'partial'                               => self::STATUS_PARTIAL,
            'canceled'                              => self::STATUS_CANCELED,
            'failed'                                => self::STATUS_FAILED,
            default                                 => self::STATUS_PENDING,
        };
    }

    /**
     * Convert status text sang số (theo STATUS_TEXT_V2)
     */
    public static function statusToNumber(string $status): ?int
    {
        $statusLower = strtolower($status);
        
        // Map từ constants sang số
        $map = [
            self::STATUS_PENDING => 0,
            self::STATUS_IN_PROGRESS => 1,
            self::STATUS_COMPLETED => 2,
            self::STATUS_PROCESSING => 3,
            self::STATUS_PARTIAL => 4,
            self::STATUS_CANCELED => 5,
            'refilling' => 6,
            'before_complete' => 7,
        ];

        return $map[$statusLower] ?? null;
    }

    /**
     * Convert số sang status text (theo STATUS_TEXT_V2)
     */
    public static function numberToStatus(int $number): ?string
    {
        return self::STATUS_TEXT_V2[$number] ?? null;
    }

    /**
     * Convert số sang status constant (lowercase)
     */
    public static function numberToStatusConstant(int $number): ?string
    {
        $text = self::numberToStatus($number);
        if (!$text) {
            return null;
        }

        // Convert text sang constant format
        $map = [
            'Pending' => self::STATUS_PENDING,
            'In progress' => self::STATUS_IN_PROGRESS,
            'Completed' => self::STATUS_COMPLETED,
            'Processing' => self::STATUS_PROCESSING,
            'Partial' => self::STATUS_PARTIAL,
            'Canceled' => self::STATUS_CANCELED,
            'Refilling' => 'refilling',
            'Before Complete' => 'before_complete',
        ];

        return $map[$text] ?? strtolower(str_replace(' ', '_', $text));
    }
}

