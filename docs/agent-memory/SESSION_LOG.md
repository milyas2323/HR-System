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

### 2026-06-04 — Internal agent memory system

- **Scope:** `AGENTS.md`, `.cursor/rules/agent-memory.mdc`, `docs/agent-memory/*`
- **Summary:** User asked to understand the project and add persistent in-repo memory for future Cursor sessions.
- **Outcome:** Created memory layout (overview, session log, decisions, known issues) and an always-on Cursor rule to read/update these files. Documented architecture from codebase review (PHP/MySQL attendance app, geofencing, hourly slots, penalty cron).
- **Follow-ups:** Append a new log entry after each future agent session that changes code or fixes bugs.
