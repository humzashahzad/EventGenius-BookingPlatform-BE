<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Real chart / activity data for the admin and store dashboards.
 * Every series covers the last N days including today, with zero-filled gaps.
 */
class DashboardCharts
{
    /** Completed-payment revenue (PKR) per day. */
    public static function revenueTrend(?int $storeId = null, int $days = 7): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);

        $rows = Payment::query()
            ->where('status', 'completed')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from)
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('store_id', $storeId)))
            ->selectRaw('DATE(paid_at) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        return self::fillDays($from, $days, $rows);
    }

    /** Bookings created per day. */
    public static function bookingsTrend(?int $storeId = null, int $days = 7): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);

        $rows = Booking::query()
            ->where('created_at', '>=', $from)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        return self::fillDays($from, $days, $rows);
    }

    /** Booking count per venue (top N), for a store. */
    public static function bookingsByVenue(int $storeId, int $limit = 6): array
    {
        return Booking::query()
            ->where('bookings.store_id', $storeId)
            ->join('venues', 'venues.id', '=', 'bookings.venue_id')
            ->selectRaw('venues.name as label, COUNT(*) as value')
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value])
            ->all();
    }

    /** Latest platform events for the admin "Recent activity" feed. */
    public static function recentActivity(int $limit = 8): array
    {
        $events = collect();

        Booking::with(['client:id,name', 'venue:id,name'])->latest()->limit($limit)->get()
            ->each(fn ($b) => $events->push([
                'type' => 'booking',
                'text' => sprintf('%s booked %s', $b->client?->name ?? 'A customer', $b->venue?->name ?? 'a venue'),
                'time' => $b->created_at?->toIso8601String(),
                'link' => '/support/bookings',
            ]));

        Payment::with(['booking:id,booking_number'])->where('status', 'completed')->whereNotNull('paid_at')
            ->latest('paid_at')->limit($limit)->get()
            ->each(fn ($p) => $events->push([
                'type' => 'payment',
                'text' => sprintf('Payment of PKR %s received for %s', number_format((float) $p->amount), $p->booking?->booking_number ?? 'a booking'),
                'time' => $p->paid_at?->toIso8601String(),
                'link' => '/support/bookings',
            ]));

        Store::latest()->limit($limit)->get()
            ->each(fn ($s) => $events->push([
                'type' => 'store',
                'text' => $s->status === 'pending'
                    ? sprintf('Store "%s" is waiting for approval', $s->name)
                    : sprintf('Store "%s" registered', $s->name),
                'time' => $s->created_at?->toIso8601String(),
                'link' => '/support/stores',
            ]));

        User::where('role', '!=', 'admin')->latest()->limit($limit)->get()
            ->each(fn ($u) => $events->push([
                'type' => 'user',
                'text' => sprintf('New %s: %s', $u->role === 'store_owner' ? 'shop owner' : 'customer', $u->name),
                'time' => $u->created_at?->toIso8601String(),
                'link' => '/support/users',
            ]));

        return $events->filter(fn ($e) => $e['time'])
            ->sortByDesc('time')
            ->take($limit)
            ->values()
            ->all();
    }

    private static function fillDays(CarbonImmutable $from, int $days, Collection $rows): array
    {
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            $out[] = [
                'date'  => $day->toDateString(),
                'label' => $day->format('D j'),
                'value' => round((float) ($rows[$day->toDateString()] ?? 0), 2),
            ];
        }
        return $out;
    }
}
