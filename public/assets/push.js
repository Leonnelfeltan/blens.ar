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
    box._timer = setTimeout(() => { box.hidden = true; }, 4500);
  }

  function bytes(key) {
    const txt = key.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(txt + '='.repeat((4 - txt.length % 4) % 4));
    return Uint8Array.from(bin, c => c.charCodeAt(0));
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
    return res.json();
  }

  async function setup() {
    if (!supported) { state(false); return null; }
    const reg = await navigator.serviceWorker.register(sw, { scope });
    return reg;
  }

  async function sync() {
    if (!supported) return;
    try {
      const reg = await setup();
      const sub = reg && await reg.pushManager.getSubscription();
      state(!!sub);
      if (sub) await send('subscribe', sub);
    } catch (_) { state(false); }
  }

  async function toggle() {
    if (!supported) { toast('Tu navegador no admite notificaciones push.'); return; }
    if (isIOS && !standalone) { toast('Instalá la app en pantalla de inicio y volvé a activar los avisos.'); return; }
    try {
      let permission = Notification.permission;
      if (permission !== 'granted') permission = await Notification.requestPermission();
      if (permission !== 'granted') { toast('Permiso de notificaciones denegado.'); return; }
      const reg = await setup();
      if (!reg) return;
      let sub = await reg.pushManager.getSubscription();
      if (sub) {
        await send('unsubscribe', sub);
        await sub.unsubscribe();
        state(false);
        toast('Avisos desactivados.');
        return;
      }
      if (!publicKey) { toast('Falta configuración de notificaciones.'); return; }
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(publicKey) });
      const res = await send('subscribe', sub);
      if (!res || !res.ok) { await sub.unsubscribe(); toast('No se pudo activar. Recargá e intentá de nuevo.'); return; }
      state(true);
      toast('Avisos activados ✓');
    } catch (_) {
      toast('No se pudo activar. Recargá e intentá de nuevo.');
    }
  }

  buttons.forEach(b => b.addEventListener('click', toggle));
  window.addEventListener('load', () => { sync(); });
})();
