<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Key/value settings (ranking weights etc.), JSON values.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        // One row per search request (page 1 and later pages).
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('search_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('query', 500)->nullable();
            $table->json('understood')->nullable();
            $table->json('filters')->nullable();
            $table->string('sort', 40)->nullable();
            $table->unsignedInteger('result_count')->default(0);
            $table->json('venue_ids')->nullable();
            $table->unsignedSmallInteger('page')->default(1);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // A result clicked from a search.
        Schema::create('search_clicks', function (Blueprint $table) {
            $table->id();
            $table->uuid('search_id')->nullable()->index();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Running counters used by the click-through (learning) signal.
        Schema::create('venue_search_stats', function (Blueprint $table) {
            $table->foreignId('venue_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });

        // Admin manual ranking control: -100 (bury) .. +100 (boost).
        Schema::table('venues', function (Blueprint $table) {
            $table->smallInteger('search_boost')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('venues', fn (Blueprint $t) => $t->dropColumn('search_boost'));
        Schema::dropIfExists('venue_search_stats');
        Schema::dropIfExists('search_clicks');
        Schema::dropIfExists('search_logs');
        Schema::dropIfExists('app_settings');
    }
};
