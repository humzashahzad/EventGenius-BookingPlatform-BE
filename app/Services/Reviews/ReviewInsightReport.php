<?php

namespace App\Services\Reviews;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read models for the store-owner and admin review-insight dashboards. */
class ReviewInsightReport
{
    public const FLAG_REASONS = [
        'spam' => 'Contains a link, phone number, e-mail or promotional text',
        'possible_duplicate' => 'Same text as an earlier review',
        'all_caps' => 'Written in capital letters (shouting)',
        'very_short' => 'Very short — too little text to analyse',
        'rating_text_mismatch' => 'Star rating and review text disagree',
    ];

    public function __construct(private ?VenueReviewAggregator $aggregator = null)
    {
        $this->aggregator ??= new VenueReviewAggregator();
    }

    /** reviews ⨝ review_insights limited to what counts publicly (approved, not deleted, not spam/duplicate). */
    public static function countedQuery(): Builder
    {
        return DB::table('reviews')
            ->join('review_insights', 'review_insights.review_id', '=', 'reviews.id')
            ->where('reviews.is_approved', true)
            ->whereNull('reviews.deleted_at')
            ->whereRaw("NOT JSON_CONTAINS(COALESCE(review_insights.flags, JSON_ARRAY()), JSON_QUOTE('spam'))")
            ->whereRaw("NOT JSON_CONTAINS(COALESCE(review_insights.flags, JSON_ARRAY()), JSON_QUOTE('possible_duplicate'))");
    }

    /** Human reasons for an insight's flags (rating-aware for the mismatch flag). */
    public static function reasons(array $flags, ?int $rating, ?float $sentiment): array
    {
        $out = [];
        foreach ($flags as $flag) {
            if ($flag === 'rating_text_mismatch' && $rating) {
                $out[] = $rating >= 4
                    ? "Rated {$rating}★ but the text reads negative"
                    : "Rated {$rating}★ but the text reads positive";
                continue;
            }
            $out[] = self::FLAG_REASONS[$flag] ?? $flag;
        }
        return $out;
    }

    /** Monthly trend per venue: [venue_id => [[month, reviews, sentiment 0..100, positive, negative, avg_rating], ...]]. */
    public function monthlyTrend(array $venueIds, int $months = 12): array
    {
        if ($venueIds === []) {
            return [];
        }
        $from = Carbon::now()->startOfMonth()->subMonths(max(1, $months) - 1);
        $rows = self::countedQuery()
            ->whereIn('reviews.venue_id', $venueIds)
            ->where('reviews.created_at', '>=', $from)
            ->groupBy('reviews.venue_id', DB::raw("DATE_FORMAT(reviews.created_at, '%Y-%m')"))
            ->orderBy(DB::raw("DATE_FORMAT(reviews.created_at, '%Y-%m')"))
            ->selectRaw("reviews.venue_id, DATE_FORMAT(reviews.created_at, '%Y-%m') AS month, COUNT(*) AS reviews,
                AVG(review_insights.sentiment) AS sentiment, AVG(reviews.rating) AS avg_rating,
                SUM(review_insights.label = 'positive') AS positive, SUM(review_insights.label = 'negative') AS negative")
            ->get();

        $out = array_fill_keys($venueIds, []);
        foreach ($rows as $r) {
            $out[(int) $r->venue_id][] = [
                'month' => $r->month,
                'reviews' => (int) $r->reviews,
                'sentiment' => (int) round(50 * ((float) $r->sentiment + 1)),
                'positive' => (int) $r->positive,
                'negative' => (int) $r->negative,
                'avg_rating' => round((float) $r->avg_rating, 2),
            ];
        }
        return $out;
    }

    /** Recent negative (by text or by stars) approved reviews without an owner reply. */
    public function needsReply(array $venueIds, int $days = 90, int $perVenue = 5): array
    {
        if ($venueIds === []) {
            return [];
        }
        $rows = DB::table('reviews')
            ->join('review_insights', 'review_insights.review_id', '=', 'reviews.id')
            ->leftJoin('users', 'users.id', '=', 'reviews.client_id')
            ->whereIn('reviews.venue_id', $venueIds)
            ->where('reviews.is_approved', true)
            ->whereNull('reviews.deleted_at')
            ->whereNull('reviews.store_reply')
            ->where('reviews.created_at', '>=', Carbon::now()->subDays($days))
            ->where(fn($q) => $q->where('review_insights.label', 'negative')->orWhere('reviews.rating', '<=', 2))
            ->orderBy('review_insights.sentiment')
            ->orderByDesc('reviews.created_at')
            ->get(['reviews.id', 'reviews.venue_id', 'reviews.rating', 'reviews.title', 'reviews.comment', 'reviews.created_at',
                'users.name as client_name', 'review_insights.sentiment', 'review_insights.label', 'review_insights.aspects']);

        $out = array_fill_keys($venueIds, []);
        foreach ($rows as $r) {
            if (count($out[(int) $r->venue_id]) >= $perVenue) {
                continue;
            }
            $aspects = json_decode((string) $r->aspects, true) ?: [];
            $out[(int) $r->venue_id][] = [
                'review_id' => (int) $r->id,
                'rating' => (int) $r->rating,
                'title' => $r->title,
                'comment' => $r->comment,
                'client_name' => $r->client_name,
                'sentiment' => (int) round(50 * ((float) $r->sentiment + 1)),
                'label' => $r->label,
                'negative_aspects' => array_values(array_keys(array_filter($aspects, fn($a) => (float) ($a['sentiment'] ?? 0) < -0.25))),
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            ];
        }
        return $out;
    }

    /** Platform-wide aspect totals from the per-venue aggregate rows. */
    public function platformAspects(): array
    {
        $tot = [];
        foreach (DB::table('venue_review_insights')->pluck('aspects') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $name => $a) {
                $t = $tot[$name] ?? ['mentions' => 0, 'positive' => 0, 'negative' => 0];
                $t['mentions'] += (int) ($a['mentions'] ?? 0);
                $t['positive'] += (int) ($a['positive'] ?? 0);
                $t['negative'] += (int) ($a['negative'] ?? 0);
                $tot[$name] = $t;
            }
        }
        $out = [];
        foreach ($tot as $name => $t) {
            if ($t['mentions'] === 0) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'label' => VenueReviewAggregator::ASPECT_LABELS[$name] ?? $name,
                'mentions' => $t['mentions'],
                'positive_pct' => (int) round(100 * $t['positive'] / $t['mentions']),
                'negative_pct' => (int) round(100 * $t['negative'] / $t['mentions']),
            ];
        }
        usort($out, fn($a, $b) => $b['mentions'] <=> $a['mentions']);
        return $out;
    }

    /** Store-wide summary from several venue rows (sums, then the same smoothing as a single venue). */
    public function combinedSummary(array $venueIds): array
    {
        $rows = DB::table('venue_review_insights')->whereIn('venue_id', $venueIds)->get();
        if ($rows->isEmpty()) {
            return $this->aggregator->summary(0, null);
        }
        $sum = (object) [
            'analysed_count' => $rows->sum('analysed_count'), 'text_count' => $rows->sum('text_count'),
            'weight_sum' => $rows->sum('weight_sum'), 'weighted_sentiment' => $rows->sum('weighted_sentiment'),
            'positive_count' => $rows->sum('positive_count'), 'neutral_count' => $rows->sum('neutral_count'),
            'negative_count' => $rows->sum('negative_count'), 'computed_at' => $rows->max('computed_at'),
            'highlights' => '[]', 'concerns' => '[]',
        ];
        $aspects = [];
        foreach ($rows as $r) {
            foreach (json_decode((string) $r->aspects, true) ?: [] as $name => $a) {
                $t = $aspects[$name] ?? ['mentions' => 0, 'positive' => 0, 'negative' => 0];
                $t['mentions'] += (int) ($a['mentions'] ?? 0);
                $t['positive'] += (int) ($a['positive'] ?? 0);
                $t['negative'] += (int) ($a['negative'] ?? 0);
                $aspects[$name] = $t;
            }
        }
        $sum->aspects = json_encode($aspects);
        $summary = $this->aggregator->summary(0, $sum);
        unset($summary['highlights'], $summary['concerns']);
        return $summary;
    }
}
