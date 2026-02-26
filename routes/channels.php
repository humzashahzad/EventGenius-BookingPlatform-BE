<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| NexusChat Broadcast Channels
|--------------------------------------------------------------------------
|
| Private & Presence channels for the real-time chat system.
| Authorization callbacks receive the authenticated user and channel params.
|
*/

// ─── User Private Channel (for personal notifications & new chat events) ──────
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ─── Chat Channel (messages within a specific chat room) ──────────────────────
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    // User must be a participant of this chat
    return \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();
});

// ─── Presence Channel (online status for a chat room) ─────────────────────────
Broadcast::channel('presence.chat.{chatId}', function ($user, $chatId) {
    $isParticipant = \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isParticipant) {
        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'avatar' => $user->avatar,
        ];
    }

    return false;
});

// ─── Global Online Presence (who is online across the app) ────────────────────
Broadcast::channel('online', function ($user) {
    return [
        'id'     => $user->id,
        'name'   => $user->name,
        'avatar' => $user->avatar,
    ];
});
