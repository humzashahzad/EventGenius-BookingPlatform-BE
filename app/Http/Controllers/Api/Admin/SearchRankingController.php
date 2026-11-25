<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Search\SearchSettings;
use App\Services\Search\VenueSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin "Search & Ranking": tune ranking weights, preview results, boost/bury venues, view search analytics.
 *
 * Metric definitions (all percentages are 0..100, one decimal):
 *  - searches         = search_logs rows with page = 1 (later pages of the same search are not re-counted)
 *  - unique_queries   = distinct LOWER(TRIM(query)) among those searches (typed queries only)
 *  - clicks           = search_clicks rows in the window
 *  - ctr (totals / top_queries) = searches with at least one click (same search_id) ÷ searches
 *  - ctr (venues / top_clicked_venues) = venue_search_stats.clicks ÷ impressions (all-time)
 *  - zero_result_rate = searches with result_count = 0 ÷ searches
 *  - understood_breakdown.* = typed searches whose parsed query contained that detail ÷ typed searches
 */
class SearchRankingController extends Controller
{
    private const SIGNALS = SearchSettings::SIGNALS;

    /** GET /admin/search/settings */
    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => SearchSettings::get(),
                'defaults' => SearchSettings::defaults(),
                'presets' => SearchSettings::presets(),
            ],
        ]);
    }

    /** PUT /admin/search/settings — partial settings, or {preset: "balanced"} */
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate(array_merge(
            ['preset' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', array_keys(SearchSettings::presets()))]],
            $this->settingsRules(''),
        ));

        $partial = $this->settingsPartial($request->all());
        if ($request->filled('preset')) {
            $partial['preset'] = $request->input('preset');
        }

        return response()->json([
            'success' => true,
            'data' => ['settings' => SearchSettings::save($partial)],
            'message' => 'Ranking settings saved.',
        ]);
    }

    /** POST /admin/search/preview — rank with (possibly unsaved) settings; never logged */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(array_merge([
            'q' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'as_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'settings' => ['nullable', 'array'],
        ], $this->settingsRules('settings.')));

        // Same merge + clamp as SearchSettings::save(), without persisting.
        $current = SearchSettings::get();
        $partial = $this->settingsPartial((array) $request->input('settings', []));
        if (isset($partial['weights'])) {
            $partial['weights'] = array_merge($current['weights'], $partial['weights']);
        }
        $merged = SearchSettings::normalize(array_merge($current, $partial));

        $params = array_filter(
            $request->only(['q', 'city', 'event_type', 'guests', 'max_price', 'min_price', 'lat', 'lng']),
            fn ($v) => $v !== null && $v !== '',
        );
        $params += ['sort_by' => 'best_match', 'page' => 1, 'per_page' => 20];

        $asUser = $request->filled('as_user_id') ? User::find($request->integer('as_user_id')) : null;

        $result = (new VenueSearch)->run($params, $asUser, ['log' => false, 'explain' => true, 'settings' => $merged]);

        $results = collect($result['paginator']->items())->take(20)->map(function ($item) {
            $v = is_array($item) ? $item : (array) $item;
            $match = (array) ($v['match'] ?? []);
            $primary = collect($v['images'] ?? [])->first();

            return [
                'venue' => [
                    'id' => (int) $v['id'],
                    'name' => $v['name'] ?? '',
                    'city' => $v['city'] ?? null,
                    'thumbnail' => $v['thumbnail'] ?? ($primary['path'] ?? null),
                    'price_per_head' => isset($v['price_per_head']) ? (float) $v['price_per_head'] : null,
                    'avg_rating' => isset($v['avg_rating']) ? (float) $v['avg_rating'] : null,
                    'total_reviews' => (int) ($v['total_reviews'] ?? 0),
                    'is_featured' => (bool) ($v['is_featured'] ?? false),
                    'search_boost' => (int) ($v['search_boost'] ?? 0),
                ],
                'score' => round((float) ($match['score'] ?? 0), 1),
                'signals' => (object) ($match['signals'] ?? []),
                'contributions' => (object) ($match['contributions'] ?? []),
                'reasons' => array_values((array) ($match['reasons'] ?? [])),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'understood' => $result['understood'],
                'results' => $results,
                'total' => $result['paginator']->total(),
                'settings' => $merged,
                'as_user' => $asUser ? ['id' => $asUser->id, 'name' => $asUser->name, 'role' => $asUser->role] : null,
            ],
        ]);
    }

    /** GET /admin/search/analytics?days=7|30|90 */
    public function analytics(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $days = $request->integer('days', 30) ?: 30;
        $since = Carbon::today()->subDays($days - 1);

        // A search = its first page; clicked = at least one click carrying the same search_id.
        $searches = fn () => DB::table('search_logs as l')->where('l.page', 1)->where('l.created_at', '>=', $since);
        $clickedExpr = 'EXISTS (SELECT 1 FROM search_clicks c WHERE c.search_id = l.search_id)';
        $normQuery = 'LOWER(TRIM(l.query))';

        $t = $searches()->selectRaw("
            COUNT(*) AS searches,
            COUNT(DISTINCT CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' THEN {$normQuery} END) AS unique_queries,
            SUM(CASE WHEN l.result_count = 0 THEN 1 ELSE 0 END) AS zero_results,
            SUM(CASE WHEN {$clickedExpr} THEN 1 ELSE 0 END) AS clicked,
            SUM(CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' THEN 1 ELSE 0 END) AS typed,
            SUM(CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' AND {$this->jsonSet('city')} THEN 1 ELSE 0 END) AS u_city,
            SUM(CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' AND {$this->jsonSet('event_type')} THEN 1 ELSE 0 END) AS u_event,
            SUM(CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' AND {$this->jsonSet('guests')} THEN 1 ELSE 0 END) AS u_guests,
            SUM(CASE WHEN l.query IS NOT NULL AND TRIM(l.query) <> '' AND ({$this->jsonSet('max_price')} OR {$this->jsonSet('min_price')}) THEN 1 ELSE 0 END) AS u_budget
        ")->first();

        $n = (int) $t->searches;
        $typed = (int) $t->typed;
        $clicks = DB::table('search_clicks')->where('created_at', '>=', $since)->count();

        // Per day, gaps filled with zeros.
        $searchDays = $searches()->selectRaw('DATE(l.created_at) AS d, COUNT(*) AS n')->groupBy('d')->pluck('n', 'd');
        $clickDays = DB::table('search_clicks')->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')->groupBy('d')->pluck('n', 'd');
        $perDay = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $since->copy()->addDays($i)->toDateString();
            $perDay[] = ['date' => $d, 'searches' => (int) ($searchDays[$d] ?? 0), 'clicks' => (int) ($clickDays[$d] ?? 0)];
        }

        $topQueries = $searches()
            ->whereNotNull('l.query')->whereRaw("TRIM(l.query) <> ''")
            ->selectRaw("{$normQuery} AS q, COUNT(*) AS cnt, AVG(l.result_count) AS avg_results, SUM(CASE WHEN {$clickedExpr} THEN 1 ELSE 0 END) AS clicked")
            ->groupBy('q')->orderByDesc('cnt')->orderBy('q')->limit(15)->get()
            ->map(fn ($r) => [
                'query' => $r->q,
                'count' => (int) $r->cnt,
                'avg_results' => round((float) $r->avg_results, 1),
                'ctr' => $this->pct((int) $r->clicked, (int) $r->cnt),
            ]);

        $zeroQueries = $searches()
            ->where('l.result_count', 0)->whereNotNull('l.query')->whereRaw("TRIM(l.query) <> ''")
            ->selectRaw("{$normQuery} AS q, COUNT(*) AS cnt, MAX(l.created_at) AS last_at")
            ->groupBy('q')->orderByDesc('cnt')->orderByDesc('last_at')->limit(15)->get()
            ->map(fn ($r) => [
                'query' => $r->q,
                'count' => (int) $r->cnt,
                'last_at' => Carbon::parse($r->last_at)->toIso8601String(),
            ]);

        $topVenues = DB::table('search_clicks as c')
            ->join('venues as v', 'v.id', '=', 'c.venue_id')
            ->leftJoin('venue_search_stats as s', 's.venue_id', '=', 'c.venue_id')
            ->where('c.created_at', '>=', $since)
            ->selectRaw('c.venue_id, v.name, COUNT(*) AS clicks, MAX(COALESCE(s.impressions, 0)) AS impressions, MAX(COALESCE(s.clicks, 0)) AS all_clicks')
            ->groupBy('c.venue_id', 'v.name')->orderByDesc('clicks')->limit(10)->get()
            ->map(fn ($r) => [
                'venue_id' => (int) $r->venue_id,
                'name' => $r->name,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr' => $this->pct((int) $r->all_clicks, (int) $r->impressions),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'days' => $days,
                'since' => $since->toDateString(),
                'totals' => [
                    'searches' => $n,
                    'unique_queries' => (int) $t->unique_queries,
                    'clicks' => $clicks,
                    'ctr' => $this->pct((int) $t->clicked, $n),
                    'zero_result_rate' => $this->pct((int) $t->zero_results, $n),
                ],
                'per_day' => $perDay,
                'top_queries' => $topQueries,
                'zero_result_queries' => $zeroQueries,
                'top_clicked_venues' => $topVenues,
                'understood_breakdown' => [
                    'city' => $this->pct((int) $t->u_city, $typed),
                    'event_type' => $this->pct((int) $t->u_event, $typed),
                    'guests' => $this->pct((int) $t->u_guests, $typed),
                    'budget' => $this->pct((int) $t->u_budget, $typed),
                ],
            ],
        ]);
    }

    /** GET /admin/search/venues */
    public function venues(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->venueRows()->get()->map(fn ($r) => $this->formatVenue($r))]);
    }

    /** PATCH /admin/search/venues/{id}/boost */
    public function boost(Request $request, string $id): JsonResponse
    {
        $request->validate(['search_boost' => ['required', 'numeric']]);
        $boost = max(-100, min(100, (int) round((float) $request->input('search_boost'))));

        $updated = DB::table('venues')->where('id', $id)->whereNull('deleted_at')->update(['search_boost' => $boost]);
        $row = $this->venueRows()->where('v.id', $id)->first();
        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatVenue($row),
            'message' => $boost === 0 ? 'Search boost cleared.' : ($boost > 0 ? 'Venue boosted.' : 'Venue buried.'),
            'changed' => (bool) $updated,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function venueRows()
    {
        return DB::table('venues as v')
            ->leftJoin('stores as st', 'st.id', '=', 'v.store_id')
            ->leftJoin('venue_search_stats as s', 's.venue_id', '=', 'v.id')
            ->whereNull('v.deleted_at')
            ->select([
                'v.id', 'v.name', 'v.city', 'st.name as store_name', 'v.status', 'v.is_featured', 'v.search_boost',
                'v.avg_rating', 'v.total_reviews',
                DB::raw('COALESCE(s.impressions, 0) AS impressions'),
                DB::raw('COALESCE(s.clicks, 0) AS clicks'),
            ])
            ->orderBy('v.name');
    }

    private function formatVenue(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'name' => $r->name,
            'city' => $r->city,
            'store_name' => $r->store_name,
            'status' => $r->status,
            'is_featured' => (bool) $r->is_featured,
            'search_boost' => (int) $r->search_boost,
            'avg_rating' => $r->avg_rating !== null ? round((float) $r->avg_rating, 2) : null,
            'total_reviews' => (int) $r->total_reviews,
            'impressions' => (int) $r->impressions,
            'clicks' => (int) $r->clicks,
            'ctr' => $this->pct((int) $r->clicks, (int) $r->impressions),
        ];
    }

    /** Validation rules for a (partial) settings object under $prefix. */
    private function settingsRules(string $prefix): array
    {
        $rules = [
            "{$prefix}weights" => ['sometimes', 'nullable', 'array'],
            "{$prefix}featured_boost" => ['sometimes', 'nullable', 'numeric'],
            "{$prefix}personalization" => ['sometimes', 'nullable', 'boolean'],
            "{$prefix}learning" => ['sometimes', 'nullable', 'boolean'],
            "{$prefix}exploration" => ['sometimes', 'nullable', 'boolean'],
            "{$prefix}rating_prior_reviews" => ['sometimes', 'nullable', 'numeric'],
            "{$prefix}popularity_window_days" => ['sometimes', 'nullable', 'numeric'],
            "{$prefix}distance_half_km" => ['sometimes', 'nullable', 'numeric'],
        ];
        foreach (self::SIGNALS as $s) {
            $rules["{$prefix}weights.{$s}"] = ['sometimes', 'nullable', 'numeric'];
        }

        return $rules; // values are clamped by SearchSettings::normalize()
    }

    /** Keep only known settings keys (and known weight names), dropping nulls. */
    private function settingsPartial(array $input): array
    {
        $keys = ['featured_boost', 'personalization', 'learning', 'exploration', 'rating_prior_reviews', 'popularity_window_days', 'distance_half_km'];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== null) {
                $out[$k] = $input[$k];
            }
        }
        if (is_array($input['weights'] ?? null)) {
            $w = array_filter(
                array_intersect_key($input['weights'], array_flip(self::SIGNALS)),
                fn ($v) => $v !== null && is_numeric($v),
            );
            if ($w) {
                $out['weights'] = $w;
            }
        }

        return $out;
    }

    /** SQL: a parsed field in search_logs.understood is present (not missing, not JSON null). */
    private function jsonSet(string $field): string
    {
        return "(JSON_EXTRACT(l.understood, '$.{$field}') IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(l.understood, '$.{$field}')) <> 'NULL')";
    }

    private function pct(int $num, int $den): float
    {
        return $den > 0 ? round($num / $den * 100, 1) : 0.0;
    }
}
