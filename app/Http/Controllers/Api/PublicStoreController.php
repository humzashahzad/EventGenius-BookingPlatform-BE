<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class PublicStoreController extends Controller
{
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
