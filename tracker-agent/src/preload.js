'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('tracker', {
  getDefaults: () => ipcRenderer.invoke('get-defaults'),
  login: (payload) => ipcRenderer.invoke('login', payload),
  getStatus: () => ipcRenderer.invoke('get-status'),
  setPaused: (paused) => ipcRenderer.invoke('set-paused', paused),
  logout: () => ipcRenderer.invoke('logout'),
  openWeb: (url) => ipcRenderer.invoke('open-web', url),
  closeIdleWarning: () => ipcRenderer.invoke('close-idle-warning'),
});
