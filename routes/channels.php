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

// Private channel for support tickets
Broadcast::channel('support.ticket.{ticketId}', function ($user, $ticketId) {
    // Check if user owns this ticket or is admin
    $ticket = \App\Models\SupportTicket::find($ticketId);
    if (!$ticket) {
        return false;
    }
    return $user->id === $ticket->user_id || $user->role === 0;
});

// Admin channel for all support messages
Broadcast::channel('support.admin', function ($user) {
    return $user->role === 0; // Only admins
});

// Notifications channel
Broadcast::channel('notifications.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Admin notifications channel
Broadcast::channel('notifications.admin', function ($user) {
    return $user->role === 0; // Only admins
});
