(() => {
  const banner = document.createElement('div');
  banner.className = 'offline-banner';
  banner.hidden = true;
  banner.textContent = 'オフラインです。打刻や登録は通信が戻ってから行ってください。';
  document.body.prepend(banner);

  const syncNetworkState = () => { banner.hidden = navigator.onLine; };
  window.addEventListener('online', syncNetworkState);
  window.addEventListener('offline', syncNetworkState);
  syncNetworkState();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js'));
  }

  let installPrompt = null;
  const installButton = document.getElementById('pwa-install');
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    if (installButton) installButton.hidden = false;
  });
  installButton?.addEventListener('click', async () => {
    if (!installPrompt) return;
    await installPrompt.prompt();
    installPrompt = null;
    installButton.hidden = true;
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    if (installButton) installButton.hidden = true;
  });
})();
