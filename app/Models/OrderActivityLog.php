<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class OrderActivityLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'order_activity_logs';

    // Activity types
    public const TYPE_ORDER_CREATED = 'order_created';
    public const TYPE_ORDER_QUEUED = 'order_queued';
    public const TYPE_ORDER_PROCESSING = 'order_processing';
    public const TYPE_PROVIDER_REQUEST = 'provider_request';
    public const TYPE_PROVIDER_RESPONSE = 'provider_response';
    public const TYPE_STATUS_CHECK = 'status_check';
    public const TYPE_STATUS_RESPONSE = 'status_response';
    public const TYPE_ORDER_UPDATED = 'order_updated';
    public const TYPE_ORDER_FAILED = 'order_failed';
    public const TYPE_ORDER_COMPLETED = 'order_completed';
    public const TYPE_ORDER_PLACED_SUCCESS = 'order_placed_success';
    public const TYPE_PROCESSING_COMPLETED = 'processing_completed';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ERROR = 'error';

    // Status levels
    public const LEVEL_INFO = 'info';
    public const LEVEL_SUCCESS = 'success';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    protected $fillable = [
        'order_id',
        'user_id',
        'provider_code',
        'provider_order_id',
        'type',
        'level',
        'message',
        'request_data',
        'response_data',
        'metadata',
        'duration_ms',
        'created_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'user_id' => 'integer',
        'request_data' => 'array',
        'response_data' => 'array',
        'metadata' => 'array',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Log activity for an order
     */
    public static function log(
        int $orderId,
        string $type,
        string $message,
        array $options = []
    ): self {
        return self::create([
            'order_id'          => $orderId,
            'user_id'           => $options['user_id'] ?? null,
            'provider_code'     => $options['provider_code'] ?? null,
            'provider_order_id' => $options['provider_order_id'] ?? null,
            'type'              => $type,
            'level'             => $options['level'] ?? self::LEVEL_INFO,
            'message'           => $message,
            'request_data'      => $options['request_data'] ?? null,
            'response_data'     => $options['response_data'] ?? null,
            'metadata'          => $options['metadata'] ?? null,
            'duration_ms'       => $options['duration_ms'] ?? null,
            'created_at'        => now(),
        ]);
    }

    /**
     * Get all logs for an order
     */
    public static function getByOrderId(int $orderId): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('order_id', $orderId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get error logs for an order
     */
    public static function getErrorsByOrderId(int $orderId): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('order_id', $orderId)
            ->where('level', self::LEVEL_ERROR)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get logs by provider order ID
     */
    public static function getByProviderOrderId(string $providerOrderId): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('provider_order_id', $providerOrderId)
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
