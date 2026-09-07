# Aureus ERP — HR Module Audit Findings

**Phase 1: research and verification only. No application code was modified.**

---

## Execution context

| Item | Value |
|---|---|
| Repository | `C:\Intern\Handover\Erp` |
| Branch inspected | `master` (not switched, not created) |
| Stack | PHP `^8.3`, `laravel/framework ^13.0`, `filament/filament ^5.0` (confirmed from `composer.json`) |
| HR plugins in scope | `employees` (354 php files), `recruitments` (292), `time-off` (338), `timesheets` (14), `analytics` (5), `security` (147) |
| Report path | `reports/hr-audit-findings.md` |

### Limitations affecting this audit — read before acting on it

1. **Graphify was unavailable.** `AGENTS.md` instructs consulting Graphify when `graphify-out/graph.json` exists. It does not exist in this checkout, and no `graphify` CLI is on PATH. All relationship tracing here was done by direct source inspection, not graph queries. Findings are backed by cited file/line evidence, but a Graphify pass may surface call paths that grep missed.
2. **The test suite could not be executed.** The Windows `MySQL80` service (StartType `Automatic`) restarted mid-session and now owns port 3306 with credentials differing from the Laragon instance previously in use. `php artisan test .../HrPlatformTest.php` fails with `SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'` — a connectivity failure, **not** a logic failure. Restoring it requires either credentials unavailable to this audit or stopping a Windows service, both outside a read-only remit. Test *inventory* below is from source; test *results* are unverified.
3. **Schema facts were read from migrations**, not a live `SHOW COLUMNS`, for the same reason.

---

## A. Already implemented

| Area | Artefacts | Location |
|---|---|---|
| Employee master | 26 models incl. `Employee`, `Department`, `EmployeeStatusHistory`, `EmployeeResume`, `EmployeeSkill` | `plugins/webkul/employees/src/Models/` |
| Org hierarchy | `Employee.parent`, `Employee.coach`, `Department.manager_id` + `parent_path`, `Team.manager_employee_id` | `employees/src/Models/Employee.php:188,193` |
| Hierarchy visibility | `HrHierarchyService` | `employees/src/Services/HrHierarchyService.php` |
| Sensitive data control | `EmployeeSensitiveChangeService` | `employees/src/Services/EmployeeSensitiveChangeService.php` |
| Attendance | `AttendanceRecord` + `AttendanceRecordResource` | `employees/src/Models/AttendanceRecord.php` |
| Working schedules | `Calendar`, `CalendarAttendance`, `CalendarLeave` | `employees/src/Models/` |
| Timesheets | `Timesheet`, `TimesheetWorkflowService`, `TimesheetPolicy` | `plugins/webkul/timesheets/src/` |
| Leave | 9 models, `LeaveApprovalService`, 7 policies, 4 Filament clusters | `plugins/webkul/time-off/src/` |
| Performance | `PerformanceCycle`, `PerformanceGoal`, `PerformanceReview`, `PerformanceService` | `employees/src/` |
| Employee requests | `EmployeeRequest`, `EmployeeRequestType`, `EmployeeRequestService` | `employees/src/` |
| Recruitment | 21 models, `ApplicantIntakeService`, `ApplicantSourceRegistry`, 2 source adapters, 15 policies | `plugins/webkul/recruitments/src/` |
| Candidate conversion | `CandidateConversionService` | `recruitments/src/Services/CandidateConversionService.php` |
| Accounting handoff | `EmployeeRequestService::createAccountingDraft()` | `employees/src/Services/EmployeeRequestService.php:109` |
| Analytics | `HrAnalyticsService` + `hr-analytics` Filament page | `employees/src/Services/HrAnalyticsService.php` |
| Permissions | `HrPermissions` (12 constants), `HrPermissionRegistrar` | `employees/src/Support/HrPermissions.php` |
| Audit trail | `HasChatter` + `HasLogActivity` on `Employee`, `Leave`, `LeaveAllocation` | model trait declarations |

---

## B. Correctly implemented

Each verified, not assumed.

### B1. `HrHierarchyService` — hierarchy visibility
`employees/src/Services/HrHierarchyService.php`

Correct because it: asserts company access against **both** `default_company_id` and `allowedCompanies()` before doing anything (`assertCompanyAccess`); honours an explicit `hr_view_all_records` bypass; walks the reporting tree **transitively** via a frontier loop on `parent_id` rather than one level; includes department-managed employees **including sub-departments** via `parent_path LIKE '%/{id}/%'`; includes team-managed employees; and applies `company_id` to every constituent query so no branch can leak across companies.

### B2. Employment status history — audit of status transitions
`employees/src/Models/Employee.php:314-337`

Written from model `created`/`updated` boot hooks, guarded by `wasChanged('employment_status')` and a `company_id` check, capturing `previous_values`/`new_values`, `changed_by` and `effective_date` (falling back to `joining_date`/`leaving_date`). **Being at model level, this survives every write path** — UI, API, console, mass assignment. This is the correct place for such an invariant.

> An initial grep for `EmployeeStatusHistory` found only the `hasMany` relation, suggesting nothing wrote to it. That was wrong — writes go through `$employee->statusHistory()->create(...)`. Recorded so the same false negative is not re-derived.

### B3. `CandidateConversionService` — candidate to employee
`recruitments/src/Services/CandidateConversionService.php:13-54`

Rejects a candidate already linked to an employee **in another company** (`:23`), rejects candidate/application company mismatch (`:29`), and independently re-validates that the department (`:34`) and job position (`:40`) belong to the application's company before creating the employee. Company integrity is enforced per-association, not once.

### B4. Accounting handoff — `createAccountingDraft()`
`employees/src/Services/EmployeeRequestService.php:109-167`

Guards, in order: request must be `approved` **and** its type `is_financial`; early-returns if `accounting_move_id` is already set (idempotency); amount must be > 0; request currency must equal company currency (explicitly refuses to guess an FX rate); journal must be company-owned **and** of type `GENERAL`; debit and credit accounts are separately validated for company ownership and must differ. The write then re-acquires the row with `lockForUpdate()` inside a transaction and re-checks `accounting_move_id` — idempotent under concurrency, not just on the happy path. It creates a **draft** move; it does not post to the ledger.

### B5. Attendance derivation at model level
`employees/src/Models/AttendanceRecord.php:70-82` — `late_minutes` and `overtime_hours` computed in a `saving` hook, so they cannot drift from the source timestamps regardless of write path.

### B6. Approval workflows are genuinely wired, not decorative
`LeaveApprovalService`, `TimesheetWorkflowService` and `PerformanceService` all delegate to the shared `ApprovalEngine`, and are invoked from real UI actions: `time-off/.../TimeOffResource.php:152,165,192`; `timesheets/.../TimesheetResource.php:262,272,282`; `employees/.../PerformanceCycleResource.php:75`. `TimesheetWorkflowService::submit` additionally enforces self-submission unless the actor holds `hr_approve_timesheets` (`:20`). State is re-synchronised from the approval record via model hooks (`Leave.php:174`, `projects/src/Models/Timesheet.php:15`).

### B7. `HrAnalyticsService` — company scoping
Takes `companyId` as a required parameter and applies it to all 14 constituent queries, including joined ones (`employees.company_id`, `applications.company_id`, `requests.company_id`).

---

## C. Partially implemented

### C1. Sensitive-field approval gate is UI-local, not an invariant

**Exists:** `EmployeeSensitiveChangeService` whitelists 8 fields (`identification_id`, `passport_id`, `ssnid`, `sinid`, `bank_account_id`, `salary_grade`, `base_salary`, `salary_currency_id`), routes them through `ApprovalEngine`, and `applyApproved()` re-verifies request type, `approved` status and company before writing.

**Missing:** enforcement exists only in the `EmployeeResource` form, via `->visible(... 'hr_view_sensitive_employee_data')` and `->disabled(fn ($record) => $record !== null)` (`EmployeeResource.php:283-299, 680-695`). Filament does not dehydrate disabled fields, so this *is* server-side within that form — but all eight fields remain in `Employee::$fillable` (`Employee.php:97`) with **no model-level or service-level guard**. Any other write path — API resource, relation manager, console command, seeder, a future second form — sets them directly with no approval and no audit entry.

**Also note:** the fields are editable when `$record === null`, i.e. at creation. Salary can be set without approval at hire time. That may be intended; it should be confirmed as a product decision rather than assumed.

### C2. `MyAllocationResource` scopes the table but not the resource

`time-off/.../MyTime/Resources/MyAllocationResource.php:242-244` applies `->modifyQueryUsing(fn ($q) => $q->where('employee_id', Auth::user()?->employee?->id))` on the **table only**. There is no `getEloquentQuery()` override, so record resolution for View/Edit pages is unscoped. Compare `MyTimeOffResource.php:58-63`, which correctly overrides `getEloquentQuery()` with both a company filter and `whereHas('employee', ... user_id = Auth::id())`. The two "my" resources use different, unequal mechanisms.

### C3. Hierarchy scoping is applied to 2 of 12 employee-data resources

`HrHierarchyService` is referenced in production code in only three places: `EmployeeResource.php:1456`, `EmployeeRequestResource.php:51,120`, and injected into `EmployeeRequestService`. Every other employee-data list applies **company scoping only** — see D3 and E1-b.

---

## D. Incorrect implementation

### D1. Two parallel, unconnected authorization mechanisms — with different definitions of "my people"

**Defect.** Two independent notions of HR scope exist that never reference each other:

| Mechanism | Basis | Used by |
|---|---|---|
| `HasScopedPermissions` | `user.resource_permission` enum: `GLOBAL` / `GROUP` / `INDIVIDUAL`; group = shared `teams` | `EmployeePolicy::update/delete/...` via `hasAccess($user, $employee, 'coach')` |
| `HrHierarchyService` | `parent_id` reporting tree + department manager (incl. sub-departments) + team manager | `EmployeeResource`, `EmployeeRequestResource` |

`security/src/Traits/HasScopedPermissions.php:79`; `employees/src/Policies/EmployeePolicy.php:47,59,79,99`.

**Downstream impact.** The policy keys on the **`coach`** relation (`Employee.php:193`), a *different column* from **`parent`** (`Employee.php:188`) used by the hierarchy service. A manager who supervises an employee via `parent_id` is not their `coach`, so policy checks and list visibility disagree: the list shows records the policy may refuse, and the policy may permit records the list hides. Neither is authoritative. Any fix must pick one owner — a design decision, not a mechanical change.

### D2. `hasGlobalAccess()` performs no company check

`security/src/Traits/HasScopedPermissions.php:17-20`:

```php
protected function hasGlobalAccess(User $user): bool
{
    return $user->resource_permission === PermissionType::GLOBAL;
}
```

**Downstream impact.** Because `hasAccess()` short-circuits on this first, a user flagged `GLOBAL` passes `EmployeePolicy::update/delete` for an employee in **any company in the installation**. This is cross-company **write** authorization, not merely read leakage. Flagged, not fixed.

### D3. Record-level policy methods ignore the record they are given

| Policy | Method | File |
|---|---|---|
| `EmployeePolicy` | `view` | `employees/src/Policies/EmployeePolicy.php:25-28` |
| `LeavePolicy` | `view` | `time-off/src/Policies/LeavePolicy.php:26-29` |
| `LeaveAllocationPolicy` | `view`, `update`, `delete` | `time-off/src/Policies/LeaveAllocationPolicy.php:24-52` |

**Downstream impact.** Object-level authorization is absent on these paths — no company check, no ownership check, no hierarchy check. Where the corresponding resource also lacks `getEloquentQuery()` scoping (E1-a), the two gaps compose into direct cross-company record access by ID. Where the resource *is* scoped (e.g. `EmployeeResource`), the query is the only thing preventing it, so the protection is incidental rather than enforced by policy.

Note the inconsistency *within* `EmployeePolicy`: `update` (`:41-47`) performs a record check, `view` (`:25-28`) does not. The pattern across the module is that write paths were hardened and read paths were not.

---

## E. Missing functionality

### E1. Genuine gaps

**E1-a. Unscoped resources exposing company-owned employee data.** Verified: `AllocationResource` and `MyAllocationResource` both `extends Resource` with no `getEloquentQuery()`; `EmployeeSkillResource` likewise (`employees/src/Filament/Clusters/Reportings/Resources/EmployeeSkillResource.php:25`).

`LeaveAllocation` **is** company-owned — the column is `employee_company_id`, not `company_id`, confirmed at `time-off/database/migrations/*leave_allocations*:20`. A grep for `'company_id'` misses it; any fix must scope on `employee_company_id`. `EmployeeSkill` has **no** company column (confirmed: no match in its migration), so it must be scoped through `employee_id → employee.company_id`.

**E1-b. Hierarchy scoping absent on employee-data lists.** Company-scoped but not hierarchy-scoped, so any user reaching the screen sees every colleague in the company:

| Resource | File | Current scope |
|---|---|---|
| `AttendanceRecordResource` | `employees/.../AttendanceRecordResource.php` | `company_id` only |
| `PerformanceReviewResource` | `employees/.../PerformanceReviewResource.php:76-79` | `company_id` only |
| `PerformanceCycleResource` | `employees/.../PerformanceCycleResource.php:83-86` | `company_id` only |
| `TimeOffResource` | `time-off/.../Management/Resources/TimeOffResource.php:299-303` | `company_id` only |
| `ByEmployeeResource` | `time-off/.../Reporting/Resources/ByEmployeeResource.php` | inherits `TimeOffResource` — company only |
| `TimesheetResource` | `timesheets/.../TimesheetResource.php` | `company_id` only |

Attendance, performance ratings and leave records are personal data; company-wide visibility is a confidentiality gap, not merely a UX issue.

**E1-c. No test coverage for three of four HR plugins.** `recruitments/tests`, `time-off/tests`, `timesheets/tests` contain **zero** test files. All HR coverage sits in one file (see H).

**E1-d. Onboarding is not implemented.** `grep -rl "onboard"` across `employees/src` and `recruitments/src` returns nothing. `ActivityPlan` exists in both plugins but is a generic activity-plan subclass with only a `department()` relation (`employees/src/Models/ActivityPlan.php:8-10`) — not wired to hiring, employee creation, or any checklist/task-completion workflow. Classified as a genuine gap because conversion (`CandidateConversionService`) and `ActivityPlan` scaffolding already exist; **flagged for product decision** because no onboarding requirement is expressed anywhere in the code.

### E2. Future features (do not implement — product decision required)

- **Payroll** — see F.
- **Live ATS integrations (LinkedIn / Indeed)** — see G.
- **Biometric attendance capture** — see G.
- **HR foreign-exchange workflow.** `EmployeeRequestService:120-122` explicitly refuses non-company-currency financial requests with *"until an approved HR exchange-rate workflow is configured"* — a deliberate, documented boundary, not an oversight.

---

## F. Payroll boundary

**Payroll is not implemented.** Verified:

- No payroll migrations: `find plugins/webkul -path "*migrations*" -name "*payroll*"` returns no results.
- No payroll models, services or resources in any plugin.
- The only occurrence of the term in HR source is a **derived analytics metric**: `HrAnalyticsService.php:62` computes `monthly_payroll_cost` as a sum over `employees_employees` (aggregated `base_salary`).

Salary **storage** exists (`base_salary`, `salary_grade`, `salary_currency_id` on `Employee`) and is treated as sensitive data under approval control (B, C1). Salary **processing** — payslips, statutory deduction, disbursement, payroll journals — does not exist. The boundary is intact and should be stated as such to stakeholders.

---

## G. External integrations

| Integration | Status | Evidence |
|---|---|---|
| Manual applicant intake | **Active** | `ManualApplicantSourceAdapter` registered via `ApplicantSourceRegistry` |
| Generic API applicant intake | **Adapter-ready, not active** | `GenericApiApplicantSourceAdapter` implements only `key()` and `normalize(array $payload)` — no HTTP client, no endpoint, no credential handling |
| LinkedIn / Indeed | **Not present at all** | No source file in `employees/src` or `recruitments/src` references either |
| Biometric attendance | **Label only — not adapter-ready** | `'biometric'` appears solely as a dropdown option value in `AttendanceRecordResource.php:62` alongside `manual`/`import`/`api`. No device adapter, protocol handler or ingestion endpoint exists. |

No live external integration is wired in the HR module. Any claim to the contrary is unsupported by this codebase.

---

## H. Existing tests

**One file, ten tests, all in the `employees` plugin:** `plugins/webkul/employees/tests/Feature/HrPlatformTest.php`

| Line | Test | Area covered |
|---|---|---|
| 88 | enforces company team and manager hierarchy and audits approved sensitive employee changes | Hierarchy + sensitive-change audit |
| 132 | calculates attendance flags and completes self and manager performance review workflow | Attendance + performance |
| 172 | routes timesheets for approval and locks an auditable final status | Timesheets |
| 201 | routes leave through the shared approval engine with company isolation | Leave |
| 244 | sends an approved financial employee request to a balanced draft accounting journal exactly once | Accounting handoff + idempotency |
| 309 | converts a sourced applicant to one company employee without duplicate re-entry and reports HR analytics | Conversion + analytics |
| 364 | normalizes idempotent manual and API applicant intake without crossing companies | Recruitment intake |
| 415 | scopes recruitment and leave resources to the active company and current employee | Resource scoping |
| 473 | renders the integrated HR Filament pages for an administrator | Smoke |
| 497 | registers HR permissions for administrators and preserves the manager subset | Permissions |

**Coverage assessment.** Happy paths of each domain are covered. What is **not** covered maps directly onto the defects in D and E:

- No negative authorization tests — nothing asserts a non-manager is *refused* another employee's record.
- No test for the unscoped resources in E1-a (allocations, employee skills).
- No test that a `GLOBAL` `resource_permission` user is confined to their own company (D2).
- No test that sensitive fields cannot be written outside the approval flow (C1).
- Zero tests in `recruitments`, `time-off`, `timesheets`.

**Results unverified** — see Limitations. Test *names* assert behaviour this audit found contradicted in places (e.g. `:415` "scopes ... resources"), so passing status should be re-established before relying on them.

---

## I. Priority recommendation — implementation backlog

Ordered for a follow-up implementation agent. Each item names the file and the decision required, so no re-audit should be necessary.

### P0 — correctness / security / data integrity

| # | Item | Files | Notes |
|---|---|---|---|
| P0-1 | Scope `AllocationResource` and `MyAllocationResource` by company | `time-off/.../Management/Resources/AllocationResource.php`, `.../MyTime/Resources/MyAllocationResource.php` | Column is **`employee_company_id`**, not `company_id`. For `MyAllocationResource` add a real `getEloquentQuery()`; the existing table-level `modifyQueryUsing` (`:242`) does not protect record pages. |
| P0-2 | Scope `EmployeeSkillResource` | `employees/src/Filament/Clusters/Reportings/Resources/EmployeeSkillResource.php` | No company column on the model — scope via `employee.company_id`. |
| P0-3 | Add a company check to `hasGlobalAccess()` | `security/src/Traits/HasScopedPermissions.php:17-20` | Cross-company **write** authorization. Shared trait — check other consumers before changing. |
| P0-4 | Make record-level policy methods actually inspect the record | `EmployeePolicy::view`, `LeavePolicy::view`, `LeaveAllocationPolicy::view/update/delete` | Minimum: company equality. Preferably route through the mechanism chosen in P0-5. |
| P0-5 | **Decide the single authority for HR scope** (`HrHierarchyService` vs `HasScopedPermissions`), and reconcile `coach` vs `parent_id` | `employees/src/Policies/EmployeePolicy.php`, `security/src/Traits/HasScopedPermissions.php`, `employees/src/Services/HrHierarchyService.php` | **Design decision, not a mechanical fix.** Blocks a principled P0-4. Recommend `HrHierarchyService` as authority — it is company-aware, transitive and already the richer model. |

### P1 — missing HR workflow functionality

| # | Item | Files |
|---|---|---|
| P1-1 | Apply hierarchy scoping to attendance, performance (review + cycle), leave management and timesheet lists | the six resources listed in E1-b |
| P1-2 | Enforce the sensitive-field gate below the UI — a model/service invariant so non-Filament write paths cannot bypass approval | `employees/src/Models/Employee.php`, `EmployeeSensitiveChangeService.php` |
| P1-3 | Confirm whether salary should be freely settable at employee creation (`disabled(fn ($record) => $record !== null)`) | `EmployeeResource.php:283-299` — product decision |
| P1-4 | Add regression tests for every P0 item, plus negative authorization tests | new tests in `recruitments`, `time-off`, `timesheets` (currently zero) |

### P2 — usability / reporting

| # | Item |
|---|---|
| P2-1 | Unify the two "my time" resources on one scoping mechanism (`MyTimeOffResource` is the correct pattern; `MyAllocationResource` is not) |
| P2-2 | Review the ~20 unscoped configuration/master-data resources (`LeaveType`, `WorkLocation`, `MandatoryDay`, `AccrualPlan` **do** carry `company_id`; `DepartureReason`, `EmployeeCategory`, `RefuseReason`, `Degree` do **not**) and decide per-resource whether each is genuinely global master data or should be company-scoped |
| P2-3 | Restore a runnable test environment (see Limitations) so the suite can gate changes |

### P3 — future capabilities (product decision first, do not implement from this report)

- Payroll subsystem (F)
- Live ATS integrations — LinkedIn / Indeed (G)
- Biometric attendance device ingestion (G)
- Onboarding workflow (E1-d) — `ActivityPlan` scaffolding exists but is unwired; requires a stated requirement before building
- HR foreign-exchange workflow for non-company-currency financial requests (E2)

---

## Verification notes

- Every finding cites a file, and a line number where the evidence is line-specific.
- Where an initial reading proved wrong under checking (B2; and `ByEmployeeResource`, which inherits scoping from `TimeOffResource` rather than having none), the corrected conclusion is what appears here, with the false lead noted so it is not re-derived.
- No claim of an active external integration is made anywhere in this report; all are classified per the evidence found (G).
- No source file, migration or configuration was created, modified or deleted. The only writes were this report and its containing `reports/` directory.
