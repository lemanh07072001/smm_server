<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Channel cho thông báo user (đơn hàng, nạp tiền...)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Channel cho support ticket chat
Broadcast::channel('support.ticket.{ticketId}', function ($user, $ticketId) {
    $ticket = \App\Models\SupportTicket::find($ticketId);
    if (!$ticket) return false;
    return $user->isAdmin() || (int) $user->id === (int) $ticket->user_id;
});

// Channel cho admin nhận thông báo từ tất cả cuộc hội thoại support
Broadcast::channel('support.admin', function ($user) {
    return $user->isAdmin();
});

