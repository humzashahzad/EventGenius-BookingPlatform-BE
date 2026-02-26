<?php

namespace App\Http\Controllers\Api;

use App\Events\Chat\ChatNotification;
use App\Events\Chat\MessageReacted;
use App\Events\Chat\MessagesRead;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessageUpdated;
use App\Events\Chat\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

            // Notify the other user about the new chat
            broadcast(new ChatNotification($otherUserId, 'chat_created', [
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
                broadcast(new ChatNotification($uid, 'chat_created', [
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
        }

        // Load relationships for broadcasting
        $message->load(['sender', 'replyTo.sender', 'reactions.user', 'reads', 'chat.activeParticipants']);

        // Broadcast to the chat channel
        broadcast(new MessageSent($message))->toOthers();

        // Send notification to each participant's private channel
        foreach ($participantIds as $pid) {
            $otherUser = $chat->otherUser($pid);
            $chatName = $chat->type === 'group'
                ? $chat->name
                : $user->name;

            broadcast(new ChatNotification($pid, 'new_message', [
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

        broadcast(new MessageUpdated($message, 'edited'))->toOthers();

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

        broadcast(new MessageUpdated($message, 'deleted'))->toOthers();

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

            // Broadcast read receipt
            broadcast(new MessagesRead(
                $chat->id,
                $user->id,
                $user->name,
                $messageIds,
                $now->toISOString(),
            ))->toOthers();
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

        broadcast(new MessageReacted(
            $chat->id,
            $message->id,
            $user->id,
            $user->name,
            $request->emoji,
            $action,
        ))->toOthers();

        return response()->json([
            'success' => true,
            'action'  => $action,
        ]);
    }

    // ─── Typing Indicator ────────────────────────────────────────────────

    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        $this->authorizeParticipant($user, $chat);

        $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        broadcast(new UserTyping(
            $chat->id,
            $user->id,
            $user->name,
            $request->is_typing,
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    // ─── Search Users ────────────────────────────────────────────────────

    public function searchUsers(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:100',
        ]);

        $user = $request->user();
        $query = $request->q;

        $users = User::where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%");
            })
            ->select('id', 'name', 'email', 'avatar', 'role', 'last_seen_at')
            ->limit(20)
            ->get()
            ->map(function ($u) {
                return [
                    'id'        => $u->id,
                    'name'      => $u->name,
                    'email'     => $u->email,
                    'avatar'    => $u->avatar,
                    'role'      => $u->role,
                    'is_online' => $u->isOnline(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    // ─── Heartbeat (online presence) ─────────────────────────────────────

    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['last_seen_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ─── Authorization Helper ────────────────────────────────────────────

    private function authorizeParticipant(User $user, Chat $chat): void
    {
        if (!$chat->hasParticipant($user->id)) {
            abort(403, 'You are not a participant of this chat.');
        }
    }
}
