<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::forUser($request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $unreadCount = Notification::forUser($request->user()->id)->unread()->count();

        return response()->json([
            'success'      => true,
            'data'         => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get unread count only (lightweight poll).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::forUser($request->user()->id)->unread()->count();

        // Also get the latest 5 unread for the dropdown
        $latest = Notification::forUser($request->user()->id)
            ->unread()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success'      => true,
            'unread_count' => $count,
            'latest'       => $latest,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::forUser($request->user()->id)->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        Notification::forUser($request->user()->id)
            ->unread()
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        Notification::forUser($request->user()->id)->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * SSE (Server-Sent Events) stream for real-time notifications.
     * JWT token passed as query param: ?token=xxx
     */
    public function stream(Request $request): Response
    {
        // Authenticate via token query param (SSE cannot send headers)
        $token = $request->query('token');
        $user  = null;

        if ($token) {
            try {
                $jwtService = app(JwtService::class);
                $user      = $jwtService->validate($token);
            } catch (\Throwable) {
                $user = null;
            }
        }

        if (!$user) {
            return response('Unauthorized', 401);
        }

        $userId    = $user->id;
        $lastId    = (int) ($request->header('Last-Event-ID') ?? $request->query('lastId', 0));

        $stream = function () use ($userId, $lastId) {
            // Disable output buffering
            if (ob_get_level()) ob_end_clean();
            set_time_limit(0);
            ignore_user_abort(true);

            // Send initial connection event
            echo "event: connected\n";
            echo "data: " . json_encode(['status' => 'connected', 'user_id' => $userId]) . "\n\n";
            flush();

            $currentLastId = $lastId;
            $elapsed       = 0;
            $interval      = 1; // poll DB every 1 second for faster in-app/socket-like delivery
            $maxTime       = 55; // close after 55 seconds, client will reconnect

            while ($elapsed < $maxTime) {
                if (connection_aborted()) break;

                sleep($interval);
                $elapsed += $interval;

                // Fetch new notifications since last sent
                $notifications = \App\Models\Notification::where('user_id', $userId)
                    ->where('id', '>', $currentLastId)
                    ->orderBy('id', 'asc')
                    ->limit(10)
                    ->get();

                foreach ($notifications as $n) {
                    $currentLastId = $n->id;
                    echo "id: {$n->id}\n";
                    echo "event: notification\n";
                    echo "data: " . json_encode([
                        'id'         => $n->id,
                        'type'       => $n->type,
                        'title'      => $n->title,
                        'body'       => $n->body,
                        'data'       => $n->data,
                        'is_read'    => $n->is_read,
                        'created_at' => $n->created_at->toISOString(),
                    ]) . "\n\n";
                    flush();
                }

                // Heartbeat to keep connection alive
                echo ": heartbeat\n\n";
                flush();
            }

            // Signal client to reconnect
            echo "event: reconnect\n";
            echo "data: " . json_encode(['reconnect' => true]) . "\n\n";
            flush();
        };

        return response()->stream($stream, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
