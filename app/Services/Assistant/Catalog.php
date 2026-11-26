<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * Everything the assistant needs to know about venues. The live implementation (DbCatalog) goes
 * through the smart search engine and the review insights; tests use an in-memory one.
 *
 * Compact venue arrays (search results and venue()) carry at least:
 * id, name, city, area, address, thumbnail, price_per_head, capacity_min, capacity_max,
 * avg_rating, total_reviews, match ['score', 'reasons'].
 */
interface Catalog
{
    /**
     * Search with the conversation state (keys: city, event_type, guests, max_price, min_price, amenities, terms,
     * intents, sort, min_capacity, max_capacity, min_rating). `opts`: page, per_page, log (bool), search_id, query.
     *
     * @return array{items: array<int, array>, total: int, search_id: ?string}
     */
    public function search(array $state, ?User $user, array $opts = []): array;

    /** Full public venue: compact fields + description, event_types, operating_start/end, amenities [{name,is_paid,price}]. */
    public function venue(int $id): ?array;

    /** Public review summary in the shape of `review_summary` on GET /venues/{id}. */
    public function reviewSummary(int $id): array;

    /** Pending/confirmed bookings on a date: [venue_id => [['start' => 'HH:MM', 'end' => 'HH:MM'], ...]]. */
    public function bookingsOn(array $venueIds, string $date): array;

    /**
     * Small, cached facts for quick replies and name look-ups:
     * cities [['name', 'count']] (most venues first), categories [['slug', 'name', 'count']],
     * city_by_category [slug => [city => count]], prices ['p25', 'p50', 'p75', 'min', 'max'],
     * max_capacity, venues [['id', 'name', 'city']], places (lowercase area words found in addresses),
     * vocabulary (SearchQueryParser vocabulary).
     */
    public function facts(): array;
}
