<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $totalBookings    = Booking::where('client_id', $user->id)->count();
        $upcomingBookings = Booking::where('client_id', $user->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('event_date', '>=', now())
            ->count();
        $completedBookings = Booking::where('client_id', $user->id)
            ->where('status', 'completed')->count();
        $cancelledBookings = Booking::where('client_id', $user->id)
            ->where('status', 'cancelled')->count();

        $recentBookings = Booking::with(['venue:id,name,city,thumbnail', 'store:id,name'])
            ->where('client_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_bookings'     => $totalBookings,
                    'upcoming_bookings'  => $upcomingBookings,
                    'completed_bookings' => $completedBookings,
                    'cancelled_bookings' => $cancelledBookings,
                ],
                'recent_bookings' => $recentBookings,
            ],
        ]);
    }
}
