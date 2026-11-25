<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\Search\VenueSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SearchClickController extends Controller
{
    /** Record a click on a search result (feeds the click-through "engagement" signal). */
    public function store(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'search_id' => 'nullable|string|max:64',
            'position' => 'nullable|integer|min:0|max:65535',
        ]);

        $venue = Venue::where('status', 'active')
            ->whereHas('store', fn($q) => $q->where('status', 'approved'))
            ->find(ctype_digit($id) ? (int) $id : 0, ['id']);

        if (!$venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], 404);
        }

        $searchId = $request->input('search_id');
        $searchId = is_string($searchId) && Str::isUuid($searchId) ? strtolower($searchId) : null;
        $user = VenueSearch::resolveUser($request);
        $now = now();

        DB::table('search_clicks')->insert([
            'search_id' => $searchId,
            'venue_id' => $venue->id,
            'position' => $request->filled('position') ? (int) $request->input('position') : null,
            'user_id' => $user?->id,
            'created_at' => $now,
        ]);

        // Atomic counter: INSERT … ON DUPLICATE KEY UPDATE clicks = clicks + 1.
        DB::table('venue_search_stats')->upsert(
            [['venue_id' => $venue->id, 'impressions' => 0, 'clicks' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['venue_id'],
            ['clicks' => DB::raw('clicks + 1'), 'updated_at' => $now]
        );

        return response()->json(['success' => true]);
    }
}
