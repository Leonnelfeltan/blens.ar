(() => {
  const forms = Array.from(document.querySelectorAll('[data-feedback-form]'));
  if (!forms.length) return;

  const stop = e => e.stopPropagation();
  document.querySelectorAll('.fb, .fb-ap, .fb-adj form, .fb-btn').forEach(el => {
    ['pointerdown', 'mousedown', 'mouseup', 'click', 'touchstart', 'touchend'].forEach(type => el.addEventListener(type, stop));
  });

  const flash = msg => {
    let box = document.querySelector('.pflash');
    if (!box) {
      box = document.createElement('p');
      box.className = 'pflash';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      const main = document.querySelector('main');
      if (main && main.parentNode) main.parentNode.insertBefore(box, main);
      else document.body.appendChild(box);
    }
    box.textContent = msg;
    box.hidden = false;
  };

  forms.forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      e.stopPropagation();
      if (!form.reportValidity()) return;
      const btn = form.querySelector('[data-feedback-btn]') || form.querySelector('button[type="submit"]');
      const texto = btn ? btn.textContent : '';
      if (btn) {
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'Enviando…';
      }
      const datos = new FormData(form);
      datos.set('ajax', '1');
      try {
        const res = await fetch('./', {
          method: 'POST',
          body: datos,
          headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
          credentials: 'same-origin',
        });
        let data = {};
        try { data = await res.json(); } catch (_) {}
        if (!res.ok || data.ok === false) throw new Error(data.error || ('Error HTTP ' + res.status));
        const fb = form.closest('.fb');
        const badge = fb ? fb.querySelector('.fb-badge') : null;
        if (badge) {
          badge.className = 'fb-badge ' + (data.estado === 'aprobado' ? 'ok' : data.estado === 'ajuste' ? 'adj' : 'pend');
          badge.textContent = data.badge || 'Aprobado ✓';
        }
        if (form.dataset.feedbackForm === 'aprobar') {
          const acts = fb ? fb.querySelector('.fb-acts') : null;
          if (acts) acts.hidden = true;
        }
        flash(data.message || 'Listo.');
      } catch (err) {
        flash(err && err.message ? err.message : 'No se pudo enviar. Intentá de nuevo.');
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
          btn.textContent = texto;
        }
      }
    });
  });
})();
