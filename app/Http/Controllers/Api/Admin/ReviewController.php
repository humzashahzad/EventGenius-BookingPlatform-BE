<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\VenueRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** GET /admin/reviews */
    public function index(Request $request): JsonResponse
    {
        $query = Review::with(['client:id,name,email,avatar', 'venue:id,name,store_id', 'venue.store:id,name']);

        if ($request->filled('approved')) {
            $query->where('is_approved', $request->boolean('approved'));
        }
        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->venue_id);
        }
        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', $s)
                  ->orWhere('comment', 'like', $s)
                  ->orWhereHas('venue', fn ($v) => $v->where('name', 'like', $s))
                  ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $s));
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->paginate(min((int) $request->get('per_page', 15), 100)),
        ]);
    }

    /** PATCH /admin/reviews/{id}/toggle-approval */
    public function toggleApproval(string $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => !$review->is_approved]);
        VenueRatingService::recalculate($review->venue_id);

        return response()->json([
            'success' => true,
            'data'    => $review,
            'message' => $review->is_approved ? 'Review approved.' : 'Review hidden.',
        ]);
    }

    /** DELETE /admin/reviews/{id} (soft delete) */
    public function destroy(string $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $venueId = $review->venue_id;
        $review->delete();
        VenueRatingService::recalculate($venueId);

        return response()->json(['success' => true, 'message' => 'Review deleted.']);
    }
}
