<?php

namespace App\Services\Search;

use App\Models\User;
use App\Models\Venue;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Public venue search: hard filters → natural-language understanding → ranking → pagination,
 * plus search logging and impression counting (see docs/smart-search.md).
 */
class VenueSearch
{
    public const SORTS = ['best_match', 'created_at', 'avg_rating', 'price_per_head', 'price_per_hour', 'price_per_day', 'price_per_event'];

    /** Tolerance over the per-head budget so near-budget venues stay visible. */
    private const BUDGET_TOLERANCE = 1.15;

    public function __construct(private ?VenueRanker $ranker = null) {}

    /**
     * @param array $opts ['log' => bool = true, 'explain' => bool = false, 'settings' => ?array,
     *                    'understood' => ?array (already-understood query, e.g. from the assistant; skips parsing `q`)]
     * @return array ['paginator' => LengthAwarePaginator, 'understood' => array, 'search_id' => ?string, 'sort' => string]
     */
    public function run(array $params, ?User $user, array $opts = []): array
    {
        $log = $opts['log'] ?? true;
        $explain = $opts['explain'] ?? false;
        $settings = SearchSettings::normalize($opts['settings'] ?? SearchSettings::get());

        // ── Understand the query; explicit params override parsed values ─────
        $q = $this->str($params['q'] ?? null) ?? $this->str($params['search'] ?? null);
        $understood = is_array($opts['understood'] ?? null)
            ? array_merge($this->parse(null), $opts['understood'])
            : $this->parse($q);

        $city = $this->str($params['city'] ?? null) ?? $this->str($understood['city']);
        $eventType = $this->str($params['event_type'] ?? null) ?? $this->str($understood['event_type']);
        $guests = $this->posInt($params['guests'] ?? null) ?? $this->posInt($understood['guests']);
        $maxPrice = $this->posFloat($params['max_price'] ?? null) ?? $this->posFloat($understood['max_price']);
        $minPrice = $this->posFloat($params['min_price'] ?? null) ?? $this->posFloat($understood['min_price']);
        $minCapacity = $this->posInt($params['min_capacity'] ?? null);
        $maxCapacity = $this->posInt($params['max_capacity'] ?? null);
        $minRating = is_numeric($params['min_rating'] ?? null) && (float) $params['min_rating'] > 0
            ? min(5.0, (float) $params['min_rating'])
            : null;
        // Amenities every result must have (names as stored in venue_amenities, case-insensitive).
        $requiredAmenities = array_values(array_unique(array_filter(array_map(
            fn($a) => is_scalar($a) ? mb_strtolower(trim((string) $a)) : '',
            is_array($params['amenities'] ?? null) ? array_slice($params['amenities'], 0, 10) : []
        ))));
        $pricingType = $this->str($params['pricing_type'] ?? null);
        $featured = filter_var($params['featured'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $lat = is_numeric($params['lat'] ?? null) && abs((float) $params['lat']) <= 90 ? (float) $params['lat'] : null;
        $lng = is_numeric($params['lng'] ?? null) && abs((float) $params['lng']) <= 180 ? (float) $params['lng'] : null;
        if ($lat === null || $lng === null) {
            $lat = $lng = null;
        }

        $sortBy = $params['sort_by'] ?? null;
        $sortBy = in_array($sortBy, self::SORTS, true) ? $sortBy : 'best_match';
        $priceSort = str_starts_with($sortBy, 'price_');
        $sortDir = in_array($params['sort_dir'] ?? null, ['asc', 'desc'], true)
            ? $params['sort_dir']
            : ($priceSort ? 'asc' : 'desc');

        $perPage = (int) ($params['per_page'] ?? 12);
        $perPage = $perPage > 0 ? min(100, $perPage) : 12;
        $page = max(1, (int) ($params['page'] ?? 1));

        // ── Hard filters ──────────────────────────────────────────────────────
        $query = Venue::with('store:id,name,city')
            ->where('status', 'active')
            ->whereHas('store', fn($s) => $s->where('status', 'approved'));

        if ($city !== null) {
            $query->where('city', $city);
        }
        if ($eventType !== null) {
            $query->whereJsonContains('event_types', $eventType);
        }
        if ($guests !== null) {
            $query->where('capacity_max', '>=', $guests);
        }
        if ($minCapacity !== null) {
            $query->where('capacity_max', '>=', $minCapacity);
        }
        if ($maxCapacity !== null) {
            $query->where('capacity_max', '<=', $maxCapacity);
        }
        if ($minRating !== null) {
            $query->where('total_reviews', '>', 0)->where('avg_rating', '>=', $minRating);
        }
        foreach ($requiredAmenities as $amenity) {
            $query->whereHas('amenities', fn($a) => $a->whereRaw('LOWER(name) = ?', [$amenity]));
        }
        if ($maxPrice !== null) {
            $ceiling = $maxPrice * self::BUDGET_TOLERANCE;
            $query->where(function ($w) use ($ceiling, $maxPrice) {
                $w->where('price_per_head', '<=', $ceiling)
                  // Venues not priced per head keep the legacy "any price within budget" behaviour.
                  ->orWhere(fn($o) => $o->whereNull('price_per_head')->where(fn($p) => $p
                      ->where('price_per_hour', '<=', $maxPrice)
                      ->orWhere('price_per_day', '<=', $maxPrice)
                      ->orWhere('price_per_event', '<=', $maxPrice)));
            });
        }
        if ($pricingType !== null) {
            $query->where('pricing_type', $pricingType);
        }
        if ($featured) {
            $query->where('is_featured', true);
        }

        // Non-best_match sorts: same ordering as the classic listing.
        if ($sortBy !== 'best_match') {
            if ($priceSort) {
                $query->orderByRaw("{$sortBy} IS NULL");
            }
            if ($sortBy === 'avg_rating') {
                $query->orderBy('avg_rating', $sortDir)->orderBy('total_reviews', 'desc');
            } else {
                $query->orderBy($sortBy, $sortDir);
            }
        }
        $query->orderBy('id', 'desc');

        $candidates = $query->get();

        // ── Rank ──────────────────────────────────────────────────────────────
        $context = [
            'user' => $user, 'lat' => $lat, 'lng' => $lng, 'guests' => $guests,
            'max_price' => $maxPrice, 'min_price' => $minPrice, 'city' => $city, 'event_type' => $eventType,
        ];
        $ranked = ($this->ranker ?? new VenueRanker())->rank($candidates, $understood, $context, $settings);

        // Text relevance: drop non-matching venues only when something does match.
        if ($ranked->contains(fn($r) => ($r['signals']['relevance'] ?? 0) > 0)) {
            $ranked = $ranked->filter(fn($r) => ($r['signals']['relevance'] ?? 0) > 0)->values();
        }

        if ($sortBy !== 'best_match') {
            // Keep the SQL order, just attach the match info.
            $byId = $ranked->keyBy(fn($r) => $r['venue']->id);
            $ranked = $candidates->filter(fn($v) => $byId->has($v->id))->map(fn($v) => $byId[$v->id])->values();
        }

        // ── Paginate (same JSON shape as Eloquent's paginate()) ──────────────
        $total = $ranked->count();
        $slice = $ranked->slice(($page - 1) * $perPage, $perPage)->values();
        $pageVenues = new \Illuminate\Database\Eloquent\Collection($slice->pluck('venue')->all());
        $pageVenues->load(['images' => fn($q) => $q->where('is_primary', true)]);

        $items = $slice->map(function ($r) use ($explain) {
            $match = ['score' => $r['score'], 'reasons' => $r['reasons']];
            if ($explain) {
                $match['signals'] = $r['signals'];
                $match['contributions'] = $r['contributions'];
            }
            return $r['venue']->toArray() + ['match' => $match];
        })->all();

        $query = array_filter($params, fn($v) => $v !== null && $v !== '' && !is_array($v));
        unset($query['page']);
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
        $paginator->appends($query);

        // ── Log ───────────────────────────────────────────────────────────────
        $searchId = null;
        // Only real searches are logged: typed text, a filter, a location, or the venues page asking
        // to be tracked (track=1). Background lists (home featured, city look-ups) are not searches.
        $isSearch = ($q !== null && trim($q) !== '')
            || $city || $eventType || $guests || $maxPrice || $minPrice || $lat !== null || $requiredAmenities
            || filter_var($params['track'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($log && $isSearch) {
            $searchId = is_string($params['search_id'] ?? null) && Str::isUuid($params['search_id'])
                ? strtolower($params['search_id'])
                : (string) Str::uuid();
            $this->log($searchId, $user, $q, $understood, [
                'city' => $city, 'event_type' => $eventType, 'guests' => $guests, 'max_price' => $maxPrice,
                'min_price' => $minPrice, 'min_capacity' => $minCapacity, 'max_capacity' => $maxCapacity,
                'min_rating' => $minRating, 'amenities' => $requiredAmenities ?: null, 'pricing_type' => $pricingType,
                'featured' => $featured ?: null, 'lat' => $lat, 'lng' => $lng, 'sort_dir' => $sortDir, 'per_page' => $perPage,
            ], $sortBy, $total, $slice->map(fn($r) => $r['venue']->id)->all(), $page);
        }

        return ['paginator' => $paginator, 'understood' => $understood, 'search_id' => $searchId, 'sort' => $sortBy];
    }

    /** Optional `Authorization: Bearer <jwt>` user; never throws, never rejects. */
    public static function resolveUser(Request $request): ?User
    {
        try {
            $header = (string) $request->header('Authorization', '');
            if (!str_starts_with($header, 'Bearer ')) {
                return null;
            }
            $token = trim(substr($header, 7));
            if ($token === '') {
                return null;
            }
            $user = app(JwtService::class)->validate($token);
            return $user && $user->is_active ? $user : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function parse(?string $q): array
    {
        $empty = [
            'raw' => $q ?? '', 'terms' => [], 'city' => null, 'event_type' => null, 'guests' => null,
            'max_price' => null, 'min_price' => null, 'intents' => [], 'amenities' => [], 'chips' => [],
        ];
        if ($q === null) {
            return $empty;
        }
        try {
            if (class_exists(SearchQueryParser::class)) {
                return array_merge($empty, (array) (new SearchQueryParser())->parse($q));
            }
        } catch (Throwable $e) {
            report($e);
        }
        // Fallback: plain keywords.
        $empty['terms'] = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [],
            fn($t) => mb_strlen($t) >= 2
        ));
        return $empty;
    }

    private function log(string $searchId, ?User $user, ?string $q, array $understood, array $filters, string $sort, int $total, array $venueIds, int $page): void
    {
        try {
            $now = now();
            DB::table('search_logs')->insert([
                'search_id' => $searchId,
                'user_id' => $user?->id,
                'query' => $q !== null ? mb_substr($q, 0, 500) : null,
                'understood' => json_encode($understood),
                'filters' => json_encode(array_filter($filters, fn($v) => $v !== null)),
                'sort' => $sort,
                'result_count' => $total,
                'venue_ids' => json_encode(array_values($venueIds)),
                'page' => min($page, 65535),
                'created_at' => $now,
            ]);

            if ($venueIds) {
                DB::table('venue_search_stats')->upsert(
                    array_map(fn($id) => ['venue_id' => $id, 'impressions' => 1, 'clicks' => 0, 'created_at' => $now, 'updated_at' => $now], $venueIds),
                    ['venue_id'],
                    ['impressions' => DB::raw('impressions + 1'), 'updated_at' => $now]
                );
            }
        } catch (Throwable $e) {
            // Analytics must never break search.
            report($e);
        }
    }

    private function str(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function posInt(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    private function posFloat(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }
}
