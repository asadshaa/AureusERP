# HR Static Bug Audit

**Method:** No manual testing. 8 parallel agents each read the actual source of one HR
sub-domain (no UI interaction) and reported candidate defects at the same bar as the three bugs
already found and fixed this session (`LeaveType.is_active`, the missing `leave_request`
Approval Workflow, and the wrong-requester anchoring in `LeaveApprovalService::submit()`). Every
candidate was then independently re-read by two adversarial reviewers instructed to refute it —
defaulting to "not a real bug" unless they could point to the exact lines causing the exact wrong
behavior. Nothing below survived without two independent confirmations against the live code.

**Result:** 16 candidates found → 16 confirmed → 0 refuted, 0 merely "plausible". After merging
duplicate reports of the same defect (found independently by more than one dimension), **14
distinct bugs**. None of these have been fixed yet — this is the findings report only.

---

## P0 — Silent data loss / corruption

### 1. `ApprovalEngine::submit()` silently discards a second requester's changes on collision
`plugins/webkul/support/src/Services/ApprovalEngine.php:56`

When a pending `ApprovalRequest` already exists for the same `company_id` + `request_type` +
`subject_type` + `subject_id`, `submit()` returns that existing request untouched — the new
caller's `$context` (their intended changes) is silently discarded, with no error and no trace.
This is in the **shared engine**, so it affects every request type that can collide this way, not
just one module. Concretely confirmed via `EmployeeSensitiveChangeService`: Manager A requests a
salary change for Employee E (creates pending request R1). Before R1 is approved, Manager B
requests a *different* change (e.g. bank account) for the same E. B's change is never persisted
anywhere — when R1 is later approved, only A's change gets applied. B sees the same "success"
notification as if their change had been captured.

### 2. `ApprovalEngine::decide()` commits `status='approved'` before the change is actually applied
`plugins/webkul/support/src/Services/ApprovalEngine.php:203`

The `DB::transaction` that marks a request `approved` commits and returns *before*
`ApprovalSubjectSynchronizer::synchronize()` runs the code that actually applies the change. If
that later step throws (concretely: `EmployeeSensitiveChangeService::applyApproved()` re-scopes
the employee lookup by the `company_id` captured at *submission* time — if the employee was moved
to a different company in the meantime, `firstOrFail()` throws), the request is permanently
stuck showing `approved` in the UI while the actual change was never written, with no retry path
anywhere in the codebase.

### 3. `EmployeeRequestService::synchronize()` can strand a financial request at `approved` forever
`plugins/webkul/employees/src/Services/EmployeeRequestService.php:101`

`is_financial` and `requires_amount` are independent toggles on `EmployeeRequestType` — nothing
forces the second on when the first is on. If an admin creates a type with `is_financial=true,
requires_amount=false`, an employee can submit with a null/zero amount. `synchronize()` commits
`status='approved'` first, then calls `createAccountingDraft()`, whose own guard throws when
amount ≤ 0 — after the approval is already durably committed. No UI action (`Edit`, `Submit`,
`Refresh`) is visible once `status='approved'`, so there is no way to ever recover: the request
sits "approved" with no accounting entry, permanently.

### 4. Bulk delete can remove an *approved* leave that the single-record screen refuses to delete
`plugins/webkul/time-off/src/Filament/Clusters/Management/Resources/TimeOffResource.php:219`

The row-level Delete button and `LeavePolicy::delete()` both correctly forbid deleting a leave in
state `VALIDATE_TWO` (approved). But `DeleteBulkAction::make()` is never given
`->authorizeIndividualRecords('delete')`, so Filament's default behavior only checks the blanket
`deleteAny` ability for the *whole* bulk action — it never re-checks each selected record. Anyone
holding the generic delete-any permission can filter the list to "Approved", select rows, and
bulk-delete them, silently destroying an approved, balance-consuming leave record.

---

## P1 — Wrong-requester approval routing (same bug class already fixed in `LeaveApprovalService`)

Three more services call into the shared `ApprovalEngine::submit()` and have the *identical*
shape of the bug already fixed this session: when a manager/HR user submits **on an employee's
behalf** (a normal, explicitly-permitted action via a `can()` gate), the service records *the
acting user* as the `ApprovalRequest`'s requester instead of the actual subject. Any
`hierarchy_route` step (`requester_manager` / `department_manager` / `team_manager`) then
resolves against the wrong person's manager chain — leaving the request unapprovable by anyone,
or approvable by someone with no real relationship to the request. `LeaveApprovalService::submit()`
already has the fix (`$leave->employee->user ?? $requester`, with a comment); none of these three
do.

### 5. `TimesheetWorkflowService::submit()` — `plugins/webkul/timesheets/src/Services/TimesheetWorkflowService.php:27`
Anchors to whoever clicks "Submit" (permitted via `hr_approve_timesheets` for non-owners), not
the timesheet's actual owner (`Timesheet.user_id`).

### 6. `EmployeeRequestService::submit()` — `plugins/webkul/employees/src/Services/EmployeeRequestService.php:38`
Anchors to whoever clicks "Submit" (permitted via `HrHierarchyService::assertCanManage()` for a
manager acting on a subordinate's request), not the request's own employee.

### 7. `EmployeeSensitiveChangeService::submit()` — `plugins/webkul/employees/src/Services/EmployeeSensitiveChangeService.php:34`
Anchors to the HR admin who clicked "Request sensitive change" (gated by
`hr_manage_sensitive_employee_data`), not the affected employee.

---

## P1 — Broken review/approval logic

### 8. `PerformanceService::launch()` can assign a `NULL` reviewer that can never be satisfied
`plugins/webkul/employees/src/Services/PerformanceService.php:33`

`reviewer_id` is set to `$employee->parent_id ?? $employee->department?->manager_id`. Both are
nullable with no fallback. Every company has at least one employee at the top of the hierarchy
(founder/CEO) with `parent_id = NULL`; if their department also has no `manager_id` set (the
normal state for a top-level department), `reviewer_id` is written as `NULL`.
`submitSelfReview()` never checks `reviewer_id`, so the employee can still move the review to
`manager_review` — but `completeManagerReview()`'s check, `(int) $review->reviewer_id === (int)
$reviewer->id`, casts `null` to `0`, which no real employee ID (always ≥ 1) can ever equal. The
review is permanently stuck at `manager_review`, for every possible reviewer, with no error at
launch time.

### 9. `PerformanceService::completeManagerReview()` never checks reviewer ≠ employee
`plugins/webkul/employees/src/Services/PerformanceService.php:61`

If an employee has no `parent_id` and is also the `manager_id` of their own department (a normal
setup for a department head with nobody above them), step 8's same formula makes
`reviewer_id = employee_id`. Nothing stops that employee from calling `submitSelfReview()` and
then `completeManagerReview()` on themselves — the two-step self-then-manager review collapses
into pure self-approval with zero independent oversight.

### 10. Resubmitting a refused leave never re-checks the allocation balance
`plugins/webkul/time-off/src/Services/LeaveApprovalService.php:16` (and
`TimeOffResource.php:139-159`, `TimeOffHelper.php:281-351`)

The only balance check in the module (`handleLeaveAllocation()`) runs exclusively inside the
Filament Create/Edit page lifecycle. The "Submit for approval" table action — which is visible
for a `REFUSE`-state leave — calls `LeaveApprovalService::submit()` directly, bypassing that
lifecycle entirely. If a manager rejects a 5-day request, the employee's balance later drops to
0, and then someone resubmits and approves that same refused record, it can reach `VALIDATE_TWO`
against a balance that no longer covers it — with no check at submit, approve, or synchronize
time.

---

## P2 — Data integrity / correctness

### 11. Clearing one side of check-in/check-out leaves stale computed values
`plugins/webkul/employees/src/Models/AttendanceRecord.php:77`

The `saving` hook only *recomputes* `worked_hours` / `late_minutes` / `early_departure_minutes`
when both sides of the relevant pair are present — it never resets them when one side is cleared.
Editing a record to clear `check_out` (a normal correction, via a plain non-required
`DateTimePicker`) leaves the old `worked_hours` value on the row unchanged. `HrAnalyticsService`
queries `late_minutes > 0` / `early_departure_minutes > 0` directly against these stale columns,
so an incomplete record keeps being counted as late/early in analytics.

### 12. Editing a leave request double-counts the record's own pre-edit value against itself
`plugins/webkul/time-off/src/Traits/TimeOffHelper.php:281`

`mutateTimeOffData()` passes `$excludeRecordId` into the overlap check (`handleLeaveOverlap`) so
editing a leave doesn't collide with itself — but does **not** pass it into
`handleLeaveAllocation()`, whose balance signature has no such parameter at all. The balance
check's `totalTaken` sum still includes the record's own stale (pre-edit) day count. Example: a
10-day allocation, one existing 2-day approved leave being edited up to 9 days (post-edit total
9/10, well within allocation) — the check computes `totalTaken=2` (stale), `available=8`,
`requested=9`, and incorrectly blocks a perfectly valid edit.

### 13. `Candidate::createEmployee()` converts the wrong application when a candidate has more than one
`plugins/webkul/recruitments/src/Models/Candidate.php:121`

The "Create Employee" button on a Candidate's page calls `Candidate::createEmployee()`, which
picks "the" application via `Applicant::where('candidate_id', $this->id)->latest('id')->first()`
— i.e. whichever application has the highest ID, with no filter on pipeline stage. A candidate
with more than one application (a normal, unprevented scenario) can be converted using an
unrelated, possibly-refused later application's `job_id`/`department_id`, while the actual
offer-stage application is left un-converted and never marked hired.

### 14. Candidate email de-duplication has no normalization and no DB constraint
`plugins/webkul/recruitments/src/Services/ApplicantIntakeService.php:68`

When an inbound application's `external_application_id` doesn't match an existing one, the
fallback candidate lookup is an exact-string `where('email_from', ...)` — no trim, no
lowercasing, anywhere in the pipeline (not the validator, not either source adapter). There is
also no unique index on `email_from` (unlike `external_application_id`, which has one). A
byte-different but identical email (a stray leading space from an upstream ATS, different
casing) creates a second `Candidate` row and a second duplicate `Partner` contact for the same
real person, silently splitting their history.

---

## Suggested fix order

1. **P0 items 1–4** first — these are silent data loss/corruption, the most damaging class.
2. **P1 items 5–7** next — mechanical, same fix pattern as the already-fixed `LeaveApprovalService`
   (anchor to `$subject->employee->user ?? $requester`), fast to apply with a matching regression
   test per service, same as the one already written for leave.
3. **P1 items 8–10** — review/approval logic gaps.
4. **P2 items 11–14** — real but lower-blast-radius correctness issues.

Every fix should get the same treatment as the three already applied: explain, fix, add a
regression test, verify fail-then-pass by isolating the fix.
