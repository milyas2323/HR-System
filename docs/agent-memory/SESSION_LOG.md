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
