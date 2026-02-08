<?php

namespace App\Events;

use App\Models\Dongtien;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositSuccess implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Dongtien $transaction;

    public function __construct(Dongtien $transaction)
    {
        $this->transaction = $transaction;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->transaction->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.success';
    }

    public function broadcastWith(): array
    {
        return [
            'transaction' => [
                'id'             => $this->transaction->id,
                'amount'         => $this->transaction->amount,
                'balance_after'  => $this->transaction->balance_after,
                'noidung'        => $this->transaction->noidung,
                'payment_method' => $this->transaction->payment_method,
            ],
        ];
    }
}
