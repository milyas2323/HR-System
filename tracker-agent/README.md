# Workforce Hub Tracker (desktop agent)

Windows tray agent for the Workforce Hub HR system. It records **real in-seat
activity** — active vs idle minutes from the OS idle clock — and uploads periodic
screenshots, so admins see *dedicated* working hours, not just "online".

It does **not** clock anyone in. The employee still checks in on the geofenced
**web** page; the agent only attaches activity and screenshots to that open shift.

## How it works

- Polls `powerMonitor.getSystemIdleTime()` every 5 s (resets on any keyboard/mouse
  input), and each minute reports `active_seconds` / `idle_seconds` / `max_idle_seconds`
  to `POST /api/ping.php`. Samples queue and retry if the network drops.
- Captures the primary screen at a **random moment every 5–10 min** and uploads a
  compressed JPEG to `POST /api/screenshot.php`. Capture is skipped while paused or
  when no shift is open.
- Lives in the system tray; can **start automatically at Windows login**.

## Run in development

```bash
cd tracker-agent
npm install
npm start
```

On first launch, enter the **Server URL** (e.g. `http://localhost/hr-system` in dev,
or your deployed URL), plus the employee's email and password. A bearer token is
stored under the OS user-data folder (`config.json`); the password is not kept.

## Build a Windows installer

```bash
npm run dist
```

Produces an NSIS installer under `dist/`. Install it on each employee machine and
sign in once; it then auto-starts and runs in the tray.

## Settings

Stored in `config.json` (user-data dir): `idleThreshold` (seconds before "away",
default 300), `screenshotMinMinutes` / `screenshotMaxMinutes` (default 5 / 10),
`openAtLogin`, `paused`.

## Endpoints used

| Endpoint | Purpose |
|----------|---------|
| `POST /api/login.php` | issue bearer token |
| `GET /api/shift.php` | is a shift open? |
| `POST /api/ping.php` | minute activity sample |
| `POST /api/screenshot.php` | periodic screen capture |
