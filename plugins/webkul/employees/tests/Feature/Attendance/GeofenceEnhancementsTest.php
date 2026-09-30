<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\WorkLocationResource;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\WorkLocationResource\Pages\ListWorkLocations;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\EmployeeWorkLocationAssignment;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\Verifiers\GpsGeofenceVerifier;
use Webkul\Employee\Services\Attendance\Verifiers\RemoteExemptVerifier;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

// ---------------------------------------------------------------------
// Phase 1: Factories
// ---------------------------------------------------------------------

it('generates valid attendance verifications through AttendanceVerificationFactory', function () {
    $f = geoFixture();

    $verification = AttendanceVerification::factory()->create([
        'company_id'  => $f['company']->id,
        'employee_id' => $f['employee']->id,
    ]);

    expect($verification->exists)->toBeTrue()
        ->and($verification->company_id)->toBe($f['company']->id)
        ->and($verification->employee_id)->toBe($f['employee']->id)
        ->and($verification->action)->toBe(AttendanceVerificationAction::CheckIn)
        ->and($verification->method)->toBe(AttendanceVerificationMethod::Gps)
        ->and($verification->result)->toBe(AttendanceVerificationResult::Verified)
        ->and($verification->server_recorded_at)->not->toBeNull();

    $rejected = AttendanceVerification::factory()->rejected()->create([
        'company_id'  => $f['company']->id,
        'employee_id' => $f['employee']->id,
    ]);
    expect($rejected->accepted)->toBeFalse()
        ->and($rejected->result)->toBe(AttendanceVerificationResult::OutsideGeofence);

    $remote = AttendanceVerification::factory()->remote()->create([
        'company_id'  => $f['company']->id,
        'employee_id' => $f['employee']->id,
    ]);
    expect($remote->method)->toBe(AttendanceVerificationMethod::None)
        ->and($remote->result)->toBe(AttendanceVerificationResult::RemoteExempt);
});

it('supports active and geofenced states on EmployeeWorkLocationAssignmentFactory', function () {
    $f = geoFixture();

    $assignment = EmployeeWorkLocationAssignment::factory()->active()->create([
        'company_id'       => $f['company']->id,
        'employee_id'      => $f['employee']->id,
        'work_location_id' => $f['location']->id,
    ]);

    expect($assignment->exists)->toBeTrue()
        ->and($assignment->valid_from)->not->toBeNull()
        ->and($assignment->valid_until)->not->toBeNull();

    $geofencedAssignment = EmployeeWorkLocationAssignment::factory()->geofenced()->create([
        'company_id'       => $f['company']->id,
        'employee_id'      => $f['employee']->id,
        'work_location_id' => $f['location']->id,
        'valid_from'       => '2026-10-01',
    ]);
    expect($geofencedAssignment->exists)->toBeTrue();
});

// ---------------------------------------------------------------------
// Phase 2: O5, O6, O7, O9
// ---------------------------------------------------------------------

it('O5: resolves verifiers through the service container and tagged services', function () {
    $service = app(GeofencedAttendanceService::class);

    $gpsVerifier = $service->resolveVerifier('gps');
    expect($gpsVerifier)->toBeInstanceOf(GpsGeofenceVerifier::class)
        ->and($gpsVerifier->method())->toBe(AttendanceVerificationMethod::Gps);

    $remoteVerifier = $service->resolveVerifier('remote');
    expect($remoteVerifier)->toBeInstanceOf(RemoteExemptVerifier::class)
        ->and($remoteVerifier->method())->toBe(AttendanceVerificationMethod::None);

    $tagged = app()->tagged('attendance.verifiers');
    $taggedClasses = collect($tagged)->map(fn ($v) => get_class($v))->all();
    expect($taggedClasses)->toContain(GpsGeofenceVerifier::class, RemoteExemptVerifier::class);
});

it('O6: scopes WorkLocationResource to default_company_id OR allowedCompanies', function () {
    $company1 = Company::factory()->create(['name' => 'Company One', 'is_active' => true]);
    $company2 = Company::factory()->create(['name' => 'Company Two', 'is_active' => true]);
    $company3 = Company::factory()->create(['name' => 'Company Three', 'is_active' => true]);

    $loc1 = WorkLocation::factory()->create(['company_id' => $company1->id, 'name' => 'Loc One']);
    $loc2 = WorkLocation::factory()->create(['company_id' => $company2->id, 'name' => 'Loc Two']);
    $loc3 = WorkLocation::factory()->create(['company_id' => $company3->id, 'name' => 'Loc Three']);

    $user = geoWorkLocationAdmin($company1);
    $user->allowedCompanies()->syncWithoutDetaching([$company2->id]); // Allowed in 1 and 2, but NOT 3
    $this->actingAs($user);

    Livewire::test(ListWorkLocations::class)
        ->assertCanSeeTableRecords([$loc1, $loc2])
        ->assertCanNotSeeTableRecords([$loc3]);
});

it('O7: logs method as none when rejecting for a remote employee', function () {
    $f = geoFixture();
    $home = WorkLocation::factory()->create(['company_id' => $f['company']->id, 'location_type' => 'home', 'is_active' => true]);
    $f['employee']->update(['work_location_id' => $home->id]);

    $service = app(GeofencedAttendanceService::class);

    // First check-in succeeds
    $service->checkIn($f['user'], null, geoRequest());

    // Duplicate check-in fails with OpenShiftExists or AlreadyCheckedIn
    $res = $service->checkIn($f['user'], null, geoRequest());
    expect($res->accepted)->toBeFalse();

    $rejectionVerif = AttendanceVerification::query()
        ->where('employee_id', $f['employee']->id)
        ->where('accepted', false)
        ->latest('id')
        ->first();

    expect($rejectionVerif)->not->toBeNull()
        ->and($rejectionVerif->method)->toBe(AttendanceVerificationMethod::None);
});

it('O9: detects time changes at second resolution in AttendanceRecordResource edit', function () {
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ManageAttendance));

    $record = AttendanceRecord::create([
        'company_id'          => $f['company']->id,
        'employee_id'         => $f['employee']->id,
        'attendance_date'     => '2026-10-05',
        'check_in'            => '2026-10-05 09:00:00',
        'check_out'           => '2026-10-05 17:00:00',
        'source'              => AttendanceSource::Gps->value,
        'verification_status' => AttendanceVerificationStatus::Verified->value,
    ]);

    // Use reflection or invoke applyEdit through component
    $method = new ReflectionMethod(AttendanceRecordResource::class, 'applyEdit');
    $method->setAccessible(true);

    // Edit check_in by 15 seconds (same minute)
    $method->invoke(null, $record, [
        'check_in'          => '2026-10-05 09:00:15',
        'check_out'         => '2026-10-05 17:00:00',
        'correction_reason' => 'Correcting seconds resolution discrepancy',
    ]);

    $record->refresh();
    expect($record->check_in->toDateTimeString())->toBe('2026-10-05 09:00:15')
        ->and($record->verification_status)->toBe(AttendanceVerificationStatus::Overridden->value);

    $correctionVerif = AttendanceVerification::query()
        ->where('attendance_record_id', $record->id)
        ->where('action', AttendanceVerificationAction::HrCorrection)
        ->first();

    expect($correctionVerif)->not->toBeNull()
        ->and($correctionVerif->metadata['after']['check_in'])->toBe('2026-10-05 09:00:15');
});

// ---------------------------------------------------------------------
// Phase 3: Optional Spec Requirements
// ---------------------------------------------------------------------

it('includes the OpenStreetMap link in WorkLocationResource infolist', function () {
    $f = geoFixture();
    $admin = geoWorkLocationAdmin($f['company'], HrPermissions::ManageAttendanceGeofences);
    $this->actingAs($admin);

    $expectedUrl = "https://www.openstreetmap.org/?mlat={$f['location']->latitude}&mlon={$f['location']->longitude}#map=18/{$f['location']->latitude}/{$f['location']->longitude}";

    $schema = WorkLocationResource::infolist(Schema::make()->record($f['location']));
    $entries = $schema->getComponents(withHidden: true);

    $osmEntry = collect($entries)->first(fn ($entry) => method_exists($entry, 'getName') && $entry->getName() === 'openstreetmap_link');
    expect($osmEntry)->not->toBeNull();

    $resolvedUrl = $osmEntry->getUrl();
    if (is_callable($resolvedUrl)) {
        $resolvedUrl = $resolvedUrl($f['location']);
    }
    expect($resolvedUrl)->toBe($expectedUrl);
});

it('creates an hr_correction verification when HR creates a manual record following a rejected GPS attempt that same day', function () {
    $f = geoFixture();
    $hrUser = geoHrUser($f['company'], HrPermissions::ManageAttendance);

    // Create a rejected GPS attempt for this employee
    $rejectedVerif = AttendanceVerification::query()->create([
        'company_id'         => $f['company']->id,
        'employee_id'        => $f['employee']->id,
        'user_id'            => $f['user']->id,
        'action'             => AttendanceVerificationAction::CheckIn,
        'method'             => AttendanceVerificationMethod::Gps,
        'result'             => AttendanceVerificationResult::OutsideGeofence,
        'accepted'           => false,
        'latitude'           => GEO_LAT + 0.02,
        'longitude'          => GEO_LNG + 0.02,
        'accuracy_meters'    => 15.0,
        'server_recorded_at' => '2026-10-05 08:30:00',
    ]);

    expect($rejectedVerif->attendance_record_id)->toBeNull();

    $this->actingAs($hrUser);

    // HR manually creates an attendance record for that employee on that same date
    $record = AttendanceRecord::create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'attendance_date' => '2026-10-05',
        'check_in'        => '2026-10-05 09:00:00',
        'check_out'       => '2026-10-05 17:00:00',
        'source'          => AttendanceSource::Manual->value,
        'notes'           => 'Employee was on client site near office fence',
    ]);

    // The rejected verification is linked to the new record
    $rejectedVerif->refresh();
    expect($rejectedVerif->attendance_record_id)->toBe($record->id);

    // An hr_correction verification was created
    $hrCorrection = AttendanceVerification::query()
        ->where('attendance_record_id', $record->id)
        ->where('action', AttendanceVerificationAction::HrCorrection)
        ->first();

    expect($hrCorrection)->not->toBeNull()
        ->and($hrCorrection->accepted)->toBeTrue()
        ->and($hrCorrection->result)->toBe(AttendanceVerificationResult::Overridden)
        ->and($hrCorrection->method)->toBe(AttendanceVerificationMethod::Manual)
        ->and($hrCorrection->metadata['linked_verification_ids'])->toContain($rejectedVerif->id);

    $record->refresh();
    expect($record->verification_status)->toBe(AttendanceVerificationStatus::Overridden->value);
});

it('verifies that Arabic and Spanish translation files exist and have all keys', function () {
    $en = require base_path('plugins/webkul/employees/resources/lang/en/attendance.php');
    $ar = require base_path('plugins/webkul/employees/resources/lang/ar/attendance.php');
    $es = require base_path('plugins/webkul/employees/resources/lang/es/attendance.php');

    expect($ar)->toBeArray()
        ->and($es)->toBeArray();

    // Check results keys
    foreach (array_keys($en['results']) as $key) {
        expect($ar['results'])->toHaveKey($key)
            ->and($es['results'])->toHaveKey($key);
    }

    // Check with_time keys
    foreach (array_keys($en['with_time']) as $key) {
        expect($ar['with_time'])->toHaveKey($key)
            ->and($es['with_time'])->toHaveKey($key);
    }

    // Check done keys
    expect($ar['done'])->toHaveKeys(['check_in', 'check_out'])
        ->and($es['done'])->toHaveKeys(['check_in', 'check_out']);

    foreach (['check_in', 'check_out'] as $action) {
        foreach (array_keys($en['done'][$action]) as $key) {
            expect($ar['done'][$action])->toHaveKey($key)
                ->and($es['done'][$action])->toHaveKey($key);
        }
    }
});
