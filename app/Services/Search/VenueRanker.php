<?php

namespace App\Services\Search;

use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\VenueReviewAggregator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores a candidate set of venues for one search request (see docs/smart-search.md).
 *
 * Every signal is 0..1. A signal with no information for a venue/request is left out of the
 * weighted average instead of counting as 0. Per-venue statistics are fetched for the whole
 * candidate set in a handful of aggregate queries (or injected via the constructor for tests).
 */
class VenueRanker
{
    private const FRESH_DAYS = 45;
    private const CTR_PRIOR_IMPRESSIONS = 20;
    private const RELIABILITY_PRIOR = 5;

    /** Relevance weight per field a term can match in. */
    private const FIELD_WEIGHTS = [
        'name' => 1.0, 'amenities' => 0.85, 'store' => 0.8, 'event_types' => 0.7, 'location' => 0.6, 'description' => 0.45,
    ];

    /**
     * Venue-type words that are near-synonyms: asking for a "hall" should not hide a ballroom or a
     * marquee, it should just rank exact matches higher.
     */
    private const VENUE_TYPE_WORDS = [
        'hall', 'marquee', 'banquet', 'ballroom', 'lawn', 'pavilion', 'arena', 'palace',
        'centre', 'center', 'lounge', 'club', 'courtyard', 'terrace', 'garden', 'farmhouse',
    ];
    private const VENUE_TYPE_PARTIAL = 0.6;

    /**
     * Review sentiment: smoothed venue sentiment (-1..1) is mapped to 0..1 over this window
     * (-0.2 → 0, 0.9 → 1), because guest text is mostly positive and the useful spread is at the top.
     */
    private const SENTIMENT_FLOOR = -0.2;
    private const SENTIMENT_CEIL = 0.9;
    /** An aspect reason needs this many mentions and this share of them positive. */
    private const ASPECT_REASON_MENTIONS = 3;
    private const ASPECT_REASON_SHARE = 0.75;
    private const ASPECT_REASONS = [
        'food' => 'Guests love the food', 'service' => 'Praised for service', 'decor' => 'Guests love the decor',
        'value' => 'Great value, say guests', 'cleanliness' => 'Praised for cleanliness', 'parking' => 'Easy parking, say guests',
        'cooling' => 'Well air-conditioned, say guests', 'sound_av' => 'Praised for sound & AV', 'location' => 'Convenient location, say guests',
        'management' => 'Well organised and on time', 'power_backup' => 'Reliable power backup, say guests',
    ];

    private const INTENT_MULTIPLIERS = [
        'top_rated' => ['quality' => 2, 'sentiment' => 2],
        'popular' => ['popularity' => 2],
        'cheap' => ['price_fit' => 2],
        'near_me' => ['distance' => 2],
        'new' => ['freshness' => 3],
    ];

    private CarbonInterface $now;

    /**
     * @param array|null $stats Pre-computed statistics (tests / callers that already have them). Keys:
     *   rating_mean float, bookings [venue_id => [recent, accepted, rejected, cancelled]],
     *   engagement [venue_id => [impressions, clicks]], global_ctr ?float, amenities [venue_id => string[]],
     *   categories [slug => name], history ?[cities => [city => n], event_types => [slug => n], median_price ?float, count int],
     *   sentiment [venue_id => [score -1..1 (smoothed), count int (analysed reviews with text), aspects [name => [mentions, positive_share]]]]
     */
    public function __construct(private ?array $stats = null, ?CarbonInterface $now = null)
    {
        $this->now = $now ?? Carbon::now();
    }

    public function rank(Collection $venues, array $parsed, array $context, ?array $settings = null): Collection
    {
        $settings = SearchSettings::normalize($settings ?? SearchSettings::get());
        if ($venues->isEmpty()) {
            return collect();
        }

        $terms = array_values(array_filter(array_map(
            fn($t) => mb_strtolower(trim((string) $t)),
            (array) ($parsed['terms'] ?? [])
        ), fn($t) => $t !== ''));
        $wantedAmenities = array_values(array_filter(array_map('strval', (array) ($parsed['amenities'] ?? []))));
        $intents = array_values(array_map('strval', (array) ($parsed['intents'] ?? [])));
        $user = $context['user'] ?? null;

        $stats = $this->stats ?? $this->loadStats($venues, $user, $settings, $terms !== [] || $wantedAmenities !== []);
        $stats += ['rating_mean' => 4.0, 'bookings' => [], 'engagement' => [], 'global_ctr' => null,
                   'amenities' => [], 'categories' => [], 'history' => null, 'sentiment' => []];
        $minSentimentReviews = max(1, (int) config('review_ai.min_reviews', 3));

        $weights = $this->effectiveWeights($settings['weights'], $intents);
        $ctx = [
            'lat' => isset($context['lat']) && is_numeric($context['lat']) ? (float) $context['lat'] : null,
            'lng' => isset($context['lng']) && is_numeric($context['lng']) ? (float) $context['lng'] : null,
            'guests' => isset($context['guests']) && (int) $context['guests'] > 0 ? (int) $context['guests'] : null,
            'max_price' => isset($context['max_price']) && (float) $context['max_price'] > 0 ? (float) $context['max_price'] : null,
            'min_price' => isset($context['min_price']) && (float) $context['min_price'] > 0 ? (float) $context['min_price'] : null,
            'city' => isset($context['city']) && trim((string) $context['city']) !== '' ? trim((string) $context['city']) : null,
            'cheap' => in_array('cheap', $intents, true),
            'luxury' => in_array('luxury', $intents, true),
        ];

        // ── Candidate-set-wide facts ──────────────────────────────────────────
        $C = (float) $settings['rating_prior_reviews'];
        $m = (float) $stats['rating_mean'];
        $bayes = [];
        foreach ($venues as $v) {
            $n = max(0, (int) $v->total_reviews);
            $avg = $n > 0 ? (float) $v->avg_rating : $m;
            $bayes[$v->id] = ($C + $n) > 0 ? ($C * $m + $avg * $n) / ($C + $n) : $m;
        }

        // Best Bayesian rating per city (for an honest "Top rated in <city>").
        $bestInCity = [];
        foreach ($venues as $v) {
            if ((int) $v->total_reviews >= 5) {
                $city = (string) $v->city;
                $bestInCity[$city] = max($bestInCity[$city] ?? 0, $bayes[$v->id]);
            }
        }

        $recentCounts = [];
        foreach ($venues as $v) {
            $recentCounts[$v->id] = (int) ($stats['bookings'][$v->id]['recent'] ?? 0);
        }
        $maxRecent = $recentCounts ? max($recentCounts) : 0;

        $prices = $venues->map(fn($v) => $v->price_per_head)->filter(fn($p) => $p !== null && $p > 0)->values()->all();
        sort($prices);

        $ages = [];
        foreach ($venues as $v) {
            $ages[$v->id] = $this->ageDays($v);
        }
        $anyFresh = $settings['exploration'] && collect($ages)->contains(fn($a) => $a !== null && $a < self::FRESH_DAYS);

        $globalCtr = $stats['global_ctr'] !== null && $stats['global_ctr'] > 0 ? (float) $stats['global_ctr'] : null;
        $history = $settings['personalization'] && $user && !empty($stats['history']['count']) ? $stats['history'] : null;

        // ── Per-venue scoring ─────────────────────────────────────────────────
        $results = $venues->map(function (Venue $v) use (
            $terms, $wantedAmenities, $stats, $weights, $ctx, $settings, $bayes, $bestInCity,
            $recentCounts, $maxRecent, $prices, $anyFresh, $globalCtr, $history, $ages, $minSentimentReviews
        ) {
            $signals = [];
            $reasons = []; // [strength, text]

            // relevance
            $match = null;
            if ($terms !== [] || $wantedAmenities !== []) {
                $match = $this->relevance($v, $terms, $wantedAmenities, $stats['amenities'][$v->id] ?? [], $stats['categories']);
                $signals['relevance'] = $match['score'];
            }

            // quality (Bayesian average rating, 3..5 window)
            $signals['quality'] = $this->clamp(($bayes[$v->id] - 3.0) / 2.0);

            // popularity
            if ($maxRecent > 0) {
                $signals['popularity'] = log1p($recentCounts[$v->id]) / log1p($maxRecent);
            }

            // reliability
            $b = $stats['bookings'][$v->id] ?? null;
            $acceptanceRate = null;
            if ($b) {
                $accepted = (int) ($b['accepted'] ?? 0);
                $rejected = (int) ($b['rejected'] ?? 0);
                $cancelled = (int) ($b['cancelled'] ?? 0);
                if ($accepted + $rejected + $cancelled > 0) {
                    $k = self::RELIABILITY_PRIOR;
                    $acceptance = ($accepted + $k * 0.9) / ($accepted + $rejected + $k);
                    $cancelRate = ($cancelled + $k * 0.1) / ($accepted + $cancelled + $k);
                    $signals['reliability'] = $this->clamp(0.6 * $acceptance + 0.4 * (1 - $cancelRate));
                    if ($accepted + $rejected >= 10) {
                        $acceptanceRate = $accepted / ($accepted + $rejected);
                    }
                }
            }

            // price fit
            $price = $v->price_per_head !== null && $v->price_per_head > 0 ? (float) $v->price_per_head : null;
            if ($price !== null) {
                $pf = $this->priceFit($price, $ctx, $prices);
                if ($pf !== null) {
                    $signals['price_fit'] = $pf;
                }
            }

            // capacity fit
            if ($ctx['guests'] !== null && $v->capacity_max) {
                $signals['capacity_fit'] = $this->capacityFit($ctx['guests'], (int) $v->capacity_min, (int) $v->capacity_max);
            }

            // distance
            $km = null;
            if ($ctx['lat'] !== null && $ctx['lng'] !== null && $v->latitude !== null && $v->longitude !== null) {
                $km = $this->haversineKm($ctx['lat'], $ctx['lng'], (float) $v->latitude, (float) $v->longitude);
                $signals['distance'] = pow(0.5, $km / max(1, $settings['distance_half_km']));
            } elseif ($ctx['city'] !== null) {
                $signals['distance'] = strcasecmp((string) $v->city, $ctx['city']) === 0 ? 1.0 : 0.0;
            }

            // engagement (click-through, smoothed towards the global CTR)
            if ($settings['learning'] && $globalCtr !== null) {
                $e = $stats['engagement'][$v->id] ?? null;
                if ($e && (int) $e['impressions'] > 0) {
                    // Prior worth at least 20 impressions and at least 2 clicks, so a single lucky click can't dominate.
                    $prior = max(self::CTR_PRIOR_IMPRESSIONS, 2 / $globalCtr);
                    $ctr = ((int) $e['clicks'] + $prior * $globalCtr) / ((int) $e['impressions'] + $prior);
                    $ratio = $ctr / $globalCtr;
                    $signals['engagement'] = $ratio / (1 + $ratio); // 0.5 = average
                }
            }

            // freshness
            $age = $ages[$v->id];
            if ($anyFresh && $age !== null) {
                $signals['freshness'] = $age < self::FRESH_DAYS ? 1 - 0.5 * ($age / self::FRESH_DAYS) : 0.0;
            }

            // personal affinity
            if ($history) {
                $signals['personal'] = $this->personal($v, $history);
            }

            // review sentiment (AI analysis of guest text); left out when there is too little text
            $sent = $stats['sentiment'][$v->id] ?? null;
            if ($sent && (int) ($sent['count'] ?? 0) >= $minSentimentReviews && isset($sent['score'])) {
                $signals['sentiment'] = $this->clamp(((float) $sent['score'] - self::SENTIMENT_FLOOR) / (self::SENTIMENT_CEIL - self::SENTIMENT_FLOOR));
            }

            // ── combine ───────────────────────────────────────────────────────
            $totalWeight = 0.0;
            foreach ($signals as $name => $s) {
                $totalWeight += $weights[$name] ?? 0;
            }
            $contributions = [];
            $base = 0.0;
            foreach ($signals as $name => $s) {
                $signals[$name] = round($s, 4);
                $w = $weights[$name] ?? 0;
                $points = $totalWeight > 0 ? 100 * $w * $s / $totalWeight : 0.0;
                $contributions[$name] = round($points, 2);
                $base += $points;
            }
            if ($totalWeight <= 0) {
                $base = 50.0;
            }
            if ($v->is_featured && $settings['featured_boost'] > 0) {
                $contributions['featured_boost'] = (float) $settings['featured_boost'];
                $base += $settings['featured_boost'];
            }
            $boost = (int) ($v->search_boost ?? 0);
            if ($boost !== 0) {
                $contributions['search_boost'] = round($boost * 0.2, 2);
                $base += $boost * 0.2;
            }
            $score = round($this->clamp($base, 0, 100), 1);

            // ── reasons: strongest real positives ─────────────────────────────
            $strength = fn(string $signal, float $bonus = 0) => ($contributions[$signal] ?? 0) + $bonus;

            if ($match && $match['score'] >= 0.5 && $match['label'] !== null) {
                $reasons[] = [$strength('relevance', 30), $match['label']];
            }
            if ($ctx['guests'] !== null && ($signals['capacity_fit'] ?? 0) >= 0.6
                && $ctx['guests'] >= (int) $v->capacity_min && $ctx['guests'] <= (int) $v->capacity_max) {
                $reasons[] = [$strength('capacity_fit', 20), 'Fits ' . number_format($ctx['guests']) . ' guests'];
            }
            if ($price !== null && $ctx['max_price'] !== null && $price <= $ctx['max_price']
                && ($ctx['min_price'] === null || $price >= $ctx['min_price'])) {
                $reasons[] = [$strength('price_fit', 20), 'Within your budget'];
            } elseif ($price !== null && $ctx['max_price'] === null && $ctx['min_price'] === null
                && ($signals['price_fit'] ?? 0) >= 0.85) {
                $reasons[] = $ctx['luxury']
                    ? [$strength('price_fit'), 'Premium venue']
                    : [$strength('price_fit'), 'Great value — Rs ' . number_format($price) . '/head'];
            }
            $reviews = (int) $v->total_reviews;
            if ($reviews >= 5 && (float) $v->avg_rating >= 4.0 && isset($bestInCity[(string) $v->city])
                && $bayes[$v->id] >= $bestInCity[(string) $v->city] - 1e-9 && $v->city) {
                $reasons[] = [$strength('quality', 5), 'Top rated in ' . $v->city];
            } elseif ($reviews >= 5 && (float) $v->avg_rating >= 4.3) {
                $reasons[] = [$strength('quality'), 'Rated ' . number_format((float) $v->avg_rating, 1) . '★ (' . $reviews . ' reviews)'];
            }
            if ($km !== null && $km <= 3 * max(1, $settings['distance_half_km'])) {
                $reasons[] = [$strength('distance', 10), $km < 1 ? 'Under 1 km away' : number_format($km, 1) . ' km away'];
            }
            if ($recentCounts[$v->id] >= 5 && ($signals['popularity'] ?? 0) >= 0.7) {
                $reasons[] = [$strength('popularity'), 'Popular — ' . $recentCounts[$v->id] . ' bookings recently'];
            }
            if ($acceptanceRate !== null && $acceptanceRate >= 0.9 && ($signals['reliability'] ?? 0) >= 0.8) {
                $reasons[] = [$strength('reliability'), 'Reliable host — ' . round($acceptanceRate * 100) . '% accepted'];
            }
            if (($signals['personal'] ?? 0) >= 0.6) {
                $reasons[] = [$strength('personal', 5), 'Like venues you booked before'];
            }
            if ($age !== null && $age < self::FRESH_DAYS && isset($signals['freshness'])) {
                $reasons[] = [$strength('freshness', 3), 'New on EventGenius'];
            }
            if (($signals['engagement'] ?? 0) >= 0.65 && (int) ($stats['engagement'][$v->id]['clicks'] ?? 0) >= 5) {
                $reasons[] = [$strength('engagement'), 'Often picked by other searchers'];
            }
            if (isset($signals['sentiment'])) {
                $sentimentReason = $this->sentimentReason($sent);
                if ($sentimentReason !== null) {
                    $reasons[] = [$strength('sentiment', 4), $sentimentReason];
                }
            }
            if ($v->is_featured) {
                $reasons[] = [1.0, 'Featured venue'];
            }

            usort($reasons, fn($a, $b) => $b[0] <=> $a[0]);
            $reasonTexts = array_values(array_unique(array_map(fn($r) => $r[1], $reasons)));

            return [
                'venue' => $v,
                'score' => $score,
                'signals' => $signals,
                'contributions' => $contributions,
                'reasons' => array_slice($reasonTexts, 0, 3),
            ];
        });

        // Score desc, then rating quality, then newest id (stable across pages).
        $rows = $results->all();
        usort($rows, fn($a, $b) => [$b['score'], $b['signals']['quality'], $b['venue']->getKey()]
            <=> [$a['score'], $a['signals']['quality'], $a['venue']->getKey()]);

        return collect($rows);
    }

    // ── Signals ───────────────────────────────────────────────────────────────

    /**
     * Relevance of the venue for the free-text terms and requested amenities: average over items of
     * the best field weight each item matched. Returns ['score' => 0..1, 'label' => ?string].
     */
    public function relevance(Venue $v, array $terms, array $wantedAmenities, array $venueAmenities, array $categories = []): array
    {
        $eventTypes = [];
        foreach ((array) ($v->event_types ?? []) as $slug) {
            $eventTypes[] = str_replace('_', ' ', (string) $slug);
            if (isset($categories[$slug])) {
                $eventTypes[] = (string) $categories[$slug];
            }
        }
        $fields = [
            'name' => (string) $v->name,
            'store' => (string) ($v->relationLoaded('store') && $v->store ? $v->store->name : ''),
            'amenities' => implode(' | ', $venueAmenities),
            'event_types' => implode(' | ', $eventTypes),
            'location' => implode(' ', array_filter([(string) $v->address, (string) $v->city, (string) $v->state])),
            'description' => (string) $v->description,
        ];
        $fields = array_map(fn($f) => mb_strtolower($f), $fields);

        $scores = [];
        $label = null;
        $labelStrength = 0;
        foreach ($terms as $term) {
            $best = 0.0;
            foreach ($fields as $field => $text) {
                if ($text !== '' && self::FIELD_WEIGHTS[$field] > $best && $this->textMatches($text, $term)) {
                    $best = self::FIELD_WEIGHTS[$field];
                }
            }
            // Only literal matches earn a "Matches …" label; a synonym venue type just scores.
            if ($best >= 0.6 && $best > $labelStrength) {
                $label = 'Matches “' . $term . '”';
                $labelStrength = $best;
            }
            if ($best < self::VENUE_TYPE_PARTIAL && $this->isVenueTypeWord($term) && $this->hasVenueTypeWord($fields['name'])) {
                $best = self::VENUE_TYPE_PARTIAL;
            }
            $scores[] = $best;
        }

        $have = array_map(fn($a) => mb_strtolower(trim($a)), $venueAmenities);
        foreach ($wantedAmenities as $amenity) {
            if (in_array(mb_strtolower(trim($amenity)), $have, true)) {
                $scores[] = 1.0;
                if ($labelStrength < 0.9) {
                    $label = 'Has ' . $amenity;
                    $labelStrength = 0.9;
                }
            } else {
                $scores[] = $this->textMatches($fields['description'], mb_strtolower($amenity)) ? 0.4 : 0.0;
            }
        }

        return [
            'score' => $scores ? array_sum($scores) / count($scores) : 0.0,
            'label' => $label,
        ];
    }

    private function isVenueTypeWord(string $term): bool
    {
        return in_array(rtrim($term, 's'), self::VENUE_TYPE_WORDS, true) || in_array($term, self::VENUE_TYPE_WORDS, true);
    }

    private function hasVenueTypeWord(string $name): bool
    {
        foreach (self::VENUE_TYPE_WORDS as $word) {
            if ($this->textMatches($name, $word)) {
                return true;
            }
        }
        return false;
    }

    /** Word-prefix match ("roof" matches "rooftop"), with a naive plural fallback ("gardens" → "garden"). */
    private function textMatches(string $text, string $term): bool
    {
        $variants = [$term];
        if (mb_strlen($term) > 3 && str_ends_with($term, 's')) {
            $variants[] = mb_substr($term, 0, -1);
        }
        foreach ($variants as $t) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '/u', $text)) {
                return true;
            }
        }
        return false;
    }

    private function priceFit(float $price, array $ctx, array $sortedPrices): ?float
    {
        $max = $ctx['max_price'];
        $min = $ctx['min_price'];

        if ($max !== null || $min !== null) {
            $s = 1.0;
            if ($max !== null) {
                if ($price <= $max) {
                    // Inside the budget; slight preference for cheaper (or pricier with "luxury").
                    $ratio = $price / $max;
                    $s = 0.85 + 0.15 * ($ctx['luxury'] ? $ratio : 1 - $ratio);
                } else {
                    // Over budget: fades from 0.5 to 0 at the +15 % tolerance edge.
                    $s = 0.5 * $this->clamp(1 - ($price - $max) / (0.15 * $max));
                }
            }
            if ($min !== null && $price < $min) {
                $s = min($s, 0.5 * $this->clamp(1 - ($min - $price) / (0.5 * $min)));
            }
            return $s;
        }

        // No budget given: mild cheaper-is-better by percentile among candidates.
        $n = count($sortedPrices);
        if ($n < 2 || $sortedPrices[0] == $sortedPrices[$n - 1]) {
            return null;
        }
        // Binary search: number of candidates strictly cheaper than this one.
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($sortedPrices[$mid] < $price) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        $cheaper = $lo;
        $pct = $cheaper / ($n - 1); // 0 = cheapest, 1 = most expensive
        return 0.5 + 0.5 * ($ctx['luxury'] ? $pct : 1 - $pct);
    }

    private function capacityFit(int $guests, int $min, int $max): float
    {
        if ($guests > $max) {
            return 0.0;
        }
        if ($min > 0 && $guests < $min) {
            // Venue needs more guests than the party has.
            return 0.2 + 0.3 * ($guests / $min);
        }
        // In range; penalise far oversized halls (party fills < 35 % of capacity).
        $utilisation = $guests / max(1, $max);
        return $utilisation >= 0.35 ? 1.0 : 0.6 + 0.4 * ($utilisation / 0.35);
    }

    private function personal(Venue $v, array $history): float
    {
        $parts = [];
        $cities = (array) ($history['cities'] ?? []);
        if ($cities) {
            $parts[] = [0.4, ($cities[(string) $v->city] ?? 0) / max($cities)];
        }
        $types = (array) ($history['event_types'] ?? []);
        if ($types) {
            $best = 0;
            foreach ((array) ($v->event_types ?? []) as $slug) {
                $best = max($best, $types[$slug] ?? 0);
            }
            $parts[] = [0.35, $best / max($types)];
        }
        $median = $history['median_price'] ?? null;
        if ($median && $v->price_per_head) {
            $parts[] = [0.25, $this->clamp(1 - abs(log((float) $v->price_per_head / (float) $median)) / log(2))];
        }
        if (!$parts) {
            return 0.0;
        }
        $wsum = array_sum(array_column($parts, 0));
        $sum = 0.0;
        foreach ($parts as [$w, $s]) {
            $sum += $w * $s;
        }
        return $sum / $wsum;
    }

    /**
     * The most-praised aspect ("Guests love the food"), only for venues guests are positive about
     * overall and only when enough reviews say so; else "Glowing guest reviews" for very positive venues.
     */
    public function sentimentReason(?array $sent): ?string
    {
        if (!$sent || (float) ($sent['score'] ?? 0) < 0.3) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        foreach ((array) ($sent['aspects'] ?? []) as $name => $a) {
            $m = (int) ($a['mentions'] ?? 0);
            $share = (float) ($a['positive_share'] ?? 0);
            if (!isset(self::ASPECT_REASONS[$name]) || $m < self::ASPECT_REASON_MENTIONS || $share < self::ASPECT_REASON_SHARE) {
                continue;
            }
            // Positive mentions, discounted by the share that was not positive.
            $score = $m * $share * $share;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }
        if ($best !== null) {
            return self::ASPECT_REASONS[$best];
        }
        return (float) $sent['score'] >= 0.6 && (int) ($sent['count'] ?? 0) >= 5 ? 'Glowing guest reviews' : null;
    }

    private function effectiveWeights(array $weights, array $intents): array
    {
        foreach ($intents as $intent) {
            foreach (self::INTENT_MULTIPLIERS[$intent] ?? [] as $signal => $factor) {
                $weights[$signal] = ($weights[$signal] ?? 0) * $factor;
            }
        }
        return $weights;
    }

    // ── Stats (a few aggregate queries for the whole candidate set) ──────────

    public function loadStats(Collection $venues, ?User $user, array $settings, bool $needText = true): array
    {
        $ids = $venues->pluck('id')->all();
        $windowStart = $this->now->copy()->subDays((int) $settings['popularity_window_days']);

        $ratingMean = DB::table('venues')
            ->where('status', 'active')->whereNull('deleted_at')->where('total_reviews', '>', 0)
            ->selectRaw('SUM(avg_rating * total_reviews) / NULLIF(SUM(total_reviews), 0) AS m')
            ->value('m');

        $bookings = DB::table('bookings')
            ->whereIn('venue_id', $ids)->whereNull('deleted_at')
            ->groupBy('venue_id')
            ->selectRaw("venue_id,
                SUM(CASE WHEN status IN ('confirmed','completed') AND created_at >= ? THEN 1 ELSE 0 END) AS recent,
                SUM(CASE WHEN status IN ('confirmed','completed') THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled", [$windowStart])
            ->get()
            ->mapWithKeys(fn($r) => [$r->venue_id => (array) $r])
            ->all();

        $engagement = [];
        $globalCtr = null;
        if ($settings['learning']) {
            $engagement = DB::table('venue_search_stats')->whereIn('venue_id', $ids)
                ->get(['venue_id', 'impressions', 'clicks'])
                ->mapWithKeys(fn($r) => [$r->venue_id => ['impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks]])
                ->all();
            $totals = DB::table('venue_search_stats')->selectRaw('SUM(impressions) AS i, SUM(clicks) AS c')->first();
            // Need a minimum of traffic before click-through means anything.
            if ($totals && (int) $totals->i >= 50 && (int) $totals->c > 0) {
                $globalCtr = (int) $totals->c / (int) $totals->i;
            }
        }

        $amenities = [];
        $categories = [];
        if ($needText) {
            DB::table('venue_amenities')->whereIn('venue_id', $ids)->whereNull('deleted_at')
                ->get(['venue_id', 'name'])
                ->each(function ($r) use (&$amenities) {
                    $amenities[$r->venue_id][] = (string) $r->name;
                });
            $categories = DB::table('categories')->whereNull('deleted_at')->pluck('name', 'slug')->all();
        }

        return [
            'rating_mean' => $ratingMean !== null ? (float) $ratingMean : 4.0,
            'bookings' => $bookings,
            'engagement' => $engagement,
            'global_ctr' => $globalCtr,
            'amenities' => $amenities,
            'categories' => $categories,
            'history' => $settings['personalization'] && $user ? $this->loadHistory($user) : null,
            'sentiment' => (new VenueReviewAggregator($this->now))->rankingStats($ids),
        ];
    }

    /** The client's own past bookings (not cancelled/rejected): cities, event types, median per-head price. */
    private function loadHistory(User $user): ?array
    {
        $rows = DB::table('bookings')
            ->join('venues', 'venues.id', '=', 'bookings.venue_id')
            ->where('bookings.client_id', $user->id)
            ->whereNull('bookings.deleted_at')
            ->whereIn('bookings.status', ['pending', 'confirmed', 'completed'])
            ->orderByDesc('bookings.created_at')
            ->limit(200)
            ->get(['venues.city', 'venues.price_per_head', 'venues.event_types', 'bookings.event_type']);

        if ($rows->isEmpty()) {
            return null;
        }

        $cities = [];
        $types = [];
        $prices = [];
        foreach ($rows as $r) {
            if ($r->city) {
                $cities[$r->city] = ($cities[$r->city] ?? 0) + 1;
            }
            $slugs = $r->event_type ? [$r->event_type] : (array) json_decode((string) $r->event_types, true);
            foreach ($slugs as $slug) {
                $types[$slug] = ($types[$slug] ?? 0) + 1;
            }
            if ($r->price_per_head > 0) {
                $prices[] = (float) $r->price_per_head;
            }
        }
        sort($prices);
        $n = count($prices);
        $median = $n ? ($n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2) : null;

        return ['count' => $rows->count(), 'cities' => $cities, 'event_types' => $types, 'median_price' => $median];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function ageDays(Venue $v): ?float
    {
        if (!$v->created_at) {
            return null;
        }
        return max(0, $v->created_at->diffInSeconds($this->now, false) / 86400);
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function clamp(float $x, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $x));
    }
}
