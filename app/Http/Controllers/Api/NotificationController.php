<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const CHAT_NOTIFICATION_TYPES = ['chat_message'];
    private const BOOKING_NOTIFICATION_TYPES = [
        'booking_created',
        'booking_confirmed',
        'booking_rejected',
        'booking_cancelled',
        'new_booking_received',
    ];

    /**
     * List notifications for the authenticated user (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $baseQuery = Notification::forUser($request->user()->id);
        $notifications = $this->applyTypeFilters(clone $baseQuery, $request)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $unreadCount = $this->applyTypeFilters(clone $baseQuery, $request)->unread()->count();

        return response()->json([
            'success'      => true,
            'data'         => $notifications,
            'unread_count' => $unreadCount,
            'counts'       => $this->buildCounts(clone $baseQuery),
        ]);
    }

    /**
     * Get unread count only (lightweight poll).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $baseQuery = Notification::forUser($request->user()->id);
        $filteredBaseQuery = $this->applyTypeFilters(clone $baseQuery, $request);
        $count = (clone $filteredBaseQuery)->unread()->count();
        $lastId = (clone $baseQuery)->max('id') ?? 0;

        $latest = (clone $filteredBaseQuery)
            ->unread()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success'      => true,
            'unread_count' => $count,
            'latest'       => $latest,
            'last_id'      => $lastId,
            'counts'       => $this->buildCounts(clone $baseQuery),
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

    private function applyTypeFilters($query, Request $request)
    {
        $types = collect((array) $request->input('types', []))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        $excludeTypes = collect((array) $request->input('exclude_types', []))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        if (!empty($types)) {
            $query->whereIn('type', $types);
        }

        if (!empty($excludeTypes)) {
            $query->whereNotIn('type', $excludeTypes);
        }

        return $query;
    }

    private function buildCounts($baseQuery): array
    {
        return [
            'all' => (clone $baseQuery)->unread()->count(),
            'general' => (clone $baseQuery)
                ->whereNotIn('type', self::CHAT_NOTIFICATION_TYPES)
                ->unread()
                ->count(),
            'chat' => (clone $baseQuery)
                ->whereIn('type', self::CHAT_NOTIFICATION_TYPES)
                ->unread()
                ->count(),
            'booking' => (clone $baseQuery)
                ->whereIn('type', self::BOOKING_NOTIFICATION_TYPES)
                ->unread()
                ->count(),
        ];
    }

}
