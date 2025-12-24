<?php

namespace App\Helpers;

use App\Models\OrderActivityLog;
use Illuminate\Support\Facades\Redis;

class OrderActivityLogger
{
    public const KEY_REDIS_ACTIVITY_LOGS = 'activity_logs_queue';

    private int $orderId;
    private ?int $userId = null;
    private ?string $providerCode = null;
    private ?string $providerOrderId = null;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Create a new logger instance
     */
    public static function for(int $orderId): self
    {
        return new self($orderId);
    }

    /**
     * Set user ID
     */
    public function user(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Set provider info
     */
    public function provider(string $code, ?string $providerOrderId = null): self
    {
        $this->providerCode = $code;
        $this->providerOrderId = $providerOrderId;
        return $this;
    }

    /**
     * Log order created
     */
    public function orderCreated(array $orderData = []): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_CREATED,
            'Đơn hàng được tạo',
            OrderActivityLog::LEVEL_INFO,
            ['metadata' => $orderData]
        );
    }

    /**
     * Log order queued to Redis
     */
    public function orderQueued(): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_QUEUED,
            'Đơn hàng được đưa vào hàng đợi Redis',
            OrderActivityLog::LEVEL_INFO
        );
    }

    /**
     * Log order processing started
     */
    public function processingStarted(): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_PROCESSING,
            'Bắt đầu xử lý đơn hàng',
            OrderActivityLog::LEVEL_INFO
        );
    }

    /**
     * Log provider request
     */
    public function providerRequest(string $url, array $body): void
    {
        $this->log(
            OrderActivityLog::TYPE_PROVIDER_REQUEST,
            "Gửi request đến provider: {$url}",
            OrderActivityLog::LEVEL_INFO,
            ['request_data' => ['url' => $url, 'body' => $body]]
        );
    }

    /**
     * Log provider response
     */
    public function providerResponse(array $response, ?int $durationMs = null): void
    {
        $success = $response['success'] ?? false;
        $level = $success ? OrderActivityLog::LEVEL_SUCCESS : OrderActivityLog::LEVEL_ERROR;
        $message = $success
            ? 'Provider trả về thành công'
            : 'Provider trả về lỗi: ' . ($response['data']['error'] ?? $response['body'] ?? 'Unknown');

        $this->log(
            OrderActivityLog::TYPE_PROVIDER_RESPONSE,
            $message,
            $level,
            [
                'response_data' => $response,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Log status check request
     */
    public function statusCheck(): void
    {
        $this->log(
            OrderActivityLog::TYPE_STATUS_CHECK,
            'Kiểm tra trạng thái đơn hàng từ provider',
            OrderActivityLog::LEVEL_INFO
        );
    }

    /**
     * Log status response
     */
    public function statusResponse(array $statusData): void
    {
        $status = $statusData['status'] ?? 'unknown';
        $this->log(
            OrderActivityLog::TYPE_STATUS_RESPONSE,
            "Trạng thái từ provider: {$status}",
            OrderActivityLog::LEVEL_INFO,
            ['response_data' => $statusData]
        );
    }

    /**
     * Log order updated
     */
    public function orderUpdated(array $updateData): void
    {
        $status = $updateData['status'] ?? 'unknown';
        $this->log(
            OrderActivityLog::TYPE_ORDER_UPDATED,
            "Cập nhật đơn hàng: status = {$status}",
            OrderActivityLog::LEVEL_SUCCESS,
            ['metadata' => $updateData]
        );
    }

    /**
     * Log order failed
     */
    public function orderFailed(string $errorMessage): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_FAILED,
            "Đơn hàng thất bại: {$errorMessage}",
            OrderActivityLog::LEVEL_ERROR,
            ['metadata' => ['error' => $errorMessage]]
        );
    }

    /**
     * Log order completed
     */
    public function orderCompleted(): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_COMPLETED,
            'Đơn hàng hoàn thành',
            OrderActivityLog::LEVEL_SUCCESS
        );
    }

    /**
     * Log order placed success - đẩy đơn lên provider thành công
     */
    public function orderPlacedSuccess(string $providerOrderId, string $status): void
    {
        $this->log(
            OrderActivityLog::TYPE_ORDER_PLACED_SUCCESS,
            "provider_order_id = {$providerOrderId}, status = {$status}",
            OrderActivityLog::LEVEL_SUCCESS,
            ['metadata' => [
                'provider_order_id' => $providerOrderId,
                'status' => $status,
            ]]
        );
    }

    /**
     * Log processing completed - hoàn thành xử lý đơn hàng
     */
    public function processingCompleted(): void
    {
        $this->log(
            OrderActivityLog::TYPE_PROCESSING_COMPLETED,
            'Hoàn thành xử lý đơn hàng',
            OrderActivityLog::LEVEL_SUCCESS
        );
    }

    /**
     * Log refund
     */
    public function refund(float $amount): void
    {
        $this->log(
            OrderActivityLog::TYPE_REFUND,
            "Hoàn tiền: {$amount}",
            OrderActivityLog::LEVEL_WARNING,
            ['metadata' => ['amount' => $amount]]
        );
    }

    /**
     * Log error
     */
    public function error(string $message, ?\Throwable $exception = null): void
    {
        $metadata = ['error' => $message];
        if ($exception) {
            $metadata['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $this->log(
            OrderActivityLog::TYPE_ERROR,
            $message,
            OrderActivityLog::LEVEL_ERROR,
            ['metadata' => $metadata]
        );
    }

    /**
     * Push log vào Redis queue - silent fail nếu Redis lỗi
     */
    private function log(string $type, string $message, string $level, array $options = []): void
    {
        try {
            $logData = [
                'order_id'          => $this->orderId,
                'user_id'           => $this->userId,
                'provider_code'     => $this->providerCode,
                'provider_order_id' => $this->providerOrderId,
                'type'              => $type,
                'level'             => $level,
                'message'           => $message,
                'request_data'      => $options['request_data'] ?? null,
                'response_data'     => $options['response_data'] ?? null,
                'metadata'          => $options['metadata'] ?? null,
                'duration_ms'       => $options['duration_ms'] ?? null,
                'created_at'        => now()->toISOString(),
            ];

            Redis::connection(RedisHelper::REDIS_ACTIVITY_LOGS)
                ->lpush(self::KEY_REDIS_ACTIVITY_LOGS, json_encode($logData));
        } catch (\Exception $e) {
            // Silent fail - không làm crash flow chính
        }
    }
}
