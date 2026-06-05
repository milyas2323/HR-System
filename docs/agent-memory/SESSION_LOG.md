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
