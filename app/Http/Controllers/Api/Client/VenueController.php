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
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhere('city', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('event_type')) {
            $query->whereJsonContains('event_types', $request->event_type);
        }

        if ($request->filled('min_capacity')) {
            $query->where('capacity_max', '>=', $request->min_capacity);
        }

        if ($request->filled('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->where('price_per_hour', '<=', $request->max_price)
                  ->orWhere('price_per_day', '<=', $request->max_price)
                  ->orWhere('price_per_event', '<=', $request->max_price);
            });
        }

        if ($request->filled('pricing_type')) {
            $query->where('pricing_type', $request->pricing_type);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $allowedSorts = ['created_at', 'avg_rating', 'price_per_hour', 'price_per_day', 'price_per_event'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $venues = $query->paginate($request->get('per_page', 12));

        return response()->json(['success' => true, 'data' => $venues]);
    }

    public function show(string $id): JsonResponse
    {
        $venue = Venue::with([
            'store:id,name,city,phone,email,address',
            'images',
            'amenities',
            'reviews' => fn($q) => $q->with('client:id,name,avatar')->where('is_approved', true)->latest()->limit(10),
        ])->where('status', 'active')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $venue]);
    }
}
