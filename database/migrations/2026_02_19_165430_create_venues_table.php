<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail')->nullable();

            // Location
            $table->string('address');
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('country')->default('Pakistan');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Capacity & details
            $table->unsignedInteger('capacity_min')->default(1);
            $table->unsignedInteger('capacity_max');
            $table->unsignedInteger('area_sqft')->nullable();
            $table->unsignedSmallInteger('floors')->default(1);

            // Pricing
            $table->decimal('price_per_hour', 10, 2)->nullable();
            $table->decimal('price_per_day', 10, 2)->nullable();
            $table->decimal('price_per_event', 10, 2)->nullable();
            $table->enum('pricing_type', ['per_hour', 'per_day', 'per_event', 'negotiable'])->default('per_hour');
            $table->boolean('dynamic_pricing')->default(false);

            // Event types supported
            $table->json('event_types')->nullable(); // ['wedding', 'corporate', 'film_shoot', ...]

            // Status & availability
            $table->enum('status', ['active', 'inactive', 'under_maintenance'])->default('active');
            $table->boolean('is_featured')->default(false);

            // Ratings cache
            $table->decimal('avg_rating', 3, 2)->default(0.00);
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('total_bookings')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
