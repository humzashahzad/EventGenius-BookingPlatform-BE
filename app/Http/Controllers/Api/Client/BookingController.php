<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['venue:id,name,city,thumbnail', 'store:id,name'])
            ->where('client_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->orderBy('event_date', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id'             => 'required|exists:venues,id',
            'event_name'           => 'required|string|max:255',
            'event_type'           => 'required|in:wedding,corporate,birthday,film_shoot,concert,exhibition,other',
            'event_date'           => 'required|date|after:today',
            'start_time'           => 'required|date_format:H:i',
            'end_time'             => 'required|date_format:H:i|after:start_time',
            'expected_guests'      => 'required|integer|min:1',
            'special_requirements' => 'nullable|string',
        ]);

        $venue = Venue::where('status', 'active')->findOrFail($validated['venue_id']);

        $conflict = Booking::where('venue_id', $venue->id)
            ->where('event_date', $validated['event_date'])
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_time', [$validated['start_time'], $validated['end_time']])
                  ->orWhereBetween('end_time', [$validated['start_time'], $validated['end_time']]);
            })->exists();

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'This venue is not available for the selected date and time.',
            ], 422);
        }

        // Check venue operating hours
        $opStart = $venue->operating_start ?? '00:00';
        $opEnd   = $venue->operating_end ?? '23:59';

        // Normalize 23:59 to 24:00 for comparison if needed, 
        // but it's easier to just compare strings if they are H:i
        if ($validated['start_time'] < $opStart || $validated['end_time'] > $opEnd) {
            return response()->json([
                'success' => false,
                'message' => "This venue is only available between {$opStart} and {$opEnd}.",
            ], 422);
        }

        $start    = \Carbon\Carbon::createFromFormat('H:i', $validated['start_time']);
        $end      = \Carbon\Carbon::createFromFormat('H:i', $validated['end_time']);
        $hours    = (int) $start->diffInHours($end);
        $basePrice = ($venue->price_per_head ?? 0) * $validated['expected_guests'];
        $taxAmount   = round($basePrice * 0.05, 2);
        $totalAmount = round($basePrice + $taxAmount, 2);

        $booking = Booking::create([
            ...$validated,
            'client_id'      => $request->user()->id,
            'store_id'       => $venue->store_id,
            'duration_hours' => $hours,
            'base_price'     => $basePrice,
            'amenities_price'=> 0,
            'discount_amount'=> 0,
            'tax_amount'     => $taxAmount,
            'total_amount'   => $totalAmount,
            'status'         => 'pending',
        ]);

        $booking->load(['venue:id,name,city', 'store:id,name']);

        $clientName = $request->user()->name;
        $venueName  = $venue->name;
        $eventDate  = $booking->event_date;

        if ($venue->store && $venue->store->user_id) {
            NotificationService::notifyStoreOwner(
                $venue->store->user_id,
                'new_booking_received',
                'New Booking Received',
                "{$clientName} has booked \"{$venueName}\" for {$eventDate}.",
                ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName, 'client_name' => $clientName]
            );
        }

        NotificationService::notifyAdmins(
            'booking_created',
            'New Booking Created',
            "{$clientName} created booking #{$booking->booking_number} for \"{$venueName}\".",
            ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName, 'client_name' => $clientName]
        );

        NotificationService::notifyClient(
            $request->user()->id,
            'booking_created',
            'Booking Submitted',
            "Your booking for \"{$venueName}\" on {$eventDate} has been submitted and is pending confirmation.",
            ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName]
        );

        return response()->json([
            'success' => true,
            'data'    => $booking,
            'message' => 'Booking created successfully.',
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = Booking::with(['venue.images', 'store', 'payment'])
            ->where('client_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $booking]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('client_id', $request->user()->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->findOrFail($id);

        $request->validate(['reason' => 'nullable|string|max:500']);

        $booking->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $request->reason,
            'cancelled_at'        => now(),
        ]);

        $booking->load(['venue:id,name', 'store']);
        $venueName  = $booking->venue?->name ?? 'venue';
        $clientName = $request->user()->name;

        if ($booking->store && $booking->store->user_id) {
            NotificationService::notifyStoreOwner(
                $booking->store->user_id,
                'booking_cancelled',
                'Booking Cancelled',
                "{$clientName} cancelled booking #{$booking->booking_number} for \"{$venueName}\".",
                ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName]
            );
        }

        NotificationService::notifyAdmins(
            'booking_cancelled',
            'Booking Cancelled',
            "{$clientName} cancelled booking #{$booking->booking_number} for \"{$venueName}\".",
            ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName, 'client_name' => $clientName]
        );

        NotificationService::notifyClient(
            $request->user()->id,
            'booking_cancelled',
            'Booking Cancelled',
            "Your booking #{$booking->booking_number} for \"{$venueName}\" has been cancelled.",
            ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName]
        );

        return response()->json(['success' => true, 'data' => $booking, 'message' => 'Booking cancelled.']);
    }
}
