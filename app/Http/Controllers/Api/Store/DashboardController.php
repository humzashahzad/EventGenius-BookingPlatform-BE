<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $store = $user->store;

        if (!$store) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_store' => false,
                    'message'   => 'You have not set up your store yet.',
                ],
            ]);
        }

        $totalVenues    = Venue::where('store_id', $store->id)->count();
        $activeVenues   = Venue::where('store_id', $store->id)->where('status', 'active')->count();
        $totalBookings  = Booking::where('store_id', $store->id)->count();
        $pendingBookings= Booking::where('store_id', $store->id)->where('status', 'pending')->count();
        $confirmedBookings = Booking::where('store_id', $store->id)->where('status', 'confirmed')->count();
        // Revenue = PKR amount of this store's completed payments
        $revenue        = (float) Payment::where('status', 'completed')
            ->whereHas('booking', fn ($q) => $q->where('store_id', $store->id))
            ->sum('amount');

        $recentBookings = Booking::with(['client:id,name,email', 'venue:id,name'])
            ->where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'has_store' => true,
                'store'     => $store,
                'stats' => [
                    'total_venues'      => $totalVenues,
                    'active_venues'     => $activeVenues,
                    'total_bookings'    => $totalBookings,
                    'pending_bookings'  => $pendingBookings,
                    'confirmed_bookings'=> $confirmedBookings,
                    'total_revenue'     => $revenue,
                ],
                'recent_bookings' => $recentBookings,
                'charts' => [
                    'revenue_trend'     => \App\Services\DashboardCharts::revenueTrend($store->id),
                    'bookings_trend'    => \App\Services\DashboardCharts::bookingsTrend($store->id),
                    'bookings_by_venue' => \App\Services\DashboardCharts::bookingsByVenue($store->id),
                ],
            ],
        ]);
    }
}
