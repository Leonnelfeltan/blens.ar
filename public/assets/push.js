(() => {
  const buttons = Array.from(document.querySelectorAll('[data-push]'));
  if (!buttons.length) return;
  const tipo = buttons[0].dataset.pushTipo || 'admin';
  const token = buttons[0].dataset.pushToken || '';
  const publicKey = buttons[0].dataset.pushKey || '';
  const scope = tipo === 'admin' ? '/admin/' : '/portal/';
  const sw = scope + 'sw.js';
  const labels = tipo === 'admin'
    ? { on: 'Activadas ✓', off: 'Activar notificaciones push' }
    : { on: 'Avisos activados ✓', off: 'Activar avisos de nuevas entregas' };
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function toast(msg) {
    let box = document.querySelector('.push-toast');
    if (!box) {
      box = document.createElement('div');
      box.className = 'push-toast';
      box.setAttribute('role', 'status');
      document.body.appendChild(box);
    }
    box.textContent = msg;
    box.hidden = false;
    clearTimeout(box._timer);
    box._timer = setTimeout(() => { box.hidden = true; }, 6000);
  }

  function bytes(key) {
    const txt = (key || '').replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(txt + '='.repeat((4 - txt.length % 4) % 4));
    const out = Uint8Array.from(bin, c => c.charCodeAt(0));
    if (out.length !== 65 || out[0] !== 4) throw new Error('Clave de notificaciones inválida.');
    return out;
  }

  function state(active) {
    buttons.forEach(b => {
      b.textContent = active ? (b.dataset.pushOn || labels.on) : (b.dataset.pushOff || labels.off);
      b.classList.toggle('on', active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  async function send(action, sub) {
    const res = await fetch('/api/push-subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, tipo, token, subscription: sub.toJSON() }),
    });
    let data = {};
    try { data = await res.json(); } catch (_) {}
    if (!res.ok || data.ok === false) throw new Error(data.error || ('Error HTTP ' + res.status));
    return data;
  }

  async function ready() {
    if (!supported) throw new Error('Tu navegador no admite notificaciones push.');
    await navigator.serviceWorker.register(sw, { scope });
    return await navigator.serviceWorker.ready;
  }

  async function sync() {
    if (!supported || Notification.permission !== 'granted') return;
    try {
      const reg = await ready();
      const sub = await reg.pushManager.getSubscription();
      state(!!sub);
    } catch (_) {
      state(false);
    }
  }

  async function toggle() {
    if (!supported) { toast('Tu navegador no admite notificaciones push.'); return; }
    if (isIOS && !standalone) { toast('Instalá la app en pantalla de inicio y volvé a activar los avisos.'); return; }
    try {
      let permission = Notification.permission;
      if (permission !== 'granted') permission = await Notification.requestPermission();
      if (permission !== 'granted') { toast('Permiso de notificaciones denegado.'); return; }
      const reg = await ready();
      let sub = await reg.pushManager.getSubscription();
      if (sub) {
        await send('unsubscribe', sub);
        await sub.unsubscribe();
        state(false);
        toast('Avisos desactivados.');
        return;
      }
      const key = bytes(publicKey);
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      try {
        await send('subscribe', sub);
      } catch (err) {
        await sub.unsubscribe();
        throw err;
      }
      state(true);
      toast(tipo === 'admin' ? 'Notificaciones activadas ✓' : 'Avisos activados ✓');
    } catch (err) {
      toast(err && err.message ? err.message : 'No se pudo activar. Intentá de nuevo.');
    }
  }

  buttons.forEach(b => b.addEventListener('click', toggle));
  window.addEventListener('load', () => { sync(); });
})();
