<?php

/**
 * Regression tests for the attendance workflow fixes found in the post-handover
 * audit: long/forgotten shifts, approved Time Off leave, stale browser fixes,
 * audited deletion of GPS days, forgotten-shift flagging + line-manager
 * notification, correction requests with no approver, and HR form validation.
 */

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\HrPermissions;

beforeEach(function () {
    $this->service = app(GeofencedAttendanceService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

function fixRecord($employee): AttendanceRecord
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
}

/** An employee plus a line manager who has a user account. */
function fixWithManager(): array
{
    $f = geoFixture();
    [$managerUser, $manager] = geoEmployee($f['company'], $f['location']);
    $f['employee']->update(['parent_id' => $manager->id]);
    $f['managerUser'] = $managerUser;

    return $f;
}

// ---------------------------------------------------------------------
// Long / forgotten shifts
// ---------------------------------------------------------------------

it('flags a check-out that stretches the shift past long_shift_hours instead of verifying it', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // Forgot to check out; taps Check Out 15 hours later (inside max_shift_hours).
    $this->travelTo(CarbonImmutable::parse('2026-10-05 20:00:00', 'UTC'));
    $result = $this->service->checkOut($f['user'], geoEvidence(['latitude' => GEO_LAT + 0.0001]), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::NeedsReview);
    expect(fixRecord($f['employee'])->verification_status)->toBe('needs_review');
    $checkout = AttendanceVerification::query()->where('action', 'check_out')->sole();
    expect($checkout->flags)->toContain('long_shift')->and($checkout->review_status)->toBe('pending');
});

it('still verifies a normal-length shift', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $result = $this->service->checkOut($f['user'], geoEvidence(['latitude' => GEO_LAT + 0.0001]), geoRequest());

    expect($result->result)->toBe(Result::Verified);
});

it('uses the scheduled end plus the grace period when the day has a schedule', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id'      => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'scheduled_start' => '2026-10-05 04:00:00', 'scheduled_end' => '2026-10-05 12:00:00', 'status' => 'absent', 'source' => 'import',
    ]);
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // 16:00 is past 12:00 + 3h grace, though only 11h after check-in.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:00', 'UTC'));
    $result = $this->service->checkOut($f['user'], geoEvidence(['latitude' => GEO_LAT + 0.0001]), geoRequest());

    expect($result->result)->toBe(Result::NeedsReview);
    expect(AttendanceVerification::query()->where('action', 'check_out')->sole()->flags)->toContain('long_shift');
});

it('sends shifts that were never checked out to the review queue once, and tells the line manager', function () {
    $f = fixWithManager();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    // Still inside max_shift_hours: nothing to flag yet.
    expect($this->service->flagForgottenShifts())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:00:00', 'UTC'));
    expect($this->service->flagForgottenShifts(CarbonImmutable::now('UTC')))->toBe(1)
        ->and($this->service->flagForgottenShifts(CarbonImmutable::now('UTC')))->toBe(0);

    $record = fixRecord($f['employee']);
    expect($record->verification_status)->toBe('needs_review')->and($record->check_out)->toBeNull();
    $flag = AttendanceVerification::query()->where('action', 'system_flag')->sole();
    expect($flag->flags)->toBe(['missing_check_out'])->and($flag->review_status)->toBe('pending');

    $notification = $f['managerUser']->notifications()->sole();
    expect($notification->data['title'])->toContain('Missing check-out');
});

it('does not flag manual records or shifts that were closed', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-01',
        'check_in'   => '2026-10-01 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    expect($this->service->flagForgottenShifts())->toBe(0);
});

it('runs the flag command from the scheduler entry point', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:00:00', 'UTC'));

    $this->artisan('hr:flag-forgotten-shifts')->expectsOutputToContain('Flagged 1')->assertSuccessful();
});

// ---------------------------------------------------------------------
// Approved leave from the Time Off module
// ---------------------------------------------------------------------

function fixLeave($employee, string $from, string $to, string $state = 'validate_two', array $extra = []): void
{
    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'state'             => $state,
        'request_date_from' => $from.' 00:00:00',
        'request_date_to'   => $to.' 00:00:00',
        'number_of_days'    => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

it('blocks check-in on a day of approved full-day leave', function () {
    $f = geoFixture();
    fixLeave($f['employee'], '2026-10-04', '2026-10-06');

    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($result->result)->toBe(Result::OnLeaveOrHoliday)->and($result->accepted)->toBeFalse();
    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('does not block check-in for leave that is not approved, is half-day, or is on another day', function (string $state, array $extra, string $from, string $to) {
    $f = geoFixture();
    fixLeave($f['employee'], $from, $to, $state, $extra);

    expect($this->service->checkIn($f['user'], geoEvidence(), geoRequest())->accepted)->toBeTrue();
})->with([
    'only submitted' => ['confirm', [], '2026-10-05', '2026-10-05'],
    'refused'        => ['refuse', [], '2026-10-05', '2026-10-05'],
    'half day'       => ['validate_two', ['request_unit_half' => 1], '2026-10-05', '2026-10-05'],
    'other day'      => ['validate_two', [], '2026-10-06', '2026-10-07'],
]);

// ---------------------------------------------------------------------
// Stale browser fixes and device clock skew
// ---------------------------------------------------------------------

function fixEvidence(CarbonImmutable $capturedAt, ?CarbonImmutable $clientNow, float $latOffset = 0.0): LocationEvidence
{
    return new LocationEvidence(GEO_LAT + $latOffset, GEO_LNG, 10.0, $capturedAt, (string) Str::uuid(), $clientNow);
}

it('accepts a fresh reading from a phone whose clock runs 10 minutes slow', function () {
    $f = geoFixture();
    $deviceNow = CarbonImmutable::now('UTC')->subMinutes(10);

    $result = $this->service->checkIn($f['user'], fixEvidence($deviceNow->subSeconds(5), $deviceNow), geoRequest());

    expect($result->result)->toBe(Result::Verified);
});

it('still refuses a stale reading at check-in', function () {
    $f = geoFixture();
    $deviceNow = CarbonImmutable::now('UTC');

    $result = $this->service->checkIn($f['user'], fixEvidence($deviceNow->subMinutes(9), $deviceNow), geoRequest());

    expect($result->result)->toBe(Result::StaleLocation)->and(AttendanceRecord::query()->count())->toBe(0);
});

it('keeps a check-out made from a cached (stale) fix but sends it for review', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    $deviceNow = CarbonImmutable::now('UTC');
    $result = $this->service->checkOut($f['user'], fixEvidence($deviceNow->subMinutes(9), $deviceNow, 0.0001), geoRequest());

    expect($result->accepted)->toBeTrue()->and($result->result)->toBe(Result::NeedsReview);
    expect(fixRecord($f['employee'])->check_out)->not->toBeNull();
    expect(AttendanceVerification::query()->where('action', 'check_out')->sole()->flags)->toContain('stale_location');
});

it('rejects a stale check-out when the policy is reject', function () {
    config(['hr_attendance_geofence.checkout_without_location' => 'reject']);
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    $deviceNow = CarbonImmutable::now('UTC');

    expect($this->service->checkOut($f['user'], fixEvidence($deviceNow->subMinutes(9), $deviceNow, 0.0001), geoRequest())->result)
        ->toBe(Result::StaleLocation);
});

it('passes the device clock from the browser payload through to the evaluator', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $f = geoFixture();
    $this->actingAs($f['user']);
    $deviceNow = CarbonImmutable::now('UTC')->subMinutes(10);

    Livewire::test(MyAttendance::class)->call('checkIn', [
        'latitude'          => GEO_LAT,
        'longitude'         => GEO_LNG,
        'accuracy'          => 10,
        'captured_at'       => $deviceNow->subSeconds(3)->getTimestampMs(),
        'client_now'        => $deviceNow->getTimestampMs(),
        'client_request_id' => (string) Str::uuid(),
    ])->assertSet('outcome.result', 'verified');
});

// ---------------------------------------------------------------------
// Deleting GPS-verified days
// ---------------------------------------------------------------------

it('deletes a GPS day only with a reason, keeping the evidence and an audit snapshot', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = fixRecord($f['employee']);
    $hr = geoHrUser($f['company'], HrPermissions::ManageAttendance);

    expect(fn () => $this->service->deleteRecord($record, $hr, 'short'))->toThrow(InvalidArgumentException::class);
    expect(AttendanceRecord::query()->count())->toBe(1);

    $this->actingAs($hr);
    $this->service->deleteRecord($record, $hr, 'Duplicate test entry created during setup');

    expect(AttendanceRecord::query()->count())->toBe(0);
    expect(AttendanceVerification::query()->where('action', 'check_in')->count())->toBe(1);
    $audit = AttendanceVerification::query()->where('action', 'record_deleted')->sole();
    expect($audit->user_id)->toBe($hr->id)
        ->and($audit->metadata['reason'])->toBe('Duplicate test entry created during setup')
        ->and($audit->metadata['deleted_record']['id'])->toBe($record->id)
        ->and($audit->metadata['deleted_record']['check_in'])->toBe('2026-10-05 05:00:00');
});

it('never lets anyone delete their own attendance, or delete without HR rights', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = fixRecord($f['employee']);
    $self = geoGrant($f['user'], HrPermissions::ManageAttendance, HrPermissions::ViewAllRecords);
    $viewer = geoHrUser($f['company'], HrPermissions::ViewAttendance);

    expect(fn () => $this->service->deleteRecord($record, $self, 'I want to remove this day'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->deleteRecord($record, $viewer, 'I want to remove this day'))->toThrow(AuthorizationException::class);
    expect(AttendanceRecord::query()->count())->toBe(1);
});

it('audits even a direct model delete of a GPS day', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    fixRecord($f['employee'])->delete();

    $audit = AttendanceVerification::query()->where('action', 'record_deleted')->sole();
    expect($audit->metadata['reason'])->toBeNull()->and($audit->metadata['deleted_record']['source'])->toBe('gps');
});

it('does not add audit rows when an ordinary manual record is deleted', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-01', 'status' => 'present', 'source' => 'manual',
    ])->delete();

    expect(AttendanceVerification::query()->count())->toBe(0);
});

it('uses a reason-required delete action for GPS days in the HR register', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    $record = fixRecord($f['employee']);
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->assertTableActionHidden('delete', $record)
        ->assertTableActionVisible('delete_verified_record', $record)
        ->callTableAction('delete_verified_record', $record, ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);
    expect(AttendanceRecord::query()->count())->toBe(1);

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('delete_verified_record', $record, ['reason' => 'Created by mistake during a demo'])
        ->assertHasNoTableActionErrors();

    expect(AttendanceRecord::query()->count())->toBe(0);
    expect(AttendanceVerification::query()->where('action', 'record_deleted')->sole()->metadata['reason'])->toBe('Created by mistake during a demo');
});

// ---------------------------------------------------------------------
// Review notifications and correction routing
// ---------------------------------------------------------------------

it('notifies the line manager when a check-in needs review, and not when it is verified', function () {
    $f = fixWithManager();

    $this->service->checkIn($f['user'], geoEvidence(['accuracy' => 80.0]), geoRequest());
    expect($f['managerUser']->notifications()->count())->toBe(1);
    expect($f['managerUser']->notifications()->first()->data['title'])->toContain('Check-in flagged');

    [$otherUser, $other] = geoEmployee($f['company'], $f['location'], ['parent_id' => $f['employee']->parent_id]);
    $this->service->checkIn($otherUser, geoEvidence(['latitude' => GEO_LAT + 0.0002]), geoRequest());
    expect($f['managerUser']->notifications()->count())->toBe(1);
});

it('refuses a correction request when the employee has no line manager to approve it', function () {
    $f = geoFixture();
    $record = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04',
        'check_in'   => '2026-10-04 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $requests = app(EmployeeRequestService::class);

    expect(fn () => $requests->requestAttendanceTimeChange($record, $f['user'], ['check_in' => '2026-10-04 03:30:00'], 'Badge reader down'))
        ->toThrow(RuntimeException::class, 'No line manager');
    expect(fn () => $requests->requestMissingAttendance($f['employee'], $f['user'], '2026-10-03', ['check_in' => '2026-10-03 04:00:00'], 'Phone died'))
        ->toThrow(RuntimeException::class, 'No line manager');
});

it('still routes a correction request to the line manager when there is one', function () {
    $f = fixWithManager();
    $record = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04',
        'check_in'   => '2026-10-04 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    $request = app(EmployeeRequestService::class)->requestAttendanceTimeChange($record, $f['user'], ['check_in' => '2026-10-04 03:30:00'], 'Badge reader down');

    expect($request->status)->toBe('pending_approval');
});

// ---------------------------------------------------------------------
// Page and HR form
// ---------------------------------------------------------------------

it('tells an employee with no workplace up front instead of showing a button that can only fail', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $f = geoFixture();
    $f['employee']->update(['work_location_id' => null]);
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('No workplace is set up for mobile check-in')
        ->assertDontSee('Requesting location');
});

it('rejects an HR entry whose check-out is before its check-in', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance));

    Livewire::test(ManageAttendanceRecords::class)
        ->callTableAction('create', data: [
            'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-04',
            'check_in'    => '2026-10-04 17:00:00', 'check_out' => '2026-10-04 09:00:00', 'status' => 'present', 'source' => 'manual',
        ])
        ->assertHasTableActionErrors(['check_out' => 'after']);

    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('links a rejected GPS attempt to a later manual entry on the employee\'s LOCAL date', function () {
    $f = geoFixture(['time_zone' => 'Asia/Karachi']);

    // 02:00 in Karachi on 6 Oct is 21:00 UTC on 5 Oct.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 21:00:00', 'UTC'));
    $this->service->checkIn($f['user'], geoEvidence(geoOutside()), geoRequest());

    $this->actingAs(geoHrUser($f['company'], HrPermissions::ManageAttendance));
    $record = AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-06',
        'check_in'   => '2026-10-05 21:00:00', 'status' => 'present', 'source' => 'manual', 'notes' => 'GPS failed at the gate',
    ]);

    expect(AttendanceVerification::query()->where('action', 'check_in')->sole()->attendance_record_id)->toBe($record->id);
    expect($record->fresh()->verification_status)->toBe('overridden');
});

// ---------------------------------------------------------------------
// Navigation: employees check in, HR manages (like Time Off)
// ---------------------------------------------------------------------

function fixAttendanceNavigation(): array
{
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();

    $group = collect($panel->getNavigation())->first(fn ($group) => $group->getLabel() === 'Attendance');

    return $group ? collect($group->getItems())->map(fn ($item) => $item->getLabel())->values()->all() : [];
}

it('gives a plain employee (no HR permissions) My Attendance and nothing HR-only', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    expect(MyAttendance::canAccess())->toBeTrue();
    expect(fixAttendanceNavigation())->toBe(['My Attendance']);
});

it('gives HR the register, the review queue and the workplace setup in the same section', function () {
    $f = geoFixture();
    $this->actingAs(geoHrUser($f['company'], HrPermissions::ViewAttendance, HrPermissions::ManageAttendance, HrPermissions::ReviewAttendanceVerifications, 'view_any_employee_work::location'));

    expect(fixAttendanceNavigation())->toContain('My Attendance', 'Attendance Register', 'Needs Review', 'Workplaces & Geofences');
});
