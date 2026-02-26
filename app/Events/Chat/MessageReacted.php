<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a user adds or removes a reaction on a message.
 */
class MessageReacted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $messageId,
        public int $userId,
        public string $userName,
        public string $emoji,
        public string $action, // 'added' or 'removed'
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.{$this->chatId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.reacted';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'    => $this->chatId,
            'message_id' => $this->messageId,
            'user_id'    => $this->userId,
            'user_name'  => $this->userName,
            'emoji'      => $this->emoji,
            'action'     => $this->action,
        ];
    }
}
