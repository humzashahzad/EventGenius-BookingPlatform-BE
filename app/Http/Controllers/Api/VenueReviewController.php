<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueReviewController extends Controller
{
    /** GET /venues/{id}/reviews — public, approved reviews only, paginated. */
    public function index(Request $request, string $id): JsonResponse
    {
        $venue = Venue::where('status', 'active')->findOrFail($id);

        $reviews = Review::with('client:id,name,avatar')
            ->where('venue_id', $venue->id)
            ->where('is_approved', true)
            ->latest()
            ->paginate(min((int) $request->get('per_page', 10), 50));

        return response()->json([
            'success' => true,
            'data'    => $reviews,
            'summary' => [
                'avg_rating'    => (float) $venue->avg_rating,
                'total_reviews' => (int) $venue->total_reviews,
            ],
        ]);
    }
}
