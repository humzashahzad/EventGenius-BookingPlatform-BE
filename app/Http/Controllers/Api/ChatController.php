<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\JwtService;
use App\Services\SocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function getConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::where('user1_id', $user->id)
            ->orWhere('user2_id', $user->id)
            ->with(['user1:id,name,email,avatar,role', 'user2:id,name,email,avatar,role', 'latestMessage'])
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(function ($conversation) use ($user) {
                $otherUser = $conversation->getOtherUser($user->id);
                $unreadCount = $conversation->unreadCount($user->id);
                $latestMsg = $conversation->latestMessage->first();

                return [
                    'id' => $conversation->id,
                    'other_user' => $otherUser,
                    'last_message' => $latestMsg ? [
                        'message' => $latestMsg->message,
                        'created_at' => $latestMsg->created_at,
                        'is_mine' => $latestMsg->sender_id === $user->id,
                    ] : null,
                    'unread_count' => $unreadCount,
                    'updated_at' => $conversation->last_message_at ?? $conversation->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    public function getOrCreateConversation(Request $request): JsonResponse
    {
        $request->validate([
            'other_user_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();
        $otherUserId = $request->other_user_id;

        if ($user->id === $otherUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot create conversation with yourself.',
            ], 422);
        }

        // Find or create conversation (ensure user1_id < user2_id for consistency)
        [$userId1, $userId2] = $user->id < $otherUserId
            ? [$user->id, $otherUserId]
            : [$otherUserId, $user->id];

        $conversation = Conversation::firstOrCreate(
            ['user1_id' => $userId1, 'user2_id' => $userId2],
            ['last_message_at' => now()]
        );

        $conversation->load(['user1:id,name,email,avatar,role', 'user2:id,name,email,avatar,role']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $conversation->id,
                'other_user' => $conversation->getOtherUser($user->id),
            ],
        ]);
    }

    public function getMessages(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($q) use ($user) {
                $q->where('user1_id', $user->id)
                  ->orWhere('user2_id', $user->id);
            })
            ->firstOrFail();

        $messages = $conversation->messages()
            ->with('sender:id,name,avatar')
            ->get()
            ->map(fn ($message) => $this->formatMessage($message, $user->id));

        // Mark all messages from other user as read and broadcast read receipt
        $toMark = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false);
        $messageIds = $toMark->pluck('id')->toArray();
        $senderId = $toMark->value('sender_id');
        $toMark->update(['is_read' => true, 'read_at' => now()]);

        if (!empty($messageIds) && $senderId) {
            app(SocketService::class)->broadcastReadReceipt([
                'conversationId' => (int) $conversationId,
                'messageIds' => $messageIds,
                'readBy' => $user->id,
                'senderId' => $senderId,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    private function formatMessage(Message $message, int $currentUserId): array
    {
        return [
            'id' => $message->id,
            'sender' => $message->sender,
            'message' => $message->message,
            'attachments' => $message->attachments ?? [],
            'is_mine' => $message->sender_id === $currentUserId,
            'is_read' => $message->is_read,
            'read_at' => $message->read_at?->toIso8601String(),
            'reactions' => $message->reactions ?? [],
            'created_at' => $message->created_at,
        ];
    }

    public function sendMessage(Request $request, string $conversationId): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240', // 10MB per file
        ]);

        $user = $request->user();
        $text = $request->input('message', '');
        $hasFiles = $request->hasFile('attachments');

        if (trim($text) === '' && !$hasFiles) {
            return response()->json([
                'success' => false,
                'message' => 'Message text or at least one attachment is required.',
            ], 422);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($q) use ($user) {
                $q->where('user1_id', $user->id)
                  ->orWhere('user2_id', $user->id);
            })
            ->firstOrFail();

        $attachments = [];
        if ($hasFiles) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store("chat/{$conversation->id}", 'public');
                $attachments[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'type' => str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'file',
                ];
            }
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'message' => trim($text) !== '' ? trim($text) : '',
            'attachments' => $attachments ?: null,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $message->load('sender:id,name,avatar');

        $recipientId = $conversation->user1_id === $user->id
            ? $conversation->user2_id
            : $conversation->user1_id;

        $payload = $this->formatMessage($message, $user->id);
        $payload['is_mine'] = false;
        $payload['is_read'] = false;
        $payload['read_at'] = null;

        app(SocketService::class)->broadcastMessage([
            'conversationId' => $conversation->id,
            'recipientId' => $recipientId,
            'message' => $payload,
            'sender' => $message->sender,
        ]);

        // Create in-app notification for recipient (real-time via SSE + notification sound)
        $bodyPreview = trim($text) !== ''
            ? \Illuminate\Support\Str::limit(trim($text), 80)
            : (count($attachments) ? 'Sent an attachment' : 'New message');
        Notification::create([
            'user_id' => $recipientId,
            'type' => 'chat_message',
            'title' => $message->sender->name . ' sent you a message',
            'body' => $bodyPreview,
            'data' => [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'sender_id' => $user->id,
                'sender_name' => $message->sender->name,
            ],
        ]);

        $out = $this->formatMessage($message, $user->id);
        $out['is_mine'] = true;
        $out['is_read'] = false;
        $out['read_at'] = null;

        return response()->json([
            'success' => true,
            'data' => $out,
        ], 201);
    }

    public function markAsRead(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($q) use ($user) {
                $q->where('user1_id', $user->id)
                  ->orWhere('user2_id', $user->id);
            })
            ->firstOrFail();

        $toUpdate = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false);
        $messageIds = $toUpdate->pluck('id')->toArray();
        $senderId = $toUpdate->value('sender_id');
        $updated = $toUpdate->update(['is_read' => true, 'read_at' => now()]);

        if ($updated > 0 && !empty($messageIds) && $senderId) {
            app(SocketService::class)->broadcastReadReceipt([
                'conversationId' => (int) $conversationId,
                'messageIds' => $messageIds,
                'readBy' => $user->id,
                'senderId' => $senderId,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "$updated messages marked as read.",
        ]);
    }

    public function addReaction(Request $request, string $conversationId, string $messageId): JsonResponse
    {
        $request->validate(['emoji' => 'required|string|max:10']);

        $user = $request->user();
        $emoji = $request->emoji;

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($q) use ($user) {
                $q->where('user1_id', $user->id)->orWhere('user2_id', $user->id);
            })
            ->firstOrFail();

        $message = Message::where('conversation_id', $conversation->id)->findOrFail($messageId);
        $reactions = $message->reactions ?? [];
        $existing = array_filter($reactions, fn ($r) => ($r['emoji'] ?? '') === $emoji && (int) ($r['user_id'] ?? 0) === $user->id);
        if (!empty($existing)) {
            $reactions = array_values(array_filter($reactions, fn ($r) => ($r['emoji'] ?? '') !== $emoji || (int) ($r['user_id'] ?? 0) !== $user->id));
        } else {
            $reactions[] = ['emoji' => $emoji, 'user_id' => $user->id];
        }
        $message->update(['reactions' => $reactions]);

        $recipientId = $conversation->user1_id === $user->id ? $conversation->user2_id : $conversation->user1_id;
        app(SocketService::class)->broadcastReaction([
            'conversationId' => (int) $conversationId,
            'messageId' => (int) $messageId,
            'reactions' => $message->fresh()->reactions ?? [],
            'userId' => $user->id,
            'recipientId' => $recipientId,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['reactions' => $message->reactions],
        ]);
    }

    public function stream(Request $request): Response|StreamedResponse
    {
        // Authenticate via token query param (SSE cannot send headers)
        $token = $request->query('token');
        $user  = null;

        if ($token) {
            try {
                $jwtService = app(JwtService::class);
                $user       = $jwtService->validate($token);
            } catch (\Throwable) {
                $user = null;
            }
        }

        if (!$user) {
            return response('Unauthorized', 401);
        }

        $userId = $user->id;

        return response()->stream(function () use ($userId) {
            $lastId = 0;

            while (true) {
                // Get new messages for this user
                $newMessages = Message::whereHas('conversation', function ($q) use ($userId) {
                    $q->where('user1_id', $userId)
                      ->orWhere('user2_id', $userId);
                })
                ->where('id', '>', $lastId)
                ->where('sender_id', '!=', $userId)
                ->with(['sender:id,name,avatar', 'conversation'])
                ->orderBy('id')
                ->get();

                foreach ($newMessages as $message) {
                    echo "data: " . json_encode([
                        'type' => 'message',
                        'conversation_id' => $message->conversation_id,
                        'message' => $this->formatMessage($message, $userId),
                    ]) . "\n\n";

                    $lastId = $message->id;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                sleep(2);

                if (connection_aborted()) {
                    break;
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
