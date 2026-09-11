<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'last_seen_at'      => 'datetime',
        ];
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'client_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'client_id');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function activeSessions()
    {
        return $this->hasMany(UserSession::class)
            ->where('is_revoked', false)
            ->where('expires_at', '>', now());
    }

    public function chats(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_participants')
            ->withPivot(['role', 'is_muted', 'is_pinned', 'is_archived', 'last_read_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function activeChats(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->chats()->whereNull('chat_participants.left_at');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Check if user is online (seen within last 2 minutes).
     * Cached for 30 seconds to reduce DB hits on chat user lists.
     */
    public function isOnline(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember("user:online:{$this->id}", 30, function () {
            return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
        });
    }
}
