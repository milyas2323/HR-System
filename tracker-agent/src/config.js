'use strict';

const { app } = require('electron');
const fs = require('fs');
const path = require('path');

const CONFIG_PATH = path.join(app.getPath('userData'), 'config.json');

const DEFAULTS = {
  // Pre-configured for the live server. Employees only enter email + password.
  // Change this one line (and rebuild) if the server's address changes.
  serverUrl: 'https://shanakperfumes.pk/Project/employee-attendance-system',
  token: '',              // bearer token from /api/login.php
  userId: null,
  userName: '',
  userEmail: '',
  idleThreshold: 300,     // seconds with no input before the user counts as "away"
  screenshotMinMinutes: 5,
  screenshotMaxMinutes: 10,
  openAtLogin: true,
  paused: false,
  sessionVersion: '',     // app version the saved session belongs to (forces re-login on update)
};

let cache = null;

function load() {
  if (cache) return cache;
  try {
    const raw = fs.readFileSync(CONFIG_PATH, 'utf8');
    cache = Object.assign({}, DEFAULTS, JSON.parse(raw));
  } catch (e) {
    cache = Object.assign({}, DEFAULTS);
  }
  return cache;
}

function save(patch) {
  cache = Object.assign(load(), patch || {});
  try {
    fs.writeFileSync(CONFIG_PATH, JSON.stringify(cache, null, 2), 'utf8');
  } catch (e) {
    // best effort; nothing we can do if userData is unwritable
  }
  return cache;
}

function clearSession() {
  return save({ token: '', userId: null, userName: '', userEmail: '' });
}

function isLoggedIn() {
  const c = load();
  return Boolean(c.serverUrl && c.token);
}

module.exports = { load, save, clearSession, isLoggedIn, CONFIG_PATH, DEFAULTS };
