<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\Search\VenueSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Filtering, natural-language understanding, ranking and logging live in VenueSearch.
        $result = app(VenueSearch::class)->run($request->query(), VenueSearch::resolveUser($request));

        return response()->json([
            'success' => true,
            'data' => $result['paginator'],
            'search' => [
                'search_id' => $result['search_id'],
                'sort' => $result['sort'],
                'understood' => $result['understood'],
            ],
        ]);
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
