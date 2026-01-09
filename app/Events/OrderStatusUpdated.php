<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->order->user_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'status' => $this->order->status,
            'provider_order_id' => $this->order->provider_order_id,
            'start_count' => $this->order->start_count,
            'remains' => $this->order->remains,
            'error_message' => $this->order->error_message,
            'updated_at' => $this->order->updated_at->toISOString(),
            'message' => $this->getStatusMessage(),
        ];
    }

    /**
     * Get status message for notification
     */
    private function getStatusMessage(): string
    {
        return match ($this->order->status) {
            Order::STATUS_PROCESSING => "Đơn hàng #{$this->order->id} đang được xử lý",
            Order::STATUS_COMPLETED => "Đơn hàng #{$this->order->id} đã hoàn thành",
            Order::STATUS_FAILED => "Đơn hàng #{$this->order->id} thất bại: {$this->order->error_message}",
            Order::STATUS_PARTIAL => "Đơn hàng #{$this->order->id} hoàn thành một phần",
            Order::STATUS_CANCELED => "Đơn hàng #{$this->order->id} đã bị hủy",
            default => "Đơn hàng #{$this->order->id} được cập nhật",
        };
    }
}
