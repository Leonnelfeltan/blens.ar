// Ajusta el reproductor de Drive al formato real del video (vertical u horizontal), usando la miniatura
document.querySelectorAll('.dfr[data-did]').forEach(el => {
  const im = new Image(); im.referrerPolicy = 'no-referrer';
  im.onload = () => { if (im.naturalWidth > 0 && im.naturalHeight > 0) el.style.aspectRatio = im.naturalWidth + ' / ' + im.naturalHeight; };
  im.src = 'https://drive.google.com/thumbnail?id=' + encodeURIComponent(el.dataset.did) + '&sz=w800';
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/portal/sw.js', { scope: '/portal/' }).catch(() => {}));
}
(function () {
  const tip = document.querySelector('[data-pwa-tip]');
  if (!tip) return;
  const btn = tip.querySelector('[data-pwa-install]');
  const close = tip.querySelector('[data-pwa-close]');
  const msg = tip.querySelector('[data-pwa-msg]');
  const key = 'blens-portal-pwa-tip';
  const leida = () => { try { return localStorage.getItem(key) === '1'; } catch (_) { return false; } };
  const guardar = () => { try { localStorage.setItem(key, '1'); } catch (_) {} };
  if (leida()) return;
  let prompt = null;
  const ocultar = () => { tip.hidden = true; };
  close.addEventListener('click', () => { guardar(); ocultar(); });
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    prompt = e;
    if (msg) msg.textContent = 'Instalá el portal en tu celular para entrar más rápido.';
    if (btn) btn.hidden = false;
    tip.hidden = false;
  });
  if (ios) {
    if (msg) msg.textContent = 'Tocá Compartir y luego “Agregar a inicio” para instalar el portal.';
    if (btn) btn.hidden = true;
    tip.hidden = false;
  }
  window.addEventListener('appinstalled', ocultar);
  if (btn) btn.addEventListener('click', async () => {
    if (!prompt) return;
    await prompt.prompt();
    await prompt.userChoice;
    prompt = null;
    ocultar();
  });
})();
