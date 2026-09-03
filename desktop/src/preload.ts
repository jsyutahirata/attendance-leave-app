import {contextBridge, ipcRenderer} from 'electron';

contextBridge.exposeInMainWorld('attendanceDesktop', {
  showNotification: (notification: {title: string; body: string}) => ipcRenderer.send('show-attendance-notification', notification)
});
