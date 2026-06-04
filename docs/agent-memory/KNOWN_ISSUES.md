# Known issues & tech debt

Update when discovering or fixing items. Link files where helpful.

## Open

| ID | Area | Description |
|----|------|-------------|
| K-001 | Security | Mixed SQL: some queries use prepared statements, others concatenate escaped IDs — prefer prepared statements for new code. |
| K-002 | Security | Legacy plain-text password fallback in `verifyPassword()` — migrate hashes on login. |
| K-003 | Ops | Cron scripts (`cron_penalty_engine.php`, `cron_monthly_reset.php`) must be scheduled externally (Windows Task Scheduler / cron); not wired in repo. |
| K-004 | Docs | No root README for human setup (XAMPP, DB import, default admin user). |

## Resolved

| ID | Resolved | Notes |
|----|----------|-------|
| — | — | (none logged yet) |
