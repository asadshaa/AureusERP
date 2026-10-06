<?php

namespace Tests\Feature;

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class EmployeeDashboardOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_dashboard_widget_can_view_only_for_users_with_employee(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $userWithoutEmployee = User::factory()->create(['default_company_id' => $company->id]);
        $userWithEmployee = User::factory()->create(['default_company_id' => $company->id]);

        Employee::query()->create([
            'company_id' => $company->id,
            'user_id'    => $userWithEmployee->id,
            'name'       => 'Test Employee',
        ]);

        $this->actingAs($userWithoutEmployee);
        $this->assertFalse(EmployeeDashboardOverviewWidget::canView());

        $this->actingAs($userWithEmployee);
        $this->assertTrue(EmployeeDashboardOverviewWidget::canView());
    }

    public function test_employee_dashboard_widget_renders_profile_and_leave_balances(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $managerUser = User::factory()->create(['default_company_id' => $company->id]);
        $manager = Employee::query()->create([
            'company_id' => $company->id,
            'user_id'    => $managerUser->id,
            'name'       => 'Manager Smith',
        ]);

        $department = Department::query()->create([
            'company_id' => $company->id,
            'name'       => 'Fleet Logistics',
        ]);

        $user = User::factory()->create(['default_company_id' => $company->id, 'name' => 'Alex Rivera']);
        $employee = Employee::query()->create([
            'company_id'      => $company->id,
            'user_id'         => $user->id,
            'parent_id'       => $manager->id,
            'department_id'   => $department->id,
            'name'            => 'Alex Rivera',
            'employee_number' => 'EMP-7788',
            'job_title'       => 'Logistics Specialist',
            'work_email'      => 'alex@company.com',
            'work_phone'      => '+1 555-0199',
        ]);

        $annualType = LeaveType::query()->create([
            'company_id' => $company->id,
            'name'       => 'Annual Leave',
            'is_active'  => true,
        ]);

        $sickType = LeaveType::query()->create([
            'company_id' => $company->id,
            'name'       => 'Sick Leave',
            'is_active'  => true,
        ]);

        LeaveAllocation::query()->create([
            'company_id'          => $company->id,
            'holiday_status_id'   => $annualType->id,
            'employee_id'         => $employee->id,
            'employee_company_id' => $company->id,
            'state'               => State::VALIDATE_TWO->value,
            'number_of_days'      => 15,
        ]);

        LeaveAllocation::query()->create([
            'company_id'          => $company->id,
            'holiday_status_id'   => $sickType->id,
            'employee_id'         => $employee->id,
            'employee_company_id' => $company->id,
            'state'               => State::VALIDATE_TWO->value,
            'number_of_days'      => 7,
        ]);

        Leave::query()->create([
            'company_id'          => $company->id,
            'employee_company_id' => $company->id,
            'employee_id'         => $employee->id,
            'user_id'             => $user->id,
            'holiday_status_id'   => $annualType->id,
            'number_of_days'      => 3,
            'state'               => State::VALIDATE_TWO->value,
        ]);

        $this->actingAs($user);

        Livewire::test(EmployeeDashboardOverviewWidget::class)
            ->assertSee('Alex Rivera')
            ->assertSee('EMP-7788')
            ->assertSee('Logistics Specialist')
            ->assertSee('Fleet Logistics')
            ->assertSee('Manager Smith')
            ->assertSee('Annual Leave')
            ->assertSee('Sick Leave')
            ->assertSee('12.0') // 15 - 3 = 12 Annual Leave Left
            ->assertSee('7.0');  // 7 Sick Leave Left
    }

    public function test_profile_page_updates_reflect_on_erp_employee(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['default_company_id' => $company->id, 'name' => 'Original Name', 'email' => 'original@erp.com']);

        $employee = Employee::query()->create([
            'company_id'      => $company->id,
            'user_id'         => $user->id,
            'name'            => 'Original Name',
            'work_email'      => 'original@erp.com',
            'mobile_phone'    => '111-222',
            'emergency_phone' => '333-444',
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm([
                'name'             => 'Updated Employee Name',
                'email'            => 'updated@erp.com',
                'mobile_phone'     => '999-888-777',
                'emergency_phone'  => '555-444-333',
            ], 'editProfileForm')
            ->call('updateProfile');

        $this->assertEquals('Updated Employee Name', $employee->fresh()->name);
        $this->assertEquals('updated@erp.com', $employee->fresh()->work_email);
        $this->assertEquals('999-888-777', $employee->fresh()->mobile_phone);
        $this->assertEquals('555-444-333', $employee->fresh()->emergency_phone);
    }
}
