<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the deleted_at column required by the SoftDeletes trait on the Payment model.
 * The original payments migration omitted this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payments', 'deleted_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
