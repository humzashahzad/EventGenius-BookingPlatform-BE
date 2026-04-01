<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'location_id')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasTable('venues') && Schema::hasColumn('venues', 'location_id')) {
            Schema::table('venues', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasTable('bookings')) {
            $columns = array_filter(['latitude', 'longitude', 'geo_accuracy'], fn (string $column) => Schema::hasColumn('bookings', $column));
            if ($columns !== []) {
                Schema::table('bookings', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists('locations');
    }

    public function down(): void
    {
        if (!Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('locations')->nullOnDelete();
                $table->string('name');
                $table->enum('level', ['country', 'city', 'area']);
                $table->json('boundary')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['level', 'is_active']);
                $table->index('parent_id');
            });
        }

        if (Schema::hasTable('stores') && !Schema::hasColumn('stores', 'location_id')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->after('status')->constrained('locations')->nullOnDelete();
            });
        }

        if (Schema::hasTable('venues') && !Schema::hasColumn('venues', 'location_id')) {
            Schema::table('venues', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->after('country')->constrained('locations')->nullOnDelete();
            });
        }

        if (Schema::hasTable('bookings') && !Schema::hasColumn('bookings', 'latitude')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('special_requirements');
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                $table->decimal('geo_accuracy', 10, 2)->nullable()->after('longitude');
            });
        }
    }
};
