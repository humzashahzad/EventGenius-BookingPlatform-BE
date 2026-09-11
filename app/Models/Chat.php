<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'avatar',
        'description',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')
            ->withPivot(['role', 'is_muted', 'is_pinned', 'is_archived', 'last_read_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function activeParticipants(): BelongsToMany
    {
        return $this->participants()->whereNull('chat_participants.left_at');
    }

    public function chatParticipants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Get the other user in a private chat.
     */
    public function otherUser(int $userId): ?User
    {
        if ($this->type !== 'private') {
            return null;
        }

        return $this->activeParticipants()
            ->where('users.id', '!=', $userId)
            ->first();
    }

    /**
     * Count unread messages for a specific user.
     */
    public function unreadCountFor(int $userId): int
    {
        $participant = $this->chatParticipants()
            ->where('user_id', $userId)
            ->first();

        if (!$participant || !$participant->last_read_at) {
            return $this->messages()->where('sender_id', '!=', $userId)->count();
        }

        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->where('created_at', '>', $participant->last_read_at)
            ->where('is_deleted', false)
            ->count();
    }

    /**
     * Check if a user is a participant.
     */
    public function hasParticipant(int $userId): bool
    {
        return $this->chatParticipants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Find an existing private chat between two users.
     */
    public static function findPrivateChat(int $userId1, int $userId2): ?self
    {
        return static::where('type', 'private')
            ->whereHas('chatParticipants', function ($q) use ($userId1) {
                $q->where('user_id', $userId1)->whereNull('left_at');
            })
            ->whereHas('chatParticipants', function ($q) use ($userId2) {
                $q->where('user_id', $userId2)->whereNull('left_at');
            })
            ->first();
    }
}
