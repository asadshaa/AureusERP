# Geofenced Attendance — Implementation Specification

**Status:** Authoritative implementation contract. Specification only, no code has been written.
**Author role:** Architecture / security / QA review (Opus). **Implementer:** Sonnet.
**Date:** 2026-09-30
**Stack verified in repo:** Laravel `^13.0`, Filament `^5.0`, Livewire `^4.0`, Pest `^4.4`, PHP 8.3, MySQL 8.4. Confirm exact versions in `composer.lock` and check Boost `search-docs` before using any version-sensitive Filament or Livewire API. The API names in this document are the intent. Verify their exact spelling against the installed version.

---

## 0. How to read this document

- **MUST / MUST NOT** are binding. **SHOULD** is strongly recommended. Deviate only if you have a written reason.
- Every file path is relative to the repo root.
- The sections follow the 23-part plan in the brief. §24 is the final review checklist.
- If the repository contradicts this document when you implement it, **the repository wins**. Stop, note the discrepancy, and adapt with the smallest change that keeps every invariant in §3.3.

---

## 1. Repository findings (inspected, not assumed)

### 1.1 Attendance: already exists, and must be extended
| Item | Location | Finding |
|---|---|---|
| Model | `plugins/webkul/employees/src/Models/AttendanceRecord.php` | Table `employees_attendance_records`. Fillable: `company_id, employee_id, approved_by, creator_id, attendance_date, scheduled_start, scheduled_end, check_in, check_out, worked_hours, overtime_hours, late_minutes, early_departure_minutes, status, source, source_reference, notes`. The `creating` hook defaults `creator_id` and `company_id` (from the employee). The `saving` hook computes `worked_hours`, `late_minutes` and `early_departure_minutes`. **No audit trait.** |
| Migration | `plugins/webkul/employees/database/migrations/2026_08_25_000011_create_hr_operations_tables.php` | `status` string(40) defaults to `present`. `source` string(40) defaults to `manual`. **UNIQUE(`company_id`,`employee_id`,`attendance_date`)** is named `attendance_employee_date_unique`. Index `attendance_reporting_index(company_id, attendance_date, status)`. Timestamps are `timestamp` columns. |
| Resource | `plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php` | Single "manage" page (`ManageAttendanceRecords`). The form hardcodes the `status` options (`present, absent, leave, holiday, remote`) and the `source` options (`manual, import, biometric, api`). `getEloquentQuery()` scopes to `default_company_id` plus `HrHierarchyService::visibleEmployeeIds()`. `canViewAny()` requires `hr_manage_attendance` or `hr_view_attendance`. A "Request Time Change" record action calls `EmployeeRequestService::requestAttendanceTimeChange()`. |
| Correction workflow | `plugins/webkul/employees/src/Services/EmployeeRequestService.php` (`requestAttendanceTimeChange`, `synchronize`, `applyAttendanceTimeChange`) and `database/seeders/AttendanceWorkflowSeeder.php` | An existing approval-based correction flow: EmployeeRequest (`attendance_time_change`, category `attendance_correction`) → `ApprovalEngine` → line manager (`hierarchy_route: requester_manager`). The original and requested values are frozen in `payload`, and the change is applied to the record only on approval. **This is the correction mechanism to reuse.** It requires that an `AttendanceRecord` already exists. |
| Reporting | `plugins/webkul/employees/src/Services/HrAnalyticsService.php` | Reads `employees_attendance_records` directly: `count`, `late_minutes>0`, `early_departure_minutes>0`, `sum(overtime_hours)`. New nullable columns do not affect it. |
| Tests | `plugins/webkul/employees/tests/Feature/TestAttendanceScenarioTest.php`, `AttendanceTimeChangeTest.php`, `HrPlatformTest.php`, `HrRolePermissionsTest.php` | Fixture pattern: `Company::factory()`, `User::factory()` with `default_company_id` plus `allowedCompanies()`, and `Employee::query()->create([... 'user_id', 'parent_id'])`. These MUST keep passing unchanged. |

### 1.2 Work locations: already exist, and must be extended rather than duplicated
| Item | Location | Finding |
|---|---|---|
| Model | `plugins/webkul/employees/src/Models/WorkLocation.php` | Table `employees_work_locations`: `name, location_type (enum), location_number, is_active, company_id (FK restrict), creator_id`, soft deletes. Has `scopeActive()`. **No coordinates.** |
| Enum | `plugins/webkul/employees/src/Enums/WorkLocation.php` | `home`, `office`, `other`. |
| Resource | `plugins/webkul/employees/src/Filament/Clusters/Configurations/Resources/WorkLocationResource.php` | Lives in the HR Configurations cluster. **Defect:** there is no `getEloquentQuery()` company scope, and the `company_id` Select lists every company. This must be fixed as part of this work because it will now hold GPS coordinates (§13.1). |
| Policy | `plugins/webkul/employees/src/Policies/WorkLocationPolicy.php` | Shield-style permissions: `view_any_/view_/create_/update_/delete_employee_work::location` and others. |
| Employee link | `Employee.work_location_id` (single FK), relation `workLocation()` | The Employee form Select is already company-scoped. `Employee::assertHierarchyIsSameCompany()` (in the `saving` hook) already enforces **same-company** for `work_location_id` server-side. |

### 1.3 Employee, hierarchy, permissions, time
- `Employee` (`plugins/webkul/employees/src/Models/Employee.php`) has `user_id`, `company_id`, `parent_id` (line manager), `attendance_manager_id`, `calendar_id`, `time_zone` (nullable string), `is_active`, and `employment_status` (`active, probation, notice, suspended, terminated, resigned, inactive`). The model uses soft deletes, `HasLogActivity`, and chatter.
- `HrHierarchyService` (`plugins/webkul/employees/src/Services/HrHierarchyService.php`) provides `visibleEmployeeIds()`, `canManage()` and `assertCanManage()`. `hr_view_all_records` bypasses the hierarchy. Company access is checked through `default_company_id` or `allowedCompanies()`.
- `HrPermissions` (`plugins/webkul/employees/src/Support/HrPermissions.php`) is the master list (`all()`) plus the role bundles (`manager()`, `hrAdministrator()`, `hrManager()`, `hrOfficer()`, `hrAuditor()`, …). `HrPermissionRegistrar::synchronize()` (run through `php artisan hr:sync-permissions`, `PermissionSeeder` and `HrRoleSeeder`) inserts the permissions and grants the bundles. **New permissions MUST be added here. No other mechanism is needed.**
- Pages are auto-discovered from `plugins/webkul/employees/src/Filament/Pages` (`EmployeePlugin::register`). The precedent is `HrAnalytics` (uses `HasPageShield`, `protected string $view = 'employees::filament.pages.hr-analytics'`).
- Time: `config('app.timezone')` = `APP_TIMEZONE=UTC`. `Calendar` (`plugins/webkul/support/src/Models/Calendar.php`) has `timezone` and `getAttendanceIntervalsBatch()`, which yields per-day work intervals and honours resource or calendar timezones. `Company` has **no** timezone column.
- Approvals: `Webkul\Support\Services\ApprovalEngine` (`submit`, `canAct`, `approve`, `reject`).
- Audit: there is no generic audit table. The existing patterns are (a) domain audit/history tables (for example `employees` status history and `accounting_document_audits`) and (b) chatter `HasLogActivity`.
- HTTP: `bootstrap/app.php` calls `$middleware->trustProxies(at: '*')`, so `request()->ip()` can be client-influenced if the app is not actually behind a trusted proxy. **Treat IP as advisory only.** No `Permissions-Policy` header is set anywhere, so geolocation is not blocked by headers.
- Local environment: `APP_URL=http://127.0.0.1:8000`, `SESSION_SECURE_COOKIE=false`.
- Mobile precedent: the `barcode` plugin ships a mobile-first Livewire UI under `admin/barcode` with its own routes. For this feature a **Filament page** is simpler and inherits panel auth, CSRF and the layout (§3).
- A repository-wide search finds **no existing** use of `navigator.geolocation`, no distance calculation, and no rate limiter in the plugins.
- Graphify: `graph.json` exists. Querying `AttendanceRecord` confirms its only consumers are the resource, `EmployeeRequestService`, `HrAnalyticsService`, seeders and tests. There are no hidden writers.

---

## 2. Existing architecture to reuse (and what is NOT created)

| Need | Reuse | Do NOT create |
|---|---|---|
| Attendance row per employee per day | `AttendanceRecord` / `employees_attendance_records` plus its computation hooks | A new attendance or check-in table |
| Workplace | `WorkLocation` / `employees_work_locations` (add geofence columns) | A new "geofence" or "site" table |
| Primary workplace of an employee | `Employee.work_location_id` | A second "primary location" column |
| Company isolation | `company_id` + `default_company_id` + `HrHierarchyService` | Any new scoping helper |
| Corrections needing approval | `EmployeeRequestService::requestAttendanceTimeChange` + `ApprovalEngine` + `AttendanceWorkflowSeeder` | A new approval system |
| Permissions | `HrPermissions` + `HrPermissionRegistrar` + Spatie roles | A new ACL |
| Schedules, late and early logic | `Calendar::getAttendanceIntervalsBatch()` + `AttendanceRecord::saving` | A new shift engine |
| Timezone | `Employee.time_zone` → `Calendar.timezone` → `config('app.timezone')` | A company timezone column |

**New things, each with a justification:**
1. **Geofence columns on `employees_work_locations`.** The workplace concept exists but has no coordinates.
2. **`employees_attendance_verifications` table (append-only evidence).** `AttendanceRecord` is *one row per employee per day* (it has a unique constraint). A verification is *one row per attempt*, and that includes rejected attempts that must not create attendance. Evidence of failed or denied attempts cannot live on the day row without overwriting it. This is audit evidence, not a parallel attendance model: it never holds worked time and is never read by payroll or analytics.
3. **`employees_employee_work_location_assignments` table.** Requirement 17 (multiple, temporary and hybrid workplaces) cannot be expressed by the single `work_location_id` FK. The primary location stays on `Employee.work_location_id`. The pivot only adds *additional* locations with an optional validity window.
4. **Four nullable columns on `employees_attendance_records`**, linking the day row to its evidence and holding its review state (§4.4).

---

## 3. Architecture decision

### 3.1 Summary
- An employee opens **Employees → My Attendance** (a new Filament page in the existing `admin` panel, mobile responsive). They tap **Check In** or **Check Out**.
- Alpine.js in the page view calls `navigator.geolocation.getCurrentPosition()` **once per tap** (`enableHighAccuracy: true`, `timeout: 15000`, `maximumAge: 0`). There is no `watchPosition` and no background tracking.
- The browser sends `{latitude, longitude, accuracy, captured_at, client_request_id}` to a **Livewire action on the page**, which uses the panel session, CSRF and the Livewire checksum. **No new public HTTP or REST route is added.**
- `GeofencedAttendanceService` (server) resolves the employee **from `Auth::user()` only**. It resolves the company, the candidate locations, the distance (Haversine in PHP), the accuracy policy and the attendance rules. It writes one `AttendanceVerification` row and, if accepted, creates or updates the day's `AttendanceRecord`. Everything happens inside a transaction that holds a per-employee row lock.
- Remote employees (primary or assigned location of type `home`) check in **without** location collection. The result is `remote_exempt`, the attendance `status` is `remote` and the `source` is `self_service`.
- HR and managers review flagged attempts on the existing Attendance resource. Corrections go through the existing approval workflow, or through a direct HR correction that requires a reason and writes an audit row.

### 3.2 Why a Filament page with a Livewire action, rather than an API endpoint
- Authentication, session, CSRF, company context (`default_company_id`) and the panel layout are all inherited. There is no Sanctum or token surface to secure.
- It matches the plugin's existing page convention (`HrAnalytics`) and auto-discovery.
- Future adapters (a native app, a QR kiosk) can later call the **same service**, `GeofencedAttendanceService`, from a proper API. The service is transport-agnostic (§6.5).

### 3.3 Invariants (binding)
1. The client never supplies `employee_id`, `company_id`, `work_location_id`, `distance`, an inside/outside flag, a verification status or a timestamp that the server trusts.
2. The server computes every distance and makes every decision.
3. Every attempt, successful or not, writes exactly one `AttendanceVerification` row. The exceptions are a replayed `client_request_id` (the stored result is returned) and a rate-limited request.
4. No path creates more than one `AttendanceRecord` per (company, employee, attendance_date). The existing unique index enforces this.
5. Existing manual, import, biometric and API attendance behaviour is unchanged.
6. Location is collected only on an explicit tap.

---

## 4. Database changes

All new migrations go in `plugins/webkul/employees/database/migrations/`. Every change is additive, nullable or defaulted, and reversible. No existing column is altered and no data is rewritten.

### 4.1 `2026_09_30_100001_add_geofence_columns_to_employees_work_locations_table.php`
```php
Schema::table('employees_work_locations', function (Blueprint $table): void {
    $table->decimal('latitude', 10, 7)->nullable()->after('location_number');
    $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
    $table->unsignedInteger('geofence_radius_meters')->nullable()->after('longitude');
    $table->boolean('geofence_enabled')->default(false)->after('geofence_radius_meters');
    $table->index(['company_id', 'is_active', 'geofence_enabled'], 'work_locations_geofence_lookup');
});
// down(): dropIndex('work_locations_geofence_lookup'); dropColumn([...4 columns]);
```
Existing rows get `geofence_enabled = false`, so they have no effect until HR configures them.

### 4.2 `2026_09_30_100002_create_employees_employee_work_location_assignments_table.php`
```php
Schema::create('employees_employee_work_location_assignments', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
    $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
    $table->foreignId('work_location_id')->constrained('employees_work_locations')->restrictOnDelete();
    $table->date('valid_from')->nullable();   // null = open start
    $table->date('valid_until')->nullable();  // null = open end; inclusive
    $table->string('reason', 255)->nullable(); // e.g. "Client site – Q4 audit", "Approved WFH"
    $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->index(['company_id', 'employee_id'], 'emp_wl_assign_lookup');
    $table->unique(['employee_id', 'work_location_id', 'valid_from'], 'emp_wl_assign_unique');
});
// down(): dropIfExists
```
`restrictOnDelete` on `work_location_id` is intentional. `WorkLocation` is soft-deleted in normal use, and a force-delete must not silently erase assignment history.

### 4.3 `2026_09_30_100003_create_employees_attendance_verifications_table.php`
```php
Schema::create('employees_attendance_verifications', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
    $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();          // actor
    $table->foreignId('attendance_record_id')->nullable()->constrained('employees_attendance_records')->nullOnDelete();
    $table->foreignId('work_location_id')->nullable()->constrained('employees_work_locations')->nullOnDelete(); // matched/nearest
    $table->string('action', 20);        // check_in | check_out | hr_correction | review
    $table->string('method', 20);        // gps | none | manual   (future: qr, gps_qr, biometric, native_app)
    $table->string('result', 40);        // AttendanceVerificationResult value
    $table->boolean('accepted')->default(false); // did this attempt change attendance?
    $table->decimal('latitude', 10, 7)->nullable();
    $table->decimal('longitude', 10, 7)->nullable();
    $table->decimal('accuracy_meters', 8, 2)->nullable();
    $table->decimal('distance_meters', 10, 2)->nullable();
    $table->json('geofence_snapshot')->nullable(); // {id,name,latitude,longitude,radius_meters} at decision time
    $table->json('flags')->nullable();              // ["low_accuracy","suspicious_accuracy","repeated_coordinates",...]
    $table->json('metadata')->nullable();           // hr_correction before/after; client error code; etc.
    $table->uuid('client_request_id')->nullable();
    $table->timestamp('client_captured_at')->nullable();
    $table->timestamp('server_recorded_at')->useCurrent();
    $table->string('failure_reason', 255)->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent', 255)->nullable();
    $table->string('review_status', 20)->nullable(); // pending | approved | rejected
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->text('review_note')->nullable();
    $table->timestamps();

    $table->unique(['employee_id', 'client_request_id'], 'attendance_verif_idempotency');
    $table->index(['company_id', 'employee_id', 'server_recorded_at'], 'attendance_verif_timeline');
    $table->index(['company_id', 'review_status'], 'attendance_verif_review_queue');
});
// down(): dropIfExists
```
MySQL allows multiple NULLs in a unique index, so rows without a `client_request_id` (HR corrections, reviews) do not collide.

### 4.4 `2026_09_30_100004_add_verification_columns_to_employees_attendance_records_table.php`
```php
Schema::table('employees_attendance_records', function (Blueprint $table): void {
    $table->foreignId('check_in_verification_id')->nullable()->after('source_reference')
        ->constrained('employees_attendance_verifications')->nullOnDelete();
    $table->foreignId('check_out_verification_id')->nullable()->after('check_in_verification_id')
        ->constrained('employees_attendance_verifications')->nullOnDelete();
    $table->string('verification_status', 20)->nullable()->after('check_out_verification_id');
    // null (legacy/manual/import) | verified | remote | needs_review | reviewed | review_rejected | overridden
    $table->index(['company_id', 'verification_status'], 'attendance_verification_status_index');
});
// down(): dropForeign x2, dropIndex, dropColumn([...3])
```
Migration order matters: 4.3 must run before 4.4 because of the FKs.

### 4.5 Migration safety notes
- Every existing attendance row keeps `verification_status = NULL` and no verification links. HrAnalytics, the resource and the tests are unaffected.
- `down()` for 4.3 and 4.4 **destroys evidence**. Section 22 explains why rolling back the schema is a last resort.
- Run `php artisan migrate --pretend` first and inspect the generated FK names. Keep the explicit index names above to stay under MySQL's 64-character limit.

---

## 5. Models

### 5.1 `WorkLocation` (modify `plugins/webkul/employees/src/Models/WorkLocation.php`)
- Add the four columns to `$fillable`. Add casts: `latitude`/`longitude` → `'decimal:7'`, `geofence_radius_meters` → `'integer'`, `geofence_enabled` → `'boolean'`.
- Add `scopeGeofenced(Builder $q)`: `where('geofence_enabled', true)->whereNotNull('latitude')->whereNotNull('longitude')->whereNotNull('geofence_radius_meters')`.
- Add `public function isGeofenceUsable(): bool`. It returns true when the location is active, geofence is enabled, all three values are set, and the type is not `Home`.
- Add a guard in the `saving` hook (server-side, independent of the UI):
  - If `location_type === Home` and latitude or longitude is set, throw `InvalidArgumentException('Home locations cannot store coordinates.')` (privacy, §17).
  - If `geofence_enabled`, require latitude in [-90, 90], longitude in [-180, 180], not both zero, and radius in [`config min`, `config max`].
  - If any of the four geofence columns `isDirty()` **and** `Auth::check()` **and** the user cannot `HrPermissions::ManageAttendanceGeofences`, throw `AuthorizationException`. Console, seeder and unauthenticated contexts are allowed, which matches how other hooks in this plugin treat console writes.

### 5.2 New `EmployeeWorkLocationAssignment` (`plugins/webkul/employees/src/Models/EmployeeWorkLocationAssignment.php`)
- Table `employees_employee_work_location_assignments`. Relations: `company`, `employee`, `workLocation`, `assigner`.
- Casts: `valid_from`/`valid_until` → `date`.
- The `creating` hook sets `assigned_by ??= Auth::id()` and `company_id ??= employee->company_id`.
- Add a `saving` guard, following the `Employee::assertHierarchyIsSameCompany()` pattern. It requires `employee.company_id === work_location.company_id === company_id` and `valid_until >= valid_from` when both are set.
- Add `scopeEffectiveOn(Builder $q, string $date)`: `(valid_from IS NULL OR valid_from <= date) AND (valid_until IS NULL OR valid_until >= date)`.

### 5.3 `Employee` (modify)
- Add `workLocationAssignments(): HasMany` to `EmployeeWorkLocationAssignment`.
- Do **not** change `$fillable` or the existing hooks.

### 5.4 New `AttendanceVerification` (`plugins/webkul/employees/src/Models/AttendanceVerification.php`)
- Table `employees_attendance_verifications`. It is effectively append-only:
  - The `updating` hook throws unless the only dirty columns are review columns (`review_status, reviewed_by, reviewed_at, review_note`), `attendance_record_id` (set once, null → id), or the privacy-pruning columns (`latitude, longitude, ip_address, user_agent`, which may only be set to null, §17).
  - The `deleting` hook throws. Rows are removed only by a FK cascade when the company or employee is deleted.
- Casts: `accepted` bool, `accuracy_meters`/`distance_meters` → `'decimal:2'`, `latitude`/`longitude` → `'decimal:7'`, `geofence_snapshot`/`flags`/`metadata` → `array`, `client_captured_at`/`server_recorded_at`/`reviewed_at` → `datetime`, `action`/`method`/`result` → enums (§5.6).
- Relations: `company`, `employee`, `user`, `attendanceRecord`, `workLocation`, `reviewer`.
- `$hidden = ['latitude', 'longitude', 'ip_address', 'user_agent']`. This is defense in depth against accidental serialization. Views that need these fields read them explicitly behind the evidence permission.

### 5.5 `AttendanceRecord` (modify)
- Add `check_in_verification_id`, `check_out_verification_id` and `verification_status` to `$fillable`.
- Add the relations `checkInVerification()`, `checkOutVerification()` (BelongsTo `AttendanceVerification`) and `verifications()` (HasMany on `attendance_record_id`).
- **Do not change the existing `creating`/`saving` hooks.** They already recompute worked, late and early values on every save, and the GPS path relies on that.
- Add an `updated` hook for defense-in-depth audit. When `check_in` or `check_out` changed **and** the record's `source` is `gps` or `self_service` **and** no verification row was written for this change in the same request, write an `AttendanceVerification` with `action=hr_correction`, `method=manual`, `result=overridden`, `accepted=true`, `metadata={before,after,reason:null,path:'direct_model_update'}` and `user_id=Auth::id()`.
  - Detect "already written" with a static request-scoped flag that `GeofencedAttendanceService` sets while it writes (for example `AttendanceRecord::$suppressCorrectionAudit`, reset in a `finally` block).
  - This guarantees that GPS-sourced times are never edited silently, whatever the write path (a Filament edit, the approval-applied correction, tinker).

### 5.6 New enums (`plugins/webkul/employees/src/Enums/`)
Follow the style of `Enums/WorkLocation.php` (string-backed, `HasLabel` and `HasColor` where shown in the UI).
- `AttendanceSource`: `Manual='manual'`, `Import='import'`, `Biometric='biometric'`, `Api='api'`, `Gps='gps'`, `SelfService='self_service'`. The existing string values are unchanged. **Do not cast `AttendanceRecord.source` to this enum.** Existing rows may hold other strings and the tests compare raw strings. Use it only for the form, filter options and new code.
- `AttendanceVerificationAction`: `CheckIn`, `CheckOut`, `HrCorrection`, `Review`.
- `AttendanceVerificationMethod`: `Gps='gps'`, `None='none'`, `Manual='manual'`. Leave a comment listing the planned future values `qr`, `gps_qr`, `biometric`, `native_app`. Do not add them yet.
- `AttendanceVerificationResult`:
  - `Verified`
  - `NeedsReview`
  - `OutsideGeofence`
  - `LowAccuracy`
  - `InvalidCoordinates`
  - `StaleLocation`
  - `PermissionDenied`
  - `LocationUnavailable`
  - `LocationTimeout`
  - `InsecureContext`
  - `NoLocationConfigured`
  - `EmployeeNotEligible`
  - `AlreadyCheckedIn`
  - `NotCheckedIn`
  - `AlreadyCheckedOut`
  - `OpenShiftExists`
  - `OnLeaveOrHoliday`
  - `RemoteExempt`
  - `Overridden`
  - `ReviewApproved`
  - `ReviewRejected`

  Each case has `getLabel()` (employee-safe wording, §15) and `getColor()`.
- `AttendanceVerificationStatus` (for `AttendanceRecord.verification_status`): `Verified`, `Remote`, `NeedsReview`, `Reviewed`, `ReviewRejected`, `Overridden`.

---

## 6. Services

Place everything under `plugins/webkul/employees/src/Services/Attendance/`. This is a sub-namespace of the existing `Services` directory, following the `Services/Security` precedent. It is not a new top-level directory.

### 6.1 `GeoDistanceCalculator` (pure, no DB)
```php
final class GeoDistanceCalculator
{
    public const EARTH_MEAN_RADIUS_METERS = 6371008.8;
    public function isValidCoordinate(mixed $lat, mixed $lng): bool;
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float;
}
```
The algorithm is specified in §11.

### 6.2 `GeofenceEvaluator` (pure, no DB)
```php
public function evaluate(LocationEvidence $evidence, Collection $candidateLocations, AttendanceVerificationAction $action): GeofenceDecision
```
It returns a `GeofenceDecision` DTO: `{ result: AttendanceVerificationResult, accepted: bool, needsReview: bool, matchedLocation: ?WorkLocation, distanceMeters: ?float, flags: array<string> }`. The rules are in §11.3.

### 6.3 `AttendanceScheduleResolver`
- `timezoneFor(Employee $e): string` uses `$e->time_zone`, then `$e->calendar?->timezone`, then `config('app.timezone')`. Each value is accepted only if `in_array($tz, DateTimeZone::listIdentifiers(), true)`, otherwise the next fallback is used.
- `attendanceDateFor(Employee $e, CarbonImmutable $nowUtc): string` is `$nowUtc->setTimezone(tz)->toDateString()`.
- `scheduledWindowFor(Employee $e, string $attendanceDate): array{0:?Carbon,1:?Carbon}`:
  - If the employee has no calendar, return `[null, null]`.
  - Otherwise, take the local day bounds `[date 00:00, date 23:59:59]` in the employee's timezone and call `$calendar->getAttendanceIntervalsBatch($start, $end, timezone: tz)[null]` (lunch periods are excluded by default).
  - If there are no intervals (a day off), return `[null, null]`. Otherwise return `[min(start)->utc(), max(stop)->utc()]`.
  - Wrap the call in `try/catch (Throwable)`. On failure, log a warning and return `[null, null]`. A schedule failure must never block a check-in; it only means late and early values are not computed, which is existing behaviour for nulls.

### 6.4 `GeofencedAttendanceService` (orchestrator, the only writer for this feature)
Constructor-promoted dependencies: `GeoDistanceCalculator`, `GeofenceEvaluator`, `AttendanceScheduleResolver`, `HrHierarchyService`.

```php
public function checkIn(User $user, LocationEvidence $evidence, Request $request): AttendanceAttemptResult;
public function checkOut(User $user, LocationEvidence $evidence, Request $request): AttendanceAttemptResult;
public function recordClientFailure(User $user, AttendanceVerificationAction $action, string $clientErrorCode, ?string $clientRequestId, Request $request): AttendanceAttemptResult;
public function reviewVerification(AttendanceRecord $record, User $reviewer, bool $approve, string $note): void;
public function recordHrCorrection(AttendanceRecord $record, User $actor, array $before, array $after, string $reason): AttendanceVerification;
public function resolveEmployee(User $user): ?Employee;          // company-scoped, eligibility-checked
public function candidateLocations(Employee $employee, string $attendanceDate): Collection;
public function todayState(User $user): array;                    // for the page (§14)
```
`LocationEvidence` is a readonly DTO: `latitude, longitude, accuracy, capturedAt (?CarbonImmutable), clientRequestId (string uuid)`. `AttendanceAttemptResult` is a readonly DTO: `result, accepted, message (employee-safe), localTime (?string), record (?AttendanceRecord)`.

The step-by-step algorithms are in §12. The Livewire page (§14) and the resource actions (§13) call this service. They never write `AttendanceRecord` or `AttendanceVerification` directly.

### 6.5 Extensibility seam (future verification methods: QR, native app, biometric)
Define an interface `AttendanceVerifier` (`Services/Attendance/Contracts/AttendanceVerifier.php`):
```php
public function method(): AttendanceVerificationMethod;
public function verify(Employee $employee, LocationEvidence|null $evidence, Collection $candidates, AttendanceVerificationAction $action): GeofenceDecision;
```
Implement two verifiers:
- `GpsGeofenceVerifier`, which wraps `GeofenceEvaluator`.
- `RemoteExemptVerifier`, which always returns `RemoteExempt`, accepted, with no evidence.

`GeofencedAttendanceService` picks the verifier from the employee's applicable locations (§12.2). A future `QrVerifier` or `NativeAppVerifier` adds a class and an enum case. The orchestrator, the tables and the UI stay the same.

### 6.6 Config: `config/hr_attendance_geofence.php` (new file in the existing `config/` directory; `config/accounting_drive.php` is the precedent)
```php
return [
    'enabled'                     => env('HR_ATTENDANCE_GEOFENCE_ENABLED', false), // kill switch, §22
    'radius_min_meters'           => 50,
    'radius_max_meters'           => 5000,
    'accuracy_verified_max'       => 50,    // ≤ this and inside → Verified
    'accuracy_reviewable_max'     => 150,   // ≤ this and inside → NeedsReview; above → LowAccuracy (reject)
    'accuracy_suspicious_below'   => 2,     // < this → flag 'suspicious_accuracy' → NeedsReview
    'max_location_age_seconds'    => 120,   // captured_at older than this → StaleLocation
    'max_clock_skew_seconds'      => 60,    // captured_at in the future beyond this → StaleLocation
    'max_shift_hours'             => 16,    // open record older than this = "missing checkout"
    'checkout_outside_geofence'   => 'review', // review | reject
    'checkout_without_location'   => 'review', // review | reject  (permission denied / unavailable at checkout)
    'max_candidate_locations'     => 20,
    'rate_limit_per_minute'       => 10,
    'evidence_retention_days'     => 365,   // §17
];
```
Never call `env()` outside this file. Read values with `config('hr_attendance_geofence.*')`.

---

## 7. Policies and authorization

- **`WorkLocationPolicy`:** keep it as is (Shield permissions). The geofence fields are additionally gated by the model guard (§5.1) and the resource (§13.1).
- **New `AttendanceVerificationPolicy`** (`plugins/webkul/employees/src/Policies/AttendanceVerificationPolicy.php`). Register it the way the plugin registers its other policies (check `EmployeeServiceProvider` or the Shield config and follow the existing mechanism).
  - `viewAny(User)`: `can(ViewAttendance) || can(ManageAttendance)`.
  - `view(User, AttendanceVerification $v)`: company match (`$v->company_id === $user->default_company_id` or the user has an allowed company) **and** `HrHierarchyService::canManage($user, $v->employee)`, **or** the verification is the user's own (`$v->employee->user_id === $user->id`).
  - `viewLocationEvidence(User, AttendanceVerification $v)`: `view()` **and** `can(ViewAttendanceLocationEvidence)`. Being the employee is not enough: raw coordinates, IP and user agent are HR-only (§17).
  - `review(User, AttendanceVerification $v)`: `can(ReviewAttendanceVerifications)` **and** `canManage($user, $v->employee)` **and** `$v->employee->user_id !== $user->id` (no self-review).
- **HR correction (direct):** `can(ManageAttendance)` **and** `canManage($user, $record->employee)` **and** not the user's own record. The last condition is new, because employees must never override their own failed geofence (§16). The existing `AttendanceRecordResource` Edit/Delete actions currently have no self-edit restriction. Add `->visible()` / `->authorize()` closures that deny a user editing a record where `employee.user_id === Auth::id()` **when the record is GPS/self-service sourced**, and enforce the same check inside `recordHrCorrection()`.
- **Self check-in:** no Shield permission. The only requirement is that `resolveEmployee(Auth::user())` returns an eligible employee (§12.1). This is a deliberate deviation from `HasPageShield`: there is no universal "employee" role to grant a page permission to, and eligibility is a property of the employee record, not of a role.

---

## 8. Permissions

Add to `HrPermissions` (constants plus `all()`):
```php
public const ManageAttendanceGeofences        = 'hr_manage_attendance_geofences';
public const ViewAttendanceLocationEvidence   = 'hr_view_attendance_location_evidence';
public const ReviewAttendanceVerifications    = 'hr_review_attendance_verifications';
```
Bundle changes (each is one line in the named method):

| Bundle | Add | Why |
|---|---|---|
| `hrAdministrator()` | `ManageAttendanceGeofences` | Technical setup role. It already owns work-location CRUD. |
| `hrManager()` | `ReviewAttendanceVerifications`, `ViewAttendanceLocationEvidence`, `ManageAttendanceGeofences` | Senior operational HR |
| `manager()` (line managers) | `ReviewAttendanceVerifications` | Reviews their own reports' flagged attempts. **Not** `ViewAttendanceLocationEvidence`: managers see result, distance and accuracy, never raw coordinates or IP. |
| `hrAuditor()` | `ViewAttendanceLocationEvidence` | Read-only compliance. No review permission. |
| `hrOfficer()` | — (unchanged) | |

"Correct attendance" reuses the existing `hr_manage_attendance`. "Override failed geofence" is implemented as an HR correction (§16) and therefore also uses `hr_manage_attendance`, with the hierarchy and self-edit rules from §7.

After deploying, run `php artisan hr:sync-permissions`. Admin and system roles and the `hr`/`hr_manager` name tiers automatically receive all of `HrPermissions::all()` through the existing registrar logic.

---

## 9. Routes and endpoints

- **No new routes.** The employee flow is Livewire actions on the Filament page `MyAttendance` (auto-discovered at `/admin/my-attendance`; confirm the slug and set `protected static ?string $slug = 'my-attendance'`).
- Livewire methods (public, on the page class):
  - `checkIn(array $payload): void`
  - `checkOut(array $payload): void`
  - `reportLocationFailure(string $action, string $code, ?string $clientRequestId): void`
- Validation inside each method. Use `Validator::make`; Form Requests do not apply to Livewire actions.
  ```php
  [
    'latitude'          => ['required', 'numeric', 'between:-90,90'],
    'longitude'         => ['required', 'numeric', 'between:-180,180'],
    'accuracy'          => ['required', 'numeric', 'gt:0', 'max:10000'],
    'captured_at'       => ['nullable', 'integer', 'min:0'],   // epoch ms from Position.timestamp
    'client_request_id' => ['required', 'uuid'],
  ]
  ```
  Unknown keys are ignored. **Never** read `employee_id`, `distance` or similar from `$payload`. If validation fails, call the service with the result `InvalidCoordinates` so the attempt is still audited (no coordinates are stored when they are non-numeric), then return the employee-safe message.
  `reportLocationFailure` validates `action ∈ {check_in, check_out}`, `code ∈ {permission_denied, position_unavailable, timeout, insecure_context, unsupported}` and a uuid.
- **Rate limiting:** at the top of each method, `RateLimiter::attempt('hr-geo-attendance:'.Auth::id(), config('hr_attendance_geofence.rate_limit_per_minute'), fn () => true, 60)`. If the limit is hit, show "Too many attempts. Please wait a minute." and write nothing.
- If `config('hr_attendance_geofence.enabled')` is false, `MyAttendance::canAccess()` returns false and the service throws `RuntimeException('Mobile check-in is not enabled.')`.

---

## 10. Browser geolocation implementation

Everything below goes in `plugins/webkul/employees/resources/views/filament/pages/my-attendance.blade.php`. Use inline Alpine with `x-data`; Filament already ships Alpine, so no new bundle or npm dependency is needed. Keep the JS small and readable.

State machine: `idle → locating → verifying → success | error`. The button is disabled unless the state is `idle`, `success` or `error`.

```js
// pseudo-code; implement faithfully
async function attempt(action) {            // action: 'check_in' | 'check_out'
  if (this.busy) return;                    // double-click / double-tap guard
  this.busy = true; this.requestId = crypto.randomUUID(); // one id per tap; reused only by Livewire's own retry
  if (!window.isSecureContext)   return this.fail(action, 'insecure_context');
  if (!('geolocation' in navigator)) return this.fail(action, 'unsupported');
  this.state = 'locating';                  // "Requesting location…"
  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      this.state = 'verifying';             // "Verifying location…"
      await $wire[action === 'check_in' ? 'checkIn' : 'checkOut']({
        latitude: pos.coords.latitude, longitude: pos.coords.longitude,
        accuracy: pos.coords.accuracy, captured_at: Math.round(pos.timestamp),
        client_request_id: this.requestId,
      });
      this.busy = false;                    // server result arrives via Livewire-rendered state
    },
    async (err) => {
      const code = {1: 'permission_denied', 2: 'position_unavailable', 3: 'timeout'}[err.code] ?? 'position_unavailable';
      await $wire.reportLocationFailure(action, code, this.requestId);
      this.busy = false;
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  );
}
```
- `crypto.randomUUID()` requires a secure context. That is fine because the flow requires one anyway. The insecure-context branch uses a server-generated fallback id (pass `null`; the server generates one with `Str::uuid()`).
- For **remote-exempt** employees the page renders a plain Livewire button `wire:click="checkIn({client_request_id: ...})"` with no geolocation call. The server already knows the employee is remote and never requests coordinates (§12.2). The page decides which button to render from `todayState()['mode']` (`gps` or `remote`).
- Do not store coordinates in `localStorage` or `sessionStorage`. Do not log them to the console.
- Show the employee-safe error text from §15. Always provide a "Try again" button.

---

## 11. Geofence algorithm

### 11.1 Coordinate validation (`GeoDistanceCalculator::isValidCoordinate`)
- Both values must be numeric, `is_finite`, latitude in [-90, 90] and longitude in [-180, 180].
- Reject exactly `(0, 0)` ("null island"). It is a common default from broken providers.
- The server rounds to 7 decimal places (about 1.1 cm) before it stores or computes anything, which matches the `decimal(10,7)` columns.

### 11.2 Distance (Haversine, metres)
```
φ1, φ2 = radians(lat1), radians(lat2)
Δφ = φ2 − φ1 ;  Δλ = radians(lng2 − lng1)
a = sin²(Δφ/2) + cos φ1 · cos φ2 · sin²(Δλ/2)
a = min(1.0, max(0.0, a))                // clamp floating-point drift → avoids NaN
d = 2 · R · atan2(√a, √(1 − a))          // R = 6 371 008.8 m (IUGG mean radius)
```
- **Units:** metres, `float`. Store `round($d, 2)`.
- **Precision:** a spherical model differs from WGS-84 by at most about 0.5%, which is under 25 m even at the 5 km maximum radius and under 1 m at typical 100–300 m radii. This is far below GPS error. Vincenty is not needed.
- **Edge cases:** identical points give 0. The antimeridian (Δλ ≈ 360°) is handled by `sin²`. Poles are fine. There is no division by zero.
- **Unit tests** (§19) use known pairs, for example Karachi (24.8607, 67.0011) to Lahore (31.5204, 74.3587) ≈ 1,030 km ± 1 km, and a 100 m offset at the equator ±0.5 m.

### 11.3 Decision rules (`GeofenceEvaluator`), evaluated in this order
Inputs: evidence, candidates (already filtered to usable, same-company, active, effective today), action.
1. Coordinates invalid → `InvalidCoordinates`, reject.
2. `capturedAt` present and older than `now − max_location_age_seconds`, or newer than `now + max_clock_skew_seconds` → `StaleLocation`, reject ("Please try again"). If `capturedAt` is missing, add the flag `missing_client_timestamp` (not a reject).
3. Accuracy > `accuracy_reviewable_max` (150) → `LowAccuracy`, reject ("Location accuracy is too low…").
4. For each candidate, compute `d`. Pick the candidate with the smallest `d / radius`, which is the most-inside fence. Record `matchedLocation`, `distanceMeters` and `geofence_snapshot`.
5. If `d > radius` for the best candidate:
   - For **check-in**: `OutsideGeofence`, reject.
   - For **check-out**: if `checkout_outside_geofence === 'review'`, return `NeedsReview` (accepted, flag `outside_geofence`). Otherwise `OutsideGeofence`, reject.
6. The location is inside (`d ≤ radius`):
   - accuracy ≤ `accuracy_verified_max` (50) and no suspicious flags → `Verified`, accepted.
   - accuracy ≤ 150 → `NeedsReview`, accepted, flag `low_accuracy`.
7. Suspicion flags (any flag forces `NeedsReview` instead of `Verified`, and never forces a reject):
   - `suspicious_accuracy`: accuracy < `accuracy_suspicious_below` (2 m). Mock-location tools often report 0–1 m.
   - `repeated_coordinates`: latitude and longitude are identical to 7 dp to this employee's previous accepted GPS verification **on a different attendance date**. Real GPS essentially never repeats to the centimetre on different days. This costs one indexed query in the service; pass the result into the evaluator.

**Why this policy:** a check-in that is certainly outside is rejected, because a check-in creates the attendance fact. Genuinely poor indoor GPS inside the fence is *accepted but flagged*, so honest employees are not blocked at the door, and HR sees every flagged case. A check-out is never lost to bad GPS by default, because a missing check-out corrupts worked hours more than a flagged one. Every threshold is configurable.

### 11.4 Honest limitation (must appear in the admin help text and in §17)
Browser GPS **cannot be cryptographically verified**. A rooted phone or a mock-location app can report any coordinates. This design makes spoofing *auditable and costly* (flags, review, repeat detection, IP and user-agent evidence, rate limits) but not impossible. Stronger assurance is the job of a future adapter (QR at the premises, or a native app with integrity attestation) through §6.5.

---

## 12. Attendance integration (server algorithms)

### 12.1 Employee resolution and eligibility (`resolveEmployee`)
```
company_id = (int) $user->default_company_id  (must be > 0)
employee = Employee::query()->where('company_id', company_id)->where('user_id', $user->id)->first()
eligible iff employee exists AND is_active = true AND employment_status ∈ {active, probation, notice}
          AND deleted_at IS NULL (soft delete default scope)
```
If the user is not eligible, return `EmployeeNotEligible` with the message "Your employee profile is not set up for mobile check-in. Please contact HR." Write a verification row **only if** an employee record exists (the FK requires one). Otherwise just log a warning.

### 12.2 Candidate locations and mode (`candidateLocations`)
```
primary   = employee.workLocation (if active and not soft-deleted)
assigned  = employee.workLocationAssignments()->effectiveOn(attendanceDate)->with('workLocation')
             → keep only workLocation active and not soft-deleted
all       = primary ∪ assigned, unique by id; drop any whose company_id !== employee.company_id (defensive re-check)

Evaluate in this exact order:
1. any ASSIGNMENT (not primary) effective on attendanceDate has location_type = Home → mode = 'remote'
      (approved remote day overrides an office primary for that date)
2. else any location in `all` isGeofenceUsable()                             → mode = 'gps'
3. else any location in `all` has location_type = Home (i.e. primary is Home) → mode = 'remote'
      (fully remote employee)
4. else                                                                       → mode = 'none' → NoLocationConfigured

geofence candidates (mode gps) = all->filter(isGeofenceUsable)->take(max_candidate_locations)
```
Hybrid employee: the primary is an office with a fence. On approved remote days HR adds a `Home` assignment with `valid_from = valid_until = that date`. On other days, rule 2 applies. A temporary site uses an assignment to a fenced location, and rule 2 then also considers that fence. An office location that has **no** usable fence never allows location-free check-in (rule 4). This is essential: switching a fence off must not silently exempt everyone. Put this in the admin help text as "Add a Home assignment for approved remote days".

### 12.3 Check-in (`checkIn`)
```
1. if !config enabled → throw
2. employee = resolveEmployee(user)  → if null/ineligible: return EmployeeNotEligible
3. tz = timezoneFor(employee); nowUtc = CarbonImmutable::now('UTC'); attendanceDate = nowUtc->setTimezone(tz)->toDateString()
4. DB::transaction(function () {
   4.1 Employee::query()->whereKey($employee->id)->lockForUpdate()->first();   // serialises all attempts for this employee
   4.2 idempotency: existing = AttendanceVerification where employee_id & client_request_id
        → if exists: return AttendanceAttemptResult rebuilt from `existing` (no new writes)
   4.3 candidates/mode = candidateLocations(employee, attendanceDate)
        mode none → write verification(NoLocationConfigured, accepted=false) ; return
   4.4 open shift check: AttendanceRecord where company, employee, check_in NOT NULL, check_out NULL,
        check_in >= nowUtc − max_shift_hours  → exists? → write verification(OpenShiftExists); return
        ("You are already checked in since HH:MM. Please check out first.")
   4.5 today = AttendanceRecord where company, employee, attendance_date = attendanceDate  ->lockForUpdate()->first()
        today && today.check_in NOT NULL → write verification(AlreadyCheckedIn); return ("Already checked in at HH:MM")
        today && today.status ∈ {leave, holiday} → write verification(OnLeaveOrHoliday); return
   4.6 decision = verifier(mode)->verify(...)
        (gps) repeated_coordinates lookup done here before evaluate
   4.7 verification = AttendanceVerification::create([... all evidence, result, accepted, flags,
         review_status = decision.needsReview ? 'pending' : null,
         ip_address = request->ip(), user_agent = Str::limit(request->userAgent(), 255, ''),
         server_recorded_at = nowUtc ])
   4.8 if !decision.accepted → return rejection result
   4.9 [scheduledStart, scheduledEnd] = scheduledWindowFor(employee, attendanceDate)
   4.10 record = today ?? new AttendanceRecord
        fill: company_id, employee_id, attendance_date, check_in = nowUtc (SERVER time, never client),
              scheduled_start/end (only if currently null), status = mode==='remote' ? 'remote' : 'present',
              source = mode==='remote' ? 'self_service' : 'gps', source_reference = 'verification:'.verification.id,
              check_in_verification_id = verification.id,
              verification_status = remote ? Remote : (needsReview ? NeedsReview : Verified),
              creator_id = user.id
        save()  (with AttendanceRecord::$suppressCorrectionAudit = true inside try/finally)
   4.11 verification.update(['attendance_record_id' => record.id])
   4.12 return success ("Checked in at 09:04 AM" formatted in tz)
   })
5. catch (QueryException $e) where SQLSTATE 23000 on attendance_employee_date_unique or attendance_verif_idempotency
      → re-read and return AlreadyCheckedIn / the stored idempotent result. (Belt and braces: 4.1's lock should make this unreachable.)
```
Existing behaviour is preserved. `late_minutes`, `worked_hours` and `early_departure_minutes` are computed by the **existing** `saving` hook. Nothing is recalculated here.

### 12.4 Check-out (`checkOut`)
```
1–2 as check-in. 3. nowUtc.
4. transaction + employee lockForUpdate; idempotency check (4.2)
5. open = AttendanceRecord where company, employee, check_in NOT NULL, check_out NULL,
          check_in >= nowUtc − max_shift_hours, orderByDesc('check_in') ->lockForUpdate()->first()
   none → recently closed exists (check_out NOT NULL, attendance_date = local today)? AlreadyCheckedOut : NotCheckedIn
6. candidates/mode as check-in (use open.attendance_date for assignment effectiveness → overnight shifts keep their day)
7. decision (check-out rules §11.3 step 5)
8. write verification (+ review_status pending if needsReview)
9. if accepted: open.check_out = nowUtc (must be > open.check_in; if not → treat as NotCheckedIn/Invalid, reject)
                open.check_out_verification_id = verification.id
                open.verification_status = worst(open.verification_status, this decision)   // NeedsReview dominates Verified
                save() (suppress flag) ; verification.attendance_record_id = open.id
10. return ("Checked out at 05:31 PM. Worked 8h 27m.")
```
**Overnight shifts:** `attendance_date` is always the local date of the **check-in**. A 22:00 → 06:00 shift closes the previous day's record because step 5 searches by the open record and not by date. `max_shift_hours` (16 by default) separates an overnight shift from a forgotten check-out. A record left open longer than that is treated as a missing check-out. The employee can check in fresh, and the old record stays open for an HR or approval correction (§16). The existing `saving` hook computes `worked_hours` across midnight correctly because it diffs full timestamps.

### 12.5 Client failure (`recordClientFailure`)
This runs the same eligibility step, idempotency check (if an id was given) and employee lock. It writes a verification with `method = gps`, `result ∈ {PermissionDenied, LocationUnavailable, LocationTimeout, InsecureContext}`, no coordinates, and `metadata.client_error_code`.
- For **check-in** it is always rejected.
- For **check-out**, when `checkout_without_location === 'review'`, it performs the check-out exactly as in §12.4 steps 5, 8 and 9, with `result = NeedsReview` and the flags `['location_unavailable', <code>]`. Otherwise it rejects.

### 12.6 Review (`reviewVerification`)
- Authorize with `AttendanceVerificationPolicy::review` for each verification whose `review_status = 'pending'` and is linked to the record. A note is required.
- Update those verifications: `review_status`, `reviewed_by`, `reviewed_at` and `review_note`.
- Write one additional verification with `action = Review`, `method = Manual`, `result = ReviewApproved|ReviewRejected` and `accepted = false`. This is the audit event.
- Set the record's `verification_status` to `Reviewed` (approved) or `ReviewRejected`.
- **Rejecting a review never deletes or alters the check-in or check-out times.** It flags the day. Changing times or status is a separate, audited HR correction (§16), which keeps "the evidence was judged bad" apart from "what the corrected attendance is".

### 12.7 HR correction (`recordHrCorrection`)
- Authorize as in §7. `reason` is required (at least 10 characters).
- Write a verification with `action = HrCorrection`, `method = Manual`, `result = Overridden`, `accepted = true` and `metadata = {before, after, reason}`.
- Apply `after` to the record, with the suppress flag on so the §5.5 hook does not double-log.
- Set `verification_status = Overridden` and `approved_by = actor.id`.

---

## 13. Admin UI

### 13.1 HR → Configurations → Work Locations (modify `WorkLocationResource`)
- **Fix company scope (required):** add `getEloquentQuery()` → `parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id)`. Also keep `withoutGlobalScopes([SoftDeletingScope::class])` if the list currently shows trashed records for Restore/ForceDelete. Check how `ListWorkLocations` exposes trashed records today and preserve that.
- `company_id` Select: scope options to the user's default and allowed companies, and default to `default_company_id`. Follow the `AttendanceRecordResource` `Hidden::make('company_id')` precedent if multi-company selection is not needed.
- New form `Section` "Mobile check-in geofence":
  - Visible when `location_type !== home`.
  - Disabled unless `Auth::user()->can(HrPermissions::ManageAttendanceGeofences)`.
  - Fields: `latitude` (numeric, between -90 and 90, step 0.0000001), `longitude` (numeric, between -180 and 180), `geofence_radius_meters` (integer, between the configured min and max, default 150, suffix "m") and `geofence_enabled` (Toggle).
  - Conditional `required` on the three numeric fields when `geofence_enabled` is on.
  - Helper action "Use my current location". It is a small Alpine button calling `getCurrentPosition` on the **admin's** device and filling the two fields. Show a note: "Stand at the centre of the workplace. Accuracy: ±N m."
  - Helper text: "Enter coordinates from any map (for example right-click → copy coordinates). No map service is used by Aureus." Also include the §11.4 limitation sentence.
- Mutation guard: in the Create/Edit action's data-mutation hook (verify the Filament 5 method name with Boost `search-docs "action mutate form data"`), strip the four geofence keys when the user lacks `ManageAttendanceGeofences`. The model guard (§5.1) is the real enforcement. This hook only avoids a confusing exception.
- Table: add `IconColumn geofence_enabled` and `TextColumn geofence_radius_meters` (suffix "m", toggleable). **Do not** show latitude or longitude in the table.
- Infolist (View): show lat/lng/radius only to users with `ManageAttendanceGeofences` or `ViewAttendanceLocationEvidence`. Optionally show a plain external link `https://www.openstreetmap.org/?mlat={lat}&mlon={lng}#map=18/{lat}/{lng}`. It is a link only, with no embedded map and no API.
- "Deactivate" uses the existing `is_active` toggle. A deactivated location immediately stops being a candidate (§12.2).

### 13.2 Employee assignment
- Primary: the existing `work_location_id` Select on the Employee form. No change is needed; it is already company-scoped and model-guarded.
- Additional or temporary: a new `WorkLocationAssignmentsRelationManager` (`plugins/webkul/employees/src/Filament/Resources/EmployeeResource/RelationManagers/`), added to `EmployeeResource::getRelations()` in a new `RelationGroup::make('Work Locations', [...])`, following the existing RelationGroup pattern.
  - Columns: location, type badge, valid_from, valid_until, reason and assigned_by.
  - Form: `work_location_id` (scoped to the employee's company and active), dates and reason (required).
  - Authorization: `can('update_employee_employee')` **and** `HrHierarchyService::canManage()`.

### 13.3 HR → Attendance (modify `AttendanceRecordResource`)
- **Form options (required, or editing GPS rows breaks):** build the `source` Select options from `AttendanceSource`, which adds `gps` and `self_service`. The `status` options are unchanged. When editing a record whose source is `gps` or `self_service`, make `source` disabled and dehydrated (never let the UI relabel evidence-backed rows).
- **Edit on GPS/self-service rows:** add a required `correction_reason` Textarea. It is visible only for those sources and dehydrated false. In the action, call `GeofencedAttendanceService::recordHrCorrection()` instead of the default save for those rows. Deny editing your own record (§7).
- **Table:**
  - Add `TextColumn verification_status` as a badge (placeholder "—" for legacy rows).
  - Add `SelectFilter verification_status`, with a "Needs review" preset.
  - Add `SelectFilter source`.
- **Record actions:**
  - "Verification evidence" (a view-only modal): lists `$record->verifications()->latest('server_recorded_at')` with time (displayed in the employee's timezone), action, result, distance, accuracy, matched location name and review status. Latitude, longitude, IP and user agent are shown **only** when `can('viewLocationEvidence', $verification)`.
  - "Approve verification" and "Reject verification": visible when `verification_status === needs_review` and `can('review', …)`. A note is required. They call `reviewVerification()`.
- The existing "Request Time Change" action stays as it is.

---

## 14. Employee UI: `MyAttendance` page

- Class: `plugins/webkul/employees/src/Filament/Pages/MyAttendance.php` (extends `Filament\Pages\Page`, **without** `HasPageShield`, see §7).
  - `NavigationGroup::Employee`, label "My Attendance", icon `heroicon-o-map-pin`, `navigationSort = 1`, slug `my-attendance`.
  - `canAccess()`: `config('hr_attendance_geofence.enabled') && app(GeofencedAttendanceService::class)->resolveEmployee(Auth::user()) !== null`.
- View: `plugins/webkul/employees/resources/views/filament/pages/my-attendance.blade.php`. Mobile-first, one column, large tap targets (at least 48 px) and a Tailwind class set consistent with Filament. Activate the `tailwindcss-development` skill before styling.
- Layout:
  1. Status card: "Not checked in" / "Checked in at 09:04 AM (Head Office)" / "Checked out at 05:31 PM, worked 8h 27m". This comes from `todayState()`, with times in the employee's timezone.
  2. A primary button, either **Check In** or **Check Out** (never both), with state text: "Requesting location…" → "Verifying location…" → result.
  3. Result banner (success is green; a failure is red with a "Try again" button).
  4. A one-line privacy note: "Your location is checked only when you tap Check In or Check Out. It is not tracked at any other time."
  5. "My recent attendance" for the last 14 days: date, check-in, check-out, worked hours and a status badge. **No coordinates.** Each row has a "Request correction" action that reuses `EmployeeRequestService::requestAttendanceTimeChange()` (the same schema as the resource action).
- Employees see their own results and messages only. They never see raw coordinates, distance, IP or flags. Distance could help an employee probe fence edges, so omit it for employees.

---

## 15. Error handling (employee-facing messages)

Map every `AttendanceVerificationResult` to a short, non-technical message. Put these in `employees::` language files (`resources/lang/en/...`) following the plugin's translation convention. Arabic and Spanish files may fall back to English for now; add a TODO in the PR description rather than in code.

| Result | Message |
|---|---|
| Verified | "Location verified. Checked in at {time}." / "Checked out at {time}." |
| NeedsReview (accepted) | "Checked in at {time}. Your location could not be confirmed precisely, so HR may review it." |
| OutsideGeofence | "You appear to be outside your authorized workplace. Move closer and try again, or contact your manager." |
| LowAccuracy | "Location accuracy is too low. Turn on precise location, move near a window or outdoors, and try again." |
| StaleLocation | "Your location reading was out of date. Please try again." |
| InvalidCoordinates | "We couldn't read a valid location. Please try again." |
| PermissionDenied | "Location permission is required to check in. Allow location for this site in your browser settings, then try again." |
| LocationUnavailable / LocationTimeout | "Your phone couldn't determine your location. Check that location services are on, then try again." |
| InsecureContext | "Location can only be used over a secure (https) connection. Please use the official Aureus address." |
| NoLocationConfigured | "No workplace is set up for mobile check-in. Please contact HR." |
| EmployeeNotEligible | "Your employee profile isn't set up for mobile check-in. Please contact HR." |
| AlreadyCheckedIn | "You already checked in today at {time}." |
| OpenShiftExists | "You're still checked in since {time}. Please check out first." |
| NotCheckedIn | "You're not checked in." |
| AlreadyCheckedOut | "You already checked out at {time}." |
| OnLeaveOrHoliday | "Today is recorded as leave or holiday. Contact HR if this is wrong." |
| RemoteExempt | "Checked in (remote) at {time}." |
| Rate limited | "Too many attempts. Please wait a minute and try again." |

Server exceptions that are not expected (anything besides the handled results) are logged with `report()`. The employee sees "Something went wrong. Please try again or contact HR." Never show stack traces or SQL.

---

## 16. Manual HR corrections (controlled exceptions)

| Scenario | Path | Audit |
|---|---|---|
| Forgot to check out (record open) | Employee → My Attendance → "Request correction" (existing time-change flow, line-manager approval). Or HR edits with a reason. | `EmployeeRequest` payload + `ApprovalDecision`s, **plus** an automatic `hr_correction` verification from the §5.5 `updated` hook when the approval is applied. |
| GPS unavailable, phone broken, legitimate fence failure (no record exists) | HR (`hr_manage_attendance`) creates the record in the Attendance resource with source `manual` and fills `notes`. For employee self-initiation, see "Extension" below. | The resource Create path for a *manual* record is existing behaviour. Also write a `hr_correction` verification when HR creates a record for an employee who has a rejected GPS attempt that day (link via `attendance_record_id`). |
| Approved remote day / field work / temporary site | HR adds an **assignment** (Home for remote, or a site location with its own fence) *before* or on the day. The employee then checks in normally. | The assignment row (`assigned_by`, `reason`) plus normal verification rows. |
| Flagged attempt (NeedsReview) | Manager or HR → Approve or Reject verification (§12.6). | Review verification row. |
| Wrong times on a GPS row | HR edit with a required reason → `recordHrCorrection()`. | `hr_correction` verification with before/after. |

**Rules:**
- An employee can **never** override their own failed geofence. There is no self-service override action. The service and the policy deny self-correction and self-review.
- Every override has an actor, a timestamp, a reason and the before/after values.

**Extension (in scope, small):** add `EmployeeRequestService::requestMissingAttendance(Employee $employee, User $requester, string $attendanceDate, array $times, string $reason)`.
- It creates an `attendance_time_change`-type request with `payload.kind = 'attendance_missing_day'` and `attendance_record_id = null`.
- In `applyAttendanceTimeChange()`, when `kind === 'attendance_missing_day'`, it creates the `AttendanceRecord` on approval (`source = manual`, `approved_by`). This happens only if no record exists for that date; if one exists, apply it like a time change.
- The existing code path for `kind = attendance_time_change` stays byte-for-byte unchanged in behaviour.
- My Attendance shows "Request attendance for a missed day" (date picker limited to the past 30 days).

---

## 17. Privacy

- **When location is collected:** only when an eligible employee taps Check In or Check Out on My Attendance, plus the optional admin "Use my current location" helper on the admin's own device. There is no `watchPosition`, no background job and no polling.
- **Remote-exempt employees:** location is never requested.
- **Home locations:** can never hold coordinates (model guard). Aureus therefore never stores an employee's home position.
- **What is stored per attempt:** latitude, longitude, accuracy, computed distance, the matched-location snapshot, the result, flags, server and client timestamps, IP and a user agent truncated to 255 characters. There is no device ID, no photo and no continuous history.
- **Who can see it:**
  - The employee sees their own results and times.
  - Managers and HR with `hr_view_attendance` or `hr_manage_attendance` in their hierarchy see result, distance, accuracy and flags.
  - **Only `hr_view_attendance_location_evidence`** (HR Manager, HR Auditor and admins) sees raw coordinates, IP and user agent.
  - All access is company- and hierarchy-scoped.
- **Retention:** no existing Aureus retention policy was found (searched). Add an artisan command `hr:prune-attendance-evidence` (`app/Console/Commands/PruneAttendanceEvidence.php`, following `SyncHrPermissions`).
  - For verifications with `server_recorded_at < now − evidence_retention_days` it **nulls** `latitude`, `longitude`, `ip_address` and `user_agent`.
  - It keeps result, distance, accuracy and review data, so the audit trail survives without precise location.
  - It uses chunked updates and prints counts. `routes/console.php` has no schedule entries today, so leave scheduling to ops and document it in the PR.
- **Employee notice:** the one-line notice on the page (§14). HR should also cover this in the employee handbook. That is a policy action and not code.
- **Honest limitation:** §11.4. Do not market this internally as tamper-proof.

---

## 18. Tests

Activate the `pest-testing` skill. Put tests in `plugins/webkul/employees/tests/`. Reuse the `taFixture()` style: company, user with `default_company_id` plus `allowedCompanies()`, and an Employee with `user_id` and `parent_id`. Add an `AttendanceWorkflowSeeder` run where corrections are exercised. Freeze time with `$this->travelTo()` / `Carbon::setTestNow()`.

### 18.1 Unit (`tests/Unit/Attendance/`)
- `GeoDistanceCalculatorTest`:
  - Identical points → 0.
  - Karachi↔Lahore ≈ 1,030 km ±1 km.
  - 0.0009° latitude ≈ 100.08 m ±0.5 m.
  - Antimeridian (179.9999, −179.9999) is tiny, not about 40,000 km.
  - Near-antipodal points do not produce NaN.
  - Invalid inputs (NaN, INF, 91, −181, "abc", (0,0)) are rejected.
- `GeofenceEvaluatorTest` (datasets):
  - Inside + 10 m → Verified.
  - Inside + 80 m → NeedsReview (`low_accuracy`).
  - Inside + 151 m → LowAccuracy (reject).
  - Outside by 1 m on check-in → OutsideGeofence.
  - Outside on check-out → NeedsReview when config is `review`, reject when `reject`.
  - Accuracy 0.5 → NeedsReview (`suspicious_accuracy`).
  - Stale `captured_at` (−121 s) and future (+61 s) → StaleLocation.
  - Missing `captured_at` → flag only.
  - Two fences → matches the most-inside one.
  - Boundary d == radius → inside.
- `AttendanceScheduleResolverTest`:
  - Timezone fallback chain, including an invalid employee timezone.
  - `attendance_date` at 23:30 PKT (18:30 UTC) is the PKT date.
  - At 00:30 PKT (19:30 UTC the previous day) it is the new PKT date.
  - Calendar window: 09:00–17:00 PKT → 04:00–12:00 UTC.
  - Day off → nulls.
  - Calendar exception → nulls and a logged warning.

### 18.2 Feature (`tests/Feature/GeofencedAttendanceTest.php`), calling the service directly
- Successful check-in:
  - Record created: `source = gps`, `status = present`, `check_in` = frozen server now (not the client time), `verification_status = verified`.
  - One verification row, linked both ways.
  - `late_minutes` computed from the calendar.
- Successful check-out: `worked_hours`, `early_departure_minutes` and both links are set.
- Outside the geofence: verification written with `accepted = false`, **no** AttendanceRecord.
- Poor accuracy (80 m): accepted, `verification_status = needs_review`, `review_status = pending`.
- Extremely poor accuracy (500 m): rejected, no record.
- Permission denied at check-in: verification row, no record. At check-out (review mode): check-out recorded and flagged.
- Invalid coordinates via the Livewire validation path: audited, no record.
- Duplicate check-in on the same day → AlreadyCheckedIn, still exactly one record.
- Duplicate check-out → AlreadyCheckedOut.
- Same `client_request_id` twice → exactly one verification, identical result returned.
- Open shift within 16 h → OpenShiftExists. Open shift older than 16 h → a new check-in is allowed and the old record is untouched.
- Overnight: check-in 22:00, check-out 06:00 the next day → the same record, `worked_hours = 8`.
- Inactive location; soft-deleted location; `geofence_enabled = false` → NoLocationConfigured.
- Inactive employee, `employment_status = terminated`, or no linked employee → EmployeeNotEligible.
- Remote (primary Home) → RemoteExempt, `status = remote`, `source = self_service`, **no coordinates stored**. A Home assignment for today overrides an office primary.
- Hybrid (office primary + temporary site assignment valid today) → verified at the site. The same assignment on an expired date → not a candidate.
- Status leave/holiday pre-existing → OnLeaveOrHoliday.
- Manual HR correction on a GPS row → `hr_correction` verification with before/after and reason, `verification_status = overridden`.
- The approved time-change request on a GPS row writes an automatic `hr_correction` audit through the `updated` hook.
- Review approve and review reject → review columns set, a `Review` audit row written, times unchanged on reject.
- Missing-day request: approval creates the record. Rejection creates nothing.
- **Regression:** all existing `TestAttendanceScenarioTest` and `AttendanceTimeChangeTest` tests pass unchanged. Manual create through the resource keeps `verification_status = null`.

### 18.3 Security (`tests/Feature/GeofencedAttendanceSecurityTest.php`)
- **Cross-company:** a user whose default company is B, with an Employee in company A (the user's allowed companies include A but the default is B) → the service resolves only in the **default** company and never uses A's fences.
- A company-B location assigned to a company-A employee is rejected by the model guards (`Employee::assertHierarchyIsSameCompany`, the assignment guard).
- **Forged employee_id:** the payload carries `employee_id` for another employee → it is ignored, and the attempt is attributed to `Auth::user()`'s employee.
- **Forged distance / inside / verified:** extra payload keys `distance: 0`, `inside: true`, `result: 'verified'` with outside coordinates → still OutsideGeofence.
- **Forged location (mock-style):** accuracy 0.5 → flagged, never plain Verified. The same coordinates as yesterday → `repeated_coordinates` flag.
- **Unauthorized evidence access:**
  - A manager without `ViewAttendanceLocationEvidence` → `can('viewLocationEvidence')` is false, and the evidence modal omits latitude, longitude and IP. Assert this through a Livewire test of the resource action.
  - An employee cannot open the AttendanceRecordResource list (existing `canViewAny`).
  - An employee cannot view another employee's verification.
- **Self-review / self-override:** a user with `ReviewAttendanceVerifications` cannot review their own verification. A user with `ManageAttendance` cannot HR-correct their own GPS record.
- **Unauthorized geofence edit:** a user with `update_employee_work::location` but without `ManageAttendanceGeofences` changes latitude → `AuthorizationException`. The console/seeder context is allowed.
- **Replay:** the same `client_request_id` → a single row.
- **Stale:** `captured_at` 10 minutes old → StaleLocation.
- **Race:** simulate two concurrent check-ins. Pest runs in a single connection, so prove it by (a) asserting the service holds `lockForUpdate` on the employee (for example with query logging checking `for update`) and (b) calling `checkIn` twice back-to-back with different request ids → one record, and the second attempt gets AlreadyCheckedIn. Also force-insert a conflicting row between the steps with a model event to hit the `QueryException 23000` fallback path.
- **Rate limit:** the 11th attempt within a minute writes nothing and returns the rate-limited message.
- **Mass manipulation:** no bulk action exists for verifications. Assert that `AttendanceVerification::query()->delete()` throws or is prevented, and that updating `result` throws (append-only guard).

### 18.4 Livewire and page tests (`tests/Feature/MyAttendancePageTest.php`)
- The page is accessible to an eligible employee, returns 403 for a user with no employee, and returns 403 when the config is disabled.
- `checkIn([...])` with valid inside coordinates → success state rendered with the local time. Invalid payload → error message.
- The remote employee's page renders the non-geolocation button.

### 18.5 Browser and manual tests (checklist in the PR)
| # | Environment | Scenario | Expected |
|---|---|---|---|
| 1 | Desktop Chrome, `http://localhost:8000` | DevTools → Sensors → a location inside the fence | Verified |
| 2 | Same | Sensors → a location outside | Outside message, no record |
| 3 | Same | Block the location permission | Permission message; check-out flagged when a record is open |
| 4 | Android Chrome via `adb reverse tcp:8000 tcp:8000`, `http://localhost:8000` | Real GPS at the office | Verified or NeedsReview indoors |
| 5 | Android Chrome, `http://192.168.x.x:8000` (LAN IP) | Tap Check In | "secure (https) connection" message (proves the insecure-context branch) |
| 6 | HTTPS dev/staging URL | Inside, outside, denied, airplane mode (unavailable) | As per the §15 table |
| 7 | Production HTTPS | A smoke test by one HR user and one employee | Verified; evidence visible only to HR Manager |
| 8 | Android "approximate location" only | Tap Check In | Large accuracy → LowAccuracy or NeedsReview message |
| 9 | Double-tap, back button, refresh, two tabs | Rapid repeats | One record; later taps show AlreadyCheckedIn |

---

## 19. Local testing

- The Geolocation API works only in a **secure context**: `https://…`, `http://localhost` and `http://127.0.0.1`. **A plain-http LAN IP (`http://192.168.x.x`) is NOT secure.** There, Chrome and Safari refuse geolocation, and the §10 insecure-context branch fires.
- Recommended, with no new infrastructure:
  1. **Desktop:** `http://localhost:8000` (or `127.0.0.1`) with Chrome DevTools → More tools → **Sensors** → Location override. Use it for inside, outside and error cases.
  2. **Real Android phone:** USB debugging, then `adb reverse tcp:8000 tcp:8000`, then open `http://localhost:8000/admin` on the phone. This is a secure context with real GPS and needs no certificates.
  3. **Optional:** an HTTPS tunnel (for example `cloudflared tunnel --url http://localhost:8000`) for a phone that cannot be USB-connected. This is a dev-only convenience. It is not required and must not be committed as configuration.
  4. Avoid `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, except as a documented last resort on a test device.
- In the Docker development environment, publish the app port to the host and use option 1 or 2 against the host port.
- Set up a test fence at your desk with the admin "Use my current location" helper and a 100 m radius.

---

## 20. Production considerations

- Serve **HTTPS only**. Set `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true` and HSTS at the proxy or web server.
- `trustProxies(at: '*')` is currently global. In production, restrict it to the real proxy IPs (an ops change, outside this feature's code). Until then, treat `ip_address` as advisory.
- Do not add a `Permissions-Policy` header that disables geolocation. If one is ever added, use `geolocation=(self)`.
- iOS Safari: location permission is per-site and can be set to "Ask". The employee may see the prompt on each tap, which is expected.
- Performance per attempt:
  - One employee lock, about 3–5 indexed selects (assignments, open record, today's record, idempotency, last verification for the repeat check), one calendar interval computation for a single day, and two inserts/updates.
  - No external calls. Distance math is O(n) over at most 20 candidates.
  - Nothing loads location history, except the single "last accepted GPS verification" row.
- Queue: not needed. Everything is synchronous and fast.

---

## 21. Migration and deployment

1. Merge with `HR_ATTENDANCE_GEOFENCE_ENABLED=false` (the default).
2. `php artisan migrate` (the 4 migrations, in order), then `php artisan hr:sync-permissions`, then `php artisan config:clear`.
3. HR Administrator configures each office location: coordinates, radius (start at 150 m) and enabled. Assign employees (primary location, plus assignments for remote and temporary cases).
4. Pilot: enable in one environment for a small group. Review the NeedsReview rate and tune the thresholds in config.
5. Enable company-wide.
6. Run `graphify update .` after the code is merged, and re-query `AttendanceRecord` / `GeofencedAttendanceService` to confirm the dependency shape (only the service writes GPS attendance).

**Files to change or create (complete list):**

| Type | Path | Change |
|---|---|---|
| Migration | `plugins/webkul/employees/database/migrations/2026_09_30_100001_add_geofence_columns_to_employees_work_locations_table.php` | new |
| Migration | `plugins/webkul/employees/database/migrations/2026_09_30_100002_create_employees_employee_work_location_assignments_table.php` | new |
| Migration | `plugins/webkul/employees/database/migrations/2026_09_30_100003_create_employees_attendance_verifications_table.php` | new |
| Migration | `plugins/webkul/employees/database/migrations/2026_09_30_100004_add_verification_columns_to_employees_attendance_records_table.php` | new |
| Model | `plugins/webkul/employees/src/Models/WorkLocation.php` | columns, casts, scope, guard |
| Model | `plugins/webkul/employees/src/Models/Employee.php` | `workLocationAssignments()` |
| Model | `plugins/webkul/employees/src/Models/AttendanceRecord.php` | fillable, relations, `updated` audit hook |
| Model | `plugins/webkul/employees/src/Models/AttendanceVerification.php` | new |
| Model | `plugins/webkul/employees/src/Models/EmployeeWorkLocationAssignment.php` | new |
| Enums | `plugins/webkul/employees/src/Enums/{AttendanceSource,AttendanceVerificationAction,AttendanceVerificationMethod,AttendanceVerificationResult,AttendanceVerificationStatus}.php` | new |
| Services | `plugins/webkul/employees/src/Services/Attendance/{GeoDistanceCalculator,GeofenceEvaluator,AttendanceScheduleResolver,GeofencedAttendanceService}.php` | new |
| Services | `plugins/webkul/employees/src/Services/Attendance/Contracts/AttendanceVerifier.php`, `.../Verifiers/{GpsGeofenceVerifier,RemoteExemptVerifier}.php` | new |
| DTOs | `plugins/webkul/employees/src/Services/Attendance/Data/{LocationEvidence,GeofenceDecision,AttendanceAttemptResult}.php` | new |
| Service | `plugins/webkul/employees/src/Services/EmployeeRequestService.php` | add `requestMissingAttendance`; extend `applyAttendanceTimeChange` for `attendance_missing_day` only |
| Policy | `plugins/webkul/employees/src/Policies/AttendanceVerificationPolicy.php` | new; register it like the sibling policies |
| Permissions | `plugins/webkul/employees/src/Support/HrPermissions.php` | 3 constants plus bundle lines |
| Resource | `plugins/webkul/employees/src/Filament/Clusters/Configurations/Resources/WorkLocationResource.php` | company scope fix, geofence section, columns |
| Resource | `plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php` | source options, verification column, filters, evidence and review actions, reason-gated edit, self-edit guard |
| Resource | `plugins/webkul/employees/src/Filament/Resources/EmployeeResource.php` | register the relation manager |
| Relation manager | `plugins/webkul/employees/src/Filament/Resources/EmployeeResource/RelationManagers/WorkLocationAssignmentsRelationManager.php` | new |
| Page | `plugins/webkul/employees/src/Filament/Pages/MyAttendance.php` | new |
| View | `plugins/webkul/employees/resources/views/filament/pages/my-attendance.blade.php` | new (Alpine geolocation) |
| Lang | `plugins/webkul/employees/resources/lang/en/…` | messages (§15) |
| Config | `config/hr_attendance_geofence.php` | new |
| Command | `app/Console/Commands/PruneAttendanceEvidence.php` | new |
| Factories | `plugins/webkul/employees/database/factories/{AttendanceVerificationFactory,EmployeeWorkLocationAssignmentFactory}.php`; update `WorkLocationFactory` with a `geofenced()` state | new/modify |
| Tests | §18 files | new |

---

## 22. Rollback strategy

1. **Instant (preferred):** set `HR_ATTENDANCE_GEOFENCE_ENABLED=false` and run `config:clear`. The page disappears and the service refuses new attempts. All existing attendance, including GPS-created records, keeps working through the normal resource. The evidence is retained.
2. **Code revert:** revert the merge commit. The additive migrations can stay in place safely. Existing code ignores the extra nullable columns and tables. **Prefer leaving the schema in place.**
3. **Schema rollback (last resort):** `php artisan migrate:rollback --step=4`. This **permanently deletes all verification evidence and assignments**. Export `employees_attendance_verifications` first if the data has any audit value. `AttendanceRecord` rows created by GPS remain (with `source = gps`) and stay valid attendance data, because the only columns dropped are the link and status columns.
4. After any rollback, run `php artisan hr:sync-permissions`. It is harmless: unused permission names stay in the table, matching how the registrar never deletes.

---

## 23. Final verification checklist (for the implementer, before claiming done)

- [ ] `php artisan test --compact --filter=Geo` passes; then the full `plugins/webkul/employees` suite passes. Existing attendance tests are untouched and passing.
- [ ] `vendor/bin/pint --dirty` is clean.
- [ ] `php artisan migrate:fresh --seed` on a scratch database succeeds, and `migrate:rollback --step=4` then `migrate` round-trips.
- [ ] Manual checklist §18.5 rows 1, 2, 3 and 5 at minimum (localhost + DevTools + LAN insecure check). Row 4 if a phone is available.
- [ ] As the HR Auditor role: evidence coordinates are visible and there are no review buttons. As a line manager: review buttons are visible and coordinates are hidden. As an employee: My Attendance only, no Attendance resource.
- [ ] A company-A user never sees company-B work locations in `WorkLocationResource` (the scope fix).
- [ ] The `git diff` contains no unrelated changes.
- [ ] `graphify update .` has been run.

---

## 24. Critical final review (second pass)

| Question | Answer / where |
|---|---|
| Did I inspect the existing attendance? | Yes: model, migration, resource, correction service, seeder, analytics and tests (§1.1). |
| Did I avoid duplicate HR architecture? | Yes. `AttendanceRecord`, `WorkLocation`, `Employee.work_location_id`, `ApprovalEngine`, `HrPermissions` and `Calendar` are all reused. The only new tables are per-attempt evidence and multi-location assignments, both justified in §2. |
| Did I preserve company isolation? | Yes. The employee is resolved from `default_company_id`, candidates are re-checked for the same company, model guards cover locations and assignments, the policies check the company and hierarchy, and the pre-existing unscoped WorkLocationResource leak is fixed (§13.1). |
| Did I preserve existing permissions? | Yes. The three new permissions are additive and flow through `HrPermissionRegistrar`. Existing gates are unchanged, except for the additional self-edit denial on GPS rows (§7). |
| Did I preserve existing audit mechanisms? | Yes. The approval chain is reused. New evidence is append-only. GPS-sourced edits on any path are auto-audited (§5.5). |
| Did I preserve existing attendance behaviour? | Yes. The existing hooks compute late, early and worked values. The unique index is unchanged. Legacy rows have `verification_status = null`. Source strings are unchanged (§5.6). |
| Did I account for browser GPS limitations? | Yes: the accuracy tiers, stale checks, error codes and the honest spoofing limitation (§11.3, §11.4). |
| Is Laravel authoritative? | Yes. The client sends only raw evidence. Distance, decision, time and identity are all server-side (§3.3). |
| Did I handle accuracy? | Yes. See §11.3 (thresholds: verified ≤ 50 m, reviewable ≤ 150 m, reject above that, suspicious below 2 m). |
| Did I handle duplicate requests and race conditions? | Yes: the employee row lock, the idempotency unique key, the existing day-unique index, the `QueryException` fallback and the client busy flag (§12.3, §10). |
| Did I handle timezone? | Yes. The chain is employee → calendar → app timezone. Storage is UTC. The attendance date uses the local check-in date. Overnight shifts are handled by `max_shift_hours` (§6.3, §12.4). |
| Did I handle HTTPS? | Yes: the secure-context requirement, LAN IP caveat, adb reverse and production settings (§19, §20). |
| Did I handle mobile browser errors? | Yes. Permission, unavailable, timeout, insecure and unsupported cases each have a message and an audit row (§10, §15). |
| Did I handle remote employees? | Yes. Home locations and assignments lead to RemoteExempt with no location collected. Hybrid uses assignment precedence (§12.2). |
| Did I handle HR corrections? | Yes: the approval flow, direct HR correction with a reason, review, missing-day requests and no self-override (§16). |
| Did I include security tests? | Yes (§18.3). |
| Did I include migration safety? | Yes: additive, nullable, indexed and reversible, with explicit consequences (§4.5, §22). |
| Did I include privacy? | Yes: tap-only collection, no home coordinates, tiered visibility and the prune command (§17). |
| Did I include rollback? | Yes (§22). |
| Did I avoid unnecessary Android development? | Yes. It works in the browser only. |
| Did I avoid paid services and Google Maps? | Yes. There are no external APIs, and the map is at most an optional plain OpenStreetMap link. |
| Is the system extensible for future attendance sources? | Yes. `AttendanceSource`, `AttendanceVerificationMethod`, the `AttendanceVerifier` interface and a transport-agnostic service (§6.5). |

**Open decisions the implementer must NOT make alone. Flag them to the user if they come up:**
1. Whether `checkout_outside_geofence` and `checkout_without_location` should default to `reject` instead of `review` for this client. The spec defaults to `review` to protect worked-hours integrity.
2. The retention period (365 days by default) is a policy and legal decision for HR, and needs sign-off.
3. Whether line managers (not only HR) should see raw coordinates. The spec says **no**.
