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
