<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicStoreController extends Controller
{
    /**
     * GET /stores — public directory of approved stores that have at least one active venue.
     * Query: q (name/area), city, event_type, lat+lng (adds distance_km, enables sort=distance),
     *        sort = rating|venues|reviews|newest|name|distance, page, per_page (1..48, default 12).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'event_type' => 'nullable|string|max:100',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'sort' => 'nullable|in:rating,venues,reviews,newest,name,distance',
            'per_page' => 'nullable|integer|min:1|max:48',
        ]);

        // Per-store figures from its public venues, in one aggregate query.
        $venueAgg = DB::table('venues')
            ->where('status', 'active')->whereNull('deleted_at')
            ->groupBy('store_id')
            ->selectRaw('store_id, COUNT(*) AS venue_count, MIN(price_per_head) AS price_from, MAX(price_per_head) AS price_to,
                MIN(capacity_min) AS capacity_min, MAX(capacity_max) AS capacity_max,
                SUM(total_reviews) AS total_reviews, SUM(total_bookings) AS total_bookings,
                SUM(avg_rating * total_reviews) / NULLIF(SUM(total_reviews), 0) AS avg_rating,
                MAX(is_featured) AS has_featured');

        $query = DB::table('stores')
            ->joinSub($venueAgg, 'va', 'va.store_id', '=', 'stores.id')
            ->leftJoin('store_landing_pages as lp', 'lp.store_id', '=', 'stores.id')
            ->where('stores.status', 'approved')->whereNull('stores.deleted_at')
            ->select('stores.id', 'stores.name', 'stores.slug', 'stores.description', 'stores.logo', 'stores.cover_image',
                'stores.address', 'stores.city', 'stores.state', 'stores.latitude', 'stores.longitude', 'stores.created_at',
                'lp.tagline', 'lp.hero_image', 'va.venue_count', 'va.price_from', 'va.price_to', 'va.capacity_min',
                'va.capacity_max', 'va.total_reviews', 'va.total_bookings', 'va.avg_rating', 'va.has_featured');

        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $query->where(fn ($w) => $w->where('stores.name', 'like', $like)
                ->orWhere('stores.address', 'like', $like)->orWhere('stores.city', 'like', $like));
        }
        if ($city = trim((string) $request->query('city', ''))) {
            $query->where('stores.city', $city);
        }
        if ($type = trim((string) $request->query('event_type', ''))) {
            $query->whereExists(fn ($e) => $e->select(DB::raw(1))->from('venues')
                ->whereColumn('venues.store_id', 'stores.id')->where('venues.status', 'active')->whereNull('venues.deleted_at')
                ->whereJsonContains('venues.event_types', $type));
        }

        $lat = $request->filled('lat') && $request->filled('lng') ? (float) $request->query('lat') : null;
        $lng = $lat !== null ? (float) $request->query('lng') : null;
        if ($lat !== null) {
            // Haversine distance in km (stores without coordinates sort last).
            $query->selectRaw('CASE WHEN stores.latitude IS NULL THEN NULL ELSE 6371 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(stores.latitude - ?) / 2), 2) +
                COS(RADIANS(?)) * COS(RADIANS(stores.latitude)) * POWER(SIN(RADIANS(stores.longitude - ?) / 2), 2))) END AS distance_km',
                [$lat, $lat, $lng]);
        }

        $sort = $request->query('sort') ?: ($lat !== null ? 'distance' : 'rating');
        if ($sort === 'distance' && $lat === null) {
            $sort = 'rating';
        }
        match ($sort) {
            'distance' => $query->orderByRaw('distance_km IS NULL')->orderBy('distance_km'),
            'venues' => $query->orderByDesc('va.venue_count')->orderByDesc('va.total_reviews'),
            'reviews' => $query->orderByDesc('va.total_reviews'),
            'newest' => $query->orderByDesc('stores.created_at'),
            'name' => $query->orderBy('stores.name'),
            // Rating weighted towards stores with more reviews (Bayesian, prior of 10 reviews at 4.0).
            default => $query->orderByRaw('(COALESCE(va.avg_rating, 0) * COALESCE(va.total_reviews, 0) + 40) / (COALESCE(va.total_reviews, 0) + 10) DESC'),
        };
        $query->orderBy('stores.id');

        $page = $query->paginate((int) $request->query('per_page', 12));

        // Event types and a few venue photos for the stores on this page.
        $ids = collect($page->items())->pluck('id');
        $venues = DB::table('venues')->whereIn('store_id', $ids)->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('avg_rating')->get(['store_id', 'thumbnail', 'event_types'])->groupBy('store_id');

        $items = collect($page->items())->map(function ($s) use ($venues) {
            $vs = $venues[$s->id] ?? collect();
            $types = $vs->flatMap(fn ($v) => json_decode($v->event_types ?? '[]', true) ?: [])->countBy()->sortDesc()->keys()->take(6)->values();
            return [
                'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug,
                'tagline' => $s->tagline, 'description' => $s->description,
                'logo' => $s->logo, 'cover_image' => $s->hero_image ?: $s->cover_image,
                'address' => $s->address, 'city' => $s->city, 'state' => $s->state,
                'latitude' => $s->latitude !== null ? (float) $s->latitude : null,
                'longitude' => $s->longitude !== null ? (float) $s->longitude : null,
                'distance_km' => isset($s->distance_km) ? round((float) $s->distance_km, 1) : null,
                'venue_count' => (int) $s->venue_count,
                'price_from' => $s->price_from !== null ? (float) $s->price_from : null,
                'price_to' => $s->price_to !== null ? (float) $s->price_to : null,
                'capacity_min' => (int) $s->capacity_min, 'capacity_max' => (int) $s->capacity_max,
                'total_reviews' => (int) $s->total_reviews, 'total_bookings' => (int) $s->total_bookings,
                'avg_rating' => $s->avg_rating !== null ? round((float) $s->avg_rating, 2) : 0,
                'has_featured' => (bool) $s->has_featured,
                'event_types' => $types,
                'photos' => $vs->pluck('thumbnail')->filter()->unique()->take(3)->values(),
                'member_since' => $s->created_at ? date('F Y', strtotime($s->created_at)) : null,
            ];
        });

        return response()->json(['success' => true, 'data' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(), 'data' => $items,
            'sort' => $sort,
        ]]);
    }

    /** GET /stores/cities — cities that have listed stores, with counts (cached 10 minutes). */
    public function cities(): JsonResponse
    {
        $cities = Cache::remember('stores:cities:v2', 600, fn () => DB::table('stores')
            ->where('status', 'approved')->whereNull('deleted_at')->whereNotNull('city')->where('city', '!=', '')
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('venues')->whereColumn('venues.store_id', 'stores.id')
                ->where('venues.status', 'active')->whereNull('venues.deleted_at'))
            ->groupBy('city', 'state')->orderByRaw('COUNT(*) DESC')->orderBy('city')
            ->selectRaw('city, state, COUNT(*) AS stores')->get()
            ->map(fn ($r) => ['city' => $r->city, 'state' => $r->state, 'stores' => (int) $r->stores])->values());

        return response()->json(['success' => true, 'data' => $cities]);
    }

    /**
     * GET /stores/{id}/profile — public store landing page.
     * Only approved stores are visible; anything else is a 404.
     */
    public function show(string $id): JsonResponse
    {
        $store = Store::with([
            'owner:id,name,avatar',
            'venues' => function ($q) {
                $q->where('status', 'active')
                  ->with('images')
                  ->withCount('bookings')
                  ->orderBy('created_at', 'desc');
            },
            'landingPage',
        ])->where('status', 'approved')->find($id);

        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        // Keep internal tenancy fields out of the public payload.
        $store->makeHidden(['tenancy_db_name', 'user_id', 'data']);

        $stats = [
            'total_venues'   => $store->venues->count(),
            'total_bookings' => (int) $store->venues->sum('bookings_count'),
            'total_reviews'  => (int) $store->venues->sum('total_reviews'),
            'avg_rating'     => ($rated = $store->venues->where('total_reviews', '>', 0))->count()
                ? round($rated->sum(fn ($v) => $v->avg_rating * $v->total_reviews) / max(1, $rated->sum('total_reviews')), 2)
                : 0,
            'member_since'   => $store->created_at?->format('F Y'),
        ];

        $data = $store->toArray();
        // The frontend reads the owner as `user`.
        $data['user'] = $data['owner'] ?? null;
        unset($data['owner']);

        return response()->json([
            'success' => true,
            'data'    => [
                'store' => $data,
                'stats' => $stats,
            ],
        ]);
    }
}
