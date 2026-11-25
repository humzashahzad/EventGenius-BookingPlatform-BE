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
 * The `sentiment` ranking signal (AI review analysis) — pure, with injected stats.
 */
class ReviewSentimentRankingTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-25 12:00:00');
        config(['review_ai.min_reviews' => 3]);
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
        $v->created_at = $this->now->copy()->subDays(400);
        $v->setRelation('store', (new Store())->forceFill(['id' => 1, 'name' => 'Test Store', 'city' => 'Lahore']));
        return $v;
    }

    private function rank(array $venues, array $sentiment, array $settings = [], array $intents = []): array
    {
        $ranker = new VenueRanker([
            'rating_mean' => 4.2, 'bookings' => [], 'engagement' => [], 'global_ctr' => null,
            'amenities' => [], 'categories' => [], 'history' => null, 'sentiment' => $sentiment,
        ], $this->now);
        $parsed = ['raw' => '', 'terms' => [], 'city' => null, 'event_type' => null, 'guests' => null, 'max_price' => null,
                   'min_price' => null, 'intents' => $intents, 'amenities' => [], 'chips' => []];
        $settings = array_replace_recursive(SearchSettings::defaults(), $settings);

        return $ranker->rank(collect($venues), $parsed, ['user' => null], $settings)
            ->keyBy(fn($r) => $r['venue']->id)->all();
    }

    private function sent(float $score, int $count, array $aspects = []): array
    {
        return ['score' => $score, 'count' => $count, 'aspects' => $aspects];
    }

    public function test_settings_include_sentiment_signal(): void
    {
        $this->assertContains('sentiment', SearchSettings::SIGNALS);
        $this->assertSame(5, SearchSettings::defaults()['weights']['sentiment']);
        foreach (SearchSettings::presets() as $name => $weights) {
            $this->assertArrayHasKey('sentiment', $weights, $name);
        }
        $this->assertSame(10, SearchSettings::normalize(['weights' => ['sentiment' => 42]])['weights']['sentiment']);
    }

    public function test_signal_mapping_and_exclusion_with_little_text(): void
    {
        $r = $this->rank([$this->venue(1), $this->venue(2), $this->venue(3), $this->venue(4)], [
            1 => $this->sent(0.9, 10),
            2 => $this->sent(0.35, 10),
            3 => $this->sent(0.9, 2),   // too few analysed reviews
        ]);

        $this->assertEqualsWithDelta(1.0, $r[1]['signals']['sentiment'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $r[2]['signals']['sentiment'], 1e-4); // (0.35 + 0.2) / 1.1
        $this->assertArrayNotHasKey('sentiment', $r[3]['signals']);
        $this->assertArrayNotHasKey('sentiment', $r[4]['signals']);              // no analysis at all
        $this->assertArrayHasKey('sentiment', $r[1]['contributions']);
        $this->assertArrayNotHasKey('sentiment', $r[4]['contributions']);
    }

    public function test_happier_guests_rank_higher_all_else_equal(): void
    {
        $r = $this->rank([$this->venue(1), $this->venue(2)], [
            1 => $this->sent(0.1, 12),
            2 => $this->sent(0.8, 12),
        ]);
        $this->assertGreaterThan($r[1]['score'], $r[2]['score']);

        // A strongly negative venue scores 0 on the signal, never below.
        $neg = $this->rank([$this->venue(1)], [1 => $this->sent(-0.9, 12)]);
        $this->assertEquals(0.0, $neg[1]['signals']['sentiment']);
    }

    public function test_weight_zero_disables_the_signal_effect(): void
    {
        $r = $this->rank([$this->venue(1), $this->venue(2)], [
            1 => $this->sent(-0.5, 12),
            2 => $this->sent(0.9, 12),
        ], ['weights' => ['sentiment' => 0]]);
        $this->assertEquals($r[1]['score'], $r[2]['score']);
        $this->assertEquals(0.0, $r[2]['contributions']['sentiment']);
    }

    public function test_top_rated_intent_boosts_sentiment_weight(): void
    {
        // Different prices so price_fit is present as a non-review signal to compare against.
        $venues = fn() => [$this->venue(1, ['price_per_head' => 2000]), $this->venue(2, ['price_per_head' => 4000])];
        $sent = [1 => $this->sent(-0.1, 12), 2 => $this->sent(0.9, 12)];
        $plain = $this->rank($venues(), $sent);
        $top = $this->rank($venues(), $sent, [], ['top_rated']);
        $ratio = fn($r) => $r[2]['contributions']['sentiment'] / $r[2]['contributions']['price_fit'];
        $this->assertEqualsWithDelta(2 * $ratio($plain), $ratio($top), 0.01); // contributions are rounded
    }

    public function test_aspect_reason_is_shown_only_when_earned(): void
    {
        $food = ['food' => ['mentions' => 8, 'positive_share' => 0.9], 'parking' => ['mentions' => 6, 'positive_share' => 0.3]];
        $r = $this->rank([$this->venue(1), $this->venue(2), $this->venue(3), $this->venue(4)], [
            1 => $this->sent(0.7, 10, $food),
            // praised food but guests are negative overall → no praise reason at all
            2 => $this->sent(0.1, 10, $food),
            // only 2 mentions → anecdote, not a pattern
            3 => $this->sent(0.7, 10, ['service' => ['mentions' => 2, 'positive_share' => 1.0]]),
            // mixed parking feedback never becomes "Easy parking"
            4 => $this->sent(0.5, 10, ['parking' => ['mentions' => 9, 'positive_share' => 0.6]]),
        ], ['weights' => ['sentiment' => 10]]);

        $this->assertContains('Guests love the food', $r[1]['reasons']);
        foreach ([2, 3, 4] as $id) {
            $this->assertNotContains('Guests love the food', $r[$id]['reasons']);
            $this->assertNotContains('Praised for service', $r[$id]['reasons']);
            $this->assertNotContains('Easy parking, say guests', $r[$id]['reasons']);
        }
        // Venue 3 is very positive with enough reviews → generic praise instead.
        $this->assertContains('Glowing guest reviews', $r[3]['reasons']);
        $this->assertNotContains('Glowing guest reviews', $r[2]['reasons']);
    }

    public function test_sentiment_reason_picks_the_most_praised_aspect(): void
    {
        $ranker = new VenueRanker([], $this->now);
        $this->assertSame('Praised for service', $ranker->sentimentReason($this->sent(0.6, 20, [
            'food' => ['mentions' => 4, 'positive_share' => 0.75],
            'service' => ['mentions' => 12, 'positive_share' => 0.92],
        ])));
        $this->assertNull($ranker->sentimentReason($this->sent(0.2, 20, [
            'service' => ['mentions' => 12, 'positive_share' => 0.92],
        ])));
        $this->assertNull($ranker->sentimentReason(null));
        $this->assertNull($ranker->sentimentReason($this->sent(0.65, 4))); // too few for "Glowing"
    }

    public function test_reasons_stay_capped_and_signals_bounded(): void
    {
        $r = $this->rank([$this->venue(1, ['is_featured' => true, 'avg_rating' => 4.8, 'total_reviews' => 40])], [
            1 => $this->sent(0.95, 40, ['food' => ['mentions' => 30, 'positive_share' => 1.0]]),
        ]);
        $this->assertLessThanOrEqual(3, count($r[1]['reasons']));
        $this->assertLessThanOrEqual(1, $r[1]['signals']['sentiment']);
        $this->assertLessThanOrEqual(100, $r[1]['score']);
    }
}
