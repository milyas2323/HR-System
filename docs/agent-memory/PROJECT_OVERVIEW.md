# Project overview — Employee Attendance System

Last updated: 2026-06-04

## Purpose

PHP web app for workforce attendance: geofenced check-in, evening shifts, hourly status updates, automated penalties, leave requests, salary slips, and admin reporting. Branded in UI as **Workforce Hub** / Employee Attendance System.

## Environment

| Item | Value |
|------|--------|
| Runtime | PHP on XAMPP (Apache) |
| Database | MySQL `employee_system` @ `localhost`, user `root`, empty password (dev) |
| Timezone | `Asia/Karachi` (PHP + MySQL `SET time_zone = '+05:00'`) |
| Entry URL | `index.php` → `login.php` / `register.php` |

## Directory layout

```
hr-system/
├── index.php, login.php, register.php, logout.php, monthly-reset.php
├── admin/          # Admin UI + cron scripts
├── employee/       # Employee UI (check-in, hourly, leave, profile, salary)
├── includes/       # db.php, auth.php, functions.php, header.php, footer.php
├── database/       # migration.sql (reference / manual apply)
├── assets/css/     # style.css, responsive.css
└── uploads/        # screenshots (created at runtime)
```

## Authentication

- Session key: `$_SESSION['user']` (full `users` row).
- `includes/auth.php` — redirects unauthenticated users to `../login.php`.
- Passwords: bcrypt preferred; `verifyPassword()` allows legacy plain text.
- Login audits: `login_logs` (IP, device, geo via ip-api.com).

## Roles & main pages

### Admin (`admin/`)

- `dashboard.php`, `employees.php`, `edit-employee.php`, `delete-employee.php`
- `attendance.php`, `reports.php`, `leave-requests.php`
- `penalties.php`, `misconduct-penalty.php`, `hourly-update.php`, `salary-slip.php`
- **Cron (CLI/browser):** `cron_penalty_engine.php`, `cron_monthly_reset.php`
- `last_audit.txt` — penalty engine audit artifact

### Employee (`employee/`)

- `dashboard.php`, `checkin.php`, `close-shift.php`
- `hourly-update.php`, `end-report.php`
- `leave-request.php`, `leave-status.php`
- `profile.php`, `salary-slip.php`

## Database & migrations

- **Connection:** `includes/db.php` — mysqli + **self-healing schema** (ALTER/CREATE if columns/tables missing).
- **Manual migration:** `database/migration.sql` mirrors major schema changes.
- Notable tables/columns (inferred from code):
  - `users` — roles, salary fields, `total_deduction`, geofence: `assigned_ip`, `assigned_location`, `assigned_latitude`, `assigned_longitude`, `assigned_radius`
  - `shifts` — active shifts, device, IP, `current_location`, screenshots
  - `hourly_updates` — `shift_id`, `slot_date`, `slot_hour`; unique `uniq_employee_shift_slot`
  - `penalties`, `login_logs`, leave-related tables (see admin/employee leave pages)

## Business rules (high level)

### Shift & hourly updates

- Evening shift model; **7 required** 15-minute slots (7:00 PM–1:15 AM windows) — see `HOURLY_UPDATES_REQUIRED` and `getHourlySlotDefinitionsForShift()` in `includes/functions.php`.
- One submission per employee per shift per slot (DB unique key).

### Working hours & break (`includes/functions.php`)

- Every shift must deliver **8 hours of dedicated work** (`SHIFT_REQUIRED_WORK_SECONDS`).
- The **1-hour break is deducted from clock-in → clock-out whether it is taken or not** (`SHIFT_BREAK_ALLOWANCE_SECONDS`), so a complete workday spans **9 hours** (`SHIFT_REQUIRED_SPAN_SECONDS`). Skipping the break does not allow leaving an hour early.
- Breaks are not individually tracked — the deduction is flat, so no schema exists for them.
- `getShiftWorkSummary()` returns span / break / worked / short seconds plus labels for any shift row; open shifts are measured against the audit timestamp, and an open shift past 12h (`SHIFT_STALE_OPEN_SECONDS`) is capped and flagged `is_stale` (hours unverifiable).

### Penalties (`admin/cron_penalty_engine.php`)

- Working days: Mon–Fri; Sat/Sun excluded.
- Shift window documented in cron header: ~6:00 PM–3:00 AM.
- Absence (no shift start): PKR 5,000 per missed weekday shift. Counted only from the day after the first clock-in; approved leave clears the day. Waived by an admin row in `absence_relaxations` (Reports → **Shift Absence Days** → **Waive off** per day with an optional reason, or **Waive off all N day(s)** on the Shift Absence penalty row), reversible via **Re-apply fine** — this is the path for public holidays and office closures. Reason string `Monthly Shift Absences%`, penalty key `absence`.
- Missed hourly/end reports: 3 free per month, then PKR 1,000 each (monthly recount deletes prior automated penalty rows for that month).
- Short working hours: PKR 1,000 per **closed weekday** shift under 8 worked hours (`SHORT_HOURS_PENALTY_AMOUNT`). Waived by an approved *Early Sign-off* / *Extended Break* request on that date, or by an admin waiver row in `short_hours_relaxations` (Reports → daily breakdown → **Waive short hours**, reversible via **Undo hours waiver**); open shifts are never fined. Reason string `Monthly Short Hours%`, penalty key `short_hours`.
- Admin-logged fines (misconduct, unapproved requests) are stored rows in `penalties` and can be **waived off** or **deleted** from Reports → penalty breakdown → Action column. A waived row keeps `waived=1` plus `waived_by` / `waived_at` / `waive_note` for audit and is excluded from every total (`waiveStoredPenalty` / `restoreStoredPenalty` / `deleteStoredPenalty`); automated monthly rows are rejected there because the engine rebuilds them.

### Geofencing

- `calculateDistance()` (Haversine) vs user `assigned_*` fields.
- Check-in validates IP/location against assigned workstation (see `employee/checkin.php`).

## Shared helpers (`includes/functions.php`)

- `addPenalty()`, `getUserIP()`, `parseUserAgent()`, `calculateDistance()`
- `verifyPassword()` / `hashPassword()`
- Hourly slot helpers: `getHourlySlotDefinitionsForShift`, `hasHourlyUpdateInSlot`, `countMissedHourlySlotsForShift`, `getDatabaseNowTimestamp`
- Working hours helpers: `getShiftWorkSummary`, `summariseShortHoursForShifts`, `isShiftShortHoursFineable`, `hasApprovedShortHoursWaiver`, `calculateShortHoursFineAmount`, `buildShortHoursPenaltyReason`, `formatWorkDuration`

## Conventions for agents

- Procedural PHP pages; include `db.php` + `functions.php`; use existing mysqli style (mixed prepared statements and escaped queries).
- Admin paths use `include "../includes/..."`; employee same pattern.
- Prefer extending `functions.php` over duplicating logic.
- Do not change `db.php` auto-migration blocks without updating `database/migration.sql` and this doc.
