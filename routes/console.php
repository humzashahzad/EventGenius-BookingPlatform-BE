<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expire unconfirmed bookings whose date has passed and complete finished events.
Schedule::command('bookings:housekeeping')->hourly()->withoutOverlapping();

// Keep the demo data growing (only when DEMO_DATA_GROWTH=true; demo accounts only).
Schedule::command('demo:grow')->everyTenMinutes()->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/demo-grow.log'));

// AI review insights: analyse new reviews (incl. ones inserted directly by demo:grow) and refresh
// venue aggregates. Skips quietly when the analyser is not installed.
Schedule::command('reviews:analyze --limit=200')->everyFiveMinutes()->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/reviews-analyze.log'));

// Ask customers to review finished events (one request + one reminder per booking), daytime only.
Schedule::command('reviews:request')->hourly()->between('10:00', '21:00')->withoutOverlapping(30);
