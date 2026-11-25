<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeReviews;
use App\Models\Booking;
use App\Models\Review;
use App\Services\NotificationService;
use App\Services\VenueRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * A booking can be reviewed once it is completed, or confirmed with an
     * event date that has already passed.
     */
    public static function isEligible(Booking $booking): bool
    {
        if ($booking->status === 'completed') {
            return true;
        }

        return $booking->status === 'confirmed'
            && $booking->event_date
            && $booking->event_date->lt(today());
    }

    /** GET /client/bookings/{id}/review — eligibility + existing review. */
    public function show(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('client_id', $request->user()->id)->findOrFail($id);
        $review  = Review::where('booking_id', $booking->id)->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'eligible'   => !$review && self::isEligible($booking),
                'has_review' => (bool) $review,
                'review'     => $review,
            ],
        ]);
    }

    /** POST /client/bookings/{id}/review */
    public function store(Request $request, string $id): JsonResponse
    {
        $booking = Booking::with('venue.store')
            ->where('client_id', $request->user()->id)
            ->findOrFail($id);

        if (!self::isEligible($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review a booking after the event has taken place.',
            ], 422);
        }

        if (Review::withTrashed()->where('booking_id', $booking->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this booking.',
            ], 422);
        }

        $validated = $request->validate([
            'rating'             => 'required|integer|between:1,5',
            'title'              => 'nullable|string|max:255',
            'comment'            => 'nullable|string|max:5000',
            'cleanliness_rating' => 'nullable|integer|between:1,5',
            'value_rating'       => 'nullable|integer|between:1,5',
            'service_rating'     => 'nullable|integer|between:1,5',
            'location_rating'    => 'nullable|integer|between:1,5',
        ]);

        // Reviews from verified bookings are published immediately; admins can
        // hide them via the moderation endpoint.
        $review = Review::create([
            ...$validated,
            'venue_id'    => $booking->venue_id,
            'booking_id'  => $booking->id,
            'client_id'   => $request->user()->id,
            'is_approved' => true,
        ]);

        VenueRatingService::recalculate($booking->venue_id);

        // Sentiment/aspect analysis runs in the background; it never blocks or fails the submission.
        AnalyzeReviews::dispatchSafely([$review->id]);

        $ownerId = $booking->venue?->store?->user_id;
        if ($ownerId) {
            $venueName = $booking->venue->name;
            NotificationService::notifyStoreOwner(
                $ownerId,
                'new_review',
                'New Review Received',
                "{$request->user()->name} rated \"{$venueName}\" {$review->rating}/5.",
                ['review_id' => $review->id, 'venue_id' => $booking->venue_id, 'booking_id' => $booking->id, 'venue_name' => $venueName]
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $review->load('client:id,name,avatar'),
            'message' => 'Thank you for your review!',
        ], 201);
    }
}
