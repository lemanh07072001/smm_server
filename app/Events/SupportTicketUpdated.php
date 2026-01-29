<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SupportTicket $ticket;

    public function __construct(SupportTicket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.ticket.' . $this->ticket->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.updated';
    }

    public function broadcastWith(): array
    {
        $statusLabels = SupportTicket::statusLabels();

        return [
            'id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'status' => $this->ticket->status,
            'status_label' => $statusLabels[$this->ticket->status] ?? $this->ticket->status,
            'priority' => $this->ticket->priority,
            'category' => $this->ticket->category,
            'assigned_admin_id' => $this->ticket->assigned_admin_id,
            'closed_at' => $this->ticket->closed_at?->toISOString(),
            'updated_at' => $this->ticket->updated_at->toISOString(),
            'message' => $this->getStatusMessage(),
        ];
    }

    private function getStatusMessage(): string
    {
        return match ($this->ticket->status) {
            SupportTicket::STATUS_OPEN => 'Ticket #' . $this->ticket->id . ' đã được mở lại.',
            SupportTicket::STATUS_IN_PROGRESS => 'Ticket #' . $this->ticket->id . ' đang được xử lý.',
            SupportTicket::STATUS_CLOSED => 'Ticket #' . $this->ticket->id . ' đã được đóng.',
            default => 'Ticket #' . $this->ticket->id . ' đã được cập nhật.',
        };
    }
}
