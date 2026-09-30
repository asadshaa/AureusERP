<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

const GEO_LAT = 24.8607;
const GEO_LNG = 67.0011;

/**
 * One company, one geofenced office (150 m radius at GEO_LAT/GEO_LNG), one
 * eligible employee whose primary work location is that office.
 *
 * @return array{company: Company, location: WorkLocation, user: User, employee: Employee}
 */
function geoFixture(array $employeeOverrides = [], array $locationOverrides = []): array
{
    config(['hr_attendance_geofence.enabled' => true]);

    $company = Company::factory()->create(['is_active' => true]);
    app(AttendanceWorkflowSeeder::class)->run();
    $location = WorkLocation::factory()->geofenced(GEO_LAT, GEO_LNG, 150)->create(['company_id' => $company->id, 'name' => 'HQ'] + $locationOverrides);
    [$user, $employee] = geoEmployee($company, $location, $employeeOverrides);

    return compact('company', 'location', 'user', 'employee');
}

/** @return array{0: User, 1: Employee} */
function geoEmployee(Company $company, ?WorkLocation $location, array $overrides = []): array
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $employee = Employee::query()->create($overrides + [
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'name'              => 'Geo Employee '.Str::random(4),
        'is_active'         => true,
        'employment_status' => 'active',
        'work_location_id'  => $location?->id,
    ]);

    return [$user, $employee];
}

function geoGrant(User $user, string ...$permissions): User
{
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    // Same thing HrPermissionRegistrar::synchronize() does after granting: drop the cached permission list.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/** A user in $company who can act on every employee (hr_view_all_records) with the given extra permissions. */
function geoHrUser(Company $company, string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    Employee::query()->create([
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'name'              => 'HR '.Str::random(4),
        'is_active'         => true,
        'employment_status' => 'active',
    ]);

    return geoGrant($user, HrPermissions::ViewAllRecords, ...$permissions);
}

if (! function_exists('geoWorkLocationAdmin')) {
    /** An HR admin for the company with Work Location CRUD plus the given extra permissions. */
    function geoWorkLocationAdmin(Company $company, string ...$extra): User
    {
        return geoHrUser($company, 'view_any_employee_work::location', 'view_employee_work::location', 'create_employee_work::location', 'update_employee_work::location', ...$extra);
    }
}

function geoEvidence(array $overrides = []): LocationEvidence
{
    $data = $overrides + [
        'latitude'   => GEO_LAT,
        'longitude'  => GEO_LNG,
        'accuracy'   => 10.0,
        'capturedAt' => CarbonImmutable::now('UTC'),
        'requestId'  => (string) Str::uuid(),
    ];

    return new LocationEvidence($data['latitude'], $data['longitude'], $data['accuracy'], $data['capturedAt'], $data['requestId']);
}

function geoRequest(string $ip = '10.0.0.7'): Request
{
    return Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Pest/Geo']);
}

/** A point ~1.1 km north of the office: well outside a 150 m fence. */
function geoOutside(): array
{
    return ['latitude' => GEO_LAT + 0.01, 'longitude' => GEO_LNG];
}
