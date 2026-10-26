'use strict';

/**
 * Thin HTTP client for the Workforce Hub tracker API.
 * Uses the global fetch / FormData / Blob available in Electron's Node runtime.
 *
 * The bearer token is sent BOTH in the Authorization header and in the request
 * body/query. Many shared hosts (Apache/LiteSpeed CGI) strip the Authorization
 * header before it reaches PHP, so the body/query copy is the reliable fallback
 * the server also accepts.
 */

const config = require('./config');

function baseUrl() {
  return String(config.load().serverUrl || '').replace(/\/+$/, '');
}

function endpoint(file) {
  return `${baseUrl()}/api/${file}`;
}

async function request(file, { method = 'GET', body = null, auth = true, isForm = false } = {}) {
  const headers = {};
  const token = config.load().token;
  let url = endpoint(file);
  let outBody = body;

  if (auth && token) {
    headers['Authorization'] = `Bearer ${token}`;

    // Fallback copy of the token for servers that drop the Authorization header.
    if (isForm && body instanceof FormData) {
      body.append('token', token);
    } else if (method.toUpperCase() === 'GET') {
      url += (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(token);
    } else {
      outBody = Object.assign({}, body || {}, { token });
    }
  }

  const init = { method, headers };
  if (method.toUpperCase() !== 'GET') {
    if (isForm) {
      init.body = outBody; // FormData sets its own content-type
    } else if (outBody != null) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(outBody);
    }
  }

  const res = await fetch(url, init);
  let data = null;
  try {
    data = await res.json();
  } catch (e) {
    data = null;
  }
  if (!res.ok || !data || data.success === false) {
    const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

async function login(serverUrl, email, password, device) {
  const url = String(serverUrl || '').replace(/\/+$/, '');
  const res = await fetch(`${url}/api/login.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password, device }),
  });
  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }
  if (!res.ok || !data || data.success === false) {
    const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return data;
}

function getShift() {
  return request('shift.php', { method: 'GET' });
}

function sendPing(sample) {
  return request('ping.php', { method: 'POST', body: sample });
}

function sendIdleAlert(idleSeconds) {
  return request('idle_alert.php', { method: 'POST', body: { idle_seconds: idleSeconds } });
}

async function sendScreenshot({ buffer, device, idleSeconds, isIdle, capturedAt }) {
  const form = new FormData();
  form.append('screenshot', new Blob([buffer], { type: 'image/jpeg' }), 'screen.jpg');
  form.append('device', device || '');
  form.append('idle_seconds', String(idleSeconds || 0));
  form.append('is_idle', isIdle ? '1' : '0');
  form.append('captured_at', capturedAt || '');
  return request('screenshot.php', { method: 'POST', body: form, isForm: true });
}

module.exports = { login, getShift, sendPing, sendIdleAlert, sendScreenshot, baseUrl };
