<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'sender_id',
        'body',
        'type',
        'reply_to_id',
        'forwarded_from_id',
        'attachments',
        'is_edited',
        'edited_at',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments'  => 'array',
            'is_edited'    => 'boolean',
            'edited_at'    => 'datetime',
            'is_deleted'   => 'boolean',
            'deleted_at'   => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'forwarded_from_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Get the delivery status for a specific viewer:
     * - 'sent'      = message saved to DB
     * - 'delivered'  = all other participants received it
     * - 'read'       = all other participants read it
     */
    public function statusFor(int $chatParticipantCount): string
    {
        $otherCount = $chatParticipantCount - 1; // Exclude sender
        if ($otherCount <= 0) return 'read';

        $readCount = $this->reads()->whereNotNull('read_at')->count();
        if ($readCount >= $otherCount) return 'read';

        $deliveredCount = $this->reads()->whereNotNull('delivered_at')->count();
        if ($deliveredCount >= $otherCount) return 'delivered';

        return 'sent';
    }
}
