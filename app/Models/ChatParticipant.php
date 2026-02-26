<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    protected $fillable = [
        'chat_id',
        'user_id',
        'role',
        'is_muted',
        'is_pinned',
        'is_archived',
        'last_read_at',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'is_muted'     => 'boolean',
            'is_pinned'    => 'boolean',
            'is_archived'  => 'boolean',
            'last_read_at' => 'datetime',
            'joined_at'    => 'datetime',
            'left_at'      => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
