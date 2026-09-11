<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE venues
                MODIFY pricing_type ENUM('per_hour', 'per_day', 'per_event', 'per_head', 'negotiable')
                NOT NULL DEFAULT 'per_head'
            ");
        }

        DB::table('venues')
            ->whereNotNull('price_per_head')
            ->update(['pricing_type' => 'per_head']);
    }

    public function down(): void
    {
        DB::table('venues')
            ->where('pricing_type', 'per_head')
            ->update(['pricing_type' => 'per_hour']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE venues
                MODIFY pricing_type ENUM('per_hour', 'per_day', 'per_event', 'negotiable')
                NOT NULL DEFAULT 'per_hour'
            ");
        }
    }
};
