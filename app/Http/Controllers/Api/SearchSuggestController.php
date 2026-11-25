<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Search\SearchQueryParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SearchSuggestController extends Controller
{
    private const MAX = 8;

    private const EXAMPLES = [
        'Wedding hall in Lahore for 300 guests',
        'Birthday venue in Karachi under 2500',
        'Conference hall in Islamabad',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->get('q', '')), 0, 200);
        $parser = new SearchQueryParser();
        $understood = $parser->parse($q);
        $vocabulary = SearchQueryParser::vocabulary();
        $categoryNames = array_column($vocabulary['categories'], 'name', 'slug');
        [$cityCounts, $categoryCounts] = $this->popularity();

        $suggestions = mb_strlen($q) < 2
            ? $this->defaults($vocabulary, $categoryNames, $cityCounts, $categoryCounts)
            : $this->forQuery($q, $understood, $vocabulary, $categoryNames, $cityCounts, $categoryCounts);

        return response()->json([
            'success' => true,
            'data' => [
                'understood' => $understood,
                'suggestions' => array_slice($suggestions, 0, self::MAX),
            ],
        ]);
    }

    /** Empty / 1-char box: example queries, then the busiest categories and cities. */
    private function defaults(array $vocabulary, array $categoryNames, array $cityCounts, array $categoryCounts): array
    {
        $out = array_map(fn($e) => ['type' => 'query', 'label' => $e, 'value' => $e], self::EXAMPLES);

        foreach (array_slice($this->rankedCategories($categoryNames, $categoryCounts), 0, 3) as $slug) {
            $out[] = ['type' => 'category', 'label' => $categoryNames[$slug], 'value' => $slug];
        }
        foreach (array_slice($this->rankedCities($vocabulary['cities'], $cityCounts), 0, self::MAX - count($out)) as $city) {
            $out[] = ['type' => 'city', 'label' => 'Venues in ' . $city, 'value' => $city];
        }

        return $out;
    }

    private function forQuery(string $q, array $u, array $vocabulary, array $categoryNames, array $cityCounts, array $categoryCounts): array
    {
        $out = [];
        $seen = [];
        $push = function (array $s) use (&$out, &$seen) {
            $key = $s['type'] . '|' . mb_strtolower((string) $s['value']);
            if (!isset($seen[$key]) && count($out) < self::MAX) {
                $seen[$key] = true;
                $out[] = $s;
            }
        };

        // 1. What we understood, rewritten as a clean query.
        $understoodSomething = $u['city'] || $u['event_type'] || $u['guests'] || $u['max_price'] || $u['min_price']
            || $u['intents'] || $u['amenities'];
        if ($understoodSomething) {
            $push($this->reformulate($u, $categoryNames));
        }

        // 2. Venue names (prefix matches first).
        foreach ($this->venues($q, $u['terms'], $u['city']) as $venue) {
            $push(['type' => 'venue', 'label' => $venue->name, 'value' => $venue->name,
                'venue_id' => (int) $venue->id, 'city' => $venue->city]);
        }

        // 3. Completions for the word being typed.
        $last = mb_strtolower((string) preg_replace('/^.*\s/u', '', $q));
        $needle = mb_strlen($last) >= 2 ? $last : mb_strtolower($q);

        if ($u['city'] === null) {
            foreach ($this->rankedCities($vocabulary['cities'], $cityCounts) as $city) {
                if (str_starts_with(mb_strtolower($city), $needle) || (mb_strlen($q) >= 3 && str_contains(mb_strtolower($city), mb_strtolower($q)))) {
                    $push(['type' => 'city', 'label' => 'Venues in ' . $city, 'value' => $city]);
                }
            }
        }
        if ($u['event_type'] === null) {
            foreach ($this->rankedCategories($categoryNames, $categoryCounts) as $slug) {
                $name = mb_strtolower($categoryNames[$slug]);
                if (str_starts_with($name, $needle) || str_contains($name, ' ' . $needle) || (mb_strlen($q) >= 3 && str_contains($name, mb_strtolower($q)))) {
                    $push(['type' => 'category', 'label' => $categoryNames[$slug], 'value' => $slug]);
                }
            }
        }

        // 4. Fill the gaps: an event type without a city → same event in the busiest cities, and vice versa.
        if ($u['event_type'] !== null && $u['city'] === null) {
            foreach (array_slice($this->rankedCities($vocabulary['cities'], $cityCounts), 0, 3) as $city) {
                $push($this->reformulate(array_merge($u, ['city' => $city]), $categoryNames));
            }
        } elseif ($u['city'] !== null && $u['event_type'] === null && !$u['terms']) {
            foreach (array_slice($this->rankedCategories($categoryNames, $categoryCounts), 0, 3) as $slug) {
                $push($this->reformulate(array_merge($u, ['event_type' => $slug]), $categoryNames));
            }
        }

        return $out;
    }

    /**
     * Builds a 'query' suggestion. `value` re-parses to the same understanding; `label` is the friendly form.
     */
    private function reformulate(array $u, array $categoryNames): array
    {
        $intentWords = ['top_rated' => 'top rated', 'popular' => 'popular', 'cheap' => 'cheap', 'luxury' => 'luxury', 'new' => 'new'];
        $lead = array_values(array_intersect_key($intentWords, array_flip($u['intents'])));

        // "Weddings" → "Wedding hall" / "Wedding venues"; no event type → "Rooftop" / "Venues".
        $what = $u['event_type'] !== null
            ? self::singular($categoryNames[$u['event_type']] ?? ucwords(str_replace('_', ' ', $u['event_type'])))
            : '';
        $terms = implode(' ', $u['terms']);
        $subject = $terms !== '' ? trim($what . ' ' . $terms) : trim($what . ' venues');
        if ($lead) {
            $subject = lcfirst($subject);
        }

        $parts = [];
        if ($u['city'] !== null) {
            $parts[] = 'in ' . $u['city'];
        }
        if (in_array('near_me', $u['intents'], true)) {
            $parts[] = 'near me';
        }
        if ($u['guests'] !== null) {
            $parts[] = 'for ' . $u['guests'] . ' guests';
        }
        if ($u['amenities']) {
            $parts[] = 'with ' . implode(' and ', $u['amenities']);
        }

        $value = $label = trim(implode(' ', $lead) . ' ' . $subject . ' ' . implode(' ', $parts));
        $money = fn($n) => number_format((float) $n);
        if ($u['min_price'] !== null && $u['max_price'] !== null) {
            $value .= ' between ' . (int) $u['min_price'] . ' and ' . (int) $u['max_price'];
            $label .= ' · PKR ' . $money($u['min_price']) . '–' . $money($u['max_price']) . ' / head';
        } elseif ($u['max_price'] !== null) {
            $value .= ' under ' . (int) $u['max_price'];
            $label .= ' · up to PKR ' . $money($u['max_price']) . ' / head';
        } elseif ($u['min_price'] !== null) {
            $value .= ' above ' . (int) $u['min_price'];
            $label .= ' · from PKR ' . $money($u['min_price']) . ' / head';
        }

        return ['type' => 'query', 'label' => ucfirst($label), 'value' => ucfirst($value)];
    }

    private static function singular(string $name): string
    {
        if (!str_contains($name, '&') && preg_match('/^(.*?)(ies|s)$/u', $name, $m) && !str_ends_with($name, 'ss')) {
            return $m[1] . ($m[2] === 'ies' ? 'y' : '');
        }
        return $name;
    }

    /** Public venues whose name matches the raw query, else any leftover keyword. One small query each. */
    private function venues(string $q, array $terms, ?string $city)
    {
        $base = fn() => DB::table('venues')
            ->join('stores', 'stores.id', '=', 'venues.store_id')
            ->where('venues.status', 'active')
            ->where('stores.status', 'approved')
            ->whereNull('venues.deleted_at')
            ->whereNull('stores.deleted_at')
            ->select('venues.id', 'venues.name', 'venues.city')
            ->limit(4);

        $escaped = addcslashes($q, '\\%_');
        $rows = $base()
            ->where('venues.name', 'like', '%' . $escaped . '%')
            ->orderByRaw('CASE WHEN venues.name LIKE ? THEN 0 ELSE 1 END', [$escaped . '%'])
            ->orderByDesc('venues.avg_rating')
            ->orderBy('venues.id')
            ->get();

        $terms = array_values(array_filter($terms, fn($t) => mb_strlen($t) >= 3));
        if ($rows->isEmpty() && $terms) {
            $rows = $base()
                ->where(function ($w) use ($terms) {
                    foreach (array_slice($terms, 0, 4) as $t) {
                        $w->orWhere('venues.name', 'like', '%' . addcslashes($t, '\\%_') . '%');
                    }
                })
                ->when($city !== null, fn($w) => $w->orderByRaw('venues.city = ? DESC', [$city]))
                ->orderByDesc('venues.avg_rating')
                ->orderBy('venues.id')
                ->limit(3)
                ->get();
        }

        return $rows;
    }

    /** Venue counts per city and per category slug across public venues (cached 10 min). */
    private function popularity(): array
    {
        return Cache::remember('search.suggest.popularity.v1', 600, function () {
            $rows = DB::table('venues')
                ->join('stores', 'stores.id', '=', 'venues.store_id')
                ->where('venues.status', 'active')
                ->where('stores.status', 'approved')
                ->whereNull('venues.deleted_at')
                ->whereNull('stores.deleted_at')
                ->get(['venues.city', 'venues.event_types']);

            $cities = [];
            $categories = [];
            foreach ($rows as $row) {
                $city = trim((string) $row->city);
                if ($city !== '') {
                    $cities[$city] = ($cities[$city] ?? 0) + 1;
                }
                $types = is_string($row->event_types) ? json_decode($row->event_types, true) : $row->event_types;
                foreach (is_array($types) ? $types : [] as $slug) {
                    if (is_string($slug)) {
                        $categories[$slug] = ($categories[$slug] ?? 0) + 1;
                    }
                }
            }

            return [$cities, $categories];
        });
    }

    private function rankedCities(array $cities, array $counts): array
    {
        usort($cities, fn($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0) ?: strcmp($a, $b));
        return $cities;
    }

    private function rankedCategories(array $names, array $counts): array
    {
        $slugs = array_keys($names);
        $order = array_flip($slugs);
        usort($slugs, fn($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0) ?: $order[$a] <=> $order[$b]);
        return $slugs;
    }
}
