<?php

/**
 * Who sees whose leave. Every employee sees their OWN leave (personal
 * calendar widget, balance widget, My Time). Other people's leave -- the
 * company overview calendar page/widget, the leave-type chart and the
 * Management -> Allocations screen -- is for HR / leave managers only, and
 * is always limited to the viewer's HR hierarchy.
 *
 * Regression for the live Employee role holding the company-wide widget and
 * overview-page permissions, which showed every employee the whole company's
 * leave, and for the Management menu appearing for plain employees.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\AllocationResource;
use Webkul\TimeOff\Filament\Pages\Overview;
use Webkul\TimeOff\Filament\Widgets\CalendarWidget;
use Webkul\TimeOff\Filament\Widgets\LeaveTypeWidget;
use Webkul\TimeOff\Filament\Widgets\MyTimeOffWidget;
use Webkul\TimeOff\Filament\Widgets\OverviewCalendarWidget;

const LEAVE_WIDGETS = [
    'widget_time_off_calendar_widget',
    'widget_time_off_my_time_off_widget',
    'widget_time_off_overview_calendar_widget',
    'widget_time_off_leave_type_widget',
    'page_time_off_overview',
];

/** @return array{0: User, 1: Employee} */
function oversightUser(Company $company, array $permissions, ?Employee $parent = null): array
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $employee = Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $user->id, 'name' => 'Staff '.Str::random(4),
        'is_active'  => true, 'employment_status' => 'active', 'parent_id' => $parent?->id,
    ]);

    return [$user->refresh(), $employee];
}

function oversightLeave(Employee $employee): void
{
    DB::table('time_off_leaves')->insert([
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'state'             => 'validate_two',
        'request_date_from' => '2026-10-12 00:00:00',
        'request_date_to'   => '2026-10-13 00:00:00',
        'number_of_days'    => 2,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

function oversightEvents(): array
{
    return app(OverviewCalendarWidget::class)->fetchEvents(['start' => '2026-10-01', 'end' => '2026-10-31']);
}

it('shows an employee their own leave but never the company-wide leave views, even when their role holds those widget permissions', function () {
    $company = Company::factory()->create(['is_active' => true]);
    // The live Employee role: "my" permissions PLUS every leave widget and the overview page.
    [$user] = oversightUser($company, [...LEAVE_WIDGETS, 'view_any_time_off_my::allocation', 'view_any_time_off_my::time::off']);
    $this->actingAs($user);

    expect(CalendarWidget::canView())->toBeTrue()
        ->and(MyTimeOffWidget::canView())->toBeTrue()
        ->and(OverviewCalendarWidget::canView())->toBeFalse()
        ->and(LeaveTypeWidget::canView())->toBeFalse()
        ->and(Overview::canAccess())->toBeFalse()
        ->and(AllocationResource::canViewAny())->toBeFalse();
    expect(oversightEvents())->toBe([]);
});

it('shows HR the whole company\'s leave', function () {
    $company = Company::factory()->create(['is_active' => true]);
    [, $a] = oversightUser($company, []);
    [, $b] = oversightUser($company, []);
    oversightLeave($a);
    oversightLeave($b);
    [$hr] = oversightUser($company, [...LEAVE_WIDGETS, 'view_any_time_off_time::off', 'view_any_time_off_allocation', HrPermissions::ViewAllRecords]);
    $this->actingAs($hr);

    expect(OverviewCalendarWidget::canView())->toBeTrue()
        ->and(LeaveTypeWidget::canView())->toBeTrue()
        ->and(Overview::canAccess())->toBeTrue()
        ->and(AllocationResource::canViewAny())->toBeTrue();
    expect(oversightEvents())->toHaveCount(2);
});

it('limits a line manager to their own team\'s leave, and hides other companies\' leave from HR', function () {
    $company = Company::factory()->create(['is_active' => true]);
    [$managerUser, $manager] = oversightUser($company, [...LEAVE_WIDGETS, 'view_any_time_off_time::off']);
    [, $report] = oversightUser($company, [], $manager);
    [, $stranger] = oversightUser($company, []);
    oversightLeave($report);
    oversightLeave($stranger);

    $otherCompany = Company::factory()->create(['is_active' => true]);
    [, $foreign] = oversightUser($otherCompany, []);
    oversightLeave($foreign);

    $this->actingAs($managerUser);
    expect(oversightEvents())->toHaveCount(1);

    [$hr] = oversightUser($company, [...LEAVE_WIDGETS, 'view_any_time_off_time::off', HrPermissions::ViewAllRecords]);
    $this->actingAs($hr);
    expect(oversightEvents())->toHaveCount(2);
});

it('gives HR and line managers the personal leave widgets and HR the overview through the HR permission bundles', function () {
    expect(HrPermissions::all())->toContain(...LEAVE_WIDGETS)
        ->and(HrPermissions::hrManager())->toContain(...LEAVE_WIDGETS)
        ->and(HrPermissions::manager())->toContain('widget_time_off_calendar_widget', 'widget_time_off_my_time_off_widget')
        ->and(HrPermissions::manager())->not->toContain('widget_time_off_overview_calendar_widget', 'page_time_off_overview');
});
