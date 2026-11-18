<?php

namespace App\Services;

use App\Models\Review;
use App\Models\Venue;

class VenueRatingService
{
    /**
     * Recalculate venues.avg_rating and venues.total_reviews from approved,
     * non-deleted reviews.
     */
    public static function recalculate(int $venueId): void
    {
        $query = Review::where('venue_id', $venueId)->where('is_approved', true);
        $count = (clone $query)->count();
        $avg   = $count ? round((float) (clone $query)->avg('rating'), 2) : 0;

        Venue::withTrashed()->where('id', $venueId)->update([
            'avg_rating'    => $avg,
            'total_reviews' => $count,
        ]);
    }
}
