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

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const vapidPublicKey = document.querySelector('meta[name="vapid-public-key"]')?.content ?? '';
  const pushState = document.getElementById('push-state');
  const pushEnable = document.getElementById('push-enable');
  const pushDisable = document.getElementById('push-disable');
  const postForm = async (route, values = {}) => {
    const body = new URLSearchParams({csrf_token: csrf, ...values});
    const response = await fetch(`/index.php?route=${encodeURIComponent(route)}`, {method: 'POST', body, credentials: 'same-origin'});
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || '処理に失敗しました。');
    return data;
  };
  const base64UrlToUint8 = value => {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const raw = atob((value + padding).replaceAll('-', '+').replaceAll('_', '/'));
    return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
  };
  const refreshPushState = async () => {
    if (!pushState) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      pushState.textContent = 'この環境はWeb Pushに対応していません。対応するブラウザまたはPWAで設定してください。';
      if (pushEnable) pushEnable.hidden = true;
      return;
    }
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    pushState.textContent = subscription ? 'この端末の通知は有効です。' : 'この端末の通知は無効です。';
    if (pushEnable) pushEnable.hidden = Boolean(subscription);
    if (pushDisable) pushDisable.hidden = !subscription;
  };
  pushEnable?.addEventListener('click', async () => {
    try {
      if (!vapidPublicKey) throw new Error('サーバーのVAPIDキーが未設定です。');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('通知が許可されませんでした。ブラウザまたはOSの設定を確認してください。');
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: base64UrlToUint8(vapidPublicKey)});
      await postForm('push/subscribe', {subscription: JSON.stringify(subscription.toJSON())});
      await refreshPushState();
    } catch (error) { if (pushState) pushState.textContent = error.message; }
  });
  pushDisable?.addEventListener('click', async () => {
    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();
      if (subscription) {
        await postForm('push/unsubscribe', {endpoint: subscription.endpoint});
        await subscription.unsubscribe();
      }
      await refreshPushState();
    } catch (error) { if (pushState) pushState.textContent = error.message; }
  });
  refreshPushState().catch(() => {});

})();
