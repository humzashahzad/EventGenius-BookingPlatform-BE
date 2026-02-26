<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'chat_id'     => $this->chat_id,
            'sender_id'   => $this->sender_id,
            'body'        => $this->is_deleted ? null : $this->body,
            'type'        => $this->type,
            'attachments' => $this->is_deleted ? [] : ($this->attachments ?? []),
            'is_edited'   => $this->is_edited,
            'edited_at'   => $this->edited_at?->toISOString(),
            'is_deleted'  => $this->is_deleted,

            // Reply
            'reply_to' => $this->when($this->relationLoaded('replyTo') && $this->replyTo, function () {
                return [
                    'id'        => $this->replyTo->id,
                    'body'      => $this->replyTo->is_deleted ? 'This message was deleted' : $this->replyTo->body,
                    'sender_id' => $this->replyTo->sender_id,
                    'sender_name' => $this->replyTo->sender?->name,
                    'type'      => $this->replyTo->type,
                ];
            }),

            // Forwarded
            'forwarded_from' => $this->when($this->forwarded_from_id, function () {
                return [
                    'id'          => $this->forwardedFrom?->id,
                    'sender_name' => $this->forwardedFrom?->sender?->name,
                ];
            }),

            // Sender info
            'sender' => [
                'id'     => $this->sender->id,
                'name'   => $this->sender->name,
                'avatar' => $this->sender->avatar,
                'role'   => $this->sender->role,
            ],

            // Reactions (grouped by emoji)
            'reactions' => $this->when($this->relationLoaded('reactions'), function () {
                return $this->reactions->groupBy('emoji')->map(function ($group, $emoji) {
                    return [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'users' => $group->map(fn ($r) => [
                            'id'   => $r->user_id,
                            'name' => $r->user?->name,
                        ])->values(),
                    ];
                })->values();
            }),

            // Read status (for the current user's sent messages)
            'status' => $this->when($this->relationLoaded('reads'), function () use ($request) {
                if ($this->sender_id !== $request->user()?->id) {
                    return null; // Only show status for own messages
                }
                $participantCount = $this->chat?->activeParticipants?->count() ?? 2;
                return $this->statusFor($participantCount);
            }),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
