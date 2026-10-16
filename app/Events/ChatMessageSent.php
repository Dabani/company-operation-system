<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Signals "something changed in this conversation"; clients refetch (no message content travels over the socket). */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatConversation $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->conversation->id),
            new PrivateChannel('org.' . $this->conversation->organization_id . '.agents'),
        ];
    }

    public function broadcastAs(): string { return 'chat.updated'; }

    public function broadcastWith(): array { return ['conversation_id' => $this->conversation->id]; }
}
