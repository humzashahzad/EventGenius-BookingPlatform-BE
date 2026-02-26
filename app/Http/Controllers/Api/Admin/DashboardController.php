<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Store;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalClients  = User::where('role', 'client')->count();
        $totalStores   = Store::count();
        $pendingStores = Store::where('status', 'pending')->count();
        $totalVenues   = Venue::count();
        $totalBookings = Booking::count();
        $revenue       = Booking::where('status', 'completed')->sum('total_amount');

        $recentBookings = Booking::with(['client:id,name,email', 'venue:id,name', 'store:id,name'])
            ->latest()->limit(10)->get();

        $recentStores = Store::with('owner:id,name,email')
            ->latest()->limit(5)->get();

        $bookingsByStatus = Booking::selectRaw('status, count(*) as count')
            ->groupBy('status')->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_clients'  => $totalClients,
                    'total_stores'   => $totalStores,
                    'pending_stores' => $pendingStores,
                    'total_venues'   => $totalVenues,
                    'total_bookings' => $totalBookings,
                    'total_revenue'  => $revenue,
                ],
                'bookings_by_status' => $bookingsByStatus,
                'recent_bookings'    => $recentBookings,
                'recent_stores'      => $recentStores,
            ],
        ]);
    }
}
