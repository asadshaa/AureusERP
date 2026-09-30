<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees_attendance_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained('employees_attendance_records', 'id', 'attendance_verif_record_fk')->nullOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('employees_work_locations')->nullOnDelete();
            $table->string('action', 20);
            $table->string('method', 20);
            $table->string('result', 40);
            $table->boolean('accepted')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->json('geofence_snapshot')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->timestamp('client_captured_at')->nullable();
            $table->timestamp('server_recorded_at')->useCurrent();
            $table->string('failure_reason', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('review_status', 20)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'client_request_id'], 'attendance_verif_idempotency');
            $table->index(['company_id', 'employee_id', 'server_recorded_at'], 'attendance_verif_timeline');
            $table->index(['company_id', 'review_status'], 'attendance_verif_review_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_verifications');
    }
};
