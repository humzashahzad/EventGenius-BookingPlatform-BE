<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ask customers to review completed events, with one reminder.
 *
 *  - request : booking completed, event ended ≥ 6 h ago and within the last 30 days, no review yet,
 *              never requested → in-app notification `review_request`, sets review_requested_at.
 *  - reminder: requested ≥ 3 days ago, still no review, never reminded, event within 30 days →
 *              one more `review_request` notification (data.reminder = true), sets review_reminded_at.
 *
 * Anti-spam: each booking is asked at most twice (request + reminder), at most one review
 * notification per customer per 24 h, inactive accounts are skipped, and each run is capped.
 */
class ReviewsRequest extends Command
{
    private const DELAY_HOURS = 6;
    private const WINDOW_DAYS = 30;
    private const REMIND_AFTER_DAYS = 3;
    private const PER_CLIENT_COOLDOWN_HOURS = 24;

    protected $signature = 'reviews:request
        {--limit=100 : Maximum notifications per run}
        {--dry-run : List what would be sent without writing anything}';

    protected $description = 'Send review requests (and one reminder) for completed bookings without a review';

    public function handle(): int
    {
        $now = now();
        $dry = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $notified = []; // client_id => true (this run)
        $sent = ['request' => 0, 'reminder' => 0];

        // Customers who already got a review notification recently.
        $recent = DB::table('notifications')->where('type', 'review_request')
            ->where('created_at', '>=', $now->copy()->subHours(self::PER_CLIENT_COOLDOWN_HOURS))
            ->pluck('user_id')->map(fn($id) => (int) $id)->flip()->all();

        foreach (['request', 'reminder'] as $kind) {
            $rows = $this->candidates($kind, $now, $limit * 3);
            foreach ($rows as $b) {
                if ($sent['request'] + $sent['reminder'] >= $limit) {
                    break 2;
                }
                $clientId = (int) $b->client_id;
                if (isset($notified[$clientId]) || isset($recent[$clientId])) {
                    continue;
                }
                if ($kind === 'request' && $this->eventEnd($b)->gt($now->copy()->subHours(self::DELAY_HOURS))) {
                    continue;
                }

                $venue = $b->venue_name ?: 'the venue';
                $title = $kind === 'request' ? 'How was your event?' : 'Reminder: how was your event?';
                $body = $kind === 'request'
                    ? "Tell others about your event at {$venue}. Your review takes a minute and helps other families choose."
                    : "You haven't reviewed {$venue} yet. Share a quick rating while the day is still fresh.";

                if ($dry) {
                    $this->line("[dry-run] {$kind} booking #{$b->booking_number} (id {$b->id}) → client {$clientId}: {$venue}");
                } else {
                    $column = $kind === 'request' ? 'review_requested_at' : 'review_reminded_at';
                    // Claim the booking first so concurrent runs can never notify twice.
                    $claimed = DB::table('bookings')->where('id', $b->id)->whereNull($column)->update([$column => $now]);
                    if (!$claimed) {
                        continue;
                    }
                    NotificationService::notifyClient($clientId, 'review_request', $title, $body, [
                        'booking_id' => (int) $b->id,
                        'booking_number' => $b->booking_number,
                        'venue_id' => (int) $b->venue_id,
                        'venue_name' => $venue,
                        'link' => "/customer/bookings/{$b->id}",
                        'reminder' => $kind === 'reminder',
                    ]);
                }
                $notified[$clientId] = true;
                $sent[$kind]++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Sent {$sent['request']} review request(s) and {$sent['reminder']} reminder(s).");
        return self::SUCCESS;
    }

    private function candidates(string $kind, Carbon $now, int $take)
    {
        $q = DB::table('bookings')
            ->join('venues', 'venues.id', '=', 'bookings.venue_id')
            ->join('users', 'users.id', '=', 'bookings.client_id')
            ->where('bookings.status', 'completed')
            ->whereNull('bookings.deleted_at')
            ->where('users.is_active', true)
            ->whereBetween('bookings.event_date', [$now->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $now->toDateString()])
            // Any review, even one hidden or deleted by moderation, means "already reviewed".
            ->whereNotExists(fn($s) => $s->select(DB::raw(1))->from('reviews')->whereColumn('reviews.booking_id', 'bookings.id'));

        if ($kind === 'request') {
            $q->whereNull('bookings.review_requested_at')->orderBy('bookings.event_date')->orderBy('bookings.id');
        } else {
            $q->whereNotNull('bookings.review_requested_at')
              ->whereNull('bookings.review_reminded_at')
              ->where('bookings.review_requested_at', '<=', $now->copy()->subDays(self::REMIND_AFTER_DAYS))
              ->orderBy('bookings.review_requested_at')->orderBy('bookings.id');
        }

        return $q->limit($take)->get([
            'bookings.id', 'bookings.booking_number', 'bookings.client_id', 'bookings.venue_id', 'bookings.event_date',
            'bookings.start_time', 'bookings.end_time', 'venues.name as venue_name',
        ]);
    }

    /** End of the event; an end time before the start time means it ran past midnight. */
    private function eventEnd(object $b): Carbon
    {
        $date = Carbon::parse((string) $b->event_date)->toDateString();
        $end = Carbon::parse($date . ' ' . ($b->end_time ?: '23:59:59'));
        if ($b->start_time && $b->end_time && $b->end_time < $b->start_time) {
            $end->addDay();
        }
        return $end;
    }
}
