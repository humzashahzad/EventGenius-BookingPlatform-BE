<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'event_type'           => [
                'required',
                'string',
                'max:100',
                // Event types are the admin-managed categories, not a fixed list.
                Rule::exists('categories', 'slug')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'event_date'           => 'required|date|after:today',
            'start_time'           => 'required|date_format:H:i',
            'end_time'             => 'required|date_format:H:i|after:start_time',
            'expected_guests'      => 'required|integer|min:1',
            'special_requirements' => 'nullable|string',
            'amenity_ids'          => 'nullable|array',
            'amenity_ids.*'        => 'integer|distinct',
        ], [
            'event_type.exists' => 'The selected event type is not an available category.',
            'event_date.after'  => 'Bookings must be made at least one day in advance.',
        ]);

        $venue = $this->findBookableVenue($validated['venue_id']);
        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'This venue is not currently accepting bookings.',
                'errors'  => ['venue_id' => ['This venue is not currently accepting bookings.']],
            ], 422);
        }

        // A venue only accepts the event types its owner listed (when any are listed).
        $offeredTypes = $venue->event_types ?: [];
        if ($offeredTypes && !in_array($validated['event_type'], $offeredTypes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This venue does not host that type of event.',
                'errors'  => ['event_type' => ['This venue does not host that type of event.']],
            ], 422);
        }

        // Guest count must fit the venue's capacity range.
        $capMin = (int) ($venue->capacity_min ?: 1);
        $capMax = (int) $venue->capacity_max;
        $guests = (int) $validated['expected_guests'];
        if ($guests < $capMin || ($capMax > 0 && $guests > $capMax)) {
            $msg = $capMax > 0
                ? "This venue accommodates between {$capMin} and {$capMax} guests."
                : "This venue requires at least {$capMin} guests.";
            return response()->json([
                'success' => false,
                'message' => $msg,
                'errors'  => ['expected_guests' => [$msg]],
            ], 422);
        }

        // Check venue operating hours
        $opStart = substr($venue->operating_start ?: '00:00', 0, 5);
        $opEnd   = substr($venue->operating_end ?: '23:59', 0, 5);

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
        // ── Amenity pricing (fix/features) ──────────────────────────────────
        // Optional amenity_ids must belong to this venue (422 otherwise); paid
        // amenities are added to amenities_price and 5% tax applies to
        // base + amenities. See App\Services\BookingPricing.
        $amenities = \App\Services\BookingPricing::resolveAmenities($venue, $validated['amenity_ids'] ?? []);
        $pricing   = \App\Services\BookingPricing::calculate($venue, (int) $validated['expected_guests'], $amenities);
        unset($validated['amenity_ids']);
        // ── end amenity pricing ─────────────────────────────────────────────

        // Conflict check + insert run atomically: the venue row is locked so two
        // concurrent requests for the same venue can't both pass the check.
        $booking = DB::transaction(function () use ($venue, $validated, $request, $hours, $pricing) {
            Venue::whereKey($venue->id)->lockForUpdate()->first();

            if ($this->hasConflict($venue->id, $validated['event_date'], $validated['start_time'], $validated['end_time'])) {
                return null;
            }

            return Booking::create([
                ...$validated,
                'client_id'      => $request->user()->id,
                'store_id'       => $venue->store_id,
                'duration_hours' => $hours,
                'base_price'     => $pricing['base_price'],
                'amenities_price'=> $pricing['amenities_price'],
                'discount_amount'=> 0,
                'tax_amount'     => $pricing['tax_amount'],
                'total_amount'   => $pricing['total_amount'],
                'status'         => 'pending',
            ]);
        });

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'This venue is already booked for part of the selected time. Please choose another slot.',
                'errors'  => ['start_time' => ['This venue is already booked for part of the selected time.']],
            ], 422);
        }

        $booking->load(['venue:id,name,city', 'store:id,name']);

        $clientName = $request->user()->name;
        $venueName  = $venue->name;
        $eventDate  = $booking->event_date->format('D, j M Y');

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

    /** Active venue whose store is approved, or null. */
    private function findBookableVenue(int|string $venueId): ?Venue
    {
        return Venue::with('store')
            ->where('status', 'active')
            ->whereHas('store', fn ($q) => $q->where('status', 'approved'))
            ->find($venueId);
    }

    /**
     * True when an active (pending/confirmed) booking overlaps [start, end).
     * Strict comparison so back-to-back slots (one ends 20:00, next starts 20:00) are allowed.
     */
    private function hasConflict(int $venueId, string $date, string $start, string $end): bool
    {
        return Booking::where('venue_id', $venueId)
            ->whereDate('event_date', \Carbon\Carbon::parse($date)->toDateString())
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_time', '<', $end . ':00')
            ->where('end_time', '>', $start . ':00')
            ->exists();
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

        if ($booking->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'This booking has already been paid and cannot be cancelled online. Please contact support to request a refund.',
            ], 422);
        }

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
