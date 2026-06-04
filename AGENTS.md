# Agent guide — HR / Employee Attendance System

This repository uses an **in-repo memory system** so Cursor agents retain context across sessions. Read this file first on every task.

## Memory locations

| File | Purpose |
|------|---------|
| [docs/agent-memory/PROJECT_OVERVIEW.md](docs/agent-memory/PROJECT_OVERVIEW.md) | Architecture, stack, folders, business rules (stable) |
| [docs/agent-memory/SESSION_LOG.md](docs/agent-memory/SESSION_LOG.md) | Chronological log of agent/human work (append-only) |
| [docs/agent-memory/DECISIONS.md](docs/agent-memory/DECISIONS.md) | Recorded design choices |
| [docs/agent-memory/KNOWN_ISSUES.md](docs/agent-memory/KNOWN_ISSUES.md) | Bugs, tech debt, open questions |
| [.cursor/rules/agent-memory.mdc](.cursor/rules/agent-memory.mdc) | Rule: always read/update memory |

## Workflow (required)

1. **Before coding** — Skim `PROJECT_OVERVIEW.md`, the last **3** entries in `SESSION_LOG.md`, and `KNOWN_ISSUES.md`.
2. **While working** — Match existing PHP patterns (`includes/`, mysqli, session auth). Timezone: `Asia/Karachi`.
3. **After meaningful changes** — Append one dated block to `SESSION_LOG.md` (see template in that file). Update `KNOWN_ISSUES.md` or `DECISIONS.md` if you fixed, found, or decided something.

## Quick facts

- **Stack:** PHP + MySQL (XAMPP), no framework. DB name: `employee_system`.
- **Roles:** `admin` → `admin/`, `employee` → `employee/`.
- **Core flows:** login geofencing, shift check-in/out, 7×15-min hourly updates (evening shift), penalties cron, leave, salary slips.
- **Schema:** Auto-migrations in `includes/db.php`; manual SQL in `database/migration.sql`.

Do not store secrets (passwords, API keys) in memory files.
