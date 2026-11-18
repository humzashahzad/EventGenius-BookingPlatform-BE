<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expire unconfirmed bookings whose date has passed and complete finished events.
Schedule::command('bookings:housekeeping')->hourly()->withoutOverlapping();
