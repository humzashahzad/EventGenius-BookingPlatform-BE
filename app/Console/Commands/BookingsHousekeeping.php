<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Moves bookings through their time-based lifecycle:
 *  - pending bookings whose event date has passed  -> cancelled (expired)
 *  - confirmed bookings whose event has ended      -> completed
 *
 * Idempotent: only rows still in the source status are touched, so re-running is a no-op.
 */
class BookingsHousekeeping extends Command
{
    public const EXPIRY_REASON = 'Expired — not confirmed before event date';

    protected $signature = 'bookings:housekeeping {--dry-run : Report what would change without writing}';

    protected $description = 'Expire stale pending bookings and mark finished confirmed bookings as completed';

    public function handle(): int
    {
        $now   = now();
        $today = $now->toDateString();
        $dry   = (bool) $this->option('dry-run');

        $expired = 0;
        Booking::with('venue:id,name')
            ->where('status', 'pending')
            ->whereDate('event_date', '<', $today)
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$expired, $dry) {
                foreach ($bookings as $booking) {
                    if ($dry) {
                        $this->line("would expire #{$booking->booking_number}");
                        $expired++;
                        continue;
                    }

                    // Conditional update guards against a concurrent confirm/cancel.
                    $changed = Booking::whereKey($booking->id)->where('status', 'pending')->update([
                        'status'              => 'cancelled',
                        'cancellation_reason' => self::EXPIRY_REASON,
                        'cancelled_at'        => now(),
                    ]);
                    if (!$changed) {
                        continue;
                    }
                    $expired++;

                    $venueName = $booking->venue?->name ?? 'the venue';
                    $date      = $booking->event_date->format('D, j M Y');
                    NotificationService::notifyClient(
                        $booking->client_id,
                        'booking_cancelled',
                        'Booking Expired',
                        "Your booking #{$booking->booking_number} for \"{$venueName}\" on {$date} expired because it was not confirmed before the event date.",
                        ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'venue_name' => $venueName, 'reason' => 'expired']
                    );
                }
            });

        $completed = 0;
        Booking::where('status', 'confirmed')
            ->whereDate('event_date', '<=', $today)
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$completed, $now, $dry) {
                foreach ($bookings as $booking) {
                    $endsAt = Carbon::parse($booking->event_date->toDateString() . ' ' . $booking->end_time);
                    if ($endsAt->greaterThan($now)) {
                        continue;
                    }
                    if ($dry) {
                        $this->line("would complete #{$booking->booking_number}");
                        $completed++;
                        continue;
                    }
                    $completed += Booking::whereKey($booking->id)->where('status', 'confirmed')
                        ->update(['status' => 'completed']);
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Expired {$expired} pending booking(s); completed {$completed} booking(s).");

        return self::SUCCESS;
    }
}
