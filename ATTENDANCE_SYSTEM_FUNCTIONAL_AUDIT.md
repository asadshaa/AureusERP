# AureusERP — Complete Attendance System Functional Audit Report

**Date:** 2026-09-30  
**Environment:** Laravel 13 / Filament 5 / Pest 4.4 / MySQL (Isolated Testing & Code Inspection)  
**Scope:** Full End-to-End Functional, Architectural, Permission, Multi-Company, and Calculation Audit of the Attendance Subsystem  

---

## 1. Executive Summary

A comprehensive, non-destructive audit was performed across the entire Attendance subsystem in AureusERP. The system consists of two primary operational entry points:
1. **Employee Self-Service Check-In / Check-Out (`MyAttendance` Page):** Browser GPS-assisted check-in, geofence verification, shift state tracking, anti-spoofing flags, and attendance correction requests routed via line-manager approval workflows.
2. **HR Attendance Management (`AttendanceRecordResource`):** Administrative overview of daily attendance records, evidence review modals for flagged attempts, audited HR time corrections, and manual entry creation.

**Key Findings:**
- **Core Geofencing, Idempotency, and Audit Verification:** Robust and high integrity. Append-only guards on `AttendanceVerification` prevent data tampering even through Eloquent query builder.
- **Critical Authorization Gap Discovered:** `AttendanceRecord` lacks a dedicated model policy (`AttendanceRecordPolicy` does not exist). `AttendanceRecordResource` defines `canViewAny()` checking `hr_view_attendance` or `hr_manage_attendance`, but does **not** define `canCreate()`, `canEdit()`, or `canDelete()`. As proven by automated tests, **a user holding only `hr_view_attendance` can create, edit, and delete manual attendance records for themselves and their subordinates.**
- **Validation Defect in HR Resource Form:** The `check_out` field in `AttendanceRecordResource::form()` lacks an `->after('check_in')` rule. A user can save a record where `check_out` is earlier than `check_in` (e.g., check-in 17:00, check-out 09:00), which saves cleanly to the database with `worked_hours = 0.0000`.
- **Forgotten Shifts Lifecycle:** When an employee checks in and forgets to check out, once `max_shift_hours` (16 hours) elapses, the employee can check in for a new shift, but the forgotten shift remains with `check_out = NULL` and `worked_hours = 0.0000` indefinitely with no automated cron closer.

---

## 2. Architecture Map & Flow

```
User
  │ (Auth::user(), default_company_id, permissions)
  ▼
Employee
  │ (company_id, work_location_id, calendar_id, parent_id, is_active, employment_status)
  ├─────────────────────────────────────────┬──────────────────────────────────────────┐
  ▼                                         ▼                                          ▼
Self-Service (`MyAttendance`)        HR Admin (`AttendanceRecordResource`)     Correction Workflow (`EmployeeRequestService`)
  │ (Livewire untrusted payload)            │ (Filament List/Manage)                   │ (ApprovalEngine: requester_manager)
  ▼                                         ▼                                          ▼
`GeofencedAttendanceService`          `HrHierarchyService`                       Line Manager Approval
  │ (lockForUpdate, Haversine,              │ (Scopes visibleEmployeeIds)              │
  │  candidate work locations)              ▼                                          ▼
  ▼                                  `AttendanceRecord`                         `AttendanceRecord::update()`
`AttendanceVerification`              │ (Save hooks: worked_hours, late/early)   │ (Triggers updated hook ->
  │ (Append-only audit row)                 ▼                                          │  AttendanceVerification::HrCorrection)
  └─────────────────────────────────> Database (`employees_attendance_records`) <──────┘
```

### Components Summary

| Layer | Component | Location | Role / Responsibility |
|---|---|---|---|
| **Database** | `employees_attendance_records` | Migration `2026_08_25_000011` & `2026_09_30_100004` | Stores daily attendance, worked hours, scheduled times, late/early metrics, status, and verification links. Unique on `(company_id, employee_id, attendance_date)`. |
| **Database** | `employees_attendance_verifications` | Migration `2026_09_30_100003` | Immutable append-only audit log of every check-in/out attempt, geofence calculation, client error, HR review, or HR correction. |
| **Model** | `AttendanceRecord` | `plugins/webkul/employees/src/Models/AttendanceRecord.php` | Eloquent model with automatic calculation hooks for `worked_hours`, `late_minutes`, and `early_departure_minutes`. Contains `updated` and `created` hooks to audit time edits. |
| **Model** | `AttendanceVerification` | `plugins/webkul/employees/src/Models/AttendanceVerification.php` | Protected audit model with `updating` and `deleting` guards throwing `LogicException` on unauthorized column mutations or record deletion. |
| **Model** | `CalendarAttendance` | `plugins/webkul/employees/src/Models/CalendarAttendance.php` | Links to working calendars defining standard working hours per weekday (`hour_from`, `hour_to`). |
| **Service** | `GeofencedAttendanceService` | `plugins/webkul/employees/src/Services/Attendance/GeofencedAttendanceService.php` | Core engine resolving employee eligibility, work location candidates, evaluating Haversine distance, enforcing row locks, handling idempotency, and executing check-in/out. |
| **Service** | `AttendanceScheduleResolver` | `plugins/webkul/employees/src/Services/Attendance/AttendanceScheduleResolver.php` | Computes employee timezone, local attendance date, and scheduled start/end windows from the employee calendar. |
| **Service** | `HrHierarchyService` | `plugins/webkul/employees/src/Services/HrHierarchyService.php` | Computes visible employee IDs based on company, management hierarchy, department managers, team managers, or `hr_view_all_records`. |
| **Service** | `EmployeeRequestService` | `plugins/webkul/employees/src/Services/EmployeeRequestService.php` | Handles employee-initiated requests for missed attendance days and time adjustments, routing through `ApprovalEngine`. |
| **UI** | `AttendanceRecordResource` | `plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php` | Filament CRUD resource for HR administrators with verification evidence modal and review actions. |
| **UI** | `MyAttendance` | `plugins/webkul/employees/src/Filament/Pages/MyAttendance.php` | Filament page for employee self-service mobile check-in/out. |
| **Command** | `PruneAttendanceEvidence` | `app/Console/Commands/PruneAttendanceEvidence.php` | Prunes precise coordinates and IP addresses older than retention threshold (default 90 days). |

---

## 3. Attendance Feature Inventory

| Feature | Sub-Feature | Implementation Status | Functional Audit Result |
|---|---|---|---|
| **Attendance Records** | Manual creation by HR | **Implemented and working** | Works as expected via `CreateAction` in `AttendanceRecordResource`. Defaults `creator_id` to current user. |
| | View attendance records | **Implemented and working** | Table displays attendance date, employee, status badge, check-in, check-out, worked hours, late minutes, early departure, source, and verification status. |
| | Edit attendance (manual record) | **Implemented and working** | Plain updates succeed for non-evidence records. |
| | Edit attendance (evidence-backed) | **Implemented and working** | Changes to `check_in` or `check_out` require a minimum 10-character reason, execute through `recordHrCorrection()`, and create an `AttendanceVerification` audit row. |
| | Delete attendance (manual record) | **Implemented and working** | Deletes record; cascade leaves verification records with `attendance_record_id = NULL`. |
| | Delete attendance (evidence-backed) | **Implemented and working** | Prohibited for employee's own record; permitted for permitted HR managers. |
| | Multi-Company Isolation | **Implemented and working** | Scoped via `default_company_id`. Unique key prevents cross-company duplication. |
| | Bulk Actions | **Not implemented** | `AttendanceRecordResource` defines no table bulk actions. |
| **Calculations** | Worked hours calculation | **Implemented and working** | Calculated in `AttendanceRecord::saving()` hook: `max(0, check_in->diffInMinutes(check_out) / 60)`. Correct for same-day and overnight shifts. |
| | Inverted time handling (`check_out < check_in`) | **Implemented but defective** | Model saving hook clamps to `0.0000` worked hours instead of throwing validation exception. Filament form accepts it without validation error. |
| | Late minutes calculation | **Implemented and working** | `max(0, scheduled_start->diffInMinutes(check_in, false))`. Correctly handles early check-in (yields 0) and late check-in. |
| | Early departure minutes | **Implemented and working** | `max(0, check_out->diffInMinutes(scheduled_end, false))`. Correctly handles late departure (yields 0) and early departure. |
| | Overtime hours | **Partially working / Manual only** | Stored on record and editable by HR, but **never automatically computed** from scheduled hours or worked hours. |
| **Self-Service Check-In / Out** | Mobile GPS Check-In | **Implemented and working** | Captures browser GPS, calculates Haversine distance, verifies against workplace geofence radius. |
| | Remote / Work-from-Home | **Implemented and working** | Employees with `Home` work location or active `Home` assignment check in with `method = none` without requiring coordinates. |
| | Temporary Worksite Assignment | **Implemented and working** | `EmployeeWorkLocationAssignment` allows hybrid/multi-site check-in within validity window. |
| | Shift Overlap / Duplicate Check-in | **Implemented and working** | Rejects check-in if open shift exists or if check-in already recorded for today. |
| | Overnight Shifts | **Implemented and working** | Check-out arriving after midnight remains linked to the record of the day the shift started. |
| | Forgotten Shift Handling | **Implemented but partially working** | Shifts open longer than `max_shift_hours` allow new check-in, but the forgotten shift remains unclosed with 0 worked hours indefinitely. |
| | Anti-Spoofing & Accuracy Checks | **Implemented and working** | Detects coordinates < 2m (`suspicious_accuracy`), excessive inaccuracy (> 250m), stale readings (> 120s), and repeated coordinates across days. Flags attempt for review. |
| | Rate Limiting | **Implemented and working** | Rate-limited to 10 attempts/minute/user via Laravel `RateLimiter`. |
| | Idempotency | **Implemented and working** | Per-employee unique `client_request_id` returns identical cached outcome without writing second record. |
| **Workflow & Requests** | Missed Day Request | **Implemented and working** | Submits `attendance_missing_day` request to line manager via `ApprovalEngine`. On approval, creates record. |
| | Time Change Request | **Implemented and working** | Submits `attendance_time_change` request to line manager. On approval, updates record and logs `HrCorrection` audit verification. |
| | Review Queue for Flagged Check-Ins | **Implemented and working** | Reviewer can Approve or Reject flagged attempts with required note. Rejecting flags day but preserves recorded times. |
| **External Integrations** | Biometric integration | **Not implemented** | `AttendanceSource::Biometric` enum exists; no hardware or service drivers implemented. |
| | Attendance CSV/Excel Import | **Not implemented** | `AttendanceSource::Import` enum exists; no batch file parser implemented for attendance. |
| | REST API endpoints | **Not implemented** | No public API routes; all attendance operates via authenticated Filament Livewire panels. |

---

## 4. Deep-Dive Findings & Defects

### Defect 1 (Security / Authorization): View-Only Users Have Full CRUD on Manual Attendance Records
- **Severity:** High
- **Location:** [`AttendanceRecordResource.php`](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php) & Policies directory.
- **Description:** 
  The application has no `AttendanceRecordPolicy` registered in `plugins/webkul/employees/src/Policies/`.
  In `AttendanceRecordResource.php`:
  ```php
  public static function canViewAny(): bool
  {
      $user = Auth::user();
      return $user !== null && ($user->can(HrPermissions::ManageAttendance) || $user->can(HrPermissions::ViewAttendance));
  }
  ```
  However, `AttendanceRecordResource` does **not** declare `canCreate()`, `canEdit()`, or `canDelete()`.
  Furthermore:
  - `CreateAction::make()` has no visibility check.
  - `EditAction::make()` only checks `! self::isOwnEvidenceBacked($record)`.
  - `DeleteAction::make()` only checks `! self::isOwnEvidenceBacked($record)`.
- **Proof:** Automated feature test in `AttendanceSystemAuditTest.php` verified:
  ```php
  $canCreate = AttendanceRecordResource::canCreate(); // Returns TRUE for view-only user
  $canEdit   = AttendanceRecordResource::canEdit($record); // Returns TRUE
  $canDelete = AttendanceRecordResource::canDelete($record); // Returns TRUE
  ```
  A user granted only `hr_view_attendance` successfully invoked `callTableAction('create')`, `callTableAction('edit')`, and `callTableAction('delete')` on their own manual records.
- **Recommended Fix:** 
  1. Add an `AttendanceRecordPolicy` or implement resource-level gates on `AttendanceRecordResource`:
     ```php
     public static function canCreate(): bool
     {
         return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
     }

     public static function canEdit(Model $record): bool
     {
         return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
     }

     public static function canDelete(Model $record): bool
     {
         return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
     }
     ```

---

### Defect 2 (Validation): Missing Chronological Time Validation on HR Form
- **Severity:** Medium
- **Location:** [`AttendanceRecordResource.php:74-75`](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php#L74-L75)
- **Description:** 
  In the Filament form definition:
  ```php
  DateTimePicker::make('check_in')->seconds(false),
  DateTimePicker::make('check_out')->seconds(false),
  ```
  There is no rule verifying that `check_out` occurs after `check_in`. When submitting `check_in = 17:00` and `check_out = 09:00`, Filament validates the form without errors and inserts the record. The model saving hook calculates `worked_hours = 0.0000` via `max(0, -480 / 60)`.
- **Recommended Fix:**
  Add Filament validation rule:
  ```php
  DateTimePicker::make('check_out')
      ->seconds(false)
      ->after('check_in'),
  ```

---

### Defect 3 (Data Integrity): Unbounded Attendance Date Range on HR Form
- **Severity:** Low
- **Location:** [`AttendanceRecordResource.php:71`](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Filament/Resources/AttendanceRecordResource.php#L71)
- **Description:** 
  While `MyAttendance::requestMissingDayAction()` restricts dates between `now()->subDays(30)` and `now()`, the HR manual form has `DatePicker::make('attendance_date')->required()->native(false)` without `minDate()` or `maxDate()`. An administrator can accidentally set dates decades in the future or past.
- **Recommended Fix:**
  Add reasonable boundary guards (e.g. max date = today or end of current pay period).

---

### Defect 4 (Lifecycle): Forgotten Open Shifts Remain Indefinitely Unclosed
- **Severity:** Low (Operational)
- **Location:** [`GeofencedAttendanceService.php:633-646`](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Services/Attendance/GeofencedAttendanceService.php#L633-L646)
- **Description:** 
  If an employee checks in at 09:00 and forgets to check out, after 16 hours (`max_shift_hours`) the shift is ignored by `openRecord()`, allowing the employee to check in for the next shift. However, the original record remains with `check_out = NULL`, `worked_hours = 0.0000`, and `status = 'present'`. There is no background task or scheduled command to flag or close forgotten shifts.
- **Recommended Fix:**
  Introduce an Artisan command (e.g., `hr:close-forgotten-shifts` or `hr:flag-forgotten-shifts`) to either set `status = 'incomplete'` or flag them for manager review.

---

## 5. Security & Isolation Matrix

| Check | Expected Behavior | Actual Behavior | Result |
|---|---|---|---|
| **Cross-Company Read** | User in Company A cannot view Company B attendance | Scoped via `where('company_id', $user->default_company_id)` | **PASS** |
| **Cross-Company Write** | User in Company A cannot select Company B employee | Relationship query in `AttendanceRecordResource` scopes to `company_id` and visible hierarchy | **PASS** |
| **Cross-Company Route Binding** | Tampering URL record ID to Company B record | Resolves through `getEloquentQuery()`, returning 404 ModelNotFound | **PASS** |
| **Hierarchy Isolation** | Manager can only see subordinates and department/team members | `HrHierarchyService::visibleEmployeeIds` correctly limits query | **PASS** |
| **All-Records Bypass** | User with `hr_view_all_records` sees all company records | `HrHierarchyService` correctly grants full company visibility | **PASS** |
| **Evidence Access** | Sensitive coordinates/IP hidden from unauthorized users | `AttendanceVerificationPolicy` requires `hr_view_attendance_location_evidence` | **PASS** |
| **Self-Review Prevention** | User cannot approve or review their own GPS attendance | Prohibited by `AttendanceVerificationPolicy::review` and `isOwnEvidenceBacked` | **PASS** |
| **Append-Only Evidence** | Evidence rows cannot be updated or deleted via Eloquent | `AttendanceVerification` model and builder throw `LogicException` | **PASS** |
| **Role Separation (View vs Manage)** | User with `hr_view_attendance` cannot create, edit, or delete | No policy or resource gates exist; view-only users can perform full CRUD | **FAIL** |

---

## 6. Verification Test Suite

An automated test suite was constructed and verified in:
[`tests/Feature/AttendanceSystemAuditTest.php`](file:///c:/Intern/Handover/Erp/tests/Feature/AttendanceSystemAuditTest.php)

Test execution results:
- **15 Tests Executed, 15 Passed (50 assertions, 10.20s duration)**
  - `worked hours, late minutes, and early departure calculations`: PASS
  - `on-time check-in and check-out`: PASS
  - `null check-out handling`: PASS
  - `overnight shift across calendar midnight`: PASS
  - `inverted check-in / check-out calculation`: PASS
  - `unique constraint on company + employee + date`: PASS
  - `resource query company isolation`: PASS
  - `manager hierarchy scoping`: PASS
  - `hr_view_all_records bypass`: PASS
  - `canViewAny permission requirement`: PASS
  - `own GPS attendance edit prevention`: PASS
  - `view-only user mutation probe`: PASS (Demonstrated authorization defect)
  - `inverted times form validation probe`: PASS (Demonstrated validation defect)
  - `model updated audit hook`: PASS
  - `recordHrCorrection validation & minimum reason length`: PASS

---

## 7. Audit Conclusion

The core calculation and geofencing engine is well-architected, highly resilient against concurrency and tampering, and thoroughly auditable. 

To bring the subsystem to enterprise production readiness, two targeted fixes are required:
1. Enforce `HrPermissions::ManageAttendance` on `AttendanceRecordResource` (or via an `AttendanceRecordPolicy`) so that `hr_view_attendance` remains strictly read-only.
2. Add an `->after('check_in')` validation rule to the `check_out` field in `AttendanceRecordResource::form()`.
