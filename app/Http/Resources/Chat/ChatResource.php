<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $participant = $this->chatParticipants->where('user_id', $user->id)->first();

        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'name'             => $this->type === 'group' ? $this->name : null,
            'avatar'           => $this->type === 'group' ? $this->avatar : null,
            'description'      => $this->when($this->type === 'group', $this->description),
            'created_by'       => $this->created_by,
            'last_message_at'  => $this->last_message_at?->toISOString(),
            'created_at'       => $this->created_at->toISOString(),

            // Other user info (for private chats)
            'other_user'       => $this->when($this->type === 'private', function () use ($user) {
                $other = $this->otherUser($user->id);
                return $other ? [
                    'id'       => $other->id,
                    'name'     => $other->name,
                    'email'    => $other->email,
                    'avatar'   => $other->avatar,
                    'role'     => $other->role,
                    'is_online' => $other->isOnline(),
                    'last_seen_at' => $other->last_seen_at?->toISOString(),
                ] : null;
            }),

            // Current user's participation info
            'is_muted'    => $participant?->is_muted ?? false,
            'is_pinned'   => $participant?->is_pinned ?? false,
            'is_archived' => $participant?->is_archived ?? false,

            // Unread count
            'unread_count' => $this->unreadCountFor($user->id),

            // Latest message preview
            'latest_message' => $this->when($this->relationLoaded('latestMessage'), function () {
                $msg = $this->latestMessage;
                if (!$msg) return null;
                return [
                    'id'         => $msg->id,
                    'body'       => $msg->is_deleted ? 'This message was deleted' : $msg->body,
                    'type'       => $msg->type,
                    'sender_id'  => $msg->sender_id,
                    'sender_name' => $msg->sender?->name,
                    'is_deleted' => $msg->is_deleted,
                    'created_at' => $msg->created_at->toISOString(),
                ];
            }),

            // Participants (for group chats)
            'participants' => $this->when($this->type === 'group' && $this->relationLoaded('activeParticipants'), function () {
                return $this->activeParticipants->map(fn ($u) => [
                    'id'       => $u->id,
                    'name'     => $u->name,
                    'avatar'   => $u->avatar,
                    'role'     => $u->pivot->role,
                    'is_online' => $u->isOnline(),
                ]);
            }),
        ];
    }
}
