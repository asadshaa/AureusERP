<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees_attendance_records', function (Blueprint $table): void {
            $table->foreignId('check_in_verification_id')->nullable()->after('source_reference')
                ->constrained('employees_attendance_verifications')->nullOnDelete();
            $table->foreignId('check_out_verification_id')->nullable()->after('check_in_verification_id')
                ->constrained('employees_attendance_verifications')->nullOnDelete();
            $table->string('verification_status', 20)->nullable()->after('check_out_verification_id');

            $table->index(['company_id', 'verification_status'], 'attendance_verification_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('employees_attendance_records', function (Blueprint $table): void {
            $table->dropForeign(['check_in_verification_id']);
            $table->dropForeign(['check_out_verification_id']);
            $table->dropIndex('attendance_verification_status_index');
            $table->dropColumn(['check_in_verification_id', 'check_out_verification_id', 'verification_status']);
        });
    }
};
