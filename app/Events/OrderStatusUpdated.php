<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Dongtien;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;
    public ?Dongtien $refundTransaction;

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order, ?Dongtien $refundTransaction = null)
    {
        $this->order = $order;
        $this->refundTransaction = $refundTransaction;
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
        return 'order.status.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'status' => $this->order->status,
                'start_count' => $this->order->start_count,
                'remains' => $this->order->remains,
            ],
            'refund' => $this->refundTransaction ? [
                'amount' => $this->refundTransaction->amount,
                'balance_after' => $this->refundTransaction->balance_after,
            ] : null,
        ];
    }
}
