<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('message.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('presence-online-users', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'status' => 'online' // Initial status
    ];
});

// User-specific notifications. This callback only runs because the channel is
// PRIVATE (see UnreadMessageCountUpdated::broadcastOn and DashboardLayout.jsx) —
// Laravel never authorizes public channels.
// A second, identical registration under `{userId}` was removed: it silently
// overwrote this one and served no purpose.
Broadcast::channel('user.{id}.notifications', function ($user, $id) {
    return (int) $user->id === (int) $id;
});