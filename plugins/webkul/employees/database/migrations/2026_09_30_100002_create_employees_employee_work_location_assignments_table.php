<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees_employee_work_location_assignments', function (Blueprint $table): void {
            $table->id();
            // Explicit constraint names: the auto-generated
            // "<table>_<column>_foreign" exceeds MySQL's 64-character limit
            // for this long table name.
            $table->foreignId('company_id')->constrained('companies', 'id', 'emp_wl_assign_company_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees', 'id', 'emp_wl_assign_employee_fk')->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained('employees_work_locations', 'id', 'emp_wl_assign_location_fk')->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users', 'id', 'emp_wl_assign_assigned_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id'], 'emp_wl_assign_lookup');
            $table->unique(['employee_id', 'work_location_id', 'valid_from'], 'emp_wl_assign_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_employee_work_location_assignments');
    }
};
