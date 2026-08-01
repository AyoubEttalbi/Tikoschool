<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UnreadMessageCountUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $unreadCount;

    public function __construct($userId, $unreadCount)
    {
       
        $this->userId = $userId;
        $this->unreadCount = $unreadCount;
    }

    public function broadcastOn()
    {
        // PRIVATE, not public. Laravel only runs the Broadcast::channel() authorization
        // callback for private/presence channels — on a public channel the guard in
        // routes/channels.php was never invoked, so any connected client could subscribe to
        // `user.{anyId}.notifications` and observe who was messaging whom.
        return new PrivateChannel("user.{$this->userId}.notifications");
    }

    public function broadcastWith()
    {
        return [
            'unread_count' => $this->unreadCount
        ];
    }
    // In your UnreadMessageCountUpdated event
    public function broadcastAs()
    {
        return 'UnreadMessageCountUpdated'; // Specify a custom event name
    }
}