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
