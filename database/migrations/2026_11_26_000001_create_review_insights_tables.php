<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review insights (AI sentiment/aspects per review), a per-venue aggregate used by ranking,
 * and review-request bookkeeping on bookings. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('sentiment', 5, 4);           // -1..1
            $table->string('label', 10);                  // positive | neutral | negative
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('language', 10)->nullable();   // en | roman_ur | ur | mixed
            $table->json('aspects')->nullable();          // {name: {sentiment, mentions}}
            $table->json('flags')->nullable();            // ["spam", "possible_duplicate", ...]
            $table->json('phrases')->nullable();          // {positive: [...], negative: [...]}
            $table->string('model_version', 60)->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
            $table->index('label');
        });

        // One row per venue, rebuilt whenever an insight of that venue changes (see VenueReviewAggregator).
        Schema::create('venue_review_insights', function (Blueprint $table) {
            $table->foreignId('venue_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('analysed_count')->default(0);   // counted reviews (approved, not spam/duplicate)
            $table->unsignedInteger('text_count')->default(0);       // of those, with enough text
            $table->double('weight_sum')->default(0);                // Σ recency weight (half-life 90 d)
            $table->double('weighted_sentiment')->default(0);        // Σ weight × sentiment
            $table->unsignedInteger('positive_count')->default(0);
            $table->unsignedInteger('neutral_count')->default(0);
            $table->unsignedInteger('negative_count')->default(0);
            $table->json('aspects')->nullable();     // {name: {mentions, positive, negative, sentiment}}
            $table->json('highlights')->nullable();  // short positive phrases
            $table->json('concerns')->nullable();    // short negative phrases
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('review_requested_at')->nullable();
            $table->timestamp('review_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['review_requested_at', 'review_reminded_at']);
        });
        Schema::dropIfExists('venue_review_insights');
        Schema::dropIfExists('review_insights');
    }
};
