<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\EmployeeWorkLocationAssignment;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    $this->service = app(GeofencedAttendanceService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

/** A GPS check-in (and optionally the fixture's manager) ready for review/correction tests. */
function geoCheckedIn(): array
{
    $f = geoFixture();
    app(GeofencedAttendanceService::class)->checkIn($f['user'], geoEvidence(['accuracy' => 80.0]), geoRequest());
    $f['record'] = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();

    return $f;
}

// ---------------------------------------------------------------------
// Company isolation
// ---------------------------------------------------------------------

it('resolves the employee only in the user\'s DEFAULT company, never another one they can reach', function () {
    $f = geoFixture();
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $f['user']->update(['default_company_id' => $otherCompany->id]);
    $f['user']->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);

    $result = $this->service->checkIn($f['user']->refresh(), geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::EmployeeNotEligible);
    expect(AttendanceRecord::query()->count())->toBe(0)->and(AttendanceVerification::query()->count())->toBe(0);
});

it('cannot point an employee at another company\'s work location', function () {
    $f = geoFixture();
    $foreign = WorkLocation::factory()->geofenced()->create(['company_id' => Company::factory()->create()->id]);

    expect(fn () => $f['employee']->update(['work_location_id' => $foreign->id]))->toThrow(InvalidArgumentException::class);
});

it('cannot assign an extra workplace from another company', function () {
    $f = geoFixture();
    $foreign = WorkLocation::factory()->geofenced()->create(['company_id' => Company::factory()->create()->id]);

    expect(fn () => EmployeeWorkLocationAssignment::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'work_location_id' => $foreign->id, 'reason' => 'attack',
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects an assignment whose end date precedes its start date', function () {
    $f = geoFixture();

    expect(fn () => EmployeeWorkLocationAssignment::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'work_location_id' => $f['location']->id,
        'valid_from' => '2026-10-06', 'valid_until' => '2026-10-05', 'reason' => 'x',
    ]))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// Geofence configuration is guarded server-side
// ---------------------------------------------------------------------

it('lets only users with the geofence permission change coordinates, radius or enablement', function () {
    $f = geoFixture();
    $editor = geoGrant(User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]), 'update_employee_work::location');
    $editor->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    $this->actingAs($editor);
    // A fresh instance per attempt: a rejected save leaves its dirty attributes on the object.
    $location = fn (): WorkLocation => WorkLocation::query()->findOrFail($f['location']->id);

    expect(fn () => $location()->update(['latitude' => 25.0]))->toThrow(AuthorizationException::class);
    expect(fn () => $location()->update(['geofence_radius_meters' => 400]))->toThrow(AuthorizationException::class);
    expect(fn () => $location()->update(['geofence_enabled' => false]))->toThrow(AuthorizationException::class);

    // Ordinary, non-geofence edits by the same user are unaffected.
    $location()->update(['name' => 'Head Office']);
    expect($location()->name)->toBe('Head Office');

    // A separate user who does hold the geofence permission can make the same change.
    $permitted = geoGrant(User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]), 'update_employee_work::location', HrPermissions::ManageAttendanceGeofences);
    $permitted->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);
    $this->actingAs($permitted);
    $location()->update(['geofence_radius_meters' => 400]);
    expect($location()->geofence_radius_meters)->toBe(400);
});

it('refuses to move a work location into a company the signed-in user cannot act in', function () {
    $f = geoFixture();
    $editor = geoGrant(User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]), 'update_employee_work::location', HrPermissions::ManageAttendanceGeofences);
    $editor->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);
    $stranger = Company::factory()->create();

    $this->actingAs($editor);

    expect(fn () => $f['location']->update(['company_id' => $stranger->id]))->toThrow(AuthorizationException::class);
});

it('never stores coordinates or a geofence on a home location', function () {
    $company = Company::factory()->create();

    expect(fn () => WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'home', 'latitude' => 24.86, 'longitude' => 67.0]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'home', 'geofence_enabled' => true]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects impossible geofence values', function (array $bad) {
    $company = Company::factory()->create();

    expect(fn () => WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'office'] + $bad))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'latitude'        => [['latitude' => 95, 'longitude' => 10, 'geofence_radius_meters' => 100, 'geofence_enabled' => true]],
    'null island'     => [['latitude' => 0, 'longitude' => 0, 'geofence_radius_meters' => 100, 'geofence_enabled' => true]],
    'radius small'    => [['latitude' => 24.8, 'longitude' => 67.0, 'geofence_radius_meters' => 10, 'geofence_enabled' => true]],
    'radius huge'     => [['latitude' => 24.8, 'longitude' => 67.0, 'geofence_radius_meters' => 99999, 'geofence_enabled' => true]],
    'enabled/no data' => [['geofence_enabled' => true]],
]);

// ---------------------------------------------------------------------
// Evidence visibility
// ---------------------------------------------------------------------

it('shows raw location evidence only to users holding the evidence permission', function () {
    $f = geoCheckedIn();
    $verification = AttendanceVerification::query()->sole();

    $manager = geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ReviewAttendanceVerifications);
    $auditor = geoHrUser($f['company'], HrPermissions::ViewAttendanceLocationEvidence, HrPermissions::ViewAttendance);

    expect($manager->can('view', $verification))->toBeTrue()
        ->and($manager->can('viewLocationEvidence', $verification))->toBeFalse()
        ->and($auditor->can('viewLocationEvidence', $verification))->toBeTrue();
});

it('lets an employee see their own attempt but never its raw evidence, nor anyone else\'s', function () {
    $f = geoCheckedIn();
    $verification = AttendanceVerification::query()->sole();
    [$otherUser] = geoEmployee($f['company'], $f['location']);

    expect($f['user']->can('view', $verification))->toBeTrue()
        ->and($f['user']->can('viewLocationEvidence', $verification))->toBeFalse()
        ->and($otherUser->can('view', $verification))->toBeFalse()
        ->and($otherUser->can('viewLocationEvidence', $verification))->toBeFalse();
});

it('denies evidence and review to HR staff from a different company', function () {
    $f = geoCheckedIn();
    $verification = AttendanceVerification::query()->sole();
    $foreignHr = geoHrUser(Company::factory()->create(['is_active' => true]), HrPermissions::ViewAttendanceLocationEvidence, HrPermissions::ReviewAttendanceVerifications, HrPermissions::ViewAttendance);

    expect($foreignHr->can('view', $verification))->toBeFalse()
        ->and($foreignHr->can('viewLocationEvidence', $verification))->toBeFalse()
        ->and($foreignHr->can('review', $verification))->toBeFalse();
});

it('keeps raw coordinates, IP and device out of any serialisation of a verification', function () {
    geoCheckedIn();

    $array = AttendanceVerification::query()->sole()->toArray();

    expect($array)->not->toHaveKeys(['latitude', 'longitude', 'ip_address', 'user_agent']);
});

// ---------------------------------------------------------------------
// Review
// ---------------------------------------------------------------------

it('lets a permitted reviewer approve a flagged check-in and audits it, leaving the times alone', function () {
    $f = geoCheckedIn();
    $reviewer = geoHrUser($f['company'], HrPermissions::ReviewAttendanceVerifications);
    $before = $f['record']->check_in->toDateTimeString();

    $this->service->reviewVerification($f['record'], $reviewer, true, 'Verified by camera log');

    expect($f['record']->fresh()->verification_status)->toBe('reviewed')
        ->and($f['record']->fresh()->check_in->toDateTimeString())->toBe($before);
    $flagged = AttendanceVerification::query()->where('action', 'check_in')->sole();
    expect($flagged->review_status)->toBe('approved')->and($flagged->reviewed_by)->toBe($reviewer->id)->and($flagged->review_note)->toBe('Verified by camera log');
    expect(AttendanceVerification::query()->where('action', 'review')->sole()->result)->toBe(Result::ReviewApproved);
});

it('rejecting a review flags the day without changing the recorded times', function () {
    $f = geoCheckedIn();
    $reviewer = geoHrUser($f['company'], HrPermissions::ReviewAttendanceVerifications);

    $this->service->reviewVerification($f['record'], $reviewer, false, 'Looks fake');

    $fresh = $f['record']->fresh();
    expect($fresh->verification_status)->toBe('review_rejected')->and($fresh->check_in)->not->toBeNull();
});

it('refuses a review without permission, from the employee themselves, or without a note', function () {
    $f = geoCheckedIn();
    $noPermission = geoHrUser($f['company']);
    $self = geoGrant($f['user'], HrPermissions::ReviewAttendanceVerifications, HrPermissions::ViewAllRecords);
    $reviewer = geoHrUser($f['company'], HrPermissions::ReviewAttendanceVerifications);

    expect(fn () => $this->service->reviewVerification($f['record'], $noPermission, true, 'ok'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->reviewVerification($f['record'], $self, true, 'ok'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->reviewVerification($f['record'], $reviewer, true, '   '))->toThrow(InvalidArgumentException::class);
    expect($f['record']->fresh()->verification_status)->toBe('needs_review');
});

it('has nothing to review on a cleanly verified record', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();
    $reviewer = geoHrUser($f['company'], HrPermissions::ReviewAttendanceVerifications);

    expect(fn () => $this->service->reviewVerification($record, $reviewer, true, 'ok'))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------
// HR corrections
// ---------------------------------------------------------------------

it('applies a reasoned HR correction to a GPS record with a full before/after audit', function () {
    $f = geoCheckedIn();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);

    $verification = $this->service->recordHrCorrection($f['record'], $hr, ['check_in' => '2026-10-05 04:30:00'], 'Employee arrived early, door log confirms');

    $fresh = $f['record']->fresh();
    expect($fresh->check_in->toDateTimeString())->toBe('2026-10-05 04:30:00')
        ->and($fresh->verification_status)->toBe('overridden')->and($fresh->approved_by)->toBe($hr->id);
    expect($verification->metadata['before']['check_in'])->toBe('2026-10-05 05:00:00')
        ->and($verification->metadata['after']['check_in'])->toBe('2026-10-05 04:30:00')
        ->and($verification->metadata['reason'])->toBe('Employee arrived early, door log confirms')
        ->and($verification->user_id)->toBe($hr->id);
    // The service's own write must not also trigger the fallback audit hook.
    expect(AttendanceVerification::query()->where('action', 'hr_correction')->count())->toBe(1);
});

it('never lets an employee correct their own attendance', function () {
    $f = geoCheckedIn();
    $self = geoGrant($f['user'], HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords);

    expect(fn () => $this->service->recordHrCorrection($f['record'], $self, ['check_in' => '2026-10-05 03:00:00'], 'I was here earlier honestly'))
        ->toThrow(AuthorizationException::class);
    expect($f['record']->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 05:00:00');
});

it('refuses corrections from users without HR attendance rights, or from another company', function () {
    $f = geoCheckedIn();
    $plain = geoHrUser($f['company']);
    $foreign = geoHrUser(Company::factory()->create(['is_active' => true]), HrPermissions::ManageAttendance);

    expect(fn () => $this->service->recordHrCorrection($f['record'], $plain, ['check_in' => '2026-10-05 04:00:00'], 'long enough reason'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->recordHrCorrection($f['record'], $foreign, ['check_in' => '2026-10-05 04:00:00'], 'long enough reason'))->toThrow(AuthorizationException::class);
});

it('requires a real reason and a sane time order for a correction', function () {
    $f = geoCheckedIn();
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);

    expect(fn () => $this->service->recordHrCorrection($f['record'], $hr, ['check_in' => '2026-10-05 04:00:00'], 'short'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->recordHrCorrection($f['record'], $hr, ['check_out' => '2026-10-05 03:00:00'], 'checkout before checkin'))->toThrow(InvalidArgumentException::class);
    expect($f['record']->fresh()->check_out)->toBeNull();
});

it('audits ANY later edit of a GPS-verified time, even one made straight on the model', function () {
    $f = geoCheckedIn();

    $f['record']->update(['check_in' => '2026-10-05 03:00:00']);

    $audit = AttendanceVerification::query()->where('action', 'hr_correction')->sole();
    expect($audit->metadata['path'])->toBe('direct_model_update')
        ->and($audit->metadata['before']['check_in'])->toBe('2026-10-05 05:00:00')
        ->and($audit->metadata['after']['check_in'])->toBe('2026-10-05 03:00:00');
    expect($f['record']->fresh()->verification_status)->toBe('overridden');
});

it('does not add audit noise to ordinary manual attendance edits', function () {
    $f = geoFixture();
    $record = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'check_in'   => '2026-10-05 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    $record->update(['check_in' => '2026-10-05 04:10:00']);

    expect(AttendanceVerification::query()->count())->toBe(0);
});

it('audits a line-manager-approved time change applied to a GPS record', function () {
    $f = geoCheckedIn();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    $requests = app(EmployeeRequestService::class);

    $request = $requests->requestAttendanceTimeChange($f['record'], $f['user'], ['check_in' => '2026-10-05 04:45:00'], 'Badge reader was down');
    $requests->approve($request, $managerUser, 'Confirmed');

    expect($f['record']->fresh()->check_in->toDateTimeString())->toBe('2026-10-05 04:45:00');
    expect(AttendanceVerification::query()->where('action', 'hr_correction')->count())->toBe(1);
});

// ---------------------------------------------------------------------
// Missed-day request
// ---------------------------------------------------------------------

it('creates attendance for a missed day only after the line manager approves it', function () {
    $f = geoFixture();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    $requests = app(EmployeeRequestService::class);

    $request = $requests->requestMissingAttendance($f['employee'], $f['user'], '2026-10-03', ['check_in' => '2026-10-03 04:00:00', 'check_out' => '2026-10-03 12:00:00'], 'Phone died');
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(0);

    $requests->approve($request, $managerUser, 'Seen it');

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();
    expect($record->attendance_date->toDateString())->toBe('2026-10-03')->and($record->source)->toBe('manual')
        ->and((float) $record->worked_hours)->toBe(8.0)->and($record->approved_by)->toBe($managerUser->id);
});

it('creates nothing when a missed-day request is rejected', function () {
    $f = geoFixture();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    $requests = app(EmployeeRequestService::class);

    $request = $requests->requestMissingAttendance($f['employee'], $f['user'], '2026-10-03', ['check_in' => '2026-10-03 04:00:00'], 'Phone died');
    $requests->reject($request, $managerUser, 'No evidence');

    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(0);
});

it('refuses missed-day requests that are too old, in the future, duplicate, or lack a check-in', function (string $date, array $times, ?Closure $prepare) {
    $f = geoFixture();
    if ($prepare) {
        $prepare($f);
    }

    expect(fn () => app(EmployeeRequestService::class)->requestMissingAttendance($f['employee'], $f['user'], $date, $times, 'reason'))
        ->toThrow(RuntimeException::class);
})->with([
    'too old'    => ['2026-08-01', ['check_in' => '2026-08-01 04:00:00'], null],
    'future'     => ['2026-10-09', ['check_in' => '2026-10-09 04:00:00'], null],
    'no checkin' => ['2026-10-03', ['check_out' => '2026-10-03 12:00:00'], null],
    'duplicate'  => ['2026-10-03', ['check_in' => '2026-10-03 04:00:00'], fn ($f) => AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-03', 'status' => 'present', 'source' => 'manual',
    ])],
]);

// ---------------------------------------------------------------------
// Replay, concurrency and the append-only evidence trail
// ---------------------------------------------------------------------

it('answers a replayed request from the original outcome even a day later', function () {
    $f = geoFixture();
    $evidence = geoEvidence();
    $first = $this->service->checkIn($f['user'], $evidence, geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'));
    $replay = $this->service->checkIn($f['user'], $evidence, geoRequest());

    expect($replay->result)->toBe($first->result);
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1)
        ->and(AttendanceVerification::query()->count())->toBe(1);
});

it('scopes request-id idempotency to the employee so it cannot suppress someone else\'s attempt', function () {
    $f = geoFixture();
    [$otherUser] = geoEmployee($f['company'], $f['location']);
    $shared = (string) Str::uuid();

    $this->service->checkIn($f['user'], geoEvidence(['requestId' => $shared]), geoRequest());
    $second = $this->service->checkIn($otherUser, geoEvidence(['requestId' => $shared, 'latitude' => GEO_LAT + 0.0001]), geoRequest());

    expect($second->accepted)->toBeTrue();
    expect(AttendanceRecord::query()->count())->toBe(2);
});

it('serialises attempts for one employee with a row lock and never creates two records', function () {
    $f = geoFixture();
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $locks = collect($statements)->filter(fn ($sql) => str_contains($sql, 'from `employees_employees`') && str_contains($sql, 'for update'));
    expect($locks->count())->toBeGreaterThanOrEqual(2);
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);
});

it('enforces one attendance record per employee per day at the database level', function () {
    $f = geoFixture();
    $attributes = ['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05', 'status' => 'present', 'source' => 'manual'];
    AttendanceRecord::query()->create($attributes);

    expect(fn () => AttendanceRecord::query()->create($attributes))->toThrow(QueryException::class);
});

it('keeps evidence append-only: no mass delete, no rewriting the result, no changing location', function () {
    $f = geoCheckedIn();
    $verification = AttendanceVerification::query()->sole();

    $fresh = fn (): AttendanceVerification => AttendanceVerification::query()->findOrFail($verification->id);

    expect(fn () => AttendanceVerification::query()->delete())->toThrow(LogicException::class);
    expect(fn () => $fresh()->delete())->toThrow(LogicException::class);
    expect(fn () => $fresh()->update(['result' => Result::Overridden]))->toThrow(LogicException::class);
    expect(fn () => $fresh()->update(['accepted' => false]))->toThrow(LogicException::class);
    expect(fn () => $fresh()->update(['latitude' => 1.0]))->toThrow(LogicException::class);
    expect(fn () => AttendanceVerification::query()->update(['latitude' => 1.0]))->toThrow(LogicException::class);
    expect(fn () => AttendanceVerification::query()->update(['result' => 'verified']))->toThrow(LogicException::class);

    // Review columns and privacy pruning remain possible.
    $fresh()->update(['review_note' => 'seen']);
    $fresh()->update(['latitude' => null, 'ip_address' => null]);
    expect($fresh())->review_note->toBe('seen')->latitude->toBeNull();
});

it('prunes precise evidence older than the retention period but keeps the audit trail', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(CarbonImmutable::parse('2027-10-20 05:00:00', 'UTC'));
    $this->service->checkIn($f['user'], geoEvidence(['latitude' => GEO_LAT + 0.0002]), geoRequest());

    $this->artisan('hr:prune-attendance-evidence')->assertSuccessful();

    $old = AttendanceVerification::query()->orderBy('id')->first();
    $recent = AttendanceVerification::query()->orderByDesc('id')->first();
    expect($old->latitude)->toBeNull()->and($old->ip_address)->toBeNull()->and($old->user_agent)->toBeNull()
        ->and($old->result)->toBe(Result::Verified)->and((float) $old->distance_meters)->toBe(0.0)
        ->and($recent->latitude)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Permission catalogue
// ---------------------------------------------------------------------

it('registers the three new permissions and hands each role only what it should have', function () {
    foreach ([HrPermissions::ManageAttendanceGeofences, HrPermissions::ViewAttendanceLocationEvidence, HrPermissions::ReviewAttendanceVerifications] as $permission) {
        expect(HrPermissions::all())->toContain($permission);
    }

    expect(HrPermissions::manager())->toContain(HrPermissions::ReviewAttendanceVerifications)
        ->not->toContain(HrPermissions::ViewAttendanceLocationEvidence)
        ->not->toContain(HrPermissions::ManageAttendanceGeofences);
    expect(HrPermissions::hrAuditor())->toContain(HrPermissions::ViewAttendanceLocationEvidence)
        ->not->toContain(HrPermissions::ReviewAttendanceVerifications)
        ->not->toContain(HrPermissions::ManageAttendanceGeofences);
    expect(HrPermissions::hrManager())->toContain(HrPermissions::ManageAttendanceGeofences, HrPermissions::ViewAttendanceLocationEvidence, HrPermissions::ReviewAttendanceVerifications);
    expect(HrPermissions::hrAdministrator())->toContain(HrPermissions::ManageAttendanceGeofences)
        ->not->toContain(HrPermissions::ViewAttendanceLocationEvidence);
    expect(HrPermissions::hrOfficer())->not->toContain(HrPermissions::ManageAttendanceGeofences)
        ->not->toContain(HrPermissions::ViewAttendanceLocationEvidence);
});

it('ties an employee to their own company on the assignment even if a company id is forged', function () {
    $f = geoFixture();
    $other = Company::factory()->create();

    expect(fn () => EmployeeWorkLocationAssignment::query()->create([
        'company_id' => $other->id, 'employee_id' => $f['employee']->id, 'work_location_id' => $f['location']->id, 'reason' => 'forged',
    ]))->toThrow(InvalidArgumentException::class);
});
