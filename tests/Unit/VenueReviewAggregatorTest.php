<?php

namespace Tests\Unit;

use App\Services\Reviews\ReviewAnalyzer;
use App\Services\Reviews\ReviewInsightReport;
use App\Services\Reviews\VenueReviewAggregator;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Pure tests of the venue review aggregate (no database) and the analyser's graceful failure.
 */
class VenueReviewAggregatorTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-25 12:00:00');
        config(['review_ai.half_life_days' => 90, 'review_ai.prior_reviews' => 2]);
    }

    private function review(float $sentiment, int $daysAgo = 0, array $flags = [], array $aspects = [], array $phrases = []): array
    {
        return [
            'created_at' => $this->now->copy()->subDays($daysAgo)->toDateTimeString(),
            'sentiment' => $sentiment,
            'label' => $sentiment > 0.25 ? 'positive' : ($sentiment < -0.25 ? 'negative' : 'neutral'),
            'flags' => json_encode($flags),
            'aspects' => json_encode((object) $aspects),
            'phrases' => json_encode(['positive' => $phrases['positive'] ?? [], 'negative' => $phrases['negative'] ?? []]),
        ];
    }

    public function test_spam_and_duplicates_are_excluded_and_short_reviews_do_not_move_sentiment(): void
    {
        $agg = (new VenueReviewAggregator($this->now))->aggregate([
            $this->review(0.8),
            $this->review(-0.9, 0, ['spam']),
            $this->review(-0.9, 0, ['possible_duplicate']),
            $this->review(-0.9, 0, ['very_short']),
        ]);

        $this->assertSame(2, $agg['analysed_count']);   // the 0.8 review and the very short one
        $this->assertSame(1, $agg['text_count']);
        $this->assertSame(1, $agg['positive_count']);
        $this->assertSame(1, $agg['negative_count']);
        $this->assertEqualsWithDelta(0.8, $agg['weighted_sentiment'] / $agg['weight_sum'], 1e-6);
    }

    public function test_recent_reviews_weigh_more(): void
    {
        $agg = (new VenueReviewAggregator($this->now))->aggregate([
            $this->review(-0.8, 360), // a year ago: weight 1/16
            $this->review(0.8, 0),
        ]);
        $mean = $agg['weighted_sentiment'] / $agg['weight_sum'];
        $this->assertEqualsWithDelta(0.0625 + 1, $agg['weight_sum'], 1e-4);
        $this->assertGreaterThan(0.6, $mean);
    }

    public function test_bayesian_smoothing_pulls_small_samples_to_the_global_mean(): void
    {
        $one = VenueReviewAggregator::smooth(1.0, 1.0, 0.5, 2);     // a single perfect review
        $many = VenueReviewAggregator::smooth(40.0, 40.0, 0.5, 2);  // forty of them
        $this->assertEqualsWithDelta(2 / 3, $one, 1e-6);
        $this->assertGreaterThan($one, $many);
        $this->assertEqualsWithDelta(0.5, VenueReviewAggregator::smooth(0, 0, 0.5, 2), 1e-9);
    }

    public function test_aspects_and_phrases(): void
    {
        $agg = (new VenueReviewAggregator($this->now))->aggregate([
            $this->review(0.7, 1, [], ['food' => ['sentiment' => 0.9, 'mentions' => 1], 'parking' => ['sentiment' => -0.6, 'mentions' => 1]],
                ['positive' => ['The food was delicious'], 'negative' => ['Parking got crowded']]),
            $this->review(0.6, 2, [], ['food' => ['sentiment' => 0.8, 'mentions' => 2]], ['positive' => ['the food was delicious!']]),
            $this->review(0.5, 3, [], ['food' => ['sentiment' => 0.1, 'mentions' => 1], 'bogus' => ['sentiment' => 1, 'mentions' => 1]],
                ['positive' => ['Staff were polite']]),
            // Old complaint (2 years): not shown as a current concern.
            $this->review(-0.7, 730, [], [], ['negative' => ['Washrooms were dirty']]),
        ]);
        $aspects = json_decode($agg['aspects'], true);

        $this->assertSame(['mentions' => 3, 'positive' => 2, 'negative' => 0], array_intersect_key($aspects['food'], array_flip(['mentions', 'positive', 'negative'])));
        $this->assertSame(1, $aspects['parking']['negative']);
        $this->assertArrayNotHasKey('bogus', $aspects);
        $this->assertSame(['The food was delicious', 'Staff were polite'], json_decode($agg['highlights'], true));
        $this->assertSame(['Parking got crowded'], json_decode($agg['concerns'], true));
    }

    public function test_public_summary_shape(): void
    {
        $agg = new VenueReviewAggregator($this->now);
        $row = (object) ($agg->aggregate([
            $this->review(0.8, 1, [], ['food' => ['sentiment' => 0.9, 'mentions' => 1], 'parking' => ['sentiment' => -0.5, 'mentions' => 1]]),
            $this->review(0.6, 2, [], ['food' => ['sentiment' => 0.7, 'mentions' => 1]]),
        ]) + ['computed_at' => $this->now->toDateTimeString()]);

        $s = $agg->summary(1, $row, 0.5);
        $this->assertSame(2, $s['analysed_count']);
        $this->assertGreaterThan(50, $s['sentiment']);
        $this->assertLessThanOrEqual(100, $s['sentiment']);
        $this->assertSame('positive', $s['label']);
        // Parking has a single mention → not shown; food has 2.
        $this->assertSame(['food'], array_column($s['aspects'], 'name'));
        $this->assertSame(100, $s['aspects'][0]['positive_pct']);
        $this->assertSame('Food', $s['aspects'][0]['label']);

        $empty = $agg->summary(1, (object) ['analysed_count' => 0]);
        $this->assertSame(0, $empty['analysed_count']);
        $this->assertNull($empty['sentiment']);
    }

    public function test_flag_reasons_are_human_and_rating_aware(): void
    {
        $this->assertSame(['Rated 5★ but the text reads negative', 'Same text as an earlier review'],
            ReviewInsightReport::reasons(['rating_text_mismatch', 'possible_duplicate'], 5, -0.7));
        $this->assertSame(['Rated 1★ but the text reads positive'], ReviewInsightReport::reasons(['rating_text_mismatch'], 1, 0.8));
    }

    public function test_missing_analyser_is_skipped_with_a_warning_not_an_exception(): void
    {
        config(['logging.default' => 'null']);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            $warnings[] = $e->level;
        });
        $analyzer = new ReviewAnalyzer(['enabled' => true, 'python' => '/nonexistent/python', 'script' => '/nonexistent/analyze.py']);

        $this->assertFalse($analyzer->available());
        $this->assertStringContainsString('python not found', (string) $analyzer->missing());
        $this->assertNull($analyzer->analyze([['id' => 1, 'title' => 'x', 'comment' => 'Great food', 'rating' => 5]]));
        $this->assertSame([], $analyzer->analyze([]));
        $this->assertSame(['warning'], $warnings);

        $off = new ReviewAnalyzer(['enabled' => false]);
        $this->assertStringContainsString('disabled', (string) $off->missing());
    }
}
