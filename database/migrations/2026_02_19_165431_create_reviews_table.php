<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');

            $table->unsignedTinyInteger('rating'); // 1-5
            $table->string('title')->nullable();
            $table->text('comment')->nullable();

            // Sub-ratings
            $table->unsignedTinyInteger('cleanliness_rating')->nullable();
            $table->unsignedTinyInteger('value_rating')->nullable();
            $table->unsignedTinyInteger('service_rating')->nullable();
            $table->unsignedTinyInteger('location_rating')->nullable();

            $table->boolean('is_approved')->default(false);
            $table->text('store_reply')->nullable();
            $table->timestamp('store_replied_at')->nullable();

            $table->timestamps();

            $table->unique(['booking_id', 'client_id']); // one review per booking
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
