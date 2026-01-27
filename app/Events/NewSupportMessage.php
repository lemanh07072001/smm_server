<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSupportMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SupportMessage $supportMessage;

    public function __construct(SupportMessage $supportMessage)
    {
        $this->supportMessage = $supportMessage;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('support.ticket.' . $this->supportMessage->ticket_id),
        ];

        // Gửi notification tới user sở hữu ticket
        $ticket = $this->supportMessage->ticket;
        if ($ticket) {
            $channels[] = new PrivateChannel('user.' . $ticket->user_id);

            // Nếu có admin được assign, gửi notification tới admin đó
            if ($ticket->assigned_admin_id) {
                $channels[] = new PrivateChannel('user.' . $ticket->assigned_admin_id);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'support.message.new';
    }

    public function broadcastWith(): array
    {
        $this->supportMessage->load(['user:id,name,email,role', 'attachments']);

        $data = [
            'id' => $this->supportMessage->id,
            'ticket_id' => $this->supportMessage->ticket_id,
            'user_id' => $this->supportMessage->user_id,
            'message' => $this->supportMessage->message,
            'is_read' => $this->supportMessage->is_read,
            'created_at' => $this->supportMessage->created_at->toISOString(),
            'user' => [
                'id' => $this->supportMessage->user->id,
                'name' => $this->supportMessage->user->name,
                'role' => $this->supportMessage->user->role,
            ],
            'attachments' => $this->supportMessage->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'url' => $attachment->url,
                    'is_image' => $attachment->isImage(),
                ];
            }),
        ];

        return $data;
    }
}
