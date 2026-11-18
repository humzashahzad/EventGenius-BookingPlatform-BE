<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid')->after('status');
            $table->timestamp('paid_at')->nullable()->after('payment_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            // payments.amount / payments.currency = app amount (PKR)
            // gateway_amount / gateway_currency = what PayFast actually charged (ZAR)
            $table->decimal('gateway_amount', 10, 2)->nullable()->after('currency');
            $table->string('gateway_currency', 3)->nullable()->after('gateway_amount');
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('gateway_currency');
        });

        DB::statement("ALTER TABLE payments MODIFY status ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE payments SET status = 'failed' WHERE status = 'cancelled'");
        DB::statement("ALTER TABLE payments MODIFY status ENUM('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending'");

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['gateway_amount', 'gateway_currency', 'exchange_rate']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_at']);
        });
    }
};
