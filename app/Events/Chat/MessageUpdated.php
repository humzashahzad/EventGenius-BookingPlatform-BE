<?php

namespace App\Events\Chat;

use App\Http\Resources\Chat\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a message is edited or deleted.
 */
class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public string $action, // 'edited' or 'deleted'
    ) {
        $this->message->load(['sender', 'replyTo.sender', 'reactions.user', 'reads']);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.{$this->message->chat_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => (new MessageResource($this->message))->resolve(),
            'action'  => $this->action,
        ];
    }
}
