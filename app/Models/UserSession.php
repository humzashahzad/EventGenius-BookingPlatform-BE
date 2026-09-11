<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'jti',
        'ip_address',
        'user_agent',
        'is_revoked',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'is_revoked'   => 'boolean',
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_revoked', false)
                     ->where('expires_at', '>', now());
    }
}
