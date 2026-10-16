<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AppNotification $notification) {}

    public function broadcastOn(): array { return [new PrivateChannel('users.' . $this->notification->user_id)]; }

    public function broadcastAs(): string { return 'notification.created'; }

    public function broadcastWith(): array
    {
        return ['id' => $this->notification->id, 'title' => $this->notification->title, 'body' => $this->notification->body, 'link' => $this->notification->link];
    }
}
