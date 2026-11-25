<?php

namespace App\Services;

use App\Models\Venue;
use App\Models\VenueAmenity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Booking price calculation: base (price_per_head x guests) + selected paid
 * amenities, with 5% tax applied to the sum of both.
 */
class BookingPricing
{
    public const TAX_RATE = 0.05;

    /**
     * Resolve the requested amenity ids against the venue. Every id must be an
     * existing (non-deleted) amenity of this venue, otherwise a 422 is thrown.
     *
     * @return Collection<int, VenueAmenity>
     */
    public static function resolveAmenities(Venue $venue, array $amenityIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $amenityIds)));
        if (!$ids) {
            return collect();
        }

        $amenities = VenueAmenity::where('venue_id', $venue->id)->whereIn('id', $ids)->get();

        if ($amenities->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'amenity_ids' => ['One or more selected amenities do not belong to this venue.'],
            ]);
        }

        return $amenities;
    }

    /**
     * @return array{base_price: float, amenities_price: float, tax_amount: float, total_amount: float}
     */
    public static function calculate(Venue $venue, int $guests, Collection $amenities): array
    {
        $base = round(((float) ($venue->price_per_head ?? 0)) * $guests, 2);
        $amenitiesPrice = round((float) $amenities->where('is_paid', true)->sum(fn ($a) => (float) ($a->price ?? 0)), 2);
        $tax = round(($base + $amenitiesPrice) * self::TAX_RATE, 2);

        return [
            'base_price'      => $base,
            'amenities_price' => $amenitiesPrice,
            'tax_amount'      => $tax,
            'total_amount'    => round($base + $amenitiesPrice + $tax, 2),
        ];
    }
}
