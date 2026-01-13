<?php

namespace App\Events;

use App\Models\Dongtien;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundSuccess implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Dongtien $transaction;
    public int $orderId;

    /**
     * Create a new event instance.
     */
    public function __construct(Dongtien $transaction, int $orderId)
    {
        $this->transaction = $transaction;
        $this->orderId = $orderId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->transaction->user_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'refund.success';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'order_id' => $this->orderId,
            'amount' => $this->transaction->amount,
            'balance_before' => $this->transaction->balance_before,
            'balance_after' => $this->transaction->balance_after,
            'noidung' => $this->transaction->noidung,
            'created_at' => $this->transaction->created_at->toISOString(),
            'message' => 'Hoàn tiền ' . number_format($this->transaction->amount) . ' VNĐ cho đơn hàng #' . $this->orderId,
        ];
    }
}
