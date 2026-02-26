<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a notification to a specific user's private channel.
 * Used for: new chat created, new message notification (for users not in the chat channel).
 */
class ChatNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $type,   // 'new_message', 'chat_created', etc.
        public array $data,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.notification';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
        ];
    }
}
