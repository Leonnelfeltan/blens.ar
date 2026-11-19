(() => {
  const buttons = Array.from(document.querySelectorAll('[data-push]'));
  if (!buttons.length) return;
  const source = buttons[0];
  const tipo = source.dataset.pushTipo || 'admin';
  const token = source.dataset.pushToken || '';
  const publicKey = source.dataset.pushKey || '';
  const scope = tipo === 'admin' ? '/admin/' : '/portal/';
  const sw = scope + 'sw.js';
  const cta = 'Activar notificaciones en este dispositivo';
  const iosAviso = 'Para recibir notificaciones en iPhone, primero instalá esta app en tu pantalla de inicio (Compartir → Agregar a inicio) y abrila desde ahí.';
  const labels = tipo === 'admin'
    ? { on: 'Activadas ✓', off: cta }
    : { on: 'Notificaciones activadas ✓', off: cta };
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const storageKey = 'blens-push-banner-' + tipo;
  let banner = null;
  let bannerText = null;

  function cerrado() {
    try { return sessionStorage.getItem(storageKey) === '1'; } catch (_) { return false; }
  }

  function marcarCerrado() {
    try { sessionStorage.setItem(storageKey, '1'); } catch (_) {}
  }

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

  function ocultarBanner() {
    if (banner) banner.hidden = true;
    document.body.classList.remove('push-banner-on');
  }

  function crearBanner() {
    if (banner) return;
    banner = document.createElement('aside');
    banner.className = 'push-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.hidden = true;
    bannerText = document.createElement('span');
    bannerText.className = 'push-banner-text';
    const accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'push-banner-btn';
    accept.textContent = cta;
    accept.addEventListener('click', toggle);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'push-banner-close';
    close.textContent = '×';
    close.setAttribute('aria-label', 'Cerrar aviso de notificaciones');
    close.addEventListener('click', () => { marcarCerrado(); ocultarBanner(); });
    banner.append(bannerText, accept, close);
    document.body.appendChild(banner);
  }

  function mostrarBanner(active) {
    crearBanner();
    if (active || cerrado()) { ocultarBanner(); return; }
    let msg = tipo === 'admin'
      ? 'Activá las notificaciones para enterarte de nuevos pedidos y consultas.'
      : 'Activá las notificaciones para enterarte de nuevas entregas y respuestas.';
    if (isIOS && !standalone) msg += ' ' + iosAviso;
    bannerText.textContent = msg;
    banner.hidden = false;
    document.body.classList.add('push-banner-on');
  }

  async function send(action, sub) {
    if (!token) throw new Error('La sesión venció. Recargá la página.');
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
    if (!token || !publicKey) throw new Error('La sesión venció. Recargá la página.');
    return await navigator.serviceWorker.register(sw, { scope });
  }

  async function sync() {
    if (!supported) { state(false); return; }
    if (Notification.permission === 'denied') { state(false); ocultarBanner(); return; }
    try {
      const reg = await ready();
      const sub = await reg.pushManager.getSubscription();
      state(!!sub);
      mostrarBanner(!sub);
    } catch (_) {
      state(false);
      mostrarBanner(true);
    }
  }

  async function toggle() {
    if (!supported) { toast('Tu navegador no admite notificaciones push.'); return; }
    if (isIOS && !standalone) { toast(iosAviso); return; }
    if (Notification.permission === 'denied') { toast('El navegador tiene las notificaciones bloqueadas. Habilitalas desde los permisos del sitio.'); return; }
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
        mostrarBanner(true);
        toast('Notificaciones desactivadas.');
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
      ocultarBanner();
      toast('Notificaciones activadas ✓. Te vamos a avisar en este dispositivo.');
    } catch (err) {
      toast(err && err.message ? err.message : 'No se pudo activar. Intentá de nuevo.');
    }
  }

  state(false);
  buttons.forEach(b => b.addEventListener('click', toggle));
  window.addEventListener('load', () => { sync(); });
})();
