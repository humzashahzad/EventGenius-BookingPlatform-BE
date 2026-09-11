<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a user reads messages in a chat.
 * Tells the sender their messages were read (blue ticks).
 */
class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $userId,
        public string $userName,
        public array $messageIds,
        public string $readAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.{$this->chatId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'     => $this->chatId,
            'user_id'     => $this->userId,
            'user_name'   => $this->userName,
            'message_ids' => $this->messageIds,
            'read_at'     => $this->readAt,
        ];
    }
}
