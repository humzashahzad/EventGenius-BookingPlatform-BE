<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Services\Assistant\Catalog;
use App\Services\Assistant\DbCatalog;

/** In-memory venues for assistant tests: same filtering rules as VenueSearch, simple ranking. */
class FakeAssistantCatalog implements Catalog
{
    /** @var array<int, array{state: array, opts: array}> */
    public array $searches = [];

    public array $venues;
    public array $summaries;
    public array $bookings;

    public function __construct(?array $venues = null, ?array $summaries = null, ?array $bookings = null)
    {
        $this->venues = $venues ?? self::defaultVenues();
        $this->summaries = $summaries ?? self::defaultSummaries();
        $this->bookings = $bookings ?? ['1|2027-03-12' => [['start' => '18:00', 'end' => '22:00']]];
    }

    public function search(array $state, ?User $user, array $opts = []): array
    {
        $this->searches[] = ['state' => $state, 'opts' => $opts];
        $list = array_filter($this->venues, function ($v) use ($state) {
            if ($state['city'] && $v['city'] !== $state['city']) {
                return false;
            }
            if ($state['event_type'] && !in_array($state['event_type'], $v['event_types'], true)) {
                return false;
            }
            if ($state['guests'] && $v['capacity_max'] < $state['guests']) {
                return false;
            }
            if ($state['max_price'] && $v['price_per_head'] > $state['max_price'] * 1.15) {
                return false;
            }
            if (!empty($state['min_capacity']) && $v['capacity_max'] < $state['min_capacity']) {
                return false;
            }
            if (!empty($state['max_capacity']) && $v['capacity_max'] > $state['max_capacity']) {
                return false;
            }
            if (!empty($state['min_rating']) && ($v['total_reviews'] === 0 || $v['avg_rating'] < $state['min_rating'])) {
                return false;
            }
            $have = array_map(fn($a) => strtolower($a['name']), $v['amenities']);
            foreach ($state['amenities'] ?? [] as $a) {
                if (!in_array(strtolower($a), $have, true)) {
                    return false;
                }
            }
            return true;
        });
        // Terms are soft: keep matching venues only if any match (like VenueSearch).
        // Venue-type words ("hall") match almost every venue in the real ranker; only places filter here.
        $terms = array_diff($state['terms'] ?? [], ['hall', 'marquee', 'ballroom', 'banquet', 'lawn']);
        if ($terms) {
            $matching = array_filter($list, function ($v) use ($terms) {
                $text = strtolower($v['name'] . ' ' . $v['address']);
                foreach ($terms as $t) {
                    if (str_contains($text, strtolower(rtrim($t, 's')))) {
                        return true;
                    }
                }
                return false;
            });
            $list = $matching ?: $list;
        }
        $list = array_values($list);
        $sort = $state['sort'] ?? 'best_match';
        usort($list, match ($sort) {
            'price_asc' => fn($a, $b) => [$a['price_per_head'], $a['id']] <=> [$b['price_per_head'], $b['id']],
            'price_desc' => fn($a, $b) => [$b['price_per_head'], $a['id']] <=> [$a['price_per_head'], $b['id']],
            default => in_array('cheap', $state['intents'] ?? [], true)
                ? fn($a, $b) => [$a['price_per_head'], $a['id']] <=> [$b['price_per_head'], $b['id']]
                : fn($a, $b) => [$b['avg_rating'], $a['id']] <=> [$a['avg_rating'], $b['id']],
        });

        $page = max(1, (int) ($opts['page'] ?? 1));
        $per = (int) ($opts['per_page'] ?? 4);
        $slice = array_slice($list, ($page - 1) * $per, $per);
        return [
            'items' => array_map(fn($v) => DbCatalog::compact($v + ['match' => ['score' => 80, 'reasons' => ['Test reason']]]), $slice),
            'total' => count($list),
            'search_id' => !empty($opts['log']) ? ($opts['search_id'] ?? 'search-' . count($this->searches)) : null,
        ];
    }

    public function venue(int $id): ?array
    {
        foreach ($this->venues as $v) {
            if ($v['id'] === $id) {
                return DbCatalog::compact($v) + [
                    'description' => '', 'event_types' => $v['event_types'],
                    'operating_start' => '12:00', 'operating_end' => '23:00', 'amenities' => $v['amenities'],
                ];
            }
        }
        return null;
    }

    public function reviewSummary(int $id): array
    {
        return $this->summaries[$id] ?? ['analysed_count' => 0, 'sentiment' => null, 'label' => null, 'aspects' => [], 'highlights' => [], 'concerns' => []];
    }

    public function bookingsOn(array $venueIds, string $date): array
    {
        $out = [];
        foreach ($venueIds as $id) {
            if (isset($this->bookings[$id . '|' . $date])) {
                $out[$id] = $this->bookings[$id . '|' . $date];
            }
        }
        return $out;
    }

    public function facts(): array
    {
        $cities = [];
        $byCat = [];
        $prices = [];
        $places = [];
        foreach ($this->venues as $v) {
            $cities[$v['city']] = ($cities[$v['city']] ?? 0) + 1;
            foreach ($v['event_types'] as $slug) {
                $byCat[$slug][$v['city']] = ($byCat[$slug][$v['city']] ?? 0) + 1;
            }
            $prices[] = $v['price_per_head'];
            foreach (preg_split('/[^a-z0-9]+/', strtolower(explode(',', $v['address'])[0])) as $w) {
                if (strlen($w) >= 3 && !in_array($w, ['road', 'area', 'block', 'phase', 'town', 'bank'], true)) {
                    $places[$w] = true;
                }
            }
        }
        arsort($cities);
        sort($prices);
        $slugs = ['weddings', 'birthday_parties', 'corporate_events', 'conferences', 'engagement_parties', 'concerts_music_events'];
        $categories = [];
        foreach ($slugs as $slug) {
            if (!empty($byCat[$slug])) {
                $categories[] = ['slug' => $slug, 'name' => ucwords(str_replace('_', ' ', $slug)), 'count' => array_sum($byCat[$slug])];
            }
        }
        return [
            'cities' => array_map(fn($n, $c) => ['name' => $n, 'count' => $c], array_keys($cities), $cities),
            'categories' => $categories,
            'city_by_category' => $byCat,
            'prices' => DbCatalog::percentiles($prices),
            'prices_by_city' => [],
            'max_capacity' => max(array_column($this->venues, 'capacity_max')),
            'venues' => array_map(fn($v) => ['id' => $v['id'], 'name' => $v['name'], 'city' => $v['city']], $this->venues),
            'places' => array_keys($places),
            'vocabulary' => [
                'cities' => array_keys($cities),
                'categories' => array_map(fn($s) => ['slug' => $s, 'name' => ucwords(str_replace('_', ' ', $s))], $slugs),
                'amenities' => ['Air Conditioning', 'Valet Parking', 'Generator Backup', 'Photography', 'Sound System'],
            ],
        ];
    }

    public static function defaultVenues(): array
    {
        $free = fn(string $n) => ['name' => $n, 'is_paid' => false, 'price' => null];
        $paid = fn(string $n, float $p) => ['name' => $n, 'is_paid' => true, 'price' => $p];
        $v = fn(int $id, string $name, string $address, string $city, float $pph, int $min, int $max, float $rating, int $reviews, array $types, array $amenities = []) => [
            'id' => $id, 'name' => $name, 'address' => $address, 'city' => $city, 'price_per_head' => $pph,
            'capacity_min' => $min, 'capacity_max' => $max, 'avg_rating' => $rating, 'total_reviews' => $reviews,
            'event_types' => $types, 'amenities' => $amenities, 'thumbnail' => "demo/{$id}.jpg",
        ];
        return [
            $v(1, 'Shalimar Grand Ballroom', 'Gulberg III, Lahore', 'Lahore', 4500, 300, 1000, 4.2, 78, ['weddings'],
                [$free('Air Conditioning'), $free('Valet Parking'), $paid('Photography', 45000)]),
            $v(2, 'Mughal Garden Marquee', 'Canal Bank Road, Lahore', 'Lahore', 3800, 200, 700, 4.1, 83, ['weddings', 'engagement_parties'],
                [$free('Generator Backup'), $paid('Sound System', 25000)]),
            $v(3, 'Royal Crystal Hall', 'DHA Phase 5, Lahore', 'Lahore', 5200, 100, 400, 4.4, 53, ['weddings', 'corporate_events'],
                [$free('Valet Parking')]),
            $v(4, 'Golden Pearl Ballroom', 'Blue Area, Lahore', 'Lahore', 2600, 160, 1000, 0, 0, ['weddings']),
            $v(5, 'Garden Town Business Hub', 'Garden Town, Lahore', 'Lahore', 3200, 110, 420, 0, 0, ['corporate_events', 'conferences']),
            $v(6, 'Seaview Convention Hall', 'Clifton Block 4, Karachi', 'Karachi', 2500, 100, 800, 4.2, 65, ['weddings', 'conferences'],
                [$free('Valet Parking')]),
            $v(7, 'Harbour Arena', 'Karsaz Road, Karachi', 'Karachi', 1800, 500, 3000, 4.0, 83, ['weddings', 'concerts_music_events']),
            $v(8, 'Clifton Rooftop Lounge', 'Clifton Block 5, Karachi', 'Karachi', 3200, 30, 150, 4.0, 61, ['birthday_parties']),
            $v(9, 'Daman Terrace', 'F-7 Markaz, Islamabad', 'Islamabad', 4000, 20, 120, 4.1, 54, ['birthday_parties']),
            $v(10, 'Capital Conference Centre', 'Blue Area, Islamabad', 'Islamabad', 2200, 50, 400, 3.9, 57, ['conferences']),
        ];
    }

    public static function defaultSummaries(): array
    {
        $aspect = fn(string $name, string $label, int $pos, int $neg, int $m) => ['name' => $name, 'label' => $label,
            'positive_pct' => $pos, 'negative_pct' => $neg, 'mentions' => $m];
        return [
            1 => ['analysed_count' => 72, 'sentiment' => 81, 'label' => 'positive',
                'aspects' => [$aspect('service', 'Service & staff', 73, 20, 44), $aspect('decor', 'Decor & ambience', 77, 10, 39),
                    $aspect('food', 'Food', 100, 0, 19), $aspect('cooling', 'AC & cooling', 47, 53, 15)],
                'highlights' => ['The stage and lighting looked stunning in photos', 'The food was delicious and served hot'],
                'concerns' => ['Air conditioning could have been stronger']],
            2 => ['analysed_count' => 77, 'sentiment' => 74, 'label' => 'positive',
                'aspects' => [$aspect('service', 'Service & staff', 74, 19, 53), $aspect('food', 'Food', 92, 0, 24),
                    $aspect('parking', 'Parking', 35, 41, 17)],
                'highlights' => ['Staff were polite and very professional'],
                'concerns' => ['Parking got crowded after 9 PM']],
            3 => ['analysed_count' => 40, 'sentiment' => 85, 'label' => 'positive',
                'aspects' => [$aspect('decor', 'Decor & ambience', 90, 0, 20)],
                'highlights' => ['Beautiful decor'], 'concerns' => []],
        ];
    }
}
