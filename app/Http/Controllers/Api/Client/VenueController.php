<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['store:id,name,city', 'images' => fn($q) => $q->where('is_primary', true)])
            ->where('status', 'active')
            ->whereHas('store', fn($q) => $q->where('status', 'approved'));

        if ($request->filled('search')) {
            // Escape LIKE wildcards so "50%" or "_" are searched literally.
            $term = '%' . addcslashes(trim((string) $request->search), '\\%_') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('description', 'like', $term)
                  ->orWhere('address', 'like', $term)
                  ->orWhere('city', 'like', $term)
                  ->orWhere('state', 'like', $term)
                  ->orWhereHas('store', fn($s) => $s->where('name', 'like', $term));
            });
        }

        if ($request->filled('city')) {
            $query->where('city', trim((string) $request->city));
        }

        if ($request->filled('event_type')) {
            $query->whereJsonContains('event_types', $request->event_type);
        }

        if ($request->filled('min_capacity')) {
            $query->where('capacity_max', '>=', $request->min_capacity);
        }

        if ($request->filled('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_head', '<=', $request->max_price)
                  ->orWhere('price_per_hour', '<=', $request->max_price)
                  ->orWhere('price_per_day', '<=', $request->max_price)
                  ->orWhere('price_per_event', '<=', $request->max_price);
            });
        }

        if ($request->filled('pricing_type')) {
            $query->where('pricing_type', $request->pricing_type);
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $allowedSorts = ['created_at', 'avg_rating', 'price_per_head', 'price_per_hour', 'price_per_day', 'price_per_event'];
        $sortBy = in_array($request->get('sort_by'), $allowedSorts, true) ? $request->get('sort_by') : 'created_at';
        $priceSort = str_starts_with($sortBy, 'price_');
        // Prices default to low-to-high, everything else to high-to-low.
        $sortDir = in_array($request->get('sort_dir'), ['asc', 'desc'], true)
            ? $request->get('sort_dir')
            : ($priceSort ? 'asc' : 'desc');

        if ($priceSort) {
            // Venues without that price go last instead of first.
            $query->orderByRaw("{$sortBy} IS NULL");
        }
        if ($sortBy === 'avg_rating') {
            $query->orderBy('avg_rating', $sortDir)->orderBy('total_reviews', 'desc');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }
        // Stable order so venues don't repeat or vanish between pages.
        $query->orderBy('id', 'desc');

        $perPage = (int) $request->get('per_page', 12);
        $perPage = $perPage > 0 ? min(100, $perPage) : 12;
        $venues = $query->paginate($perPage)->withQueryString();

        return response()->json(['success' => true, 'data' => $venues]);
    }

    public function show(string $id): JsonResponse
    {
        $venue = Venue::with([
            'store:id,name,city,phone,email,address',
            'images',
            'amenities',
            'reviews' => fn($q) => $q->with('client:id,name,avatar')->where('is_approved', true)->latest()->limit(10),
        ])->where('status', 'active')
          ->whereHas('store', fn($q) => $q->where('status', 'approved'))
          ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $venue]);
    }
}
