# Known issues & tech debt

Update when discovering or fixing items. Link files where helpful.

## Open

| ID | Area | Description |
|----|------|-------------|
| K-001 | Security | Mixed SQL: some queries use prepared statements, others concatenate escaped IDs — prefer prepared statements for new code. |
| K-002 | Security | Legacy plain-text password fallback in `verifyPassword()` — migrate hashes on login. |
| K-003 | Ops | Cron scripts (`cron_penalty_engine.php`, `cron_monthly_reset.php`) must be scheduled externally (Windows Task Scheduler / cron); not wired in repo. |
| K-004 | Docs | No root README for human setup (XAMPP, DB import, default admin user). |
| K-005 | Hourly | Slots are fixed 7:00 PM–1:15 AM on **shift start calendar date**, not relative to actual clock-in; late check-in auto-misses earlier slots. |
| K-006 | Hourly | `employee/checkin.php` has no server-side shift start time window (cron docs say ~6:00 PM). |
| K-007 | Penalties | `employee/close-shift.php` closes shift without `end_reports` → counts as missed summary; not linked in UI but reachable. |
| K-008 | Penalties | Weekend shifts still audited for missed hourly/end report; absence logic only excludes Sat/Sun. |
| K-009 | Penalties | Active shift audited after `start_time + 9 hours` even if `status='active'` — can fine before employee closes shift. |
| K-010 | Data | Legacy `hourly_updates` rows without `shift_id`/`slot_date`/`slot_hour` count as missed in penalty engine. |
| K-011 | Penalties | **Fixed 2026-06-05:** Absence fines no longer count weekdays before join/first clock-in. Use Reports → Recalculate automated penalties to rebuild old incorrect rows. |

## Resolved

| ID | Resolved | Notes |
|----|----------|-------|
| — | — | (none logged yet) |
