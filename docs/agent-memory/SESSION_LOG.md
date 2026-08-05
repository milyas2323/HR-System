# Session log (agent memory)

Append-only log of significant work. Newest entries at the **top**.

## Entry template

```markdown
### YYYY-MM-DD — Short title

- **Scope:** files or areas touched
- **Summary:** what was requested / investigated
- **Outcome:** what changed or was concluded
- **Follow-ups:** optional next steps
```

---

---

### 2026-07-06 — Admin hourly submit: normal visible updates

- **Scope:** `includes/functions.php`, `admin/hourly-submit.php`, `admin/hourly-update.php`, `employee/dashboard.php`, `admin/reports.php`, `admin/dashboard.php`
- **Summary:** User did not want hidden `is_admin_check` rows; admin submissions should be real employee hourly updates.
- **Outcome:** Admin submit now inserts normal hourly_updates linked to employee active shift; visible in feeds; counts for missed-slot logic; no time window; `admin_submitted_by` kept for audit only.
- **Follow-ups:** Re-upload touched files to live.

### 2026-07-06 — Admin hourly check submit (hidden test entries)

- **Scope:** `admin/hourly-submit.php`, `admin/hourly-update.php`, `admin/dashboard.php`, `includes/functions.php`, `includes/db.php`, `employee/dashboard.php`, `admin/reports.php`
- **Summary:** User wanted admin to submit/check hourly updates without time window; entries hidden from all feeds and assigned to admin in DB.
- **Outcome:** Added Submit Check Update button on admin hourly page; new admin submit form (employee-like, no time/location rules); `is_admin_check` + `admin_submitted_by` columns; excluded from listings, reports, and `hasHourlyUpdateInSlot` penalty logic.
- **Follow-ups:** Upload all touched files to live.

### 2026-07-06 — Fix hourly submit after location granted

- **Scope:** `employee/hourly-update.php`, `employee/dashboard.php`, `includes/functions.php`
- **Summary:** Hourly updates still not saving after location permission granted.
- **Outcome:** Fixed JS programmatic `form.submit()` omitting POST `submit` flag; added hidden `hourly_update` marker; use `requestSubmit` after location check; server accepts coordinates-only location text built from lat/lng.
- **Follow-ups:** Upload all three files to live.

### 2026-07-06 — Require location for hourly updates

- **Scope:** `employee/hourly-update.php`, `includes/functions.php`, `includes/db.php`
- **Summary:** Admin listing showed empty location on hourly updates; user required blocking submit without live location access.
- **Outcome:** Server validates lat/lng + address before insert; client blocks submit until GPS ready; submit button disabled until location acquired; re-added hourly_updates audit columns auto-migration in db.php.
- **Follow-ups:** Upload employee/hourly-update.php, includes/functions.php, includes/db.php to live. Old rows without location remain blank.

### 2026-07-06 — Fix dashboard redirect loop

- **Scope:** `includes/functions.php`, `admin/dashboard.php`, `employee/dashboard.php`, `login.php`
- **Summary:** User hit `ERR_TOO_MANY_REDIRECTS` on dashboard.
- **Outcome:** Added shared session-user refresh helper so both dashboards normalize role from DB before role-based redirects; removed duplicate employee-side refresh block; regenerated session ID on login for cleaner session transitions.
- **Follow-ups:** Upload these four files to live and clear browser cookies once.

### 2026-07-03 — Hourly update system info columns

- **Scope:** `admin/hourly-update.php`, `includes/functions.php`, `includes/db.php`, `database/migration.sql`, `employee/hourly-update.php`, `employee/dashboard.php`
- **Summary:** User wanted device and location shown on admin hourly update listing. Correction: location/device must be captured in real time at submit, NOT copied from existing shift/DB data.
- **Outcome:** Added ip_address, device, current_location to hourly_updates (auto-migration). Employee hourly form now captures live GPS (watchPosition + reverse geocode) and refreshes before submit; device/IP taken from the live request. Admin listing shows real-time System/Device and Location (device/IP fall back to nothing, location no shift fallback).
- **Decision:** Do not backfill location/device from shifts table; only store what was captured with each update.
- **Follow-ups:** Upload includes/db.php, includes/functions.php, employee/hourly-update.php, employee/dashboard.php, admin/hourly-update.php to live.

### 2026-07-03 — Hourly update page filters

- **Scope:** `admin/hourly-update.php`, `admin/dashboard.php`
- **Summary:** User wanted current month by default, Current/Last month quick filters, employee filter, and removal of Clear All Hourly Updates button.
- **Outcome:** Default date range is current month; added month quick filters and employee dropdown; stats and table respect filters; removed clear button and dashboard reset handler.
- **Follow-ups:** Upload both files to live.

### 2026-07-02 — Payroll month filter + payslip sync

- **Scope:** `admin/penalties.php`, `admin/salary-slip.php`
- **Summary:** Salaries page showed all-time penalties while payslip always used current month; View Payslip did not match payroll list.
- **Outcome:** Added month filter (Current month, Previous month, custom month picker) on penalties page; filtered stats and payout sheet by month; View Payslip passes selected month; payslip reads month param and shows same deductions; back link preserves month.
- **Follow-ups:** Upload both files to live.

### 2026-07-02 — Reports: Last month quick filter

- **Scope:** `admin/reports.php`
- **Summary:** User requested a Last month report filter on the reports page.
- **Outcome:** Added Last month quick-filter button to main report date range and hourly history shortcuts (first day through last day of previous calendar month).
- **Follow-ups:** None.

### 2026-06-05 — Fix false missed slots after backfill

- **Scope:** `includes/functions.php`, `employee/hourly-update.php`, `employee/dashboard.php`
- **Summary:** User still saw incorrect missed slots; orphans on wrong shift_id + slots before clock-in counted as missed.
- **Outcome:** `hasHourlyUpdateInSlot` matches employee+slot (any shift); skip slots ending before shift start; employee UI shows N/A for pre-clock-in windows.
- **Follow-ups:** Remaining misses are genuine (no on-time row); 25 late legacy rows intentionally not counted.

### 2026-06-05 — Backfill script for legacy hourly slot data

- **Scope:** `database/backfill_hourly_slots.php`
- **Summary:** User asked to fix existing hourly_updates without time-bound slot data; onward only in-window entries count as filled.
- **Outcome:** CLI + admin browser script: match shift at created_at, backfill if inside :00–:15 window, clear/delete late/duplicate rows.
- **Follow-ups:** Ran on local DB: 62 scanned, 36 backfilled, 25 late cleared, 1 duplicate removed, 0 errors.

### 2026-06-05 — User workflow: approval before changes/commands

- **Scope:** `.cursor/rules/approval-before-action.mdc`, `AGENTS.md`, `DECISIONS.md`
- **Summary:** User asked agent to always ask before file changes and before running commands; no unsolicited syntax checks.
- **Outcome:** Added always-on Cursor rule and updated agent docs.
- **Follow-ups:** Follow on every future task.

### 2026-06-05 — Fix missed updates showing 0 penalty

- **Scope:** `includes/functions.php`, `admin/reports.php`, `admin/dashboard.php`
- **Summary:** User saw 35 missed updates but PKR 0 penalty. Causes: fines only ran once/day; active/unclosed shifts excluded from penalty engine but included in report audit.
- **Outcome:** `isShiftAuditableForMissedUpdateFines()` (includes all slots ended); penalty runs on every admin/reports load; reports show billable vs pending missed separately.
- **Follow-ups:** Refresh Reports page — Waleed should show ~PKR 32,000 missed-update fine if 35 billable.

### 2026-06-05 — Fix incorrect absence penalties + reports

- **Scope:** `includes/functions.php`, `admin/cron_penalty_engine.php`, `admin/reports.php`
- **Summary:** User said penalty breakdown not correct (8 missed vs PKR 120k). Root cause: absence fines counted all month weekdays before employee joined/first shift.
- **Outcome:** Absence counting starts day after first clock-in; no fines until first shift. Extracted `runMonthlyPenaltyAudit` / `recalculateAllAutomatedPenalties`. Reports: net salary uses this-month penalties; recalculate button; expected missed-update fine hint.
- **Follow-ups:** Click **Recalculate automated penalties** on Reports once on live DB.

### 2026-06-04 — Employee report penalty breakdown section

- **Scope:** `admin/reports.php`, `includes/functions.php`
- **Summary:** User wanted full separate penalty breakdown on employee report (clarify 8 missed vs PKR 120k).
- **Outcome:** Added categorized penalty section (absences, missed-update fines, manual) with summary cards and line-item table; `classifyPenaltyType()` helper.
- **Follow-ups:** None.

### 2026-06-04 — Admin reports performance overview + missed updates

- **Scope:** `admin/reports.php`, `includes/functions.php`
- **Summary:** User wanted main reports page listing all employees with performance metrics; employee detail with missed updates (daily/monthly), not total updates count.
- **Outcome:** Overview table (shifts, missed updates, penalty, net salary, View Details). Detail view: monthly/daily missed breakdown using slot rules; helpers `getMissedUpdatesBreakdownForShift`, `buildEmployeeMissedUpdatesReport`. Quick filters: Yesterday, Last 7 days, This month. Removed hourly submitted logs section from detail.
- **Follow-ups:** None.

### 2026-06-04 — Admin attendance date filters (default today)

- **Scope:** `admin/attendance.php`
- **Summary:** User requested date filters on attendance page; default view should show current date only.
- **Outcome:** Added From/To range (defaults to today on first load), filters table and stats by `DATE(start_time)`; added Today reset and Show all records links.
- **Follow-ups:** None.

### 2026-06-04 — Admin hourly-update date range filters

- **Scope:** `admin/hourly-update.php`
- **Summary:** User requested date range filters on admin hourly progress monitor page.
- **Outcome:** Added From/To dates, Apply/Clear, validation; filters feed table and update count; third stat shows distinct employees with logs in range when filtered.
- **Follow-ups:** None.

### 2026-06-04 — Admin reports date range filters

- **Scope:** `admin/reports.php`
- **Summary:** User requested date range filtering on admin reports when an employee is selected.
- **Outcome:** Added From/To date inputs, validation, Apply/Clear dates; filters shifts (by `start_time`), hourly updates, login logs, and penalty/update stats. Without dates, kept previous row limits (5/8/10).
- **Follow-ups:** None.

### 2026-06-04 — Audit: hourly slots, time windows, penalties

- **Scope:** `includes/functions.php`, `employee/hourly-update.php`, `employee/dashboard.php`, `employee/end-report.php`, `employee/close-shift.php`, `admin/cron_penalty_engine.php`, `admin/dashboard.php`
- **Summary:** User asked to review hourly update logic, time-bound rules, and penalty calculations.
- **Outcome:** Documented 7 fixed PKT slots, 15-minute enforcement, monthly penalty rules (absence PKR 5k, 3 free missed updates then PKR 1k each), admin pseudo-cron. Logged issues K-005–K-010 in KNOWN_ISSUES.md. No code changes.
- **Follow-ups:** Optional fixes: clock-in time gate, slot schedule relative to shift start, align close-shift with end-report, exclude weekend shift audits.

### 2026-06-04 — Internal agent memory system

- **Scope:** `AGENTS.md`, `.cursor/rules/agent-memory.mdc`, `docs/agent-memory/*`
- **Summary:** User asked to understand the project and add persistent in-repo memory for future Cursor sessions.
- **Outcome:** Created memory layout (overview, session log, decisions, known issues) and an always-on Cursor rule to read/update these files. Documented architecture from codebase review (PHP/MySQL attendance app, geofencing, hourly slots, penalty cron).
- **Follow-ups:** Append a new log entry after each future agent session that changes code or fixes bugs.

### 2026-07-06 — Employee dashboard: payslip links + missed updates title

- **Scope:** `employee/dashboard.php`, `employee/salary-slip.php`
- **Summary:** User requested smaller font for Missed Updates card title and Previous Months Salary Slip section at very top of home dashboard.
- **Outcome:** Added top card with last 6 months + current month payslip links; reduced Missed Updates heading/count font sizes; employee salary-slip accepts `?month=YYYY-MM` with back link.
- **Follow-ups:** None.

### 2026-07-07 — Reports detail page dynamic penalties

- **Scope:** `admin/reports.php`
- **Summary:** Employee detail penalty breakdown showed stale DB total (e.g. PKR 6,000) while overview used live calculation (PKR 4,000 for 7 missed / 4 fined).
- **Outcome:** Detail view now uses `buildEmployeePenaltyReportRows()` with the same date range as overview; metrics and breakdown cards match live rules.
- **Follow-ups:** None.

### 2026-07-07 — Dashboard + Salaries & Deduct live penalties

- **Scope:** `includes/functions.php`, `admin/dashboard.php`, `admin/penalties.php`, `admin/salary-slip.php`
- **Summary:** Admin home and payroll pages still summed stale `penalties` table rows.
- **Outcome:** Added `getPayrollMonthDateRange()` and `calculateWorkforceDynamicPenalties()`; dashboard home, penalties list, and admin payslip now use live calculations matching reports.
- **Follow-ups:** Consider aligning `employee/salary-slip.php` the same way.

### 2026-07-07 — Admin dashboard all-time fines stat

- **Scope:** `admin/dashboard.php`
- **Summary:** User wanted home dashboard fines card to show all-time total only (not current month).
- **Outcome:** Card relabeled “All Time Fines”; sums live penalties from first shift date through today. Salaries & Deduct unchanged.
- **Follow-ups:** None.

### 2026-07-07 — Employee payslip + dashboard live fines

- **Scope:** `employee/salary-slip.php`, `employee/dashboard.php`
- **Summary:** Employee payslip still read stale `penalties` table; dashboard Salary Deductions showed `total_deduction` from users table only.
- **Outcome:** Payslip uses `buildEmployeePenaltyReportRows()`; dashboard shows current month and all-time live fine totals via `calculateEmployeeDynamicPenalties()`.
- **Follow-ups:** None.

### 2026-08-04 — Bonuses: admin entry, payslip credit, employee visibility

- **Scope:** `includes/db.php`, `database/migration.sql`, `includes/functions.php`, `admin/bonuses.php` (new), `admin/dashboard.php`, `admin/salary-slip.php`, `admin/penalties.php`, `employee/salary-slip.php`, `employee/dashboard.php`
- **Summary:** No way to credit an employee for a bonus; payslips only ever subtracted fines.
- **Outcome:** New `bonuses` table keyed by `bonus_month` (YYYY-MM) so admin picks the target slip month explicitly, plus `bonus_logs` audit trail. Admin-only "Add Bonuses" page (add/remove + activity log). Bonuses appear as earnings rows on both admin and employee payslips; net = salary + bonuses − fines everywhere, including the Salaries & Deduct payout sheet. Employee dashboard gained a Bonuses card (all-time + current month) and an all-time bonus history table.
- **Follow-ups:** Bonuses are excluded from `users.total_deduction` and the penalty engine by design — they are a separate ledger, not a negative penalty.

### 2026-08-04 — Employee requests with admin approval

- **Scope:** `includes/db.php`, `database/migration.sql`, `includes/functions.php`, `employee/add-request.php` (new), `employee/dashboard.php`, `admin/employee-requests.php` (new), `admin/dashboard.php`
- **Summary:** Employees had no channel for shift-affecting asks (late joining, urgent issue, extended break) — only full-day leave requests existed.
- **Outcome:** New `employee_requests` table with six categories (late joining, urgent issue, extended break, early sign-off, WFH, other), date + optional time window, and inline review fields (`admin_response`, `reviewed_by`, `reviewed_at`). Employee "Add Request" page submits and tracks own requests with admin remarks; admin "Employee Requests" page filters by status, approves/rejects with optional remarks, and shows a pending count badge in the sidebar. Already-processed requests cannot be re-decided.
- **Follow-ups:** Approved requests are informational only — the penalty engine does not yet auto-waive fines for an approved late-joining/absence request. Admin relaxation tools in `reports.php` remain the manual path.

### 2026-08-04 — Request penalties (PKR 5,000) + Change Workstation type

- **Scope:** `includes/db.php`, `database/migration.sql`, `includes/functions.php`, `admin/employee-requests.php`, `admin/dashboard.php`, `employee/add-request.php`, `employee/dashboard.php`
- **Summary:** Requests were informational only; unapproved shift changes carried no consequence. Also replaced the Work From Home type with Change Workstation.
- **Outcome:** `REQUEST_VIOLATION_PENALTY_AMOUNT` (5000) fines unapproved shift changes via two deterministic triggers — rejecting a request (admin can untick), and an admin "Log Unrequested Violation" form for cases with no request. Approving waives any fine already logged for that employee/type/date. Fines are idempotent per employee+type+date and back-date to the violation day so they land on the right payslip month. New `request_violation` penalty class; `isAutomatedPenaltyReason()` now treats only absence/missed_updates as engine-generated so stored violation rows are summed and displayed rather than skipped. Caution banners on employee dashboard home, employee Add Request, and admin Employee Requests.
- **Follow-ups:** Late joining is NOT auto-detected — there is no per-employee scheduled shift start in the schema to compare `shifts.start_time` against. Adding one to `admin/settings.php` would let the engine flag late joins automatically.

### 2026-08-05 — 1-hour break policy: 8h dedicated working hours + short-hours fine

- **Scope:** `includes/functions.php`, `admin/reports.php`, `admin/attendance.php`, `employee/dashboard.php`, `docs/agent-memory/*`
- **Summary:** User defined the working-hours policy: every shift must deliver **8 hours of dedicated work**, and the **1-hour break is never counted as working time — taken or not**. Nothing in the system measured worked hours before; shifts only stored `start_time`/`end_time`.
- **Outcome:** New working-hours layer in `functions.php` — `SHIFT_REQUIRED_WORK_SECONDS` (8h), `SHIFT_BREAK_ALLOWANCE_SECONDS` (1h), `SHIFT_REQUIRED_SPAN_SECONDS` (9h), `SHORT_HOURS_PENALTY_AMOUNT` (1,000), `getShiftWorkSummary()`, `summariseShortHoursForShifts()`, `isShiftShortHoursFineable()`, `hasApprovedShortHoursWaiver()`, `formatWorkDuration()`. Worked hours = clock-in → clock-out **minus a flat 1h**, so a complete workday spans 9h; skipping the break earns no early sign-off. Closed **weekday** shifts under 8h worked are fined PKR 1,000 each, unless an *Early Sign-off* or *Extended Break* request was approved for that date. The fine flows through the same live/stored pattern as absences and missed updates (`calculateEmployeeDynamicPenalties`, `buildEmployeePenaltyReportRows`, `classifyPenaltyType` key `short_hours`, `isAutomatedPenaltyReason`, engine insert + `Monthly Short Hours%` delete on recalc), so payslips and every dashboard picked it up automatically. UI: worked-hours column on admin attendance, admin reports (daily + monthly + overview + stat card), employee daily breakdown; live "worked so far / 8h" progress on the employee active-shift card; new Working Hours card on the employee home. **No schema change** — the flat deduction needs no break tracking.
- **Follow-ups:** Break start/stop is not tracked; if per-break auditing is ever wanted, add a `shift_breaks` table and switch `getShiftWorkSummary()` to actual break time. Fines apply from the moment a short shift closes (no month-end grace) — the historical June shifts already carry them, so run Reports → Recalculate automated penalties if stored rows should match.

### 2026-08-05 — Admin waiver for the short-hours fine

- **Scope:** `includes/db.php`, `database/migration.sql`, `includes/functions.php`, `admin/dashboard.php`, `admin/reports.php`, `admin/attendance.php`, `employee/dashboard.php`
- **Summary:** User wanted a relaxation button for short-hours penalties, matching the existing hourly-slot relaxation, so admin can waive the fine when justified. Screenshot also showed a shift ~20 seconds short rendering as "0H 00M SHORT · PKR 1,000".
- **Outcome:** New `short_hours_relaxations` table (auto-migration block 13 + `migration.sql`), unique per employee+shift. `isShiftShortHoursRelaxed()`, `grantAdminRelaxationForShiftShortHours()` and `revokeAdminRelaxationForShiftShortHours()` in `functions.php`; a waiver short-circuits `isShiftShortHoursFineable()` and triggers `recalculateAutomatedPenaltiesForEmployeeMonth()` so live views and stored rows agree immediately. Reports → daily breakdown gained **Waive short hours** / **Undo hours waiver** buttons in the Action column (stacked with the hourly relaxation button), handled by the `short_hours_relaxation` POST branch in `admin/dashboard.php`. Waived shifts show a green "X short · waived by admin" badge on admin attendance, admin reports and the employee dashboard. Shortfall labels now round **up** to the next minute (`formatShortfallDuration()`) so a few seconds short reads "0h 01m short" instead of "0h 00m".
- **Follow-ups:** Fines still have no grace period — 20 seconds short costs PKR 1,000 (K-013). A `SHIFT_SHORT_HOURS_GRACE_SECONDS` constant in `isShiftShortHoursFineable()` would fix that if the user wants it; the waiver button is the manual workaround for now.
