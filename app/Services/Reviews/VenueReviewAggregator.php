<?php

namespace App\Services\Reviews;

use App\Models\ReviewInsight;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-venue review sentiment, kept in `venue_review_insights` (one row per venue) and rebuilt
 * whenever an insight of that venue changes. Only approved, non-deleted reviews without a
 * spam/duplicate flag count; very short reviews count for the label totals but not for sentiment.
 *
 * Sentiment is recency-weighted (half-life ~90 days) and Bayesian-smoothed toward the platform
 * mean at read time, so ranking stays honest for venues with few reviews.
 */
class VenueReviewAggregator
{
    public const ASPECT_LABELS = [
        'food' => 'Food', 'service' => 'Service & staff', 'decor' => 'Decor & ambience', 'value' => 'Value for money',
        'cleanliness' => 'Cleanliness', 'parking' => 'Parking', 'cooling' => 'AC & cooling', 'sound_av' => 'Sound & AV',
        'location' => 'Location & access', 'management' => 'Management & timing', 'power_backup' => 'Power backup',
    ];

    private const LABEL_THRESHOLD = 0.25;

    private CarbonInterface $now;
    private float $halfLife;
    private float $prior;

    public function __construct(?CarbonInterface $now = null)
    {
        $this->now = $now ?? Carbon::now();
        $this->halfLife = (float) config('review_ai.half_life_days', 90);
        $this->prior = (float) config('review_ai.prior_reviews', 2);
    }

    // ── Rebuild ───────────────────────────────────────────────────────────────

    /** Rebuild the aggregate rows of the given venues. Returns the number of venues written. */
    public function recompute(array $venueIds): int
    {
        $venueIds = array_values(array_unique(array_filter(array_map('intval', $venueIds))));
        if ($venueIds === []) {
            return 0;
        }

        $byVenue = array_fill_keys($venueIds, []);
        foreach (array_chunk($venueIds, 200) as $chunk) {
            DB::table('reviews')
                ->join('review_insights', 'review_insights.review_id', '=', 'reviews.id')
                ->whereIn('reviews.venue_id', $chunk)
                ->where('reviews.is_approved', true)
                ->whereNull('reviews.deleted_at')
                ->orderBy('reviews.id')
                ->select('reviews.id', 'reviews.venue_id', 'reviews.created_at', 'review_insights.sentiment',
                    'review_insights.label', 'review_insights.aspects', 'review_insights.flags', 'review_insights.phrases')
                ->get()
                ->each(function ($r) use (&$byVenue) {
                    $byVenue[(int) $r->venue_id][] = $r;
                });
        }

        $now = now();
        $rows = [];
        foreach ($byVenue as $venueId => $reviews) {
            $rows[] = ['venue_id' => $venueId] + $this->aggregate($reviews) + ['computed_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
        // Venues may have been hard-deleted meanwhile; only write rows for existing ones.
        $existing = DB::table('venues')->whereIn('id', $venueIds)->pluck('id')->map(fn($id) => (int) $id)->all();
        $rows = array_values(array_filter($rows, fn($r) => in_array($r['venue_id'], $existing, true)));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('venue_review_insights')->upsert($chunk, ['venue_id'], [
                'analysed_count', 'text_count', 'weight_sum', 'weighted_sentiment', 'positive_count', 'neutral_count',
                'negative_count', 'aspects', 'highlights', 'concerns', 'computed_at', 'updated_at',
            ]);
        }
        return count($rows);
    }

    /** Rebuild every venue that has reviews or an aggregate row (recency weights decay over time). */
    public function recomputeAll(): int
    {
        $ids = DB::table('reviews')->distinct()->pluck('venue_id')
            ->merge(DB::table('venue_review_insights')->pluck('venue_id'))
            ->map(fn($id) => (int) $id)->unique()->values()->all();
        $n = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $n += $this->recompute($chunk);
        }
        return $n;
    }

    /**
     * Pure aggregation of one venue's analysed reviews (objects/arrays with created_at, sentiment,
     * label, aspects, flags, phrases). Public for unit tests.
     */
    public function aggregate(iterable $reviews): array
    {
        $counted = 0;
        $text = 0;
        $wSum = 0.0;
        $wsSum = 0.0;
        $labels = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $aspects = [];
        $pos = [];
        $neg = [];

        foreach ($reviews as $r) {
            $r = (object) $r;
            $flags = $this->json($r->flags ?? null);
            if (array_intersect($flags, ReviewInsight::EXCLUDING_FLAGS)) {
                continue;
            }
            $counted++;
            $label = in_array($r->label ?? null, ['positive', 'neutral', 'negative'], true) ? $r->label : 'neutral';
            $labels[$label]++;
            if (in_array('very_short', $flags, true)) {
                continue;
            }
            $text++;
            $w = $this->weight($r->created_at ?? null);
            $s = max(-1.0, min(1.0, (float) ($r->sentiment ?? 0)));
            $wSum += $w;
            $wsSum += $w * $s;

            foreach ($this->json($r->aspects ?? null) as $name => $a) {
                if (!isset(self::ASPECT_LABELS[$name]) || !is_array($a)) {
                    continue;
                }
                $as = (float) ($a['sentiment'] ?? 0);
                $agg = $aspects[$name] ?? ['mentions' => 0, 'positive' => 0, 'negative' => 0, 'w' => 0.0, 'ws' => 0.0];
                $agg['mentions']++;
                $agg['positive'] += $as > self::LABEL_THRESHOLD ? 1 : 0;
                $agg['negative'] += $as < -self::LABEL_THRESHOLD ? 1 : 0;
                $agg['w'] += $w;
                $agg['ws'] += $w * $as;
                $aspects[$name] = $agg;
            }

            $phrases = $this->json($r->phrases ?? null);
            foreach ((array) ($phrases['positive'] ?? []) as $p) {
                $this->addPhrase($pos, (string) $p, $w);
            }
            foreach ((array) ($phrases['negative'] ?? []) as $p) {
                $this->addPhrase($neg, (string) $p, $w);
            }
        }

        $aspectOut = [];
        foreach ($aspects as $name => $a) {
            $aspectOut[$name] = [
                'mentions' => $a['mentions'],
                'positive' => $a['positive'],
                'negative' => $a['negative'],
                'sentiment' => $a['w'] > 0 ? round($a['ws'] / $a['w'], 4) : 0.0,
            ];
        }
        uasort($aspectOut, fn($x, $y) => $y['mentions'] <=> $x['mentions']);

        return [
            'analysed_count' => $counted,
            'text_count' => $text,
            'weight_sum' => round($wSum, 6),
            'weighted_sentiment' => round($wsSum, 6),
            'positive_count' => $labels['positive'],
            'neutral_count' => $labels['neutral'],
            'negative_count' => $labels['negative'],
            'aspects' => json_encode($aspectOut, JSON_FORCE_OBJECT),
            'highlights' => json_encode($this->topPhrases($pos, 0.0), JSON_UNESCAPED_UNICODE),
            // A concern must be fairly recent (weight ≥ 0.25 ≈ within ~6 months) to be shown.
            'concerns' => json_encode($this->topPhrases($neg, 0.25), JSON_UNESCAPED_UNICODE),
        ];
    }

    // ── Read side ─────────────────────────────────────────────────────────────

    /** Recency-weighted mean sentiment over all counted reviews on the platform (-1..1). */
    public function globalMean(): float
    {
        try {
            $row = DB::table('venue_review_insights')->selectRaw('SUM(weighted_sentiment) AS ws, SUM(weight_sum) AS w')->first();
            if ($row && (float) $row->w > 0) {
                return (float) $row->ws / (float) $row->w;
            }
        } catch (Throwable $e) {
            report($e);
        }
        return 0.5;
    }

    public static function smooth(float $weightSum, float $weightedSentiment, float $globalMean, float $prior): float
    {
        $den = $prior + $weightSum;
        return $den > 0 ? max(-1.0, min(1.0, ($prior * $globalMean + $weightedSentiment) / $den)) : $globalMean;
    }

    /**
     * Ranking input for a candidate set: [venue_id => ['score' => -1..1 smoothed, 'count' => counted text reviews,
     * 'aspects' => [name => ['mentions', 'positive_share']]]]. Never throws (e.g. table not migrated yet → []).
     */
    public function rankingStats(array $venueIds): array
    {
        if ($venueIds === []) {
            return [];
        }
        try {
            $rows = DB::table('venue_review_insights')->whereIn('venue_id', $venueIds)->where('text_count', '>', 0)->get();
            if ($rows->isEmpty()) {
                return [];
            }
            $g = $this->globalMean();
            $out = [];
            foreach ($rows as $r) {
                $aspects = [];
                foreach ($this->json($r->aspects) as $name => $a) {
                    $m = (int) ($a['mentions'] ?? 0);
                    if ($m > 0) {
                        $aspects[$name] = ['mentions' => $m, 'positive_share' => round((int) ($a['positive'] ?? 0) / $m, 4)];
                    }
                }
                $out[(int) $r->venue_id] = [
                    'score' => round(self::smooth((float) $r->weight_sum, (float) $r->weighted_sentiment, $g, $this->prior), 4),
                    'count' => (int) $r->text_count,
                    'aspects' => $aspects,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    /** Public `review_summary` for a venue page. */
    public function summary(int $venueId, ?object $row = null, ?float $globalMean = null): array
    {
        $row ??= DB::table('venue_review_insights')->where('venue_id', $venueId)->first();
        if (!$row || (int) $row->analysed_count === 0) {
            return ['analysed_count' => 0, 'sentiment' => null, 'label' => null, 'aspects' => [], 'highlights' => [], 'concerns' => []];
        }
        $smoothed = (int) $row->text_count > 0
            ? self::smooth((float) $row->weight_sum, (float) $row->weighted_sentiment, $globalMean ?? $this->globalMean(), $this->prior)
            : null;

        $aspects = [];
        foreach ($this->json($row->aspects) as $name => $a) {
            $m = (int) ($a['mentions'] ?? 0);
            if ($m < 2 || !isset(self::ASPECT_LABELS[$name])) {
                continue; // one mention is an anecdote, not a pattern
            }
            $aspects[] = [
                'name' => $name,
                'label' => self::ASPECT_LABELS[$name],
                'positive_pct' => (int) round(100 * (int) ($a['positive'] ?? 0) / $m),
                'negative_pct' => (int) round(100 * (int) ($a['negative'] ?? 0) / $m),
                'mentions' => $m,
            ];
        }
        // JSON columns do not keep key order: most-discussed aspects first.
        usort($aspects, fn($x, $y) => [$y['mentions'], $x['name']] <=> [$x['mentions'], $y['name']]);

        return [
            'analysed_count' => (int) $row->analysed_count,
            'sentiment' => $smoothed === null ? null : (int) round(50 * ($smoothed + 1)),
            'label' => $smoothed === null ? null : self::labelFor($smoothed),
            'distribution' => [
                'positive' => (int) $row->positive_count,
                'neutral' => (int) $row->neutral_count,
                'negative' => (int) $row->negative_count,
            ],
            'aspects' => $aspects,
            'highlights' => $this->json($row->highlights),
            'concerns' => $this->json($row->concerns),
            'updated_at' => $row->computed_at ? Carbon::parse($row->computed_at)->toIso8601String() : null,
        ];
    }

    public static function labelFor(float $sentiment): string
    {
        return $sentiment > self::LABEL_THRESHOLD ? 'positive' : ($sentiment < -self::LABEL_THRESHOLD ? 'negative' : 'mixed');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function weight(mixed $createdAt): float
    {
        if (!$createdAt) {
            return 0.5;
        }
        $at = $createdAt instanceof CarbonInterface ? $createdAt : Carbon::parse((string) $createdAt);
        $ageDays = max(0.0, $at->diffInSeconds($this->now, false) / 86400);
        return pow(0.5, $ageDays / max(1.0, $this->halfLife));
    }

    private function addPhrase(array &$bucket, string $phrase, float $w): void
    {
        $phrase = trim(preg_replace('/\s+/u', ' ', $phrase) ?? '');
        if ($phrase === '' || mb_strlen($phrase) > 90) {
            return;
        }
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', '', $phrase) ?? $phrase);
        $bucket[$key] ??= ['text' => mb_strtoupper(mb_substr($phrase, 0, 1)) . mb_substr($phrase, 1), 'n' => 0, 'w' => 0.0];
        $bucket[$key]['n']++;
        $bucket[$key]['w'] += $w;
    }

    private function topPhrases(array $bucket, float $minWeight, int $limit = 3): array
    {
        $bucket = array_filter($bucket, fn($p) => $p['w'] >= $minWeight);
        uasort($bucket, fn($a, $b) => [$b['n'], $b['w']] <=> [$a['n'], $a['w']]);
        return array_values(array_map(fn($p) => $p['text'], array_slice($bucket, 0, $limit)));
    }

    private function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
}
