<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueAvailabilityController extends Controller
{
    public function check(Request $request, string $venueId): JsonResponse
    {
        $request->validate([
            'date' => 'required|date|after:today',
        ]);

        $venue = Venue::findOrFail($venueId);

        // Get all booked slots for this venue on the specified date
        $bookedSlots = Booking::where('venue_id', $venue->id)
            ->where('event_date', $request->date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->select('start_time', 'end_time', 'event_name', 'status')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $request->date,
                'booked_slots' => $bookedSlots,
                'venue' => [
                    'id' => $venue->id,
                    'name' => $venue->name,
                ],
            ],
        ]);
    }

    public function getMonthAvailability(Request $request, string $venueId): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2024',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $venue = Venue::findOrFail($venueId);

        $startDate = sprintf('%04d-%02d-01', $request->year, $request->month);
        $endDate = date('Y-m-t', strtotime($startDate));

        // Get all bookings in this month
        $bookings = Booking::where('venue_id', $venue->id)
            ->whereBetween('event_date', [$startDate, $endDate])
            ->whereIn('status', ['pending', 'confirmed'])
            ->select('event_date')
            ->get()
            ->pluck('event_date')
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $request->year,
                'month' => $request->month,
                'booked_dates' => $bookings,
            ],
        ]);
    }
}
