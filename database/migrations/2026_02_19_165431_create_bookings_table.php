<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique();
            $table->foreignId('venue_id')->constrained()->onDelete('restrict');
            $table->foreignId('client_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('store_id')->constrained()->onDelete('restrict');

            // Event details
            $table->string('event_name');
            $table->enum('event_type', ['wedding', 'corporate', 'birthday', 'film_shoot', 'concert', 'exhibition', 'other']);
            $table->text('special_requirements')->nullable();
            $table->unsignedInteger('expected_guests');

            // Date & Time
            $table->date('event_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('duration_hours')->nullable();

            // Pricing
            $table->decimal('base_price', 10, 2);
            $table->decimal('amenities_price', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('tax_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2);

            // Status flow: pending -> confirmed -> completed / cancelled / rejected
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled', 'rejected'])->default('pending');
            $table->text('cancellation_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
