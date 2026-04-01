<?php

namespace App\Http\Controllers\Api;

use App\Events\Chat\ChatNotification;
use App\Events\Chat\MessageReacted;
use App\Events\Chat\MessagesRead;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Booking;
use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\MessageRead;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NexusChatController extends Controller
{
    // ─── List Chats ──────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $chats = Chat::whereHas('chatParticipants', function ($q) use ($user) {
            $q->where('user_id', $user->id)->whereNull('left_at');
        })
            ->with([
                'chatParticipants.user',
                'activeParticipants',
                'latestMessage.sender',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ChatResource::collection($chats),
        ]);
    }

    // ─── Create / Get Private Chat ───────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'required_without:user_ids|integer|exists:users,id',
            'user_ids' => 'required_without:user_id|array|min:2',
            'user_ids.*' => 'integer|exists:users,id',
            'name'     => 'nullable|string|max:100',
        ]);

        $user = $request->user();

        // ─── Private chat (1-on-1) ──────────────────────────────────
        if ($request->has('user_id')) {
            $otherUserId = $request->user_id;

            if ($otherUserId === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot create a chat with yourself.',
                ], 422);
            }

            // Check if private chat already exists
            $existing = Chat::findPrivateChat($user->id, $otherUserId);
            if ($existing) {
                $existing->load(['chatParticipants.user', 'activeParticipants', 'latestMessage.sender']);
                return response()->json([
                    'success' => true,
                    'data'    => new ChatResource($existing),
                ]);
            }

            // Create new private chat
            $chat = DB::transaction(function () use ($user, $otherUserId) {
                $chat = Chat::create([
                    'type'       => 'private',
                    'created_by' => $user->id,
                ]);

                ChatParticipant::create([
                    'chat_id' => $chat->id,
                    'user_id' => $user->id,
                    'role'    => 'owner',
                ]);

                ChatParticipant::create([
                    'chat_id' => $chat->id,
                    'user_id' => $otherUserId,
                    'role'    => 'member',
                ]);

                return $chat;
            });

            $chat->load(['chatParticipants.user', 'activeParticipants', 'latestMessage.sender']);

            // Invalidate private chat lookup cache
            $min = min($user->id, $otherUserId);
            $max = max($user->id, $otherUserId);
            Cache::forget("chat:private:{$min}:{$max}");

            // Notify the other user about the new chat
            $this->broadcastSafely($request, new ChatNotification($otherUserId, 'chat_created', [
                'chat' => (new ChatResource($chat))->resolve(),
            ]));

            return response()->json([
                'success' => true,
                'data'    => new ChatResource($chat),
            ], 201);
        }

        // ─── Group chat ─────────────────────────────────────────────
        $userIds = collect($request->user_ids)->unique()->values();
        if (!$userIds->contains($user->id)) {
            $userIds->push($user->id);
        }

        $chat = DB::transaction(function () use ($user, $userIds, $request) {
            $chat = Chat::create([
                'type'       => 'group',
                'name'       => $request->name ?? 'Group Chat',
                'created_by' => $user->id,
            ]);

            foreach ($userIds as $uid) {
                ChatParticipant::create([
                    'chat_id' => $chat->id,
                    'user_id' => $uid,
                    'role'    => $uid === $user->id ? 'owner' : 'member',
                ]);
            }

            return $chat;
        });

        $chat->load(['chatParticipants.user', 'activeParticipants', 'latestMessage.sender']);

        // Notify all other participants
        foreach ($userIds as $uid) {
            if ($uid !== $user->id) {
                $this->broadcastSafely($request, new ChatNotification($uid, 'chat_created', [
                    'chat' => (new ChatResource($chat))->resolve(),
                ]));
            }
        }

        return response()->json([
            'success' => true,
            'data'    => new ChatResource($chat),
        ], 201);
    }

    // ─── Show Chat ───────────────────────────────────────────────────────

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $chat);

        $chat->load(['chatParticipants.user', 'activeParticipants', 'latestMessage.sender']);

        return response()->json([
            'success' => true,
            'data'    => new ChatResource($chat),
        ]);
    }

    // ─── Delete / Leave Chat ─────────────────────────────────────────────

    public function destroy(Request $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        $this->authorizeParticipant($user, $chat);

        $participant = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->first();

        $participant->update(['left_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Left the chat.',
        ]);
    }

    // ─── Get Messages ────────────────────────────────────────────────────

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $chat);

        $request->validate([
            'before' => 'nullable|integer', // Message ID for pagination
            'limit'  => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $request->input('limit', 50);

        $query = $chat->messages()
            ->with(['sender', 'replyTo.sender', 'reactions.user', 'reads'])
            ->orderByDesc('id');

        if ($request->before) {
            $query->where('id', '<', $request->before);
        }

        $messages = $query->limit($limit)->get()->reverse()->values();

        return response()->json([
            'success'  => true,
            'data'     => MessageResource::collection($messages),
            'has_more' => $messages->count() === $limit,
        ]);
    }

    // ─── Send Message ────────────────────────────────────────────────────

    public function sendMessage(Request $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        $this->authorizeParticipant($user, $chat);

        $request->validate([
            'body'        => 'nullable|string|max:5000',
            'type'        => 'nullable|in:text,image,video,audio,file,voice,gif',
            'reply_to_id' => 'nullable|integer|exists:messages,id',
            'forwarded_from_id' => 'nullable|integer|exists:messages,id',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240', // 10MB per file
        ]);

        if (!$request->body && !$request->hasFile('attachments')) {
            return response()->json([
                'success' => false,
                'message' => 'Message body or attachments required.',
            ], 422);
        }

        // Handle file uploads
        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store("chat/{$chat->id}", 'public');
                $attachments[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        }

        // Determine message type
        $type = $request->input('type', 'text');
        if (count($attachments) > 0 && $type === 'text') {
            $mime = $attachments[0]['mime'] ?? '';
            if (str_starts_with($mime, 'image/')) $type = 'image';
            elseif (str_starts_with($mime, 'video/')) $type = 'video';
            elseif (str_starts_with($mime, 'audio/')) $type = 'audio';
            else $type = 'file';
        }

        $message = Message::create([
            'chat_id'           => $chat->id,
            'sender_id'         => $user->id,
            'body'              => $request->body,
            'type'              => $type,
            'reply_to_id'       => $request->reply_to_id,
            'forwarded_from_id' => $request->forwarded_from_id,
            'attachments'       => count($attachments) > 0 ? $attachments : null,
        ]);

        // Update chat's last_message_at
        $chat->update(['last_message_at' => $message->created_at]);

        // Create delivery records for all other participants
        $participantIds = $chat->chatParticipants()
            ->whereNull('left_at')
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id');

        foreach ($participantIds as $pid) {
            MessageRead::create([
                'message_id' => $message->id,
                'user_id'    => $pid,
            ]);
            // Invalidate cached unread count for this participant
            Cache::forget("chat:unread:{$chat->id}:{$pid}");
        }

        // Load relationships for broadcasting
        $message->load(['sender', 'replyTo.sender', 'reactions.user', 'reads', 'chat.activeParticipants']);

        // Broadcast to the chat channel (safe no-op when realtime backend is unavailable)
        $this->broadcastSafely($request, new MessageSent($message), true);

        // Send notification to each participant's private channel
        foreach ($participantIds as $pid) {
            $chatName = $chat->type === 'group'
                ? $chat->name
                : $user->name;

            $this->broadcastSafely($request, new ChatNotification($pid, 'new_message', [
                'chat_id'     => $chat->id,
                'chat_name'   => $chatName,
                'message_id'  => $message->id,
                'sender_id'   => $user->id,
                'sender_name' => $user->name,
                'sender_avatar' => $user->avatar,
                'body'        => Str::limit($message->body ?? '[Attachment]', 80),
                'type'        => $message->type,
                'created_at'  => $message->created_at->toISOString(),
            ]));
        }

        NotificationService::send(
            $participantIds->all(),
            'chat_message',
            "{$user->name} sent you a message",
            Str::limit($message->body ?? '[Attachment]', 80),
            [
                'conversation_id' => $chat->id,
                'chat_id'         => $chat->id,
                'message_id'      => $message->id,
                'sender_id'       => $user->id,
                'sender_name'     => $user->name,
                'sender_avatar'   => $user->avatar,
                'chat_name'       => $chat->type === 'group' ? $chat->name : $user->name,
                'type'            => $message->type,
            ],
        );

        return response()->json([
            'success' => true,
            'data'    => new MessageResource($message),
        ], 201);
    }

    // ─── Edit Message ────────────────────────────────────────────────────

    public function editMessage(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if ($message->sender_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own messages.',
            ], 403);
        }

        if ($message->is_deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot edit a deleted message.',
            ], 422);
        }

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update([
            'body'      => $request->body,
            'is_edited' => true,
            'edited_at' => now(),
        ]);

        $message->load(['sender', 'replyTo.sender', 'reactions.user', 'reads', 'chat.activeParticipants']);

        $this->broadcastSafely($request, new MessageUpdated($message, 'edited'), true);

        return response()->json([
            'success' => true,
            'data'    => new MessageResource($message),
        ]);
    }

    // ─── Delete Message ──────────────────────────────────────────────────

    public function deleteMessage(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if ($message->sender_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own messages.',
            ], 403);
        }

        $message->update([
            'is_deleted' => true,
            'deleted_at' => now(),
            'body'       => null,
            'attachments' => null,
        ]);

        $message->load(['sender', 'replyTo.sender', 'reactions.user', 'reads', 'chat.activeParticipants']);

        $this->broadcastSafely($request, new MessageUpdated($message, 'deleted'), true);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted.',
        ]);
    }

    // ─── Mark Messages as Read ───────────────────────────────────────────

    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        $this->authorizeParticipant($user, $chat);

        $now = now();

        // Update participant's last_read_at
        ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => $now]);

        // Mark all unread messages as read
        $unreadReads = MessageRead::whereHas('message', function ($q) use ($chat) {
                $q->where('chat_id', $chat->id);
            })
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->get();

        $messageIds = $unreadReads->pluck('message_id')->toArray();

        if (count($messageIds) > 0) {
            // Also set delivered_at if not already set
            MessageRead::whereIn('id', $unreadReads->pluck('id'))
                ->update([
                    'read_at'      => $now,
                    'delivered_at' => DB::raw("COALESCE(delivered_at, '{$now}')"),
                ]);

            // Invalidate cached unread count
            Cache::forget("chat:unread:{$chat->id}:{$user->id}");

            // Broadcast read receipt
            $this->broadcastSafely($request, new MessagesRead(
                $chat->id,
                $user->id,
                $user->name,
                $messageIds,
                $now->toISOString(),
            ), true);
        }

        return response()->json([
            'success'     => true,
            'message_ids' => $messageIds,
        ]);
    }

    // ─── Toggle Reaction ─────────────────────────────────────────────────

    public function toggleReaction(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        // Verify user is in this chat
        $chat = $message->chat;
        $this->authorizeParticipant($user, $chat);

        $request->validate([
            'emoji' => 'required|string|max:32',
        ]);

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $request->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id'    => $user->id,
                'emoji'      => $request->emoji,
            ]);
            $action = 'added';
        }

        $this->broadcastSafely($request, new MessageReacted(
            $chat->id,
            $message->id,
            $user->id,
            $user->name,
            $request->emoji,
            $action,
        ), true);

        return response()->json([
            'success' => true,
            'action'  => $action,
        ]);
    }

    // ─── Search Users ────────────────────────────────────────────────────

    public function searchUsers(Request $request): JsonResponse
    {
        $request->validate([
            'q'     => 'nullable|string|max:100',
            'role'  => 'nullable|in:admin,store_owner,client',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $user = $request->user();
        $search = trim((string) $request->input('q', ''));
        $role = $request->input('role');
        $limit = (int) $request->input('limit', 20);

        $query = User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->select('id', 'name', 'email', 'avatar', 'role', 'last_seen_at');

        if ($role) {
            $query->where('role', $role);
        }

        // Keep non-admin search scoped to support + role-allowed contacts.
        if ($user->role !== 'admin') {
            $allowedIds = $this->allowedContactIdsFor($user);
            if (count($allowedIds) === 0) {
                return response()->json([
                    'success' => true,
                    'data'    => [],
                ]);
            }
            $query->whereIn('id', $allowedIds);
        }

        if ($search !== '') {
            $query->where(function (Builder $sub) use ($search) {
                $sub->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $users = $query
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => $this->toChatUserPayload($u));

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    // ─── Eligible Contacts ────────────────────────────────────────────────

    public function eligibleContacts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }

        $contacts = Cache::remember("eligible_contacts:{$user->id}", 600, function () use ($user) {
            $query = User::query()
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->select('id', 'name', 'email', 'avatar', 'role', 'last_seen_at');

            if ($user->role === 'store_owner') {
                $storeId = $user->store?->id;
                if (!$storeId) {
                    return [];
                }

                $clientIds = Booking::where('store_id', $storeId)
                    ->whereNotNull('client_id')
                    ->distinct()
                    ->pluck('client_id');

                if ($clientIds->isEmpty()) {
                    return [];
                }

                $query->where('role', 'client')->whereIn('id', $clientIds);
            } else {
                $storeIds = Booking::where('client_id', $user->id)
                    ->whereNotNull('store_id')
                    ->distinct()
                    ->pluck('store_id');

                if ($storeIds->isEmpty()) {
                    return [];
                }

                $query->where('role', 'store_owner')
                    ->whereHas('store', fn (Builder $q) => $q->whereIn('id', $storeIds));
            }

            return $query
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn (User $u) => $this->toChatUserPayload($u))
                ->all();
        });

        return response()->json([
            'success' => true,
            'data'    => $contacts,
        ]);
    }

    // ─── Authorization Helper ────────────────────────────────────────────

    private function authorizeParticipant(User $user, Chat $chat): void
    {
        if (!$chat->hasParticipant($user->id)) {
            abort(403, 'You are not a participant of this chat.');
        }
    }

    private function toChatUserPayload(User $user): array
    {
        return [
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'avatar'    => $user->avatar,
            'role'      => $user->role,
            'is_online' => $user->isOnline(),
        ];
    }

    private function allowedContactIdsFor(User $user): array
    {
        return Cache::remember("allowed_contacts:{$user->id}", 600, function () use ($user) {
            $supportIds = Cache::remember('users:admins:active', 900, function () {
                return User::where('role', 'admin')
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();
            });

            if ($user->role === 'store_owner') {
                $storeId = $user->store?->id;
                if (!$storeId) {
                    return $supportIds;
                }

                $clientIds = Booking::where('store_id', $storeId)
                    ->whereNotNull('client_id')
                    ->distinct()
                    ->pluck('client_id')
                    ->all();

                return array_values(array_unique(array_merge($supportIds, $clientIds)));
            }

            if ($user->role === 'client') {
                $storeIds = Booking::where('client_id', $user->id)
                    ->whereNotNull('store_id')
                    ->distinct()
                    ->pluck('store_id');

                if ($storeIds->isEmpty()) {
                    return $supportIds;
                }

                $storeOwnerIds = User::where('role', 'store_owner')
                    ->where('is_active', true)
                    ->whereHas('store', fn (Builder $q) => $q->whereIn('id', $storeIds))
                    ->pluck('id')
                    ->all();

                return array_values(array_unique(array_merge($supportIds, $storeOwnerIds)));
            }

            return [];
        });
    }

    private function broadcastSafely(Request $request, object $event, bool $toOthers = false): void
    {
        if (!$this->canBroadcast($request)) {
            return;
        }

        try {
            if ($toOthers && method_exists($event, 'dontBroadcastToCurrentUser')) {
                $event->dontBroadcastToCurrentUser();
            }
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Avoid broadcast deadlocks when API and Reverb are configured to the same host:port.
     * In that case, synchronous broadcast calls can block the request for ~30s.
     */
    private function canBroadcast(Request $request): bool
    {
        if ((bool) env('NEXUS_FORCE_BROADCAST', false)) {
            return true;
        }

        $connection = (string) config('broadcasting.default', 'null');
        if ($connection === '' || in_array($connection, ['null', 'log'], true)) {
            return false;
        }

        if (!in_array($connection, ['reverb', 'pusher'], true)) {
            return true;
        }

        $host = (string) config("broadcasting.connections.{$connection}.options.host", '');
        $port = (int) config("broadcasting.connections.{$connection}.options.port", 0);

        if ($host === '' || $port <= 0) {
            return true;
        }

        $normalizeHost = static function (string $value): string {
            $v = strtolower(trim($value));
            return in_array($v, ['127.0.0.1', '::1'], true) ? 'localhost' : $v;
        };

        $broadcastHost = $normalizeHost($host);
        $requestHost = $normalizeHost((string) $request->getHost());
        $requestPort = (int) ($request->getPort() ?: ($request->isSecure() ? 443 : 80));

        return !($broadcastHost === $requestHost && $port === $requestPort);
    }
}
