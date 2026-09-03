import {app, BrowserWindow, ipcMain, Menu, Notification, shell, Tray} from 'electron';
import path from 'node:path';
import {readFileSync} from 'node:fs';

function configuredAppUrl(): string {
  if (process.env.ATTENDANCE_APP_URL) return process.env.ATTENDANCE_APP_URL;
  if (app.isPackaged) {
    const config = JSON.parse(readFileSync(path.join(process.resourcesPath, 'app-config.json'), 'utf8')) as {appUrl?: unknown};
    if (typeof config.appUrl === 'string') return config.appUrl;
    throw new Error('app-config.json に appUrl がありません。');
  }
  return 'http://127.0.0.1:8765/index.php';
}

const appUrl = configuredAppUrl();
const allowedOrigin = new URL(appUrl).origin;
const attendanceUrl = new URL('/index.php?route=attendance', appUrl).toString();
let tray: Tray | null = null;
let quitting = false;

function createWindow(): void {
  const window = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 900,
    minHeight: 620,
    show: false,
    backgroundColor: '#f6f7f3',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  });

  window.once('ready-to-show', () => window.show());
  window.on('close', event => {
    if (!quitting) { event.preventDefault(); window.hide(); }
  });
  window.webContents.setWindowOpenHandler(({url}) => {
    try { if (new URL(url).origin !== allowedOrigin) void shell.openExternal(url); } catch (_) {}
    return {action: 'deny'};
  });
  window.webContents.on('will-navigate', (event, url) => {
    try { if (new URL(url).origin === allowedOrigin) return; } catch (_) {}
    {
      event.preventDefault();
      void shell.openExternal(url);
    }
  });
  window.webContents.session.setPermissionRequestHandler((webContents, permission, callback) => {
    let sameOrigin = false;
    try { sameOrigin = new URL(webContents.getURL()).origin === allowedOrigin; } catch (_) {}
    callback(sameOrigin && permission === 'notifications');
  });
  void window.loadURL(appUrl);
}

ipcMain.on('show-attendance-notification', (_event, value: unknown) => {
  if (!value || typeof value !== 'object') return;
  const data = value as {title?: unknown; body?: unknown};
  if (typeof data.title !== 'string' || typeof data.body !== 'string') return;
  const notification = new Notification({title: data.title.slice(0, 100), body: data.body.slice(0, 300), icon: path.join(__dirname, '../resources/icon.png')});
  notification.on('click', () => {
    const window = BrowserWindow.getAllWindows()[0];
    if (window) { if (window.isMinimized()) window.restore(); window.show(); window.focus(); void window.loadURL(attendanceUrl); }
  });
  notification.show();
});

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) app.quit();
app.on('second-instance', () => {
  const window = BrowserWindow.getAllWindows()[0];
  if (window) { if (window.isMinimized()) window.restore(); window.show(); window.focus(); }
});

app.whenReady().then(() => {
  createWindow();
  tray = new Tray(path.join(__dirname, '../resources/icon.png'));
  tray.setToolTip('社内勤怠管理');
  tray.setContextMenu(Menu.buildFromTemplate([
    {label: '開く', click: () => { const window = BrowserWindow.getAllWindows()[0]; if (window) { window.show(); window.focus(); } else createWindow(); }},
    {label: '終了', click: () => { quitting = true; app.quit(); }}
  ]));
  tray.on('click', () => { const window = BrowserWindow.getAllWindows()[0]; if (window) { window.show(); window.focus(); } });
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('before-quit', () => { quitting = true; });
