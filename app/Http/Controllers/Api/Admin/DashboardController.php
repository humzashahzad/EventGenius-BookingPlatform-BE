<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
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
        // Revenue = PKR amount of payments actually completed (PayFast ITN / admin)
        $revenue       = (float) Payment::where('status', 'completed')->sum('amount');

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
                'charts' => [
                    'revenue_trend'  => \App\Services\DashboardCharts::revenueTrend(),
                    'bookings_trend' => \App\Services\DashboardCharts::bookingsTrend(),
                ],
                'recent_activity' => \App\Services\DashboardCharts::recentActivity(),
            ],
        ]);
    }
}
