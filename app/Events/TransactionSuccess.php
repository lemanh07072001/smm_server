<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionSuccess implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;
    public int $amount;
    public string $reference;
    public string $message;

    /**
     * Create a new event instance.
     */
    public function __construct(int $userId, int $amount, string $reference)
    {
        $this->userId = $userId;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->message = 'Nạp tiền thành công: ' . number_format($amount) . ' VND';
    }

    /**
     * Get the channels the event should broadcast on.
     * Sử dụng Private Channel để chỉ user đó mới nhận được
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'transaction.success';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'amount' => $this->amount,
            'amount_formatted' => number_format($this->amount) . ' VND',
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
