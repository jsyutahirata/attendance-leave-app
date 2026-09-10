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

  const navToggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.nav');
  if (navToggle && nav) {
    const setNav = open => {
      nav.classList.toggle('open', open);
      navToggle.classList.toggle('is-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    navToggle.addEventListener('click', () => setNav(!nav.classList.contains('open')));
    nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setNav(false)));
    document.addEventListener('click', event => {
      if (nav.classList.contains('open') && !nav.contains(event.target) && !navToggle.contains(event.target)) setNav(false);
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && nav.classList.contains('open')) { setNav(false); navToggle.focus(); }
    });
  }

  const themeChoices = document.querySelectorAll('input[name="display_theme"]');
  const themeState = document.getElementById('theme-state');
  const refreshThemeControls = event => {
    const preference = event?.detail?.preference ?? window.getAttendanceTheme?.() ?? 'system';
    const resolved = event?.detail?.resolved ?? document.documentElement.dataset.resolvedTheme ?? 'light';
    themeChoices.forEach(choice => { choice.checked = choice.value === preference; });
    if (themeState) themeState.textContent = `現在は${resolved === 'dark' ? 'ダーク' : 'ライト'}表示です。`;
  };
  themeChoices.forEach(choice => choice.addEventListener('change', () => {
    if (choice.checked) window.setAttendanceTheme?.(choice.value);
  }));
  window.addEventListener('attendance-theme-change', refreshThemeControls);
  refreshThemeControls();

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

  const autoModal = document.querySelector('.js-auto-modal');
  if (autoModal instanceof HTMLDialogElement) {
    const closeModal = () => autoModal.close();
    autoModal.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', closeModal));
    autoModal.addEventListener('click', event => {
      const bounds = autoModal.getBoundingClientRect();
      const outside = event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
      if (outside) closeModal();
    });
    autoModal.showModal();
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('edit');
    cleanUrl.hash = '';
    history.replaceState(null, '', cleanUrl);
  }

  let calendarRequest = null;
  const calendarDirection = (widget, targetUrl, preferred = '') => {
    if (preferred === 'previous' || preferred === 'next' || preferred === 'none') return preferred;
    const targetMonth = new URL(targetUrl, window.location.href).searchParams.get('month') ?? '';
    return targetMonth < (widget.dataset.calendarMonth ?? '') ? 'previous' : 'next';
  };
  const navigateCalendar = async (widget, targetUrl, preferredDirection = '', updateHistory = true) => {
    if (!widget || widget.classList.contains('is-loading')) return;
    widget.classList.add('is-loading');
    widget.setAttribute('aria-busy', 'true');
    calendarRequest?.abort();
    calendarRequest = new AbortController();
    try {
      const response = await fetch(targetUrl, {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'calendar-navigation'},
        signal: calendarRequest.signal,
      });
      if (!response.ok) throw new Error('calendar fetch failed');
      const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
      const incoming = documentFragment.querySelector('.js-calendar-widget');
      if (!incoming) throw new Error('calendar response missing');
      const direction = calendarDirection(widget, targetUrl, preferredDirection);
      if (direction === 'previous') incoming.classList.add('calendar-enter-previous');
      if (direction === 'next') incoming.classList.add('calendar-enter-next');
      const currentSummary = document.querySelector('.js-calendar-summary');
      const incomingSummary = documentFragment.querySelector('.js-calendar-summary');
      if (currentSummary && incomingSummary) currentSummary.replaceWith(incomingSummary);
      widget.replaceWith(incoming);
      initialiseCalendar(incoming);
      if (updateHistory) history.pushState({calendarNavigation: true}, '', targetUrl);
    } catch (error) {
      if (error.name === 'AbortError') return;
      window.location.assign(targetUrl);
    } finally {
      document.querySelector('.js-calendar-widget.is-loading')?.classList.remove('is-loading');
      calendarRequest = null;
    }
  };
  const initialiseCalendar = widget => {
    if (widget.dataset.navigationReady === 'true') return;
    widget.dataset.navigationReady = 'true';
    widget.querySelectorAll('.js-calendar-navigation').forEach(link => link.addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      navigateCalendar(widget, link.href, link.dataset.calendarDirection ?? '');
    }));
    const calendar = widget.querySelector('.js-swipe-calendar');
    if (!calendar) return;
    let suppressDateClickUntil = 0;
    const openDateDialog = day => {
      if (!(day instanceof HTMLElement)) return;
      const dialog = document.getElementById('personal-calendar-dialog');
      const form = dialog?.querySelector('.js-personal-calendar-form');
      if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) return;
      form.reset();
      form.elements.entry_date.value = day.dataset.calendarDate ?? '';
      form.querySelector('.calendar-form-status').hidden = true;
      syncCalendarFormSections(form);
      dialog.showModal();
    };
    widget.querySelectorAll('.js-personal-calendar-date').forEach(day => {
      day.addEventListener('click', event => {
        if (Date.now() < suppressDateClickUntil) return;
        if (event.target.closest('.calendar-item,a,button,input,select,textarea,summary')) return;
        openDateDialog(day);
      });
      day.addEventListener('keydown', event => {
        if (event.target !== day || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        openDateDialog(day);
      });
    });
    widget.querySelectorAll('.js-calendar-date-add').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      openDateDialog(link.closest('.js-personal-calendar-date'));
    }));
    let startX = 0;
    let startY = 0;
    let startTime = 0;
    let swipeEnabled = false;
    calendar.addEventListener('touchstart', event => {
      if (event.touches.length !== 1 || event.target.closest('a,button,input,select,textarea,summary')) {
        swipeEnabled = false;
        return;
      }
      startX = event.touches[0].clientX;
      startY = event.touches[0].clientY;
      startTime = Date.now();
      swipeEnabled = true;
    }, {passive: true});
    calendar.addEventListener('touchend', event => {
      if (!swipeEnabled || event.changedTouches.length !== 1) return;
      swipeEnabled = false;
      const deltaX = event.changedTouches[0].clientX - startX;
      const deltaY = event.changedTouches[0].clientY - startY;
      if (Date.now() - startTime > 1000 || Math.abs(deltaX) < 60 || Math.abs(deltaX) < Math.abs(deltaY) * 1.25) return;
      suppressDateClickUntil = Date.now() + 400;
      const direction = deltaX > 0 ? 'previous' : 'next';
      const target = direction === 'previous' ? calendar.dataset.previousMonthUrl : calendar.dataset.nextMonthUrl;
      if (target) navigateCalendar(widget, target, direction);
    }, {passive: true});
    calendar.addEventListener('touchcancel', () => { swipeEnabled = false; }, {passive: true});

    let pointerStartX = 0;
    let pointerStartY = 0;
    let pointerStartedAt = 0;
    let pointerEnabled = false;
    calendar.addEventListener('pointerdown', event => {
      if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('a,button,input,select,textarea,summary')) return;
      pointerStartX = event.clientX;
      pointerStartY = event.clientY;
      pointerStartedAt = Date.now();
      pointerEnabled = true;
    });
    calendar.addEventListener('pointermove', event => {
      if (!pointerEnabled || event.pointerType !== 'mouse') return;
      const moved = Math.hypot(event.clientX - pointerStartX, event.clientY - pointerStartY);
      if (moved <= 8 || calendar.classList.contains('is-dragging')) return;
      calendar.classList.add('is-dragging');
      calendar.setPointerCapture(event.pointerId);
    });
    calendar.addEventListener('pointerup', event => {
      if (!pointerEnabled || event.pointerType !== 'mouse') return;
      pointerEnabled = false;
      calendar.classList.remove('is-dragging');
      if (calendar.hasPointerCapture(event.pointerId)) calendar.releasePointerCapture(event.pointerId);
      const deltaX = event.clientX - pointerStartX;
      const deltaY = event.clientY - pointerStartY;
      if (Math.abs(deltaX) > 10 || Math.abs(deltaY) > 10) suppressDateClickUntil = Date.now() + 400;
      if (Date.now() - pointerStartedAt > 1200 || Math.abs(deltaX) < 80 || Math.abs(deltaX) < Math.abs(deltaY) * 1.25) return;
      const direction = deltaX > 0 ? 'previous' : 'next';
      const target = direction === 'previous' ? calendar.dataset.previousMonthUrl : calendar.dataset.nextMonthUrl;
      if (target) navigateCalendar(widget, target, direction);
    });
    calendar.addEventListener('pointercancel', () => {
      pointerEnabled = false;
      calendar.classList.remove('is-dragging');
    });

    let accumulatedWheelX = 0;
    let wheelResetTimer = 0;
    calendar.addEventListener('wheel', event => {
      if (Math.abs(event.deltaX) <= Math.abs(event.deltaY) * 1.15) return;
      event.preventDefault();
      accumulatedWheelX += event.deltaX * (event.deltaMode === 1 ? 16 : 1);
      window.clearTimeout(wheelResetTimer);
      wheelResetTimer = window.setTimeout(() => { accumulatedWheelX = 0; }, 180);
      if (Math.abs(accumulatedWheelX) < 90) return;
      const direction = accumulatedWheelX > 0 ? 'next' : 'previous';
      accumulatedWheelX = 0;
      const target = direction === 'previous' ? calendar.dataset.previousMonthUrl : calendar.dataset.nextMonthUrl;
      if (target) navigateCalendar(widget, target, direction);
    }, {passive: false});
  };
  const syncCalendarFormSections = form => {
    const kind = form.elements.entry_kind?.value ?? 'leave';
    form.querySelectorAll('[data-calendar-fields]').forEach(section => {
      section.classList.toggle('is-hidden', section.dataset.calendarFields !== kind);
    });
  };
  const calendarEntryDialog = document.getElementById('personal-calendar-dialog');
  const calendarEntryForm = calendarEntryDialog?.querySelector('.js-personal-calendar-form');
  if (calendarEntryDialog instanceof HTMLDialogElement && calendarEntryForm instanceof HTMLFormElement) {
    calendarEntryForm.elements.entry_kind.addEventListener('change', () => syncCalendarFormSections(calendarEntryForm));
    calendarEntryDialog.querySelectorAll('[data-calendar-dialog-close]').forEach(button => button.addEventListener('click', () => calendarEntryDialog.close()));
    calendarEntryDialog.addEventListener('click', event => {
      const bounds = calendarEntryDialog.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) calendarEntryDialog.close();
    });
    calendarEntryForm.addEventListener('submit', async event => {
      event.preventDefault();
      const status = calendarEntryForm.querySelector('.calendar-form-status');
      const submit = calendarEntryForm.querySelector('button[type="submit"]');
      status.hidden = true;
      submit.disabled = true;
      try {
        const response = await fetch(calendarEntryForm.action, {method: 'POST', body: new FormData(calendarEntryForm), credentials: 'same-origin', headers: {'X-Requested-With': 'calendar-entry'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '予定を登録できませんでした。');
        calendarEntryDialog.close();
        const widget = document.querySelector('.js-calendar-widget');
        if (widget) {
          const refreshUrl = new URL(widget.dataset.calendarBaseUrl || window.location.pathname, window.location.origin);
          refreshUrl.searchParams.set('month', widget.dataset.calendarMonth ?? '');
          await navigateCalendar(widget, refreshUrl.pathname + refreshUrl.search, 'none', false);
          const updatedWidget = document.querySelector('.js-calendar-widget');
          if (updatedWidget) {
            const flash = document.createElement('p');
            flash.className = 'flash success calendar-action-flash';
            flash.textContent = result.message;
            updatedWidget.before(flash);
            window.setTimeout(() => flash.remove(), 5000);
          }
        }
      } catch (error) {
        status.textContent = error.message || '予定を登録できませんでした。';
        status.className = 'calendar-form-status flash error';
        status.hidden = false;
      } finally {
        submit.disabled = false;
      }
    });
    document.querySelectorAll('.js-open-entry-dialog').forEach(button => button.addEventListener('click', () => {
      calendarEntryForm.reset();
      const today = new Date();
      const iso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
      calendarEntryForm.elements.entry_date.value = button.dataset.entryDate || iso;
      if (button.dataset.entryKind && calendarEntryForm.elements.entry_kind) {
        calendarEntryForm.elements.entry_kind.value = button.dataset.entryKind;
      }
      calendarEntryForm.querySelector('.calendar-form-status').hidden = true;
      syncCalendarFormSections(calendarEntryForm);
      calendarEntryDialog.showModal();
    }));
    syncCalendarFormSections(calendarEntryForm);
  }
  const initialCalendar = document.querySelector('.js-calendar-widget');
  if (initialCalendar) {
    initialiseCalendar(initialCalendar);
    history.replaceState({...(history.state ?? {}), calendarNavigation: true}, '', window.location.href);
    window.addEventListener('popstate', () => {
      const widget = document.querySelector('.js-calendar-widget');
      if (widget) navigateCalendar(widget, window.location.href, '', false);
    });
  }

})();
