<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Models\Venue;
use App\Services\Search\SearchSettings;
use App\Services\Search\VenueRanker;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure ranker tests: in-memory Venue models + injected stats, no database.
 */
class VenueRankerTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-25 12:00:00');
    }

    private function venue(int $id, array $attrs = []): Venue
    {
        $v = new Venue();
        $v->forceFill(array_merge([
            'name' => "Venue $id", 'description' => 'A lovely place.', 'address' => 'Main Boulevard',
            'city' => 'Lahore', 'state' => 'Punjab', 'latitude' => 31.52, 'longitude' => 74.35,
            'capacity_min' => 100, 'capacity_max' => 500, 'pricing_type' => 'per_head', 'price_per_head' => 3000,
            'event_types' => ['weddings'], 'is_featured' => false, 'search_boost' => 0,
            'avg_rating' => 4.2, 'total_reviews' => 20, 'status' => 'active',
        ], $attrs));
        $v->id = $id;
        $v->created_at = $attrs['created_at'] ?? $this->now->copy()->subDays(400);
        $v->setRelation('store', (new Store())->forceFill(['id' => 1, 'name' => 'Test Store', 'city' => 'Lahore']));
        return $v;
    }

    private function parsed(array $p = []): array
    {
        return array_merge([
            'raw' => '', 'terms' => [], 'city' => null, 'event_type' => null, 'guests' => null,
            'max_price' => null, 'min_price' => null, 'intents' => [], 'amenities' => [], 'chips' => [],
        ], $p);
    }

    private function rank(array $venues, array $parsed = [], array $context = [], array $stats = [], array $settings = []): Collection
    {
        $ranker = new VenueRanker(array_merge([
            'rating_mean' => 4.2, 'bookings' => [], 'engagement' => [], 'global_ctr' => null,
            'amenities' => [], 'categories' => ['weddings' => 'Weddings'], 'history' => null,
        ], $stats), $this->now);
        $settings = array_replace_recursive(SearchSettings::defaults(), $settings);

        return $ranker->rank(collect($venues), $this->parsed($parsed), $context + ['user' => null], $settings);
    }

    private function byId(Collection $results): array
    {
        return $results->keyBy(fn($r) => $r['venue']->id)->all();
    }

    public function test_result_shape_and_score_bounds(): void
    {
        $results = $this->rank([$this->venue(1), $this->venue(2, ['is_featured' => true, 'search_boost' => 100])]);

        $this->assertCount(2, $results);
        foreach ($results as $r) {
            $this->assertSame(['venue', 'score', 'signals', 'contributions', 'reasons'], array_keys($r));
            $this->assertGreaterThanOrEqual(0, $r['score']);
            $this->assertLessThanOrEqual(100, $r['score']);
            $this->assertLessThanOrEqual(3, count($r['reasons']));
            foreach ($r['signals'] as $s) {
                $this->assertGreaterThanOrEqual(0, $s);
                $this->assertLessThanOrEqual(1, $s);
            }
        }
        // Featured + max boost goes first.
        $this->assertSame(2, $results[0]['venue']->id);
        $this->assertEquals(5.0, $results[0]['contributions']['featured_boost']);
        $this->assertEquals(20.0, $results[0]['contributions']['search_boost']);
    }

    public function test_signals_without_information_are_left_out(): void
    {
        $r = $this->rank([$this->venue(1), $this->venue(2, ['price_per_head' => 4000])])[0];

        $this->assertArrayNotHasKey('relevance', $r['signals']);   // no terms
        $this->assertArrayNotHasKey('capacity_fit', $r['signals']); // no guests
        $this->assertArrayNotHasKey('distance', $r['signals']);     // no lat/lng/city
        $this->assertArrayNotHasKey('popularity', $r['signals']);   // no bookings at all
        $this->assertArrayNotHasKey('engagement', $r['signals']);   // no traffic
        $this->assertArrayNotHasKey('freshness', $r['signals']);    // nothing new
        $this->assertArrayNotHasKey('personal', $r['signals']);     // anonymous
        $this->assertArrayHasKey('quality', $r['signals']);
        $this->assertArrayHasKey('price_fit', $r['signals']);
    }

    public function test_bayesian_rating_beats_tiny_sample_and_no_fake_top_rated(): void
    {
        $results = $this->byId($this->rank([
            $this->venue(1, ['avg_rating' => 5.0, 'total_reviews' => 1]),
            $this->venue(2, ['avg_rating' => 4.6, 'total_reviews' => 28]),
            $this->venue(3, ['avg_rating' => 0, 'total_reviews' => 0]),
        ]));

        $this->assertGreaterThan($results[1]['signals']['quality'], $results[2]['signals']['quality']);
        // Zero reviews sits at the prior mean (4.2 → 0.6 on the 3..5 window), not at 0.
        $this->assertEqualsWithDelta(0.6, $results[3]['signals']['quality'], 0.001);
        $this->assertContains('Top rated in Lahore', $results[2]['reasons']);
        foreach ([1, 3] as $id) {
            foreach ($results[$id]['reasons'] as $reason) {
                $this->assertStringNotContainsString('Top rated', $reason);
                $this->assertStringNotContainsString('★', $reason);
            }
        }
    }

    public function test_budget_and_guests_fit(): void
    {
        $results = $this->byId($this->rank([
            $this->venue(1, ['price_per_head' => 4500, 'capacity_min' => 100, 'capacity_max' => 400]),
            $this->venue(2, ['price_per_head' => 5600, 'capacity_min' => 100, 'capacity_max' => 400]), // 12 % over
            $this->venue(3, ['price_per_head' => 4500, 'capacity_min' => 50, 'capacity_max' => 3000]), // far oversized
        ], ['guests' => 300, 'max_price' => 5000], ['guests' => 300, 'max_price' => 5000]));

        $this->assertGreaterThan(0.85, $results[1]['signals']['price_fit']);
        $this->assertEqualsWithDelta(0.1, $results[2]['signals']['price_fit'], 0.001); // 0.5 → 0 across the +15 % tolerance
        $this->assertEquals(1.0, $results[1]['signals']['capacity_fit']);
        $this->assertLessThan(1.0, $results[3]['signals']['capacity_fit']);
        $this->assertContains('Within your budget', $results[1]['reasons']);
        $this->assertContains('Fits 300 guests', $results[1]['reasons']);
        $this->assertNotContains('Within your budget', $results[2]['reasons']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);
        $this->assertGreaterThan($results[3]['score'], $results[1]['score']);
    }

    public function test_cheap_and_luxury_intents(): void
    {
        $venues = [$this->venue(1, ['price_per_head' => 1500]), $this->venue(2, ['price_per_head' => 6000])];

        $cheap = $this->rank($venues, ['intents' => ['cheap']]);
        $this->assertSame(1, $cheap[0]['venue']->id);

        $lux = $this->rank($venues, ['intents' => ['luxury']]);
        $this->assertSame(2, $lux[0]['venue']->id);
    }

    public function test_top_rated_intent_doubles_quality_weight(): void
    {
        $venues = [$this->venue(1, ['avg_rating' => 4.8, 'total_reviews' => 40]), $this->venue(2, ['price_per_head' => 2000])];
        $plain = $this->byId($this->rank($venues));
        $top = $this->byId($this->rank($venues, ['intents' => ['top_rated']]));

        $this->assertGreaterThan($plain[1]['contributions']['quality'], $top[1]['contributions']['quality']);
        $this->assertGreaterThan($plain[1]['score'] - $plain[2]['score'], $top[1]['score'] - $top[2]['score']);
    }

    public function test_distance_decay_and_reason(): void
    {
        $results = $this->byId($this->rank([
            $this->venue(1, ['latitude' => 31.5204, 'longitude' => 74.3587]),
            $this->venue(2, ['latitude' => 31.4241, 'longitude' => 73.0948]), // Faisalabad, ~120 km
        ], [], ['lat' => 31.52, 'lng' => 74.35]));

        $this->assertGreaterThan(0.9, $results[1]['signals']['distance']);
        $this->assertLessThan(0.01, $results[2]['signals']['distance']);
        $this->assertContains('Under 1 km away', $results[1]['reasons']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);

        $ranker = new VenueRanker([], $this->now);
        $this->assertEqualsWithDelta(1031, $ranker->haversineKm(31.5204, 74.3587, 24.8607, 67.0011), 15); // Lahore–Karachi
    }

    public function test_relevance_matches_fields_and_labels(): void
    {
        $results = $this->byId($this->rank([
            $this->venue(1, ['name' => 'Clifton Rooftop Lounge']),
            $this->venue(2, ['name' => 'Plain Hall']),
            $this->venue(3, ['description' => 'Open air rooftop seating.']),
        ], ['terms' => ['rooftop']]));

        $this->assertEquals(1.0, $results[1]['signals']['relevance']);
        $this->assertEquals(0.0, $results[2]['signals']['relevance']);
        $this->assertEquals(0.45, $results[3]['signals']['relevance']);
        $this->assertContains('Matches “rooftop”', $results[1]['reasons']);
        $this->assertNotContains('Matches “rooftop”', $results[3]['reasons']); // weak description-only match
    }

    public function test_venue_type_words_match_partially(): void
    {
        $results = $this->byId($this->rank([
            $this->venue(1, ['name' => 'Royal Crystal Hall']),
            $this->venue(2, ['name' => 'Shalimar Grand Ballroom']),
            $this->venue(3, ['name' => 'Chapli House']),
        ], ['terms' => ['hall']]));

        $this->assertEquals(1.0, $results[1]['signals']['relevance']);
        $this->assertEquals(0.6, $results[2]['signals']['relevance']); // kept, ranked lower
        $this->assertEquals(0.0, $results[3]['signals']['relevance']);
        $this->assertNotContains('Matches “hall”', $results[2]['reasons']);
    }

    public function test_amenity_relevance(): void
    {
        $results = $this->byId($this->rank(
            [$this->venue(1), $this->venue(2)],
            ['amenities' => ['Valet Parking']],
            [],
            ['amenities' => [1 => ['Valet Parking', 'Wi-Fi'], 2 => ['Wi-Fi']]]
        ));
        $this->assertEquals(1.0, $results[1]['signals']['relevance']);
        $this->assertEquals(0.0, $results[2]['signals']['relevance']);
        $this->assertContains('Has Valet Parking', $results[1]['reasons']);
    }

    public function test_popularity_and_reliability(): void
    {
        $results = $this->byId($this->rank([$this->venue(1), $this->venue(2), $this->venue(3)], [], [], [
            'bookings' => [
                1 => ['recent' => 40, 'accepted' => 60, 'rejected' => 1, 'cancelled' => 2],
                2 => ['recent' => 3, 'accepted' => 5, 'rejected' => 6, 'cancelled' => 5],
            ],
        ]));

        $this->assertEquals(1.0, $results[1]['signals']['popularity']);
        $this->assertLessThan(0.5, $results[2]['signals']['popularity']);
        $this->assertEquals(0.0, $results[3]['signals']['popularity']);
        $this->assertGreaterThan($results[2]['signals']['reliability'], $results[1]['signals']['reliability']);
        $this->assertArrayNotHasKey('reliability', $results[3]['signals']);
        $this->assertContains('Popular — 40 bookings recently', $results[1]['reasons']);
    }

    public function test_engagement_uses_smoothed_ctr_and_respects_learning_switch(): void
    {
        $stats = [
            'global_ctr' => 0.05,
            'engagement' => [1 => ['impressions' => 200, 'clicks' => 40], 2 => ['impressions' => 200, 'clicks' => 2], 3 => ['impressions' => 1, 'clicks' => 1]],
        ];
        $results = $this->byId($this->rank([$this->venue(1), $this->venue(2), $this->venue(3)], [], [], $stats));

        $this->assertGreaterThan(0.7, $results[1]['signals']['engagement']);
        $this->assertLessThan(0.5, $results[2]['signals']['engagement']);
        // One lucky click on one impression is pulled hard toward average.
        $this->assertLessThan($results[1]['signals']['engagement'], $results[3]['signals']['engagement']);

        $off = $this->rank([$this->venue(1)], [], [], $stats, ['learning' => false])[0];
        $this->assertArrayNotHasKey('engagement', $off['signals']);
    }

    public function test_freshness_and_new_intent(): void
    {
        $venues = [$this->venue(1, ['created_at' => $this->now->copy()->subDays(5)]), $this->venue(2, ['avg_rating' => 4.5])];
        $results = $this->byId($this->rank($venues, ['intents' => ['new']]));

        $this->assertGreaterThan(0.9, $results[1]['signals']['freshness']);
        $this->assertEquals(0.0, $results[2]['signals']['freshness']);
        $this->assertContains('New on EventGenius', $results[1]['reasons']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);

        $off = $this->byId($this->rank($venues, [], [], [], ['exploration' => false]));
        $this->assertArrayNotHasKey('freshness', $off[1]['signals']);
    }

    public function test_personal_affinity(): void
    {
        $user = new \App\Models\User();
        $user->id = 99;
        $history = ['count' => 4, 'cities' => ['Lahore' => 3, 'Karachi' => 1], 'event_types' => ['weddings' => 4], 'median_price' => 3000];
        $venues = [
            $this->venue(1),
            $this->venue(2, ['city' => 'Multan', 'event_types' => ['conferences'], 'price_per_head' => 9000]),
        ];

        $results = $this->byId($this->rank($venues, [], ['user' => $user], ['history' => $history]));
        $this->assertEquals(1.0, $results[1]['signals']['personal']);
        $this->assertEquals(0.0, $results[2]['signals']['personal']);
        $this->assertContains('Like venues you booked before', $results[1]['reasons']);

        $anon = $this->byId($this->rank($venues, [], [], ['history' => $history]));
        $this->assertArrayNotHasKey('personal', $anon[1]['signals']);

        $off = $this->byId($this->rank($venues, [], ['user' => $user], ['history' => $history], ['personalization' => false]));
        $this->assertArrayNotHasKey('personal', $off[1]['signals']);
    }

    public function test_score_formula(): void
    {
        // Single venue, only quality + city distance present: score = 100 * (7*q + 5*1) / 12.
        $r = $this->rank([$this->venue(1, ['avg_rating' => 4.2, 'total_reviews' => 10])], [], ['city' => 'Lahore'])[0];
        $q = $r['signals']['quality'];
        $this->assertEqualsWithDelta(100 * (7 * $q + 5 * 1.0) / 12, $r['score'], 0.1);
        $this->assertEqualsWithDelta($r['score'], array_sum($r['contributions']), 0.1);
    }

    public function test_settings_normalize_clamps(): void
    {
        $s = SearchSettings::normalize([
            'weights' => ['quality' => 99, 'relevance' => -3, 'bogus' => 5],
            'featured_boost' => 50, 'learning' => 'false', 'distance_half_km' => 0,
        ]);
        $this->assertSame(10, $s['weights']['quality']);
        $this->assertSame(0, $s['weights']['relevance']);
        $this->assertArrayNotHasKey('bogus', $s['weights']);
        $this->assertSame(20, $s['featured_boost']);
        $this->assertFalse($s['learning']);
        $this->assertSame(1, $s['distance_half_km']);
        $this->assertSame(SearchSettings::SIGNALS, array_keys(SearchSettings::presets()['budget']));
    }
}
