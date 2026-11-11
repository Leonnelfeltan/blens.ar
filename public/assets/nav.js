(() => {
  const n = document.querySelector('.nav'), b = document.querySelector('.burger'), m = document.getElementById('menu');
  if (!n || !b || !m) return;
  const set = o => { n.classList.toggle('open', o); b.setAttribute('aria-expanded', String(o)); b.setAttribute('aria-label', o ? 'Cerrar menú' : 'Abrir menú'); };
  b.addEventListener('click', () => set(!n.classList.contains('open')));
  m.addEventListener('click', e => { if (e.target.closest('a')) set(false); });
  document.addEventListener('click', e => { if (!n.contains(e.target)) set(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') set(false); });
  addEventListener('resize', () => { if (innerWidth > 800) set(false); });
})();
