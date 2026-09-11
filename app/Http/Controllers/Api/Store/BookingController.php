<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) return response()->json(['success' => false, 'message' => 'Store not found.'], 404);

        $query = Booking::with(['client:id,name,email,phone', 'venue:id,name'])
            ->where('store_id', $store->id);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_number', 'like', '%'.$request->search.'%')
                  ->orWhere('event_name', 'like', '%'.$request->search.'%');
            });
        }

        return response()->json(['success' => true, 'data' => $query->orderBy('created_at','desc')->paginate($request->get('per_page',10))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        $booking = Booking::with(['client','venue.images','payment'])->where('store_id',$store->id)->findOrFail($id);
        return response()->json(['success' => true, 'data' => $booking]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) return response()->json(['success' => false, 'message' => 'Store not found.'], 404);

        $booking = Booking::with(['venue:id,name','client:id,name'])
            ->where('store_id', $store->id)->where('status','pending')->findOrFail($id);

        $booking->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $venueName = $booking->venue?->name ?? 'venue';
        $storeName = $store->name;

        NotificationService::notifyClient(
            $booking->client_id, 'booking_confirmed', 'Booking Confirmed!',
            "Great news! Your booking #{$booking->booking_number} for \"{$venueName}\" has been confirmed by {$storeName}.",
            ['booking_id'=>$booking->id,'booking_number'=>$booking->booking_number,'venue_name'=>$venueName,'store_name'=>$storeName]
        );

        NotificationService::notifyAdmins(
            'booking_confirmed', 'Booking Confirmed',
            "{$storeName} confirmed booking #{$booking->booking_number} for \"{$venueName}\".",
            ['booking_id'=>$booking->id,'booking_number'=>$booking->booking_number,'venue_name'=>$venueName]
        );

        return response()->json(['success' => true, 'data' => $booking, 'message' => 'Booking confirmed.']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) return response()->json(['success' => false, 'message' => 'Store not found.'], 404);

        $request->validate(['reason' => 'required|string|max:500']);

        $booking = Booking::with(['venue:id,name','client:id,name'])
            ->where('store_id',$store->id)->whereIn('status',['pending','confirmed'])->findOrFail($id);

        $booking->update(['status' => 'rejected', 'rejection_reason' => $request->reason]);

        $venueName = $booking->venue?->name ?? 'venue';
        $storeName = $store->name;

        NotificationService::notifyClient(
            $booking->client_id, 'booking_rejected', 'Booking Rejected',
            "Your booking #{$booking->booking_number} for \"{$venueName}\" was rejected. Reason: {$request->reason}",
            ['booking_id'=>$booking->id,'booking_number'=>$booking->booking_number,'venue_name'=>$venueName,'reason'=>$request->reason]
        );

        NotificationService::notifyAdmins(
            'booking_rejected', 'Booking Rejected',
            "{$storeName} rejected booking #{$booking->booking_number} for \"{$venueName}\".",
            ['booking_id'=>$booking->id,'booking_number'=>$booking->booking_number,'venue_name'=>$venueName]
        );

        return response()->json(['success' => true, 'data' => $booking, 'message' => 'Booking rejected.']);
    }
}
