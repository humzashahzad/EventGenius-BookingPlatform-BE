<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('hero_image')->nullable();
            $table->string('tagline')->nullable();
            $table->text('about_text')->nullable();
            $table->json('services')->nullable();
            $table->json('faq')->nullable();
            $table->json('social_links')->nullable();
            $table->json('testimonials')->nullable();
            $table->json('gallery')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_landing_pages');
    }
};
