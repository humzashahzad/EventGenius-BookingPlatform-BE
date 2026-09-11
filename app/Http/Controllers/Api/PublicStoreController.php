<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class PublicStoreController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $store = Store::with([
            'user:id,name,email,phone,avatar',
            'venues' => function ($q) {
                $q->where('status', 'active')
                  ->with('images')
                  ->withCount('bookings')
                  ->orderBy('created_at', 'desc');
            },
            'landingPage',
        ])->findOrFail($id);

        // Add stats
        $stats = [
            'total_venues' => $store->venues->count(),
            'total_bookings' => $store->venues->sum('bookings_count'),
            'member_since' => $store->created_at->format('F Y'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'store' => $store,
                'stats' => $stats,
            ],
        ]);
    }
}
