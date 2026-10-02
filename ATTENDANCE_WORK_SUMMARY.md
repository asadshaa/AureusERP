# Attendance and Time Off: work summary

**Date:** 2026-10-01
**Repository:** `handover` → https://github.com/asadshaa/AureusERP_HndOvr (branch `main`)
**State:** everything listed as done below is committed and pushed. Nothing is pending locally.

Legend: ✅ done · ⚠️ **left / needs action** · ℹ️ note

---

## 1. Commits pushed

| Commit | What it is |
|---|---|
| `3efb0f1` | Browser-GPS geofenced attendance with the HR review workflow, plus all the attendance bug fixes |
| `a3c52bd` | Time Off: only HR / leave managers see other people's leave |
| `a435902` | Fix: "Use my current location" printed its script as text on the Work Location form |

---

## 2. How the system works now

### Employee
- **Attendance → My Attendance → Check In / Check Out.**
  - The browser asks for location once per tap.
  - There is no background tracking.
- Remote workers (a *Home* workplace, or an approved remote day) check in with no location.
- **My recent attendance** (on the same page) offers:
  - **Request correction** on a day.
  - **Request attendance for a missed day** (up to 30 days back).
  - Both go to the line manager for approval.
- Leave: each person sees only their **own** leave and balance (Time Off → My Time, dashboard cards).

### Line manager
- **Attendance → Attendance Register** and **Needs Review**, for their own team.
- Gets a bell notification when a team member's check-in or check-out is flagged, or when a shift was never checked out.
- Approves correction requests in **Settings → Approval Queue**.
- Sees their own team's leave in Time Off → Management.

### HR (HR Manager)
- **Attendance → Workplaces & Geofences:**
  - Office coordinates and allowed radius.
  - Home locations for remote staff.
- **Employee → Work Information:** work location, working hours and line manager for each person.
- **Employee → Work Locations tab:** extra or temporary workplaces, and approved remote days.
- **Attendance → Needs Review:** see the evidence (distance, accuracy, flags), then Approve or Reject with a note.
- **Attendance → Attendance Register → Edit:** correct times. A reason is required for GPS-verified days, and it is kept permanently.
- **Delete** of a GPS-verified day requires a reason and leaves an audit record.
- **Time Off → Overview / Management:** the whole company's leave.

### Admin
- Holds every permission (1,304), so it sees every screen and widget. This is by design.

---

## 3. What was built (geofenced attendance) ✅

- **Database:** four additive migrations.
  - Geofence columns on work locations.
  - Extra/temporary workplace assignments.
  - An append-only verification (evidence) table.
  - Verification links and status on attendance records.
- **Server decides everything:**
  - Employee, company and workplace come from the logged-in user.
  - Distance is calculated on the server (Haversine).
  - Nothing the browser sends about identity, distance or "verified" is trusted.
- **Accuracy rules:**
  - Verified when GPS accuracy is ≤ 50 m inside the fence.
  - 50–150 m inside the fence: recorded, but flagged for review.
  - Over 150 m: rejected.
  - Check-in from outside the fence: rejected.
  - Check-out from outside the fence: recorded and flagged.
- **Protection against duplicates and races:**
  - A per-employee database lock.
  - An idempotent request id.
  - One record per employee per day.
  - Rate limit of 10 attempts per minute.
- **Evidence:** every attempt is stored, including failed ones. Raw coordinates, IP and device are visible only with the *location evidence* permission (HR Manager, auditors).
- **Corrections:**
  - Line-manager approval for time changes and missed days.
  - HR direct correction with a reason.
  - Any later edit of GPS times is audited, whatever path made it.
  - Nobody can edit, delete or approve their own attendance.
- **Privacy:**
  - Location is read only on a tap.
  - Home locations can never store coordinates.
  - A daily prune job clears coordinates, IP and device after 365 days.
- **Kill switch:** `HR_ATTENDANCE_GEOFENCE_ENABLED`.

---

## 4. Bugs found and fixed ✅

### From the audit of Gemini's handover

| # | Bug | Fix |
|---|---|---|
| 1 | A forgotten check-out tapped hours later was saved as a clean "verified" 15-hour shift | The check-out is saved but flagged **long_shift** for review: more than 12 h, or later than the scheduled end + 3 h |
| 2 | HR could delete a GPS-verified day with no reason and no audit | A reason is required; an audit row keeps a snapshot of the deleted day; the evidence is kept |
| 3 | The browser rewrote old GPS timestamps to "now", which defeated the stale-reading check | The real timestamp is sent; age is measured on the phone's own clock; a stale check-out is kept and flagged; the page retries once for a fresh reading |
| 4 | The HR form accepted a check-out earlier than the check-in (saved as 0 hours) | The form refuses it; the date range is bounded |
| 5 | Approved leave in Time Off was ignored, so someone on leave could check in | Approved full-day leave blocks check-in. Half-day and hourly leave do not |
| 6 | Shifts never checked out stayed open forever with 0 hours | Hourly job `hr:flag-forgotten-shifts` sends them to review once and notifies the line manager |
| 7 | Nobody was told about flagged attendance | The line manager gets a bell notification, sent immediately rather than through the queue |
| 8 | A correction request for someone with no line manager would wait forever (approvals have no admin bypass) | Refused up front with a clear message |
| 9 | Employees with no workplace saw a Check In button that could only fail | They now see "No workplace is set up, contact HR" |
| 10 | Linking a manual HR entry to that day's rejected GPS attempts compared UTC with local dates | Uses the employee's local day |
| 11 | An audit test asserted that bug #4 *existed*, so fixing the bug would fail the test | The test now asserts the fix |

### Reported by you

| # | Problem | Fix |
|---|---|---|
| 12 | Employees (e.g. Hamza) had no Check In button | Gemini had set `HR_ATTENDANCE_HR_ONLY=true` in `.env`; it is now `false`. Attendance has its own sidebar section like Time Off: employees see **My Attendance**; HR and managers also see the Register, Needs Review and Workplaces & Geofences |
| 13 | Every employee could see the whole company's leave calendar and leave statistics | Seeing other people's leave needs the Time Off management permission **in code**, limited to the viewer's hierarchy. Employees keep their own leave. HR and managers now also see their *own* leave cards |
| 14 | "Management" appeared in Time Off for plain employees | Management → Allocations now needs the allocation-management permission |
| 15 | The Work Location form showed raw JavaScript under "Use my current location" | A quoted word broke the HTML attribute; fixed, with a regression test |

---

## 5. Tests ✅ / ℹ️

- ✅ **241 tests pass** across the attendance suites, the audit and scenario tests in `tests/Feature`, and the new leave-visibility tests. These are the latest run of each file.
- ✅ Pint (code style) passes on all changed files.
- ℹ️ **Failures that existed before this work.** Each was proven with an A/B run: the same tests fail without these changes.
  - Employees suite: 24 failures (claims workflow ×16, `EmployeePolicy` built without its constructor argument ×4, bulk delete ×3, one HR page render).
  - Time Off suite: 8 failures (leave balance numbers, approval workflow step count).
  - ⚠️ These are separate bugs that are not fixed.
- ℹ️ **Nothing was tested on a real phone**, over HTTPS, or by me in a logged-in browser. Gemini's "live test" was a desktop PC (Wi-Fi/IP location, not GPS).

---

## 6. ⚠️ Left to do

### 6.1 Code: Working Schedules (high priority, not started)

Working schedules are what make *late minutes*, *early departure* and the long-shift check against the schedule work.

- ⚠️ **The screen is hidden from every menu.** The support plugin ships it with `$shouldRegisterNavigation = false`. It only opens at `http://localhost:8000/admin/calendars`.
- ⚠️ **HR cannot open it.** HR Managers (Zainab, Mehwish) have none of the `support_calendar` permissions; only Admin does.
- ⚠️ **It is not separated by company.** The schedule list, its company picker and the employee form's "Working Hours" picker show every company's schedules. No schedules exist yet, so nothing has leaked.
- **Planned fix:**
  - Add a **Working Schedules** link in the Attendance section.
  - Give HR view/create/edit/delete through `hr:sync-permissions`.
  - Scope the list and both pickers to the user's company.
  - Add a test, then push.
- **Workaround until then:** log in as Admin and open `/admin/calendars`.

### 6.2 Setup HR must do in the app (data, not code)

- ⚠️ **No working schedules exist.** Create one, e.g. "Office 9–6", Mon–Fri, Asia/Karachi, and set it as **Working Hours** on each employee.
- ⚠️ **18 of 25 employees have no Work Location**, so they see "contact HR" and cannot check in. Only Zainab and Bilal can check in today.
- ⚠️ **Karachi HQ – Main Office is marked inactive**, and Raza and Sarah are assigned to it.
- ⚠️ **Mehwish and Raza have no line manager**, so their correction requests are refused until one is set.
- ⚠️ **The Giga Mall geofence radius is 1,000 m**, which is very loose. 150–200 m is typical. Set the centre from a phone standing in the office, not from a desktop.

### 6.3 Infrastructure

- ⚠️ **HTTPS is required for phones.**
  - Phone browsers only give location over `https://` (or `localhost`).
  - The app runs on `http://127.0.0.1:8000`, so phone check-in will not work until it is served over HTTPS.
  - For local testing, use a USB-connected Android phone with `adb reverse tcp:8000 tcp:8000`, or an HTTPS tunnel.
- ⚠️ **The scheduler must run.**
  - `hr:flag-forgotten-shifts` (hourly) and `hr:prune-attendance-evidence` (daily 02:30) are scheduled in `routes/console.php`.
  - They only run if the server runs `php artisan schedule:run` every minute.
- ⚠️ **Production hardening:** set `SESSION_SECURE_COOKIE=true`, and restrict `trustProxies(at: '*')` to the real proxy. Until then, recorded IP addresses are advisory only.
- ⚠️ **Evidence retention (365 days)** needs an HR or legal decision.

### 6.4 Data clean-up (not touched; needs your OK)

- ⚠️ **Duplicate leave types.**
  - *Annual Leave*, *Casual Leave* and *Sick Leave* each exist 6 times (5 without a company + 1 for the company).
  - This is why Time Off Analysis on the dashboard shows rows of duplicate 0.0 cards.
  - Allocations may reference them, so they need a careful merge, not a delete.
- ℹ️ **Leftover test data in the dev database:**
  - Verification row #2 (user agent "Symfony", a scripted check-in whose record was deleted). It is append-only by design, so it stays.
  - Zainab's 30 Sep record is still in *Needs Review*.

### 6.5 Spotted, out of scope (worth checking)

- ⚠️ An **Employee-role** user (Hamza) sees **Accounting → Reporting / Configurations** in the menu. That's likely a permission or visibility gap in the accounting clusters.
- ⚠️ Pint reports style issues in two files this work never touched (`Clusters/Configurations.php`, `Clusters/Reporting.php`, `types_spaces`).

### 6.6 Deliberately not done

- **Payroll**, per your instruction: overtime auto-calculation and a monthly attendance export for payroll.

### 6.7 Possible enhancements

- Nightly **absent** marking for scheduled employees who did not check in, excluding leave and holidays.
- Check-out reminder notification for employees.
- HR dashboard widget: present, late, absent and not-checked-in today, plus pending reviews.
- **QR code at the office** as a second factor against GPS spoofing. Browser GPS can be faked on a rooted phone; today this is caught by flags and review, not prevented.
- "Add to home screen" (PWA) for phones, once HTTPS is in place.

---

## 7. Configuration reference

| Setting | Where | Current value |
|---|---|---|
| `HR_ATTENDANCE_GEOFENCE_ENABLED` | `.env` | `true` (dev) |
| `HR_ATTENDANCE_HR_ONLY` | `.env` | `false`. Must stay false so employees can check in |
| Accuracy: verified / reviewable | `config/hr_attendance_geofence.php` | 50 m / 150 m |
| Reading age limit / clock skew | same | 120 s / 60 s |
| Forgotten-shift cut-off | same | 16 h |
| Long-shift flag | same | 12 h, or scheduled end + 3 h |
| Check-out outside the fence / without location | same | `review` (recorded and flagged) |
| Evidence retention | same | 365 days |

ℹ️ **Files kept out of git on purpose:**
- `.env`
- `graphify-out/`
- `CLAUDE_HANDOVER_ATTENDANCE.md`: contains real users' login emails and passwords. Do not commit it.
- `ATTENDANCE_SYSTEM_FUNCTIONAL_AUDIT.md`: Gemini's report, now out of date.
