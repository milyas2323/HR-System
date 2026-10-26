'use strict';

const {
  app, BrowserWindow, Tray, Menu, ipcMain,
  powerMonitor, desktopCapturer, screen, nativeImage, shell, Notification,
} = require('electron');
const path = require('path');
const os = require('os');

const config = require('./config');
const api = require('./api');

// ---- Tuning ---------------------------------------------------------------
const SAMPLE_INTERVAL_MS = 5000;     // how often we read the OS idle clock
const PING_FLUSH_MS = 15000;         // how often we try to flush queued minute samples
const MAX_QUEUE = 1440;              // keep at most ~1 day of unsent minute samples
const IDLE_WARN_SECONDS = 600;      // warn the employee after 10 min idle (during a shift)
const DEVICE_LABEL = `${os.hostname()} (${os.platform()} ${os.arch()})`;

// ---- Runtime state --------------------------------------------------------
let tray = null;
let loginWindow = null;
let statusWindow = null;

const state = {
  trackingSince: null,
  hasOpenShift: false,
  online: false,          // last API call succeeded
  lastError: '',
  todayActiveSeconds: 0,
  todayKey: todayKey(),
  // current minute accumulator
  windowKey: null,
  windowStart: null,
  activeSeconds: 0,
  idleSeconds: 0,
  maxIdle: 0,
  idleWarned: false,
};

function showIdleWarning(mins) {
  try {
    if (Notification.isSupported()) {
      new Notification({
        title: '⚠️ You appear to be idle',
        body: `No activity for ~${mins} min during your shift. Continued inactivity may cause deductions and lower your performance rating — please resume work.`,
      }).show();
    }
  } catch (e) { /* notifications unavailable */ }
}

const pingQueue = [];
let sampleTimer = null;
let flushTimer = null;
let screenshotTimer = null;

// ---- Helpers --------------------------------------------------------------
function pad(n) { return String(n).padStart(2, '0'); }

function fmtLocal(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
         `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function todayKey(d = new Date()) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function minuteKey(ms) { return Math.floor(ms / 60000); }

// ---- Activity sampling ----------------------------------------------------
function startWindow(key) {
  state.windowKey = key;
  state.windowStart = new Date(key * 60000);
  state.activeSeconds = 0;
  state.idleSeconds = 0;
  state.maxIdle = 0;
}

function flushWindow() {
  if (state.windowKey === null) return;
  const sample = {
    window_start: fmtLocal(state.windowStart),
    active_seconds: Math.min(60, state.activeSeconds),
    idle_seconds: Math.min(60, state.idleSeconds),
    max_idle_seconds: state.maxIdle,
    device: DEVICE_LABEL,
  };
  pingQueue.push(sample);
  while (pingQueue.length > MAX_QUEUE) pingQueue.shift();
}

function sampleTick() {
  const cfg = config.load();
  if (cfg.paused) return;

  const nowMs = Date.now();
  const key = minuteKey(nowMs);

  if (state.windowKey === null) {
    startWindow(key);
  } else if (key !== state.windowKey) {
    flushWindow();
    startWindow(key);
  }

  // Seconds since last keyboard/mouse input at OS level.
  const idle = powerMonitor.getSystemIdleTime();
  const secs = SAMPLE_INTERVAL_MS / 1000;
  if (idle < secs) {
    state.activeSeconds += secs;
    if (todayKey() === state.todayKey) {
      state.todayActiveSeconds += secs;
    } else {
      state.todayKey = todayKey();
      state.todayActiveSeconds = secs;
    }
  } else {
    state.idleSeconds += secs;
  }
  if (idle > state.maxIdle) state.maxIdle = idle;

  // Warn the employee after prolonged idle during an open shift (once per idle episode).
  if (state.hasOpenShift && idle >= IDLE_WARN_SECONDS) {
    if (!state.idleWarned) { state.idleWarned = true; showIdleWarning(Math.floor(idle / 60)); }
  } else if (idle < 60) {
    state.idleWarned = false;
  }
}

async function flushQueue() {
  if (!config.isLoggedIn() || pingQueue.length === 0) {
    // Even with an empty queue, poll shift state occasionally.
    if (config.isLoggedIn() && pingQueue.length === 0) {
      try {
        const r = await api.getShift();
        state.online = true;
        state.hasOpenShift = Boolean(r.has_open_shift);
        state.lastError = '';
      } catch (e) {
        state.online = false;
        state.lastError = e.message;
      }
      updateTray();
    }
    return;
  }

  // Send oldest-first; stop on first failure and retry next cycle.
  while (pingQueue.length > 0) {
    const sample = pingQueue[0];
    try {
      const r = await api.sendPing(sample);
      pingQueue.shift();
      state.online = true;
      state.lastError = '';
      state.hasOpenShift = Boolean(r.has_open_shift);
    } catch (e) {
      state.online = false;
      state.lastError = e.message;
      break;
    }
  }
  updateTray();
}

// ---- Screenshots ----------------------------------------------------------
function scheduleScreenshot(first) {
  if (screenshotTimer) clearTimeout(screenshotTimer);
  let delay;
  if (first) {
    delay = 60000; // first capture ~1 min after tracking starts, so it's verifiable quickly
  } else {
    const cfg = config.load();
    const min = Math.max(1, cfg.screenshotMinMinutes) * 60000;
    const max = Math.max(min, cfg.screenshotMaxMinutes * 60000);
    delay = min + Math.random() * (max - min);
  }
  screenshotTimer = setTimeout(doScreenshot, delay);
}

async function doScreenshot() {
  try {
    const cfg = config.load();
    if (config.isLoggedIn() && !cfg.paused) {
      await captureAndUpload(cfg); // always-on: capture regardless of shift state
    }
  } catch (e) {
    state.lastError = e.message;
  } finally {
    scheduleScreenshot();
  }
}

async function captureAndUpload(cfg) {
  const primary = screen.getPrimaryDisplay();
  const { width, height } = primary.size;
  const scale = Math.min(1, 1600 / Math.max(1, width));
  const sources = await desktopCapturer.getSources({
    types: ['screen'],
    thumbnailSize: { width: Math.round(width * scale), height: Math.round(height * scale) },
  });
  if (!sources.length) return;

  // Prefer the primary display's source when identifiable.
  let src = sources[0];
  for (const s of sources) {
    if (String(s.display_id) === String(primary.id)) { src = s; break; }
  }

  const jpeg = src.thumbnail.toJPEG(60);
  const idle = powerMonitor.getSystemIdleTime();
  await api.sendScreenshot({
    buffer: jpeg,
    device: DEVICE_LABEL,
    idleSeconds: idle,
    isIdle: idle >= cfg.idleThreshold,
    capturedAt: fmtLocal(new Date()),
  });
  state.online = true;
}

// ---- Engine start/stop ----------------------------------------------------
function startEngine() {
  stopEngine();
  state.trackingSince = new Date();
  startWindow(minuteKey(Date.now()));
  sampleTimer = setInterval(sampleTick, SAMPLE_INTERVAL_MS);
  flushTimer = setInterval(flushQueue, PING_FLUSH_MS);
  scheduleScreenshot(true); // quick first capture, then random 5–10 min
  updateTray();
}

function stopEngine() {
  if (sampleTimer) clearInterval(sampleTimer);
  if (flushTimer) clearInterval(flushTimer);
  if (screenshotTimer) clearTimeout(screenshotTimer);
  sampleTimer = flushTimer = screenshotTimer = null;
}

// ---- Tray UI --------------------------------------------------------------
function trayIcon() {
  const img = nativeImage.createFromPath(path.join(__dirname, '..', 'assets', 'tray.png'));
  return img.isEmpty() ? nativeImage.createEmpty() : img;
}

function statusLine() {
  const cfg = config.load();
  if (!config.isLoggedIn()) return 'Not signed in';
  if (cfg.paused) return 'Paused';
  if (!state.online) return 'Offline — retrying…';
  return 'Tracking active';
}

function updateTray() {
  if (!tray) return;
  const cfg = config.load();
  const loggedIn = config.isLoggedIn();
  const mins = Math.round(state.todayActiveSeconds / 60);
  const activeLabel = `${Math.floor(mins / 60)}h ${mins % 60}m active today`;

  // Locked-down menu: status only. No pause, sign out, quit, or startup toggle,
  // so employees cannot stop tracking from the app. Stopping it requires removing
  // the app (admin) or ending it via Task Manager.
  const template = [
    { label: 'Workforce Hub Tracker', enabled: false },
    { label: statusLine(), enabled: false },
    { label: loggedIn ? `${cfg.userName || cfg.userEmail}` : '—', enabled: false },
    { label: loggedIn ? activeLabel : '', visible: loggedIn, enabled: false },
    { type: 'separator' },
    { label: 'Open status window', click: openStatusWindow },
  ];

  tray.setToolTip(`Workforce Hub Tracker — ${statusLine()}`);
  tray.setContextMenu(Menu.buildFromTemplate(template));
}

function createTray() {
  if (tray) return;
  tray = new Tray(trayIcon());
  tray.on('click', openStatusWindow);
  updateTray();
}

// ---- Windows --------------------------------------------------------------
function openLoginWindow() {
  if (loginWindow) { loginWindow.focus(); return; }
  loginWindow = new BrowserWindow({
    width: 420, height: 540, resizable: false, fullscreenable: false,
    title: 'Workforce Hub Tracker — Sign in', autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js') },
  });
  loginWindow.loadFile(path.join(__dirname, '..', 'renderer', 'login.html'));
  loginWindow.on('closed', () => {
    loginWindow = null;
    if (!config.isLoggedIn()) { app.isQuitting = true; app.quit(); }
  });
}

function openStatusWindow() {
  if (statusWindow) { statusWindow.focus(); return; }
  statusWindow = new BrowserWindow({
    width: 420, height: 460, resizable: false, fullscreenable: false,
    title: 'Workforce Hub Tracker', autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js') },
  });
  statusWindow.loadFile(path.join(__dirname, '..', 'renderer', 'status.html'));
  statusWindow.on('closed', () => { statusWindow = null; });
}

function doLogout() {
  stopEngine();
  config.clearSession();
  pingQueue.length = 0;
  Object.assign(state, { trackingSince: null, hasOpenShift: false, todayActiveSeconds: 0 });
  updateTray();
  openLoginWindow();
}

// ---- IPC ------------------------------------------------------------------
ipcMain.handle('login', async (_evt, { serverUrl, email, password }) => {
  try {
    const data = await api.login(serverUrl, email, password, DEVICE_LABEL);
    config.save({
      serverUrl: String(serverUrl || '').replace(/\/+$/, ''),
      token: data.token,
      userId: data.user ? data.user.id : null,
      userName: data.user ? data.user.name : '',
      userEmail: data.user ? data.user.email : '',
      paused: false,
    });
    state.hasOpenShift = Boolean(data.active_shift_id);
    state.online = true;
    if (loginWindow) loginWindow.close();
    createTray();
    startEngine();
    return { success: true };
  } catch (e) {
    return { success: false, error: e.message };
  }
});

ipcMain.handle('get-defaults', () => {
  const cfg = config.load();
  return { serverUrl: cfg.serverUrl || config.DEFAULTS.serverUrl || '' };
});

ipcMain.handle('get-status', () => {
  const cfg = config.load();
  const mins = Math.round(state.todayActiveSeconds / 60);
  return {
    loggedIn: config.isLoggedIn(),
    serverUrl: cfg.serverUrl,
    userName: cfg.userName,
    userEmail: cfg.userEmail,
    paused: cfg.paused,
    online: state.online,
    hasOpenShift: state.hasOpenShift,
    status: statusLine(),
    lastError: state.lastError,
    queued: pingQueue.length,
    activeMinutesToday: mins,
    trackingSince: state.trackingSince ? fmtLocal(state.trackingSince) : null,
  };
});

ipcMain.handle('set-paused', (_e, paused) => { config.save({ paused: !!paused }); updateTray(); return true; });
ipcMain.handle('logout', () => { doLogout(); return true; });
ipcMain.handle('open-web', (_e, url) => { shell.openExternal(url); return true; });

// ---- App lifecycle --------------------------------------------------------
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (config.isLoggedIn()) openStatusWindow(); else openLoginWindow();
  });

  app.whenReady().then(() => {
    // Force auto-start at login on, always (employees can't disable it from the app).
    config.save({ openAtLogin: true });
    app.setLoginItemSettings({ openAtLogin: true });

    if (config.isLoggedIn()) {
      createTray();
      startEngine();
    } else {
      openLoginWindow();
    }
  });

  app.on('window-all-closed', (e) => {
    // Keep running in the tray; do not quit when windows close.
    if (!app.isQuitting) { /* stay alive */ }
  });

  app.on('before-quit', () => { app.isQuitting = true; stopEngine(); });
}
