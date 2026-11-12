(() => {
  const S = (c, d) => `<svg class="${c}" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
  const I = {
    play: S('i-play', '<path d="M7 4v16l13-8z" fill="currentColor"/>'),
    pause: S('i-pause', '<path d="M6 4h4v16H6zM14 4h4v16h-4z" fill="currentColor"/>'),
    vol: S('i-vol', '<path d="M3 9v6h4l5 4V5L7 9z" fill="currentColor"/><path d="M15 8.5a5 5 0 010 7M17.5 6a8.5 8.5 0 010 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'),
    mute: S('i-mute', '<path d="M3 9v6h4l5 4V5L7 9z" fill="currentColor"/><path d="M16 9l5 6M21 9l-5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'),
    fs: S('i-fs', '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>')
  };
  const fmt = t => { t = Math.max(0, Math.floor(t || 0)); return Math.floor(t / 60) + ':' + String(t % 60).padStart(2, '0'); };
  document.querySelectorAll('.cvw').forEach(w => {
    const v = w.querySelector('video'); v.controls = false;
    w.insertAdjacentHTML('beforeend', `<button type="button" class="cv-big" aria-label="Reproducir">${I.play}</button><div class="cv-bar"><button type="button" class="cv-b cv-pp" aria-label="Reproducir o pausar">${I.play}${I.pause}</button><span class="cv-time">0:00 / 0:00</span><div class="cv-prog" role="slider" tabindex="0" aria-label="Progreso" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i></i></div><button type="button" class="cv-b cv-mu" aria-label="Sonido">${I.vol}${I.mute}</button><button type="button" class="cv-b cv-fs" aria-label="Pantalla completa">${I.fs}</button></div><button type="button" class="cv-snd" hidden>Tocar para activar el sonido</button>`);
    const $ = s => w.querySelector(s), snd = $('.cv-snd'), prog = $('.cv-prog'), bar = $('.cv-prog i'), tm = $('.cv-time');
    const load = () => { if (!v.dataset.ready) { const s = v.querySelectorAll('source[data-src]'); s.forEach(x => { if (!x.src) x.src = x.dataset.src; }); if (s.length) v.load(); v.dataset.ready = 1; } };
    const toggle = () => { load(); v.paused ? v.play().catch(() => {}) : v.pause(); };
    const sync = () => { w.classList.toggle('playing', !v.paused); w.classList.toggle('muted', v.muted); if (!v.muted) snd.hidden = true; };
    const tick = () => { const pct = v.currentTime / v.duration * 100 || 0; bar.style.width = pct + '%'; prog.setAttribute('aria-valuenow', String(Math.round(pct))); tm.textContent = fmt(v.currentTime) + ' / ' + fmt(v.duration); };
    ['play', 'pause', 'volumechange'].forEach(ev => v.addEventListener(ev, sync));
    ['timeupdate', 'loadedmetadata'].forEach(ev => v.addEventListener(ev, tick));
    $('.cv-big').onclick = toggle; $('.cv-pp').onclick = toggle; v.onclick = toggle;
    $('.cv-mu').onclick = () => { v.muted = !v.muted; };
    $('.cv-fs').onclick = () => { document.fullscreenElement ? document.exitFullscreen() : w.requestFullscreen ? w.requestFullscreen() : v.webkitEnterFullscreen && v.webkitEnterFullscreen(); };
    $('.cv-prog').onclick = e => { const r = e.currentTarget.getBoundingClientRect(); v.currentTime = (e.clientX - r.left) / r.width * v.duration; };
    prog.addEventListener('keydown', e => {
      const d = v.duration || 0; if (!d) return;
      const step = e.shiftKey ? 10 : 5;
      if (e.key === 'ArrowRight') { v.currentTime = Math.min(d, v.currentTime + step); e.preventDefault(); }
      else if (e.key === 'ArrowLeft') { v.currentTime = Math.max(0, v.currentTime - step); e.preventDefault(); }
      else if (e.key === 'Home') { v.currentTime = 0; e.preventDefault(); }
      else if (e.key === 'End') { v.currentTime = d; e.preventDefault(); }
    });
    snd.onclick = () => { v.muted = false; };
    sync();
    if (v.hasAttribute('data-auto')) {
      w.classList.add('grow'); load(); v.muted = false;
      v.play().catch(() => { v.muted = true; v.play().then(() => { snd.hidden = false; }).catch(() => {}); });
    }
  });
})();
