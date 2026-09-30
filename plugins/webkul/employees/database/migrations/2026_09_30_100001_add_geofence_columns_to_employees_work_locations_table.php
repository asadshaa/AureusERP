<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees_work_locations', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('location_number');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('geofence_radius_meters')->nullable()->after('longitude');
            $table->boolean('geofence_enabled')->default(false)->after('geofence_radius_meters');

            $table->index(['company_id', 'is_active', 'geofence_enabled'], 'work_locations_geofence_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('employees_work_locations', function (Blueprint $table): void {
            $table->dropIndex('work_locations_geofence_lookup');
            $table->dropColumn(['latitude', 'longitude', 'geofence_radius_meters', 'geofence_enabled']);
        });
    }
};
