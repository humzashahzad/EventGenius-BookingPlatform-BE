<?php

namespace App\Services\Reviews;

use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs reviews through the offline analyser, stores `review_insights`, adds the cross-review
 * `possible_duplicate` flag, and refreshes the affected venue aggregates.
 */
class ReviewInsightService
{
    /** Copies shorter than this (normalised) are not treated as duplicates ("Great!", "Nice"). */
    private const DUPLICATE_MIN_LENGTH = 20;

    public function __construct(
        private ?ReviewAnalyzer $analyzer = null,
        private ?VenueReviewAggregator $aggregator = null,
    ) {
        $this->analyzer ??= new ReviewAnalyzer();
        $this->aggregator ??= new VenueReviewAggregator();
    }

    public function analyzer(): ReviewAnalyzer
    {
        return $this->analyzer;
    }

    /** Reviews (not soft-deleted) that have no insight yet. */
    public static function pendingQuery(): Builder
    {
        return Review::query()->whereDoesntHave('insight');
    }

    /**
     * Analyse the given reviews (models or ids). Returns ['analysed' => n, 'venues' => n, 'skipped' => bool].
     * Never throws: on analyser problems it logs a warning and reports skipped = true.
     */
    public function analyze(iterable $reviews): array
    {
        $reviews = collect($reviews)->map(fn($r) => $r instanceof Review ? $r : Review::find($r))->filter()->values();
        if ($reviews->isEmpty()) {
            return ['analysed' => 0, 'venues' => 0, 'skipped' => false];
        }

        $analysed = 0;
        $venueIds = [];
        $skipped = false;
        $batch = max(1, (int) config('review_ai.batch_size', 50));

        foreach ($reviews->chunk($batch) as $chunk) {
            $results = $this->analyzer->analyze($chunk->map(fn(Review $r) => [
                'id' => $r->id, 'title' => $r->title, 'comment' => $r->comment, 'rating' => $r->rating,
            ])->all());
            if ($results === null) {
                $skipped = true;
                break;
            }
            $duplicates = $this->duplicateIds($chunk);

            $now = now();
            $rows = [];
            foreach ($chunk as $review) {
                $res = $results[$review->id] ?? null;
                if (!is_array($res)) {
                    continue;
                }
                $flags = array_values(array_unique(array_filter(array_map('strval', (array) ($res['flags'] ?? [])))));
                if (isset($duplicates[$review->id])) {
                    $flags[] = 'possible_duplicate';
                }
                $sentiment = max(-1.0, min(1.0, (float) ($res['sentiment'] ?? 0)));
                $rows[] = [
                    'review_id' => $review->id,
                    'sentiment' => round($sentiment, 4),
                    'label' => in_array($res['label'] ?? null, ['positive', 'neutral', 'negative'], true) ? $res['label'] : 'neutral',
                    'confidence' => round(max(0, min(1, (float) ($res['confidence'] ?? 0))), 3),
                    'language' => mb_substr((string) ($res['language'] ?? ''), 0, 10) ?: null,
                    'aspects' => json_encode((object) ($res['aspects'] ?? []), JSON_UNESCAPED_UNICODE),
                    'flags' => json_encode(array_values(array_unique($flags))),
                    'phrases' => json_encode($res['phrases'] ?? ['positive' => [], 'negative' => []], JSON_UNESCAPED_UNICODE),
                    'model_version' => mb_substr((string) ($res['model_version'] ?? ''), 0, 60) ?: null,
                    'analyzed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $venueIds[$review->venue_id] = true;
            }
            if ($rows) {
                DB::table('review_insights')->upsert($rows, ['review_id'], [
                    'sentiment', 'label', 'confidence', 'language', 'aspects', 'flags', 'phrases', 'model_version', 'analyzed_at', 'updated_at',
                ]);
                $analysed += count($rows);
            }
        }

        $venues = 0;
        if ($venueIds) {
            try {
                $venues = $this->aggregator->recompute(array_keys($venueIds));
            } catch (Throwable $e) {
                Log::warning('Venue review aggregate refresh failed: ' . $e->getMessage());
            }
        }

        return ['analysed' => $analysed, 'venues' => $venues, 'skipped' => $skipped];
    }

    /** Refresh one venue's aggregate (after moderation changes); never throws. */
    public function refreshVenue(int $venueId): void
    {
        try {
            $this->aggregator->recompute([$venueId]);
        } catch (Throwable $e) {
            Log::warning("Venue review aggregate refresh failed for venue {$venueId}: " . $e->getMessage());
        }
    }

    /**
     * Reviews in $chunk whose text repeats an *earlier* review: same client on the same venue,
     * same client within 7 days (copy-paste across venues), or anyone on the same venue within 48 hours.
     * Returns [review_id => true].
     */
    public function duplicateIds(Collection $chunk): array
    {
        $norm = fn(?string $t) => trim(preg_replace('/\s+/u', ' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', '', (string) $t) ?? '')) ?? '');
        $comments = $chunk->pluck('comment')->filter(fn($c) => mb_strlen($norm($c)) >= self::DUPLICATE_MIN_LENGTH)->unique()->values()->all();
        if ($comments === []) {
            return [];
        }
        $others = DB::table('reviews')->whereIn('comment', $comments)
            ->get(['id', 'venue_id', 'client_id', 'comment', 'created_at'])
            ->groupBy(fn($r) => $norm($r->comment));

        $dups = [];
        foreach ($chunk as $review) {
            $key = $norm($review->comment);
            if (mb_strlen($key) < self::DUPLICATE_MIN_LENGTH) {
                continue;
            }
            $at = $review->created_at ? $review->created_at->getTimestamp() : time();
            foreach ($others[$key] ?? [] as $o) {
                if ((int) $o->id >= (int) $review->id) {
                    continue;
                }
                $hours = abs($at - strtotime((string) $o->created_at)) / 3600;
                $sameClient = (int) $o->client_id === (int) $review->client_id;
                $sameVenue = (int) $o->venue_id === (int) $review->venue_id;
                if (($sameClient && $sameVenue) || ($sameClient && $hours <= 24 * 7) || ($sameVenue && $hours <= 48)) {
                    $dups[$review->id] = true;
                    break;
                }
            }
        }
        return $dups;
    }
}
