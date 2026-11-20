(() => {
  const printButton = document.querySelector('[data-print]');
  if (printButton) printButton.addEventListener('click', () => window.print());
  const form = document.getElementById('presu-accept');
  if (!form) return;
  const button = form.querySelector('[data-presu-accept]');
  const message = document.getElementById('presu-msg');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (button) button.disabled = true;
    if (message) {
      message.className = 'pub-presu-msg';
      message.textContent = 'Procesando…';
    }
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!data.ok) throw new Error(data.message || 'No se pudo aceptar el presupuesto.');
      if (message) {
        message.className = 'pub-presu-msg ok';
        message.textContent = data.mensaje || '¡Listo! Aceptamos tu presupuesto.';
      }
      form.hidden = true;
      document.body.classList.add('presu-aceptado');
    } catch (error) {
      if (message) {
        message.className = 'pub-presu-msg err';
        message.textContent = error.message || 'No se pudo aceptar el presupuesto. Recargá la página e intentá de nuevo.';
      }
      if (button) button.disabled = false;
    }
  });
})();
