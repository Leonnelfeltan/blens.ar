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
    w.insertAdjacentHTML('beforeend', `<button type="button" class="cv-big" aria-label="Reproducir">${I.play}</button><div class="cv-bar"><button type="button" class="cv-b cv-pp" aria-label="Reproducir o pausar">${I.play}${I.pause}</button><span class="cv-time">0:00 / 0:00</span><div class="cv-prog" role="slider" aria-label="Progreso"><i></i></div><button type="button" class="cv-b cv-q" hidden aria-label="Calidad del video"></button><div class="cv-menu" hidden></div><button type="button" class="cv-b cv-mu" aria-label="Sonido">${I.vol}${I.mute}</button><button type="button" class="cv-b cv-fs" aria-label="Pantalla completa">${I.fs}</button></div><button type="button" class="cv-snd" hidden>Tocar para activar el sonido</button>`);
    const $ = s => w.querySelector(s), snd = $('.cv-snd'), bar = $('.cv-prog i'), tm = $('.cv-time');
    const load = () => { if (!v.dataset.ready) { const s = v.querySelectorAll('source[data-src]'); s.forEach(x => { if (!x.src) x.src = x.dataset.src; }); if (s.length) v.load(); v.dataset.ready = 1; } };
    const toggle = () => { load(); v.paused ? v.play().catch(() => {}) : v.pause(); };
    const sync = () => { w.classList.toggle('playing', !v.paused); w.classList.toggle('muted', v.muted); if (!v.muted) snd.hidden = true; };
    const tick = () => { bar.style.width = (v.currentTime / v.duration * 100 || 0) + '%'; tm.textContent = fmt(v.currentTime) + ' / ' + fmt(v.duration); };
    ['play', 'pause', 'volumechange'].forEach(ev => v.addEventListener(ev, sync));
    ['timeupdate', 'loadedmetadata'].forEach(ev => v.addEventListener(ev, tick));
    $('.cv-big').onclick = toggle; $('.cv-pp').onclick = toggle; v.onclick = toggle;
    $('.cv-mu').onclick = () => { v.muted = !v.muted; };
    $('.cv-fs').onclick = () => { document.fullscreenElement ? document.exitFullscreen() : w.requestFullscreen ? w.requestFullscreen() : v.webkitEnterFullscreen && v.webkitEnterFullscreen(); };
    $('.cv-prog').onclick = e => { const r = e.currentTarget.getBoundingClientRect(); v.currentTime = (e.clientX - r.left) / r.width * v.duration; };
    snd.onclick = () => { v.muted = false; };
    sync();
    // ---- Calidad: Auto / 1080p / 720p / 480p, según las versiones guardadas en el servidor ----
    let Q = null; try { Q = v.dataset.q ? JSON.parse(v.dataset.q) : null; } catch (er) {}
    const keys = Q ? Object.keys(Q).sort((a, b) => b - a) : [];
    if (keys.length > 1) {
      const ls = { get: k => { try { return localStorage.getItem(k); } catch (er) { return null; } }, set: (k, x) => { try { localStorage.setItem(k, x); } catch (er) {} } };
      const qb = $('.cv-q'), qm = $('.cv-menu'); let cur = null;
      const pref = () => ls.get('blens_q') || 'auto';
      const auto = () => {
        const need = Math.max(Math.min(innerWidth, 560), 320) * (devicePixelRatio || 1), cn = navigator.connection || {};
        const asc = [...keys].reverse(), slow = cn.saveData || /2g/.test(cn.effectiveType || '');
        return slow ? asc[0] : (asc.find(k => +k >= need * .9) || keys[0]);
      };
      const label = () => { qb.textContent = pref() === 'auto' ? 'Auto · ' + cur + 'p' : cur + 'p'; };
      const choose = () => { const p = pref(); return p !== 'auto' && Q[p] ? p : auto(); };
      const apply = (k, keep) => {
        if (!Q[k] || cur === k) { label(); return; }
        const t = v.currentTime, was = !v.paused; cur = k;
        v.removeAttribute('src'); v.innerHTML = '<source src="' + Q[k] + '" type="video/mp4">'; v.dataset.ready = 1; v.load();
        if (keep) v.addEventListener('loadedmetadata', () => { v.currentTime = t; if (was) v.play().catch(() => {}); }, { once: true });
        label();
      };
      qm.innerHTML = ['auto', ...keys].map(k => '<button type="button" data-k="' + k + '">' + (k === 'auto' ? 'Auto' : k + 'p') + '</button>').join('');
      qb.hidden = false;
      qb.onclick = e => { e.stopPropagation(); qm.hidden = !qm.hidden; };
      qm.onclick = e => { const b = e.target.closest('button'); if (!b) return; ls.set('blens_q', b.dataset.k); qm.hidden = true; apply(choose(), true); label(); };
      document.addEventListener('click', e => { if (!w.contains(e.target)) qm.hidden = true; });
      apply(choose(), false);
    }
    if (v.hasAttribute('data-auto')) {
      w.classList.add('grow'); load(); v.muted = false;
      v.play().catch(() => { v.muted = true; v.play().then(() => { snd.hidden = false; }).catch(() => {}); });
    }
  });
})();
