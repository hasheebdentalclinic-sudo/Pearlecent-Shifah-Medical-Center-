// Pearlecent admin: small conveniences. Everything still works without JavaScript.
(() => {
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.dataset.confirm)) e.preventDefault();
  });
  document.querySelectorAll('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.requestSubmit ? s.form.requestSubmit() : s.form.submit()));
  document.querySelectorAll('[data-drop]').forEach((zone) => {
    const input = zone.querySelector('input[type=file]');
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) return;
      let img = zone.querySelector('img');
      if (!img) { img = document.createElement('img'); img.alt = ''; zone.appendChild(img); }
      const ph = zone.querySelector('.drop__ph'); if (ph) ph.remove();
      img.src = URL.createObjectURL(file);
      zone.classList.add('is-new');
    });
  });
  document.querySelectorAll('form').forEach((f) => f.addEventListener('submit', () => {
    const b = f.querySelector('button[type=submit]');
    if (b && f.enctype === 'multipart/form-data') { b.disabled = true; b.textContent = 'Saving…'; }
  }));
})();

// ---------------------------------------------------------------- phone app + notifications
(() => {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {});

  const box = document.querySelector('[data-push]');
  let deferred = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; render(); });
  window.addEventListener('appinstalled', () => { deferred = null; render(); });
  if (!box) return;

  const ua = navigator.userAgent;
  const isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /android/i.test(ua);
  const isSafari = /safari/i.test(ua) && !/crios|fxios|edgios|chrome|android/i.test(ua);
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const canPush = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const csrf = document.querySelector('meta[name=csrf]').content;
  const msg = box.querySelector('[data-push-msg]');
  let subscribed = false;

  const label = () => {
    const dev = isIOS ? (/ipad/i.test(ua) ? 'iPad' : 'iPhone') : isAndroid ? 'Android phone' : /windows/i.test(ua) ? 'Windows computer' : /mac/i.test(ua) ? 'Mac' : 'Device';
    const br = standalone ? 'app' : /edg/i.test(ua) ? 'Edge' : /chrome|crios/i.test(ua) ? 'Chrome' : /firefox|fxios/i.test(ua) ? 'Firefox' : isSafari ? 'Safari' : 'browser';
    return dev + ' (' + br + ')';
  };
  const show = (step, key) => box.querySelectorAll(`[data-step="${step}"] [data-when]`).forEach((el) => { el.hidden = el.dataset.when !== key; });
  const say = (t) => { msg.textContent = t; msg.hidden = !t; };

  function render() {
    show('install', standalone ? 'installed' : isIOS ? (isSafari ? 'ios' : 'ios-other') : deferred ? 'android' : isAndroid ? 'android-manual' : 'desktop');
    let n;
    if (!canPush) n = isIOS && !standalone ? 'ios-need-install' : 'unsupported';
    else if (Notification.permission === 'denied') n = 'blocked';
    else n = subscribed ? 'on' : 'off';
    show('notify', n);
  }

  const b64ToBytes = (s) => { const p = '='.repeat((4 - s.length % 4) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(r, (c) => c.charCodeAt(0)); };
  const bytesToB64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const post = (data) => fetch('?p=account', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ csrf, ...data }) }).then((r) => r.ok);

  async function check() {
    if (!canPush) return render();
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      subscribed = !!sub;
      if (sub) {                                    // keep the server's copy fresh
        const j = sub.toJSON();
        post({ action: 'push_subscribe', endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, label: label() });
      }
    } catch (e) { /* ignore */ }
    render();
  }

  box.addEventListener('click', async (e) => {
    if (e.target.closest('[data-install]') && deferred) {
      deferred.prompt();
      await deferred.userChoice.catch(() => null);
      deferred = null; render();
    }
    if (e.target.closest('[data-push-on]')) {
      say('');
      try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { render(); return say('Notifications were not allowed.'); }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(box.dataset.key) });
        const ok = await post({ action: 'push_subscribe', endpoint: sub.endpoint, p256dh: bytesToB64(sub.getKey('p256dh')), auth: bytesToB64(sub.getKey('auth')), label: label() });
        if (!ok) throw new Error('save');
        location.href = '?p=account#app';
      } catch (err) { say('Could not turn on notifications. Please reload the page and try again.'); }
    }
    if (e.target.closest('[data-push-off]')) {
      try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) { await post({ action: 'push_unsubscribe', endpoint: sub.endpoint }); await sub.unsubscribe(); }
        location.href = '?p=account#app';
      } catch (err) { say('Could not turn off notifications.'); }
    }
  });

  render();
  check();
})();
