# Decisions log

Record non-obvious choices so future sessions do not reverse them without reason.

## Format

```markdown
### YYYY-MM-DD — Title
- **Decision:** ...
- **Rationale:** ...
- **Alternatives considered:** (optional)
```

---

### 2026-08-05 — 1-hour break is deducted whether taken or not

- **Decision:** Worked hours = clock-in → clock-out **minus a flat 1 hour**, always. Every shift must deliver 8 net working hours, so a complete workday spans 9 hours. Skipping the break does not buy an early sign-off. Breaks are **not** individually tracked (no start/stop, no table).
- **Rationale:** User's policy statement — "employee must give 8 hours full dedicated with one hour break taken or not". A flat deduction encodes exactly that with no schema change, and matches observed data (real shifts already span ~9h00m–9h07m).
- **Enforcement:** Closed **weekday** shifts under 8h worked are fined PKR 1,000 each (`SHORT_HOURS_PENALTY_AMOUNT`), waived by an approved *Early Sign-off* or *Extended Break* request for that date. Weekends are excluded, matching the Mon–Fri absence rule.
- **Open shifts:** Never fined — hours are only verifiable once the shift is closed. An open shift past 12h (`SHIFT_STALE_OPEN_SECONDS`) is treated as abandoned: its displayed hours are capped at the 9h span, flagged "hours unverified", and excluded from worked-hour totals. A shift left open still draws the existing missed end-report fine.
- **Alternatives considered:** Tracking actual break start/stop and deducting real break time (rejected — the policy deducts the hour regardless, so tracking adds a table and two buttons without changing any number); blocking early shift close (rejected — user chose the fine).

### 2026-06-05 — User approval before changes/commands

- **Decision:** Agent must ask user and wait for explicit approval before editing files or running shell commands.
- **Rationale:** User preference; avoids unsolicited changes and command execution.
- **Rule file:** `.cursor/rules/approval-before-action.mdc` (`alwaysApply: true`).

### 2026-06-04 — In-repo agent memory

- **Decision:** Store agent context under `docs/agent-memory/` with root `AGENTS.md` and Cursor rule `.cursor/rules/agent-memory.mdc` (`alwaysApply: true`).
- **Rationale:** Keeps memory in git, visible to humans and agents; no dependency on external memory APIs; works with Cursor rules.
- **Alternatives considered:** Only `.cursor/rules` (too little structure); only README (not loaded by default).

### (Inherited) Schema self-healing in `db.php`

- **Decision:** Run lightweight `SHOW COLUMNS` / `ALTER TABLE` checks on every request via `includes/db.php`.
- **Rationale:** XAMPP deployments may skip manual `migration.sql`; app stays bootable after pulls.
- **Note:** Agents should mirror new columns in `database/migration.sql` for documentation and manual installs.
