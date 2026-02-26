<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users soft delete
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Payments soft delete
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Reviews soft delete
        Schema::table('reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('reviews', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Venue amenities soft delete
        Schema::table('venue_amenities', function (Blueprint $table) {
            if (!Schema::hasColumn('venue_amenities', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Venue images soft delete
        Schema::table('venue_images', function (Blueprint $table) {
            if (!Schema::hasColumn('venue_images', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('venue_amenities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('venue_images', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
