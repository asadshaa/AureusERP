# Geofenced Attendance: Implementation Review

**Author:** Sonnet (implementation pass 1). **Date:** 2026-09-30.
**Input:** `GEOfenced_ATTENDANCE_IMPLEMENTATION_SPEC.md`.
**Status:** implemented and covered by automated tests. **Nothing has been verified on a real phone, over HTTPS, or in a logged-in real browser.** See §7.

Every claim below is labelled either **automated** (a test or command I ran) or **not verified** (needs a device, browser or ops step).

---

## 1. Implementation summary

Browser-GPS check-in / check-out built on the existing HR architecture. No parallel attendance system exists.

**How it works:** the employee opens **Employees → My Attendance** (a Filament page) and taps Check In or Check Out. The browser calls `getCurrentPosition` once and sends raw evidence (`latitude, longitude, accuracy, captured_at, client_request_id`) to a Livewire action. `GeofencedAttendanceService` resolves the employee, company and workplace from `Auth::user()`. It computes the Haversine distance itself and applies the accuracy policy and attendance rules. It writes one `AttendanceVerification` row per attempt and, if accepted, creates or updates the day's existing `AttendanceRecord`.

**Created**
- **4 migrations**, all additive:
  - geofence columns on `employees_work_locations`
  - `employees_employee_work_location_assignments`
  - `employees_attendance_verifications` (append-only evidence)
  - 3 nullable columns on `employees_attendance_records`
- **Models:** `AttendanceVerification` (plus `Builders/AttendanceVerificationBuilder`) and `EmployeeWorkLocationAssignment`.
- **5 enums:** `AttendanceSource`, `AttendanceVerificationAction`, `AttendanceVerificationMethod`, `AttendanceVerificationResult`, `AttendanceVerificationStatus`.
- **Services** in `Services/Attendance/`:
  - `GeoDistanceCalculator`
  - `GeofenceEvaluator`
  - `AttendanceScheduleResolver`
  - `GeofencedAttendanceService`
  - `Contracts/AttendanceVerifier`, `Verifiers/GpsGeofenceVerifier`, `Verifiers/RemoteExemptVerifier`
  - `Data/{LocationEvidence,GeofenceDecision,AttendanceAttemptResult}`
- **Authorization and config:**
  - `AttendanceVerificationPolicy`
  - 3 new permissions
  - `config/hr_attendance_geofence.php`
  - prune command `hr:prune-attendance-evidence`
- **UI:**
  - `MyAttendance` page and Blade view
  - evidence Blade view
  - "use my location" Blade helper
  - `WorkLocationAssignmentsRelationManager`
- **Language file:** `resources/lang/en/attendance.php`.
- **Factory:** `EmployeeWorkLocationAssignmentFactory`.
- **Tests:** 6 test files plus a helper file, in `plugins/webkul/employees/tests/Feature/Attendance/`.

**Modified**
- `WorkLocation`: geofence columns, casts, `isGeofenceUsable()`, and a save guard (§4).
- `AttendanceRecord`: new fillable columns, relations, and an `updated` audit hook.
- `Employee`: one `workLocationAssignments()` relation.
- `HrPermissions`: 3 constants plus role bundles.
- `EmployeeRequestService`: `requestMissingAttendance()` and a new branch in `applyAttendanceTimeChange()`.
- `WorkLocationResource`: company-scope fix, geofence section, columns, infolist.
- `AttendanceRecordResource`: source options, verification column and filters, evidence and review actions, audited edit path.
- `EmployeeResource`: registers the relation manager.
- `EmployeeServiceProvider`: registers the 4 migrations, because plugin migrations are listed explicitly.
- `WorkLocationFactory`: adds a `geofenced()` state.

**Deployed to the dev and testing databases:** migrations applied to `aureuserp` and `aureuserp_testing`. `php artisan hr:sync-permissions` ran on dev (159 permissions synced; each new permission is granted to 4 roles). `HR_ATTENDANCE_GEOFENCE_ENABLED` is **left at its default `false`**, and `.env` was not changed for this feature. `graphify update .` was run.

**Nothing was committed or pushed.**

---

## 2. Specification coverage

| Spec § | Status | Notes |
|---|---|---|
| 0 How to read | NOT APPLICABLE | Reading guidance only. |
| 1 Repository findings | IMPLEMENTED (verified) | Re-inspected the repo. All findings held. One factual correction: the spec's "Karachi↔Lahore ≈ 1,030 km" is really ≈ 1,033 km. The test uses 1,033 km (§3, T1). Two dev-data observations are new (§3, O1 and O2). |
| 2 Reuse; what is not created | IMPLEMENTED | Reuses `AttendanceRecord`, `WorkLocation`, `Employee.work_location_id`, `ApprovalEngine`, `HrPermissions`, `HrHierarchyService`, `Calendar` and the existing time-change workflow. The only new tables are the two the spec justified. |
| 3.1–3.3 Architecture and invariants | IMPLEMENTED | Server-authoritative. Employee, company and workplace come from the session user. Nothing decision-related is read from the client. Invariants are covered by tests (§4). |
| 4.1 Work-location geofence columns | IMPLEMENTED | Applied to dev and testing databases. |
| 4.2 Assignments table | IMPLEMENTED | **Deviation:** explicit short constraint names. MySQL rejected the auto-generated name (>64 characters, error 1059). See B1. |
| 4.3 Verifications table | IMPLEMENTED | Same explicit-name precaution on one foreign key. |
| 4.4 Attendance-record columns | IMPLEMENTED | |
| 4.5 Migration safety | PARTIALLY IMPLEMENTED | Additive, indexed, nullable. `migrate --pretend` was inspected. `migrate:rollback --step=4` and re-`migrate` was **run on the testing DB** and succeeds. **Not done:** `migrate:fresh --seed` on a scratch database. |
| 5.1 `WorkLocation` model | IMPLEMENTED (+extension) | Columns, casts, `scopeGeofenced`, `isGeofenceUsable`, and a save guard covering: no coordinates on Home, valid coordinates, radius range, enabled requires complete data, and permission required to change geofence columns. **Extension:** the guard also rejects moving a location into a company the signed-in user cannot act in. |
| 5.2 Assignment model | IMPLEMENTED | Same-company guard, date-order guard, `scopeEffectiveOn`. A defaulting bug was fixed (B4). |
| 5.3 `Employee` relation | IMPLEMENTED | |
| 5.4 `AttendanceVerification` | IMPLEMENTED (+extension) | Append-only through model events. **Extension:** `AttendanceVerificationBuilder` also blocks mass `delete()` and whitelists `update()` columns, because model events do not fire for query-level writes (spec gap, S2). `$hidden` covers latitude, longitude, IP and user agent. |
| 5.5 `AttendanceRecord` changes | IMPLEMENTED | The `updated` hook audits any later change to `check_in` or `check_out` on a `gps` / `self_service` record, from any write path. The service suppresses it during its own writes. Existing `creating` / `saving` hooks are untouched. |
| 5.6 Enums | IMPLEMENTED | `AttendanceSource` is deliberately not cast on the model, as the spec said. |
| 6.1 `GeoDistanceCalculator` | IMPLEMENTED | |
| 6.2 `GeofenceEvaluator` | IMPLEMENTED | Distance is also computed for rejected low-accuracy and stale readings so HR sees it. |
| 6.3 `AttendanceScheduleResolver` | IMPLEMENTED (**deviation**) | The spec assumed `Calendar::getAttendanceIntervalsBatch(..., timezone:)` returns local working hours. It does not: it lays `hour_from` / `hour_to` out as UTC wall-clock and it type-hints mutable `Carbon`. The resolver asks for the day in UTC and reinterprets the wall-clock values in the employee's timezone. Verified by a test: 09:00–17:00 Asia/Karachi becomes 04:00–12:00 UTC and late minutes = 15. |
| 6.4 `GeofencedAttendanceService` | IMPLEMENTED | `checkIn`, `checkOut`, `recordClientFailure`, `reviewVerification`, `recordHrCorrection`, `resolveEmployee` (plus `findEmployee`, `isEligible`), `candidateLocations` (plus `resolveMode`), `todayState`. |
| 6.5 Extensibility seam | PARTIALLY IMPLEMENTED | `AttendanceVerifier` interface and two verifiers exist. The orchestrator injects the two concrete classes, so a future QR verifier needs a small change in `decide()`. It is not yet container-resolved by method. |
| 6.6 Config | IMPLEMENTED | Adds `default_radius_meters`. |
| 7 Policies | IMPLEMENTED | `AttendanceVerificationPolicy` (`viewAny, view, viewLocationEvidence, review`) is picked up by Laravel policy discovery (proven by the tests). The self-edit / self-delete denial for own GPS records is applied to the Edit and Delete actions and inside the service. `MyAttendance` is intentionally not Shield-gated, as the spec said. |
| 8 Permissions | IMPLEMENTED | 3 constants, `all()`, role bundles exactly as specified. Managers get review but not evidence. |
| 9 Routes / endpoints | IMPLEMENTED | No new routes. Livewire actions `checkIn`, `checkOut`, `reportLocationFailure`. Validation via `Validator`. Rate limit 10 / min / user. Kill switch. |
| 10 Browser geolocation JS | PARTIALLY IMPLEMENTED | Written to the spec: one `getCurrentPosition` call per tap, `enableHighAccuracy`, 15 s timeout, `maximumAge: 0`, insecure-context and unsupported branches, double-tap guard, error-code mapping, remote mode without geolocation. **The JavaScript has never been executed in a browser** (§7). |
| 11.1–11.3 Validation, Haversine, decision rules | IMPLEMENTED | Covered by unit tests. |
| 11.4 Spoofing limitation | IMPLEMENTED | Stated in the Work Location form help text. |
| 12.1–12.5 Eligibility, mode, check-in, check-out, client failure | IMPLEMENTED | **Deviation:** at check-out, a `none` mode (workplace no longer configured) follows the `checkout_without_location` policy instead of hard-rejecting, so a check-out is not lost to a configuration gap. |
| 12.6 Review | IMPLEMENTED | |
| 12.7 HR correction | IMPLEMENTED | |
| 13.1 Work Location admin | PARTIALLY IMPLEMENTED | Done: company-scope fix, geofence section, table columns, infolist gating, model-side permission enforcement. **Not implemented:** the optional OpenStreetMap link. The "Use my current location" helper exists but is untested in a browser. |
| 13.2 Employee assignments | IMPLEMENTED | Relation manager with authorization tests. |
| 13.3 Attendance resource | IMPLEMENTED | Source options, verification badge, filters, evidence modal, approve / reject, reason-gated audited edit for evidence-backed rows, self-edit guard. |
| 14 Employee UI | PARTIALLY IMPLEMENTED | Page, state card, button phases, outcome banner, privacy note, 14-day list, correction and missed-day actions all render under Livewire tests. Built from Filament components, not custom Tailwind (the Tailwind skill is not available here). **Not looked at in a browser or on a phone.** |
| 15 Error messages | PARTIALLY IMPLEMENTED | English only. Arabic and Spanish fall back to English. |
| 16 HR corrections | PARTIALLY IMPLEMENTED | Done: approval-based time change, direct reasoned correction, review, assignments, and the missed-day request (approval creates the record). **Not implemented:** writing an extra `hr_correction` verification when HR creates a record for an employee who had a rejected GPS attempt that day (an optional row in the spec table). |
| 17 Privacy | IMPLEMENTED | Tap-only collection. No coordinates on Home locations, and none for remote employees. Coordinates only for real location decisions. Tiered visibility. Prune command. **Not done:** the prune command is not scheduled (`routes/console.php` has no schedule entries today). The retention period needs HR sign-off. |
| 18.1 Unit tests | IMPLEMENTED | |
| 18.2 Feature tests | IMPLEMENTED | Includes overnight, hybrid, remote, leave, placeholder-row and duplicate scenarios. |
| 18.3 Security tests | PARTIALLY IMPLEMENTED | Cross-company, forged fields, evidence access, self-review, self-correction, replay, append-only and rate limiting are covered. The race condition is proven only by asserting the `FOR UPDATE` lock, not by concurrent execution (S4). |
| 18.4 Page tests | IMPLEMENTED | Plus extra admin-UI tests (17). |
| 18.5 Browser / manual tests | NOT IMPLEMENTED | Cannot be done from this environment (§7). |
| 19 Local testing | NOT APPLICABLE | Guidance for humans; no code. |
| 20 Production considerations | NOT IMPLEMENTED | Ops items: HTTPS, `SESSION_SECURE_COOKIE`, restricting `trustProxies(at: '*')`. |
| 21 Migration / deployment | PARTIALLY IMPLEMENTED | See 4.5. Migrations applied to dev and testing, permissions synced, `graphify update` run. The pilot and rollout are human steps. |
| 22 Rollback | PARTIALLY IMPLEMENTED | Kill switch works (a test asserts the service refuses when disabled, and the page is hidden). Schema rollback proven on the testing DB. |
| 23 Final checklist | PARTIALLY IMPLEMENTED | Done: tests, Pint, rollback round-trip, role-based UI checks through Livewire, `graphify update`. **Not done:** `migrate:fresh --seed`, the manual browser and phone checklist. |
| 24 Final review | NOT APPLICABLE | Self-review of the spec. |

---

## 3. Bugs

### Found and fixed during implementation

| ID | Severity | File / location | Problem | Expected | Actual (before fix) | Fix |
|---|---|---|---|---|---|---|
| B1 | High (blocked deploy) | `…_100002_create_employees_employee_work_location_assignments_table.php` | The auto-generated foreign-key name `employees_employee_work_location_assignments_work_location_id_foreign` is over MySQL's 64-character limit (error 1059). The migration was left half-applied on both databases. | Migration succeeds. | Table created, then FK creation failed. | Dropped the empty table, gave the constraints explicit short names, re-ran. Also shortened one borderline name in migration 3. |
| B2 | High | `AttendanceScheduleResolver::scheduledWindowFor` | Passed `CarbonImmutable` to `Calendar::getAttendanceIntervalsBatch()`, which type-hints `Carbon\Carbon`. The `TypeError` was swallowed by my defensive `try/catch`. | Scheduled start/end computed, so late/early minutes work. | Silently `[null, null]`; late minutes always 0. | Pass mutable `Carbon`. A test caught it; the warning log would not have. |
| B3 | Medium | same file; underlying quirk in `plugins/webkul/support/src/Models/Calendar.php` | The calendar builds `hour_from` / `hour_to` as UTC wall-clock regardless of timezone, so a 09:00–17:00 Karachi calendar came back as 09:00Z–17:00Z (14:00–22:00 local). | 09:00–17:00 local. | Wrong by the UTC offset. | Worked around by reinterpreting wall-clock values in the employee timezone (test-verified). The `Calendar` quirk itself is **not fixed** (out of scope; see O8). |
| B4 | High | `EmployeeWorkLocationAssignment::booted` | Eloquent fires `saving` **before** `creating`. `company_id` was defaulted in `creating`, so the same-company check in `saving` saw `null`. Creating an assignment through the relation manager (which omits `company_id`) threw. | Create works. | `InvalidArgumentException`. | Default `company_id` at the start of `saving`. A UI test covers it. |
| B5 | Low | `AttendanceVerification` (spec §5.4) | Model events do not fire for `AttendanceVerification::query()->delete()` or mass `update()`, so the spec's append-only guard could be bypassed. | Append-only. | Bypassable at query level. | Added `AttendanceVerificationBuilder` (blocks `delete`, whitelists `update` columns). |

### Open (not fixed)

| ID | Severity | Where | Problem | Recommended fix |
|---|---|---|---|---|
| O1 | **Medium (deployment blocker for real users)** | Eligibility in `GeofencedAttendanceService::isEligible` (spec §12.1) | The rule needs `is_active = true` **and** an eligible `employment_status`. In the dev database **19 of 25 employees have `is_active = 0` with `employment_status = 'active'`** (including Khurram). The column defaults to 0 and only the Employee form defaults it to true. These employees would be refused ("Your employee profile is not set up…"). | Decide the source of truth. Either bulk-correct `is_active` for genuinely active staff before rollout, or base eligibility on `employment_status` alone. Left as the spec wrote it because it fails closed. |
| O2 | Medium | `AttendanceScheduleResolver::timezoneFor` | 23 of 25 dev employees have `time_zone = NULL` and no timezone-bearing calendar. They fall back to `APP_TIMEZONE=UTC`, so `attendance_date` rolls at 05:00 Karachi time. Fine for office hours, wrong for night shifts. | Set `Employee.time_zone` (or a calendar timezone) for staff before rollout, or add a company timezone (schema change; not in the spec). |
| O3 | Low | `AttendanceRecordResource::applyEdit` | For GPS-backed rows only `check_in` / `check_out` edits are audited. HR edits to `status`, `notes`, `overtime_hours` or `scheduled_*` on such a row are plain updates with no audit row. | Include changed non-time fields in the correction metadata, or add `HasLogActivity`. |
| O4 | Low | `GeofencedAttendanceService::attempt` | The `QueryException` 23000 fallback path is unreachable in single-connection tests, so it is untested. The employee row lock (proven by query inspection) is the actual protection. | Load-test with parallel requests (§7). |
| O5 | Low | `GeofencedAttendanceService::__construct` / `decide` | The verifier seam is not container-resolved (see 6.5). | Tag verifiers in the service provider and select by method when a second method is added. |
| O6 | Low | `WorkLocationResource` | The list is scoped to the user's default company, while the company Select offers every company the user may act in. A location saved into a non-default allowed company will not appear in that user's list. | Switch default company, or widen the list scope to the allowed companies. |
| O7 | Low | `GeofencedAttendanceService::reject` | Non-location rejections (`AlreadyCheckedIn` etc.) are logged with `method = gps` even for remote employees. Cosmetic audit inaccuracy. | Pass the resolved mode into `reject()`. |
| O8 | Info | `Support\Models\Calendar::getAttendanceIntervalsBatch` | Pre-existing quirk described in B3. Any other feature using it will get UTC-offset results. | Fix in the support plugin separately, then simplify the resolver. |
| O9 | Low | `AttendanceRecordResource::applyEdit` | The "did the time change" comparison is at minute resolution, so a seconds-only edit is ignored. | Compare at second resolution if seconds matter. |
| O10 | Low | `use-my-location.blade.php` | Fills fields by finding `input[id$='.latitude']`. This assumes Filament renders input ids ending in the state path. Not seen in a real browser. | Verify manually (§7). |

---

## 4. Security issues

**Fixed or mitigated by design (automated):**
- **Forged identity, company, location, distance or verdict.** Ignored. Employee, company and workplace come only from `Auth::user()`. Tested with forged `employee_id`, `company_id`, `work_location_id`, `distance`, `inside`, `verified`, `result`: an outside-fence attempt is still rejected and attributed to the signed-in employee.
- **Malformed payload** (text, out-of-range, arrays, absurd accuracy): audited as `InvalidCoordinates`, coordinates not stored.
- **Cross-company.** The employee is resolved in the user's default company only. A user with a different default company gets `EmployeeNotEligible`. Model guards reject a foreign work location on an employee, a foreign assignment, and moving a location into a company the user cannot act in. The unscoped Work Locations list is fixed.
- **Evidence visibility.** Raw coordinates, IP and device only with `hr_view_attendance_location_evidence`, and only for employees the viewer may manage. Employees see their own attempt but not its raw evidence. Foreign-company HR sees nothing. Not present in `toArray()`. The work-location JSON API resource lists fields explicitly, so coordinates are not exposed there.
- **Self-service abuse.** Nobody can review or HR-correct their own attendance. Edit and Delete are hidden on own GPS records.
- **Unauthorized geofence edits.** Enforced in the model save, not only the form.
- **Replay.** Idempotency is scoped per employee. A replayed request id returns the original outcome and writes nothing, even a day later. Stale readings (older than 120 s) and future readings (more than 60 s ahead) are rejected.
- **Silent edits of evidence-backed times.** Audited on any write path.
- **Append-only evidence.** Model and builder guards.
- **Rate limit.** 10 attempts / minute / user, nothing written once limited.

**Remaining risks:**
- **S1 Spoofing is not preventable in a browser.** A rooted phone or mock-location tool can report any coordinates. Mitigations: flags (`suspicious_accuracy` under 2 m, `repeated_coordinates`, `low_accuracy`), review queue, evidence, IP and user-agent capture. Anti-spoofing is **not** perfect. Stronger assurance needs a QR-at-premises or native-app verifier.
- **S2 Query-level bypass.** Raw `DB::table()` can still alter evidence. The prune command uses it deliberately. Anyone with database access is out of scope.
- **S3 IP address is advisory.** `trustProxies(at: '*')` lets a client influence `request()->ip()` unless the app is really behind a trusted proxy. An ops fix.
- **S4 Race condition proven indirectly.** The `SELECT … FOR UPDATE` on the employee row is asserted, and back-to-back duplicate attempts yield one record. Genuine parallel requests were not run.
- **S5 By design:** an employee whose primary workplace is a Home location, or who has an effective Home assignment, checks in with no location at all. Setting that requires `update_employee_employee` and hierarchy access, and it is recorded (`assigned_by`, `reason`). An office whose fence is merely switched off does **not** exempt anyone (tested).
- **S6 Client-tamperable Livewire state.** `MyAttendance::$outcome` is a public property a client could alter. It is display-only and no decision reads it.

---

## 5. Test failures

**Run history (automated):**

| Test | Failure | Root cause | Fixed? |
|---|---|---|---|
| `GeoDistanceCalculatorTest` Karachi↔Lahore | 1,033 km, expected 1,030 ± 1 | My expected value came from the spec's loose figure; the implementation is right (independent estimate ≈ 1,033.6 km) | Yes: test expectation corrected |
| `GeofencedAttendanceServiceTest` late minutes | 0, expected 15 | **Real bug B2** (TypeError swallowed) and **B3** (UTC wall-clock) | Yes: code fixed |
| `MyAttendancePageTest` own-record correction | No request created | Test fixture seeded the attendance workflow before creating the company | Yes: fixture reordered |
| `GeofencedAttendanceSecurityTest` geofence-permission test (3 iterations) | `AuthorizationException` on a permitted update | Test artifacts: a rejected save leaves dirty attributes on the object, and the app caches the permission list (`forgetCachedPermissions()` was missing in my helper) | Yes: tests and helper corrected. Production code was right |
| `GeofencedAttendanceSecurityTest` append-only test | `LogicException` on a legitimate update | Same stale-dirty-attribute artifact | Yes: fresh instances |
| `HrGeofenceAdminUiTest` (2 tests) | "create action not visible" | Test called a table header action as a page action | Yes: test corrected |
| `HrGeofenceAdminUiTest` assignment create | `InvalidArgumentException` | **Real bug B4** | Yes: code fixed |

**Final state (automated):**
- **Attendance suite:** `plugins/webkul/employees/tests/Feature/Attendance`: **154 passed, 0 failed** (517 assertions).
- **Full employees suite:** `plugins/webkul/employees/tests/Feature`: **272 passed, 24 failed**.
- **The 24 failures are pre-existing and unrelated.** Proved by A/B: my paths were stashed (`git stash` of `plugins/`, `config/`, `app/Console/Commands/PruneAttendanceEvidence.php`, sha `d78ea96b`), the six affected files were run, then the stash was restored and dropped. The baseline fails the identical 24 tests and the failing-name lists are byte-identical:
  - `ClaimsWorkflowTest` (12) and `ClaimsAcceptanceTest` (4): the claims workflow
  - `OnboardingScenarioTest` (3) and `ReportingAccessScenarioTest` (1): `new EmployeePolicy()` is called without its required `HrHierarchyService` argument
  - `BulkActionAuthorizationTest` (3): bulk-delete authorization
  - `HrPlatformTest` (1): "renders the integrated HR Filament pages for an administrator"
- **Formatting:** `vendor/bin/pint --dirty --test` passes.
- **Not run:** other plugins' suites (`accounts`, `accounting`, `support`, …). My changes do not touch them, but I did not run them. No JS/build check was needed (no npm assets changed).

---

## 6. Missing requirements (vs. the specification)

- Optional OpenStreetMap link on the Work Location view (§13.1).
- Extra `hr_correction` verification when HR creates a record for an employee with a rejected GPS attempt that day (§16 optional row).
- Arabic and Spanish message translations (§15).
- `migrate:fresh --seed` on a scratch database (§23).
- The manual browser, phone and HTTPS test matrix (§18.5). See §7.
- Scheduling of `hr:prune-attendance-evidence`; no HR sign-off on the retention period (§17).
- HTTPS / `SESSION_SECURE_COOKIE` / `trustProxies` hardening (§20, ops).
- Container-resolved verifier selection (§6.5, O5).
- `AttendanceVerificationFactory` (mentioned in the spec's file list). Not needed by any test, so not created.

---

## 7. Manual testing still required

I could **not** do any of the following. In particular, no real GPS reading, no phone, no HTTPS URL and no logged-in browser session was used. I tried to open the admin in the in-app browser, but it redirected to the login page (no session) and I do not enter credentials.

1. **Real device GPS:** Chrome on Android, at the office, inside and outside the fence; approximate-location-only; airplane mode (unavailable); permission denied then granted.
2. **Secure-context behaviour:** `http://localhost` (with `adb reverse`) should work; `http://<LAN IP>` should show the "secure (https) connection" message; an HTTPS staging or production URL.
3. **The Alpine geolocation JavaScript:** phases ("Requesting location…" → "Verifying location…"), double-tap, back button, refresh, two tabs. None was executed.
4. **Layout on a phone:** the page uses Filament components and no custom Tailwind, so its appearance is unseen.
5. **Admin screens in a browser:** Work Locations form (geofence section and the "Use my current location" button), Attendance list (verification badge, filters, evidence modal, approve / reject), Employee → Work Locations relation manager. Livewire component tests pass, but nothing was visually checked.
6. **Real concurrency:** two simultaneous check-ins (for example two `curl`s or two tabs) for one employee; confirm exactly one record.
7. **Data readiness:** decide O1 (`is_active`) and O2 (time zones) before enabling for real staff.
8. **Enable and pilot:** set `HR_ATTENDANCE_GEOFENCE_ENABLED=true` in one environment, configure one workplace fence from the admin screen, assign a few employees, and watch the needs-review rate.
9. `php artisan migrate:fresh --seed` on a scratch database.

---

## 8. Files changed (for the next reviewer)

The tracked and untracked change list is in `git status` (nothing committed). `graphify-out/` was regenerated and is not part of the change set.
