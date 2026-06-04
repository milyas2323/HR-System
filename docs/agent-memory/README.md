# Agent memory folder

This directory is the **persistent brain** of the HR system repo for AI-assisted development.

## Files

- **PROJECT_OVERVIEW.md** — Stable map of the app (update when architecture changes).
- **SESSION_LOG.md** — What happened each session (newest on top).
- **DECISIONS.md** — Why we chose X over Y.
- **KNOWN_ISSUES.md** — Bugs and tech debt tracker.

## For humans

You can edit these files manually. When you finish a feature branch, ask the agent to append `SESSION_LOG.md` so the next chat knows what you did.

## For Cursor agents

See root **AGENTS.md** and rule **agent-memory.mdc**. Do not store passwords or API keys here.
