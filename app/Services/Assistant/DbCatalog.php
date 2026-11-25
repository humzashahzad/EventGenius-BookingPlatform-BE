<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\VenueReviewAggregator;
use App\Services\Search\SearchQueryParser;
use App\Services\Search\VenueSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Live catalog: smart search (VenueSearch), venue rows, review insights and bookings. */
class DbCatalog implements Catalog
{
    public const FACTS_KEY = 'assistant.facts.v1';
    private const FACTS_TTL = 600;

    private const SORTS = [
        'price_asc' => ['price_per_head', 'asc'],
        'price_desc' => ['price_per_head', 'desc'],
        'rating' => ['avg_rating', 'desc'],
        'newest' => ['created_at', 'desc'],
    ];

    /** Address words too generic to identify an area. */
    private const GENERIC_PLACE_WORDS = ['road', 'town', 'phase', 'block', 'area', 'colony', 'market', 'commercial', 'sector',
        'street', 'tehsil', 'district', 'division', 'punjab', 'pakistan', 'sindh', 'near', 'main', 'new', 'old', 'the', 'and',
        'scheme', 'society', 'housing', 'city', 'chowk', 'bazaar', 'bazar', 'plaza', 'iii', 'iv', 'ii'];

    public function __construct(private ?VenueSearch $search = null) {}

    public function search(array $state, ?User $user, array $opts = []): array
    {
        [$sortBy, $sortDir] = self::SORTS[$state['sort'] ?? ''] ?? ['best_match', null];
        $params = array_filter([
            'q' => $opts['query'] ?? null,
            'city' => $state['city'] ?? null,
            'event_type' => $state['event_type'] ?? null,
            'guests' => $state['guests'] ?? null,
            'max_price' => $state['max_price'] ?? null,
            'min_price' => $state['min_price'] ?? null,
            'min_capacity' => $state['min_capacity'] ?? null,
            'max_capacity' => $state['max_capacity'] ?? null,
            'min_rating' => $state['min_rating'] ?? null,
            'amenities' => ($state['amenities'] ?? []) ?: null,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'page' => $opts['page'] ?? 1,
            'per_page' => $opts['per_page'] ?? 4,
            'search_id' => $opts['search_id'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $understood = [
            'raw' => (string) ($opts['query'] ?? ''),
            'terms' => array_values($state['terms'] ?? []),
            'city' => $state['city'] ?? null,
            'event_type' => $state['event_type'] ?? null,
            'guests' => $state['guests'] ?? null,
            'max_price' => $state['max_price'] ?? null,
            'min_price' => $state['min_price'] ?? null,
            'intents' => array_values($state['intents'] ?? []),
            'amenities' => array_values($state['amenities'] ?? []),
            'chips' => [],
        ];

        $result = ($this->search ?? new VenueSearch())->run($params, $user, [
            'log' => (bool) ($opts['log'] ?? false),
            'understood' => $understood,
        ]);
        $paginator = $result['paginator'];

        return [
            'items' => array_map(fn($v) => self::compact($v), $paginator->items()),
            'total' => $paginator->total(),
            'search_id' => $result['search_id'],
        ];
    }

    public function venue(int $id): ?array
    {
        $venue = Venue::with(['amenities:id,venue_id,name,is_paid,price', 'images' => fn($q) => $q->where('is_primary', true)])
            ->where('status', 'active')
            ->whereHas('store', fn($s) => $s->where('status', 'approved'))
            ->find($id);
        if (!$venue) {
            return null;
        }
        $row = $venue->toArray();
        return self::compact($row) + [
            'description' => (string) ($row['description'] ?? ''),
            'event_types' => array_values((array) ($row['event_types'] ?? [])),
            'operating_start' => $row['operating_start'] ? substr((string) $row['operating_start'], 0, 5) : null,
            'operating_end' => $row['operating_end'] ? substr((string) $row['operating_end'], 0, 5) : null,
            'amenities' => array_map(fn($a) => [
                'name' => (string) $a['name'],
                'is_paid' => (bool) $a['is_paid'],
                'price' => $a['price'] !== null ? (float) $a['price'] : null,
            ], $row['amenities'] ?? []),
        ];
    }

    public function reviewSummary(int $id): array
    {
        try {
            return (new VenueReviewAggregator())->summary($id);
        } catch (Throwable $e) {
            report($e);
            return ['analysed_count' => 0, 'sentiment' => null, 'label' => null, 'aspects' => [], 'highlights' => [], 'concerns' => []];
        }
    }

    public function bookingsOn(array $venueIds, string $date): array
    {
        if (!$venueIds) {
            return [];
        }
        $out = [];
        DB::table('bookings')
            ->whereIn('venue_id', $venueIds)
            ->whereDate('event_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('start_time')
            ->get(['venue_id', 'start_time', 'end_time'])
            ->each(function ($b) use (&$out) {
                $out[(int) $b->venue_id][] = [
                    'start' => substr((string) $b->start_time, 0, 5),
                    'end' => substr((string) $b->end_time, 0, 5),
                ];
            });
        return $out;
    }

    public function facts(): array
    {
        return Cache::remember(self::FACTS_KEY, self::FACTS_TTL, fn() => $this->buildFacts());
    }

    private function buildFacts(): array
    {
        $venues = DB::table('venues')
            ->join('stores', 'stores.id', '=', 'venues.store_id')
            ->where('venues.status', 'active')
            ->where('stores.status', 'approved')
            ->whereNull('venues.deleted_at')
            ->whereNull('stores.deleted_at')
            ->get(['venues.id', 'venues.name', 'venues.city', 'venues.address', 'venues.price_per_head',
                'venues.capacity_max', 'venues.event_types']);

        $cities = [];
        $byCategory = [];
        $prices = [];
        $cityPrices = [];
        $places = [];
        $maxCapacity = 0;
        foreach ($venues as $v) {
            $city = trim((string) $v->city);
            if ($city !== '') {
                $cities[$city] = ($cities[$city] ?? 0) + 1;
            }
            foreach ((array) json_decode((string) $v->event_types, true) as $slug) {
                if (is_string($slug) && $city !== '') {
                    $byCategory[$slug][$city] = ($byCategory[$slug][$city] ?? 0) + 1;
                }
            }
            if ((float) $v->price_per_head > 0) {
                $prices[] = (float) $v->price_per_head;
                $cityPrices[$city][] = (float) $v->price_per_head;
            }
            $maxCapacity = max($maxCapacity, (int) $v->capacity_max);
            $area = mb_strtolower(trim(explode(',', (string) $v->address)[0]));
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $area) ?: [] as $word) {
                if (mb_strlen($word) >= 3 && !preg_match('/^\d+$/', $word) && !in_array($word, self::GENERIC_PLACE_WORDS, true)) {
                    $places[$word] = true;
                }
            }
        }
        arsort($cities);
        foreach ($byCategory as &$list) {
            arsort($list);
        }
        unset($list);
        sort($prices);
        $pricesByCity = [];
        foreach ($cityPrices as $city => $list) {
            sort($list);
            $pricesByCity[$city] = self::percentiles($list);
        }

        $vocabulary = SearchQueryParser::vocabulary();
        $categories = [];
        foreach ($vocabulary['categories'] as $c) {
            $count = array_sum($byCategory[$c['slug']] ?? []);
            if ($count > 0) {
                $categories[] = ['slug' => $c['slug'], 'name' => $c['name'], 'count' => $count];
            }
        }
        usort($categories, fn($a, $b) => $b['count'] <=> $a['count']);

        return [
            'cities' => array_map(fn($name, $count) => ['name' => $name, 'count' => $count], array_keys($cities), $cities),
            'categories' => $categories,
            'city_by_category' => $byCategory,
            'prices' => self::percentiles($prices),
            'prices_by_city' => $pricesByCity,
            'max_capacity' => $maxCapacity,
            'venues' => $venues->map(fn($v) => ['id' => (int) $v->id, 'name' => (string) $v->name, 'city' => (string) $v->city])->all(),
            'places' => array_keys($places),
            'vocabulary' => $vocabulary,
        ];
    }

    public static function percentiles(array $sorted): array
    {
        if (!$sorted) {
            return ['p25' => null, 'p50' => null, 'p75' => null, 'min' => null, 'max' => null];
        }
        $at = fn(float $q) => $sorted[(int) floor($q * (count($sorted) - 1))];
        return ['p25' => $at(0.25), 'p50' => $at(0.5), 'p75' => $at(0.75), 'min' => $sorted[0], 'max' => end($sorted)];
    }

    /** Small venue object for the chat (also used by the in-memory test catalog). */
    public static function compact(array $v): array
    {
        $images = is_array($v['images'] ?? null) ? $v['images'] : [];
        $primary = null;
        foreach ($images as $img) {
            if (!empty($img['is_primary'])) {
                $primary = $img['path'] ?? null;
                break;
            }
        }
        $primary ??= $images[0]['path'] ?? null;
        $address = trim((string) ($v['address'] ?? ''));
        $city = trim((string) ($v['city'] ?? ''));
        $area = trim(explode(',', $address)[0]);

        return [
            'id' => (int) $v['id'],
            'name' => (string) $v['name'],
            'city' => $city,
            'area' => $area !== '' && mb_strtolower($area) !== mb_strtolower($city) ? $area : null,
            'address' => $address,
            'thumbnail' => $primary ?: ($v['thumbnail'] ?? null),
            'price_per_head' => isset($v['price_per_head']) && $v['price_per_head'] !== null ? (float) $v['price_per_head'] : null,
            'capacity_min' => isset($v['capacity_min']) ? (int) $v['capacity_min'] : null,
            'capacity_max' => isset($v['capacity_max']) ? (int) $v['capacity_max'] : null,
            'avg_rating' => round((float) ($v['avg_rating'] ?? 0), 2),
            'total_reviews' => (int) ($v['total_reviews'] ?? 0),
            'is_featured' => (bool) ($v['is_featured'] ?? false),
            'event_types' => array_values((array) ($v['event_types'] ?? [])),
            'match' => [
                'score' => isset($v['match']['score']) ? (float) $v['match']['score'] : null,
                'reasons' => array_values((array) ($v['match']['reasons'] ?? [])),
            ],
        ];
    }
}
