// Ajusta el reproductor de Drive al formato real del video (vertical u horizontal), usando la miniatura
document.querySelectorAll('.dfr[data-did]').forEach(el => {
  const im = new Image(); im.referrerPolicy = 'no-referrer';
  im.onload = () => { if (im.naturalWidth > 0 && im.naturalHeight > 0) el.style.aspectRatio = im.naturalWidth + ' / ' + im.naturalHeight; };
  im.src = 'https://drive.google.com/thumbnail?id=' + encodeURIComponent(el.dataset.did) + '&sz=w800';
});

(function () {
  const card = document.querySelector('[data-pwa-card]');
  const tip = document.querySelector('[data-pwa-tip]');
  const targets = [card, tip].filter(Boolean);
  if (!targets.length) return;
  const instalar = Array.from(document.querySelectorAll('[data-pwa-install]'));
  const cerrar = Array.from(document.querySelectorAll('[data-pwa-close]'));
  const mensajes = Array.from(document.querySelectorAll('[data-pwa-msg]'));
  const key = 'blens-portal-pwa-tip';
  const leida = () => { try { return localStorage.getItem(key) === '1'; } catch (_) { return false; } };
  const guardar = () => { try { localStorage.setItem(key, '1'); } catch (_) {} };
  const ocultar = () => { targets.forEach(t => { t.hidden = true; }); };
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const mostrar = (texto, puedeInstalar) => {
    if (leida()) return;
    mensajes.forEach(m => { m.textContent = texto; });
    instalar.forEach(b => { b.hidden = !puedeInstalar; });
    targets.forEach(t => { t.hidden = false; });
  };
  cerrar.forEach(b => b.addEventListener('click', () => { guardar(); ocultar(); }));
  if (standalone) {
    mensajes.forEach(m => { m.textContent = 'Ya estás usando el portal como app. Tus entregas y avisos quedan a mano en la pantalla de inicio.'; });
    instalar.forEach(b => { b.hidden = true; });
    if (!leida()) targets.forEach(t => { t.hidden = false; });
    return;
  }
  let prompt = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    prompt = e;
    mostrar('Instalá el portal en tu celular para entrar más rápido y recibir avisos de tus entregas.', true);
  });
  if (ios) mostrar('Tocá Compartir y luego “Agregar a pantalla de inicio” para instalar el portal.', false);
  window.addEventListener('appinstalled', () => { guardar(); ocultar(); });
  instalar.forEach(b => b.addEventListener('click', async () => {
    if (!prompt) return;
    await prompt.prompt();
    await prompt.userChoice;
    prompt = null;
    guardar();
    ocultar();
  }));
})();
