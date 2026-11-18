<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event types are now managed by admins in the `categories` table, so the
 * original hard-coded ENUM (wedding, corporate, birthday, ...) could never
 * store a real category slug such as "bridal_showers".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('event_type', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('event_type', [
                'wedding', 'corporate', 'birthday', 'film_shoot',
                'concert', 'exhibition', 'other',
            ])->change();
        });
    }
};
