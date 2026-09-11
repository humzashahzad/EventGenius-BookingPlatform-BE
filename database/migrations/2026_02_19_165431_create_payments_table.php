<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained('users')->onDelete('restrict');
            $table->string('transaction_id')->unique()->nullable();
            $table->string('payment_reference')->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('PKR');
            $table->enum('payment_method', ['payd_fast', 'bank_transfer', 'cash', 'other'])->default('payd_fast');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'refunded'])->default('pending');

            $table->json('gateway_response')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
