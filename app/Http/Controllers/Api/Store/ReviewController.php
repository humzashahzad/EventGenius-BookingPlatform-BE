<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** GET /store/reviews — reviews on the authenticated owner's venues. */
    public function index(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $query = Review::with(['client:id,name,avatar', 'venue:id,name', 'booking:id,booking_number,event_date'])
            ->whereHas('venue', fn ($q) => $q->where('store_id', $store->id));

        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->venue_id);
        }
        if ($request->get('replied') === 'no') {
            $query->whereNull('store_reply');
        } elseif ($request->get('replied') === 'yes') {
            $query->whereNotNull('store_reply');
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->paginate(min((int) $request->get('per_page', 10), 50)),
        ]);
    }

    /** POST /store/reviews/{id}/reply — set or update the owner's public reply. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $review = Review::with('venue:id,name,store_id')
            ->whereHas('venue', fn ($q) => $q->where('store_id', $store->id))
            ->findOrFail($id);

        $validated = $request->validate(['reply' => 'required|string|max:2000']);

        $review->update([
            'store_reply'      => $validated['reply'],
            'store_replied_at' => now(),
        ]);

        NotificationService::notifyClient(
            $review->client_id,
            'review_reply',
            'The venue replied to your review',
            "{$store->name} replied to your review of \"{$review->venue->name}\".",
            ['review_id' => $review->id, 'venue_id' => $review->venue_id]
        );

        return response()->json([
            'success' => true,
            'data'    => $review->load('client:id,name,avatar'),
            'message' => 'Reply saved.',
        ]);
    }
}
