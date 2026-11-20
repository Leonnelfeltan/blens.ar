document.addEventListener('submit', e => {
  const f = e.target;
  if (f.enctype === 'multipart/form-data') { const b = f.querySelector('button:not(.g)'); if (b) setTimeout(() => { b.disabled = true; b.textContent = 'Guardando… no cierres la página'; }, 0); }
});
document.addEventListener('click', e => { if (e.target.closest('[data-print]')) window.print(); });
document.querySelectorAll('select.tipo-sel').forEach(sel => {
  const m = sel.closest('form').querySelector('.monto-in');
  if (!m) return;
  const fill = () => { const v = sel.selectedOptions[0].dataset.m; if (v && v !== '0') m.value = v; };
  sel.addEventListener('change', fill);
});
document.querySelectorAll('form').forEach(f => {
  const pj = f.querySelector('select[name=proyecto_id]');
  const hd = f.querySelector('input[name=link_hd]');
  if (!pj || !hd) return;
  const upd = () => { hd.required = !(pj.value && pj.value !== '0'); };
  pj.addEventListener('change', upd); upd();
});
const todos = document.getElementById('todos');
if (todos) todos.addEventListener('change', () => document.querySelectorAll('.sel').forEach(c => c.checked = todos.checked));
const cliTabs = document.getElementById('cli-tabs');
if (cliTabs) {
  const tabIds = ['tab-resumen', 'tab-locaciones', 'tab-datos', 'tab-historial'];
  const tabs = Array.from(cliTabs.querySelectorAll('.tab'));
  const panels = Array.from(document.querySelectorAll('.tab-panel'));
  const activarTab = id => {
    const activo = tabIds.includes(id) ? id : 'tab-resumen';
    tabs.forEach(t => { const on = t.getAttribute('href') === '#' + activo; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    panels.forEach(p => p.hidden = p.id !== activo);
  };
  activarTab(location.hash.slice(1));
  window.addEventListener('hashchange', () => activarTab(location.hash.slice(1)));
  cliTabs.addEventListener('click', e => {
    const t = e.target.closest('.tab'); if (!t) return;
    e.preventDefault();
    const id = t.getAttribute('href').slice(1);
    activarTab(id);
    if (location.hash !== '#' + id) location.hash = id;
  });
}
document.querySelectorAll('[data-loc-estado]').forEach(sel => sel.addEventListener('change', () => { if (sel.form) sel.form.requestSubmit(); }));
document.addEventListener('click', e => {
  const b = e.target.closest('[data-confirm]');
  if (b && !confirm(b.dataset.confirm || '¿Confirmar?')) e.preventDefault();
});
document.addEventListener('click', e => {
  const t = e.target.closest('[data-toggle]');
  if (t) {
    const box = document.querySelector(t.dataset.toggle);
    if (box && 'open' in box) { box.open = !box.open; if (box.open) box.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  }
  const d = e.target.closest('[data-open-detalle]');
  if (d) {
    const box = document.querySelector(d.dataset.openDetalle);
    if (box && 'open' in box) { box.open = true; box.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  }
});
const abrirNuevo = () => { if (location.hash === '#nuevo') { const box = document.getElementById('nuevo'); if (box) box.open = true; } };
abrirNuevo();
window.addEventListener('hashchange', abrirNuevo);
function waNumero(tel) {
  let d = String(tel || '').replace(/\D+/g, ''); if (!d) return '';
  if (d.startsWith('00')) d = d.slice(2);
  if (d.startsWith('54')) return d.startsWith('549') ? d : '549' + d.slice(2);
  d = d.replace(/^0+/, '');
  if (d.length === 12) for (const a of (d.startsWith('11') ? [2] : [3, 4])) { if (d.slice(a, a + 2) === '15') { d = d.slice(0, a) + d.slice(a + 2); break; } }
  return d.length === 10 ? '549' + d : '';
}
document.querySelectorAll('[data-wa-quick]').forEach(box => {
  const sel = box.querySelector('[data-wa-q-mode]');
  const link = box.querySelector('[data-wa-q-link]');
  if (!sel || !link) return;
  sel.addEventListener('change', () => {
    const msg = box.dataset['waMsg' + sel.value.charAt(0).toUpperCase() + sel.value.slice(1)] || '';
    const n = waNumero(box.dataset.tel || '');
    if (n) link.href = 'https://wa.me/' + n + '?text=' + encodeURIComponent(msg);
  });
});

// ---- Imágenes para WhatsApp: tarjeta de acceso, ticket de cobro y recibo ----
function bajar(b, nombre) { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = nombre; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); }
function aviso(m) {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = m; t.classList.add('on'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('on'), 8000);
}
function envolver(g, txt, maxW, maxLin) {
  const pal = String(txt).split(/\s+/), out = []; let l = '';
  for (const p of pal) { const t = l ? l + ' ' + p : p; if (g.measureText(t).width <= maxW || !l) l = t; else { out.push(l); l = p; } }
  if (l) out.push(l);
  if (out.length > maxLin) { out.length = maxLin; let u = out[maxLin - 1]; while (u.length > 1 && g.measureText(u + '…').width > maxW) u = u.slice(0, -1); out[maxLin - 1] = u + '…'; }
  return out;
}
function layoutDoc(g, d, logo, W, H) {
  const P = 70, F = 'system-ui, "Segoe UI", Arial, sans-serif'; let y = 70;
  if (H) { g.fillStyle = '#ffffff'; g.fillRect(0, 0, W, H); }
  g.textBaseline = 'alphabetic'; g.textAlign = 'left';
  if (d.prueba) {
    g.save(); g.setLineDash([10, 8]); g.strokeStyle = '#b3300f'; g.lineWidth = 3; g.fillStyle = '#fde8e0'; g.fillRect(P, y, W - 2 * P, 70); g.strokeRect(P, y, W - 2 * P, 70); g.restore();
    g.fillStyle = '#b3300f'; g.font = '700 28px ' + F; g.textAlign = 'center'; g.fillText(d.pruebaTxt || 'DE PRUEBA · sin valor', W / 2, y + 46); g.textAlign = 'left'; y += 110;
  }
  g.fillStyle = '#111111'; g.font = '700 54px ' + F; g.fillText(d.titulo, P, y + 50);
  g.fillStyle = '#555555'; g.font = '400 28px ' + F; g.fillText('Fecha: ' + d.fecha, P, y + 96);
  if (d.estado) { g.font = '700 26px ' + F; const w = g.measureText(d.estado).width + 36; g.strokeStyle = '#FF5A1F'; g.lineWidth = 2; g.strokeRect(P, y + 118, w, 48); g.fillStyle = '#b3300f'; g.fillText(d.estado, P + 18, y + 152); }
  if (logo) g.drawImage(logo, W - P - 340, y - 4, 340, 340 * 130 / 640);
  g.fillStyle = '#555555'; g.font = '400 22px ' + F; g.textAlign = 'right'; g.fillText('Contenido audiovisual para empresas', W - P, y + 100); g.textAlign = 'left';
  y += d.estado ? 200 : 150;
  g.fillStyle = '#FF5A1F'; g.fillRect(P, y, W - 2 * P, 5); y += 55;
  const linea = (et, val) => { g.font = '700 28px ' + F; g.fillStyle = '#111111'; g.fillText(et, P, y); const w = g.measureText(et + ' ').width; g.font = '400 28px ' + F; const ls = envolver(g, val, W - 2 * P - w, 2); ls.forEach((t, i) => g.fillText(t, P + w, y + i * 36)); y += ls.length * 36 + 14; };
  linea(d.cLabel || 'Cliente:', d.cliente); if (d.periodo) linea('Período:', d.periodo);
  y += 30;
  const xC = 650, xP = 850, xS = W - P;
  g.fillStyle = '#555555'; g.font = '700 22px ' + F; g.fillText('DETALLE', P, y); g.textAlign = 'right'; g.fillText('CANT.', xC, y); g.fillText('PRECIO', xP, y); g.fillText('SUBTOTAL', xS, y); g.textAlign = 'left';
  y += 20; g.fillStyle = '#dddddd'; g.fillRect(P, y, W - 2 * P, 2);
  d.items.forEach(it => {
    g.font = '400 28px ' + F; const ls = envolver(g, it.d, xC - 90 - P, 2), h = ls.length * 36 + 30;
    g.fillStyle = '#111111'; ls.forEach((t, i) => g.fillText(t, P, y + 42 + i * 36));
    g.textAlign = 'right'; g.fillText(String(it.c), xC, y + 42); g.fillText(it.p, xP, y + 42); g.fillText(it.s, xS, y + 42); g.textAlign = 'left';
    y += h; g.fillStyle = '#dddddd'; g.fillRect(P, y, W - 2 * P, 2);
  });
  y += 75; g.fillStyle = '#111111'; g.font = '700 46px ' + F; g.textAlign = 'right'; g.fillText(d.totalLabel + ': ' + d.total, W - P, y); g.textAlign = 'left';
  if (d.medio) { y += 60; g.font = '400 28px ' + F; g.fillText('Forma de pago: ' + d.medio, P, y); }
  y += 60; g.fillStyle = '#777777'; g.font = '400 22px ' + F; const lp = envolver(g, d.pie, W - 2 * P, 3); lp.forEach((t, i) => g.fillText(t, P, y + i * 30)); y += lp.length * 30;
  return y + 60;
}
function docBlob(d) {
  return new Promise(res => {
    const W = 1080, im = new Image();
    const ir = logo => { const H = layoutDoc(document.createElement('canvas').getContext('2d'), d, logo, W, 0); const cv = document.createElement('canvas'); cv.width = W; cv.height = H; layoutDoc(cv.getContext('2d'), d, logo, W, H); cv.toBlob(res, 'image/png'); };
    im.onload = () => ir(im); im.onerror = () => ir(null); im.src = d.logo;
  });
}
function tarjetaBlob() {
  return new Promise(res => {
    const d = document.getElementById('acceso').dataset, W = 1080, H = 1350, P = 90;
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H; const g = cv.getContext('2d');
    const F = 'Sora, Inter, system-ui, sans-serif', M = 'ui-monospace, Consolas, monospace';
    const dibujar = logo => {
      g.fillStyle = '#14110F'; g.fillRect(0, 0, W, H);
      g.fillStyle = '#FF5A1F'; g.fillRect(0, 0, 18, H);
      if (logo) g.drawImage(logo, P, 100, 520, 520 * 130 / 640);
      g.fillStyle = '#F5F1EA'; g.font = '700 70px ' + F; g.fillText('Tu acceso al portal', P, 330);
      g.fillStyle = '#a39d93'; g.font = '400 34px ' + F; g.fillText('Mirá y descargá tus entregas desde', P, 395); g.fillText('cualquier dispositivo.', P, 440);
      const fila = (et, val, y, col, tam) => { g.fillStyle = '#a39d93'; g.font = '600 28px ' + F; g.fillText(et.toUpperCase(), P, y); g.fillStyle = col; g.font = '600 ' + tam + 'px ' + M; g.fillText(val, P, y + 62); };
      fila('Portal', d.url.replace(/^https?:\/\//, ''), 560, '#F5F1EA', 46);
      fila('Usuario', d.u, 730, '#F5F1EA', 56);
      fila('Clave inicial', d.k, 900, '#FF5A1F', 72);
      g.fillStyle = '#a39d93'; g.font = '400 30px ' + F;
      g.fillText('La clave inicial vale 7 días. Al entrar,', P, 1120); g.fillText('vas a elegir la tuya.', P, 1162);
      g.fillStyle = '#F5F1EA'; g.font = '600 34px ' + F; g.fillText('blens.ar', P, H - 90);
      cv.toBlob(res, 'image/png');
    };
    const img = new Image(); img.onload = () => dibujar(img); img.onerror = () => dibujar(null);
    img.src = '../assets/brand/blens_logo-horizontal_color_fondo-oscuro.svg';
  });
}
async function enviarWA(b) {
  const url = b.dataset.wa || '', txt = b.dataset.txt || '', nombre = (b.dataset.nombre || 'imagen') + '.png';
  const mk = () => b.dataset.img === 'tarjeta' ? tarjetaBlob() : docBlob(JSON.parse(b.dataset.doc));
  if (matchMedia('(pointer:coarse)').matches && navigator.canShare) {
    try {
      const f = new File([await mk()], nombre, { type: 'image/png' });
      if (navigator.canShare({ files: [f] })) { await navigator.share({ files: [f], text: txt }); return; }
    } catch (e) { if (e && e.name === 'AbortError') return; }
  }
  const w = url ? window.open('about:blank', '_blank') : null;
  let ok = false;
  try { await navigator.clipboard.write([new ClipboardItem({ 'image/png': mk() })]); ok = true; } catch (e) { try { bajar(await mk(), nombre); } catch (e2) {} }
  if (w) w.location.href = url;
  aviso(ok ? (url ? 'Imagen copiada. En el chat de WhatsApp apretá Ctrl+V para adjuntarla.' : 'Imagen copiada. Pegala (Ctrl+V) en el chat que quieras.') : 'Se descargó la imagen. Adjuntala en el chat de WhatsApp.');
}
document.addEventListener('click', e => {
  const c = e.target.closest('[data-copiar]');
  if (c && navigator.clipboard) navigator.clipboard.writeText(c.dataset.copiar).then(() => { const t = c.textContent; c.textContent = '¡Copiado!'; setTimeout(() => c.textContent = t, 1500); });
  if (e.target.closest('[data-tarjeta]')) tarjetaBlob().then(b => bajar(b, 'acceso-' + document.getElementById('acceso').dataset.u + '.png'));
  const w = e.target.closest('[data-wa-img]'); if (w) enviarWA(w);
});

// ---- Proyectos: nombre del archivo, arrastrar y soltar, barra de progreso y recarga mientras procesa ----
const pform = document.getElementById('pform');
if (pform) {
  const inp = pform.querySelector('input[name=mp4]'), drop = pform.querySelector('.drop'), fn = document.getElementById('fname');
  if (inp && drop) {
    inp.addEventListener('change', () => { fn.textContent = inp.files[0] ? inp.files[0].name + ' · ' + (inp.files[0].size / 1048576).toFixed(1) + ' MB' : ''; });
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('on'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('on'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; inp.dispatchEvent(new Event('change')); } });
  }
  pform.addEventListener('submit', e => {
    e.preventDefault(); e.stopPropagation();
    const btn = document.getElementById('gb'), prog = document.getElementById('prog'), bar = prog.querySelector('i'), txt = prog.querySelector('span');
    const fail = m => { btn.disabled = false; prog.hidden = true; bar.style.width = '0'; aviso(m); };
    const x = new XMLHttpRequest(); x.open('POST', location.pathname); x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    btn.disabled = true; prog.hidden = false; txt.textContent = 'Enviando…';
    x.upload.onprogress = ev => { if (ev.lengthComputable) { const p = Math.round(ev.loaded / ev.total * 100); bar.style.width = p + '%'; txt.textContent = p < 100 ? 'Subiendo… ' + p + '%' : 'Guardando…'; } };
    x.onload = () => { let j = null; try { j = JSON.parse(x.responseText); } catch (er) {} if (j && j.ok) { location.href = j.to; } else { fail(j && j.error ? j.error : 'No se pudo guardar. Revisá los datos e intentá de nuevo.'); } };
    x.onerror = () => fail('Se cortó la conexión. Intentá de nuevo.');
    x.send(new FormData(pform));
  });
}
document.querySelectorAll('[data-unpublish]').forEach(b => b.addEventListener('click', async () => {
  if (!window.confirm('¿Desvincular la entrega de este proyecto?')) return;
  const fd = new FormData();
  fd.append('a', 'desvincular_entrega');
  fd.append('t', b.dataset.token || '');
  fd.append('proyecto_id', b.dataset.proyecto || '');
  fd.append('entrega_id', b.dataset.entrega || '');
  try {
    const res = await fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    let j = null; try { j = await res.json(); } catch (er) {}
    if (j && j.ok) location.href = j.to;
    else aviso(j && j.error ? j.error : 'No se pudo desvincular la entrega.');
  } catch (er) { aviso('No se pudo desvincular la entrega.'); }
}));
// ---- Autocompletar el título con el nombre del archivo de video ----
document.querySelectorAll('form').forEach(f => {
  const file = f.querySelector('input[type=file][name=mp4]');
  const titulo = f.querySelector('input[name=titulo]');
  if (!file || !titulo) return;
  const completar = () => {
    const fl = file.files && file.files[0];
    if (!fl || titulo.value.trim() !== '') return;
    titulo.value = fl.name.replace(/\.[^./\\]+$/, '').trim();
  };
  file.addEventListener('change', completar);
});
// ---- Portfolio: reordenar arrastrando las tarjetas y guardar el orden en MySQL ----
const portfolio = document.getElementById('portfolio-list');
if (portfolio) {
  let dragged = null;
  const cards = () => Array.from(portfolio.querySelectorAll(':scope > .pi[data-id]'));
  const guardarOrden = async () => {
    const body = new URLSearchParams();
    body.set('a', 'reorder');
    body.set('t', portfolio.dataset.t || '');
    cards().forEach(c => body.append('ids[]', c.dataset.id));
    try {
      const r = await fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'No se pudo guardar el orden.');
    } catch (err) {
      aviso('No se pudo guardar el orden. Recargá la página e intentá de nuevo.');
    }
  };
  portfolio.addEventListener('dragstart', e => {
    const c = e.target.closest('.pi[data-id]');
    if (!c || e.target.closest('a,button,input,select,textarea,label,form')) { e.preventDefault(); return; }
    dragged = c; c.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', c.dataset.id); } catch (_) { }
  });
  portfolio.addEventListener('dragend', () => { if (dragged) dragged.classList.remove('dragging'); dragged = null; });
  portfolio.addEventListener('dragover', e => {
    if (!dragged) return;
    e.preventDefault(); e.dataTransfer.dropEffect = 'move';
    const t = e.target.closest('.pi[data-id]');
    if (!t || t === dragged) return;
    const r = t.getBoundingClientRect();
    portfolio.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? t.nextSibling : t);
  });
  portfolio.addEventListener('drop', e => {
    if (!dragged) return;
    e.preventDefault(); guardarOrden();
  });
}
document.querySelectorAll('[data-importar]').forEach(b => b.addEventListener('click', () => {
  const sel = b.getAttribute('data-importar');
  const dlg = sel ? document.querySelector(sel) : document.getElementById('imp');
  if (!dlg) return;
  if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
}));
document.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', () => {
  const dlg = b.closest('dialog');
  if (dlg) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); return; }
  const box = b.closest('details');
  if (box) box.open = false;
}));
const procReloadKey = 'blens_proc_reloads';
if (document.querySelector('[data-proc]')) {
  const n = parseInt(sessionStorage.getItem(procReloadKey) || '0', 10) || 0;
  if (n < 15) { sessionStorage.setItem(procReloadKey, String(n + 1)); setTimeout(() => location.reload(), 8000); }
  else aviso('El procesamiento sigue en marcha. Dejá la página abierta y volvé a revisar en unos minutos.');
} else sessionStorage.removeItem(procReloadKey);

// ---- Cobros: habilitar el saldo cuando hay algún importe cobrado ----
const cobroEstado = document.querySelector('[data-estado]');
const cobroSumar = document.querySelector('[data-sumar]');
if (cobroEstado && cobroSumar) {
  const updCobro = () => { const pendiente = cobroEstado.value === 'pendiente'; cobroSumar.disabled = pendiente; cobroSumar.checked = !pendiente; };
  cobroEstado.addEventListener('change', updCobro); updCobro();
}
const finNum = v => {
  let s = String(v || '').trim().replace(/\s/g, '');
  if (s.includes(',') && s.includes('.')) s = s.lastIndexOf(',') > s.lastIndexOf('.') ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
  else if (s.includes(',')) s = s.replace(',', '.');
  const n = parseFloat(s);
  return Number.isFinite(n) ? Math.max(0, n) : 0;
};
const finFmt = n => '$ ' + n.toLocaleString('es-AR', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
document.querySelectorAll('[data-fin-form]').forEach(f => {
  const monto = f.querySelector('[data-fin-monto]');
  const cantidad = f.querySelector('[data-fin-cantidad]');
  const estado = f.querySelector('[data-fin-estado]');
  const senia = f.querySelector('[data-fin-senia]');
  const seniaWrap = f.querySelector('[data-fin-senia-wrap]');
  const totalOut = f.querySelector('[data-fin-total]');
  const saldoOut = f.querySelector('[data-fin-saldo]');
  if (!monto || !estado) return;
  const calc = () => {
    const total = finNum(monto.value) * (cantidad ? finNum(cantidad.value) || 1 : 1);
    const modo = estado.value;
    let saldo = total;
    if (modo === 'cobrado') { saldo = 0; if (senia) senia.value = total.toFixed(2); }
    else if (modo === 'pendiente') { if (senia) senia.value = '0'; }
    else saldo = Math.max(0, total - finNum(senia ? senia.value : 0));
    if (seniaWrap) seniaWrap.hidden = modo !== 'seniado';
    if (senia) senia.disabled = modo !== 'seniado';
    if (totalOut) totalOut.textContent = finFmt(total);
    if (saldoOut) saldoOut.textContent = finFmt(saldo);
  };
  [monto, cantidad, estado, senia].forEach(el => { if (el) { el.addEventListener('input', calc); el.addEventListener('change', calc); } });
  calc();
});
document.querySelectorAll('[data-crear-trabajo]').forEach(chk => {
  const box = chk.closest('form')?.querySelector('[data-crear-opts]');
  if (!box) return;
  const upd = () => { box.hidden = !chk.checked; };
  chk.addEventListener('change', upd); upd();
});
const movForm = document.querySelector('.mov-form');
if (movForm) {
  const mode = movForm.querySelector('[data-mov-mode]');
  const action = movForm.querySelector('[data-mov-a]');
  const label = movForm.querySelector('[data-mov-label]');
  const submit = movForm.querySelector('[data-mov-submit]');
  const cero = movForm.querySelector('[data-mov-cero]');
  const updMov = () => {
    const trabajo = mode.value === 'trabajo';
    movForm.querySelectorAll('[data-mov-only]').forEach(x => x.hidden = x.dataset.movOnly !== mode.value);
    if (action) action.value = trabajo ? 'trabajo' : 'cobro_rapido';
    if (label) label.textContent = trabajo ? 'Precio unitario ($) * obligatorio' : 'Monto total ($) * obligatorio';
    if (submit) submit.textContent = trabajo ? 'Registrar trabajo' : 'Registrar cobro';
    if (cero) cero.hidden = !trabajo;
    if (trabajo) { const tipo = movForm.querySelector('select.tipo-sel'); if (tipo) tipo.dispatchEvent(new Event('change')); }
  };
  mode.addEventListener('change', updMov); updMov();
}
// ---- Dashboard: cambiar de mes al elegir otro ----
document.querySelectorAll('.msel input[type=month]').forEach(inp => inp.addEventListener('change', () => { if (inp.form) inp.form.submit(); }));
document.querySelectorAll('[data-open-finanzas]').forEach(b => b.addEventListener('click', () => {
  const box = document.querySelector('.dash .accd'); if (!box) return;
  box.open = true;
  box.scrollIntoView({ block: 'start', behavior: 'smooth' });
  const input = box.querySelector('input[name=detalle]') || box.querySelector('input[name=concepto]');
  if (input) setTimeout(() => input.focus(), 150);
}));
const pfSearch = document.getElementById('portfolio-search');
const pfList = document.getElementById('portfolio-list');
const pfFilters = document.getElementById('portfolio-filters');
if (pfList && pfFilters) {
  let filtro = 'all';
  const aplicar = () => {
    const q = (pfSearch ? pfSearch.value : '').trim().toLocaleLowerCase('es-AR');
    let visibles = 0;
    pfList.querySelectorAll(':scope > .pi[data-id]').forEach(card => {
      const texto = (card.dataset.search || '').toLocaleLowerCase('es-AR');
      const okQ = !q || texto.includes(q);
      const okF = filtro === 'all' || (filtro === 'visible' && card.dataset.visible === '1') || (filtro === 'hidden' && card.dataset.visible === '0') || (filtro.indexOf('cat:') === 0 && card.dataset.cat === filtro.slice(4));
      const ok = okQ && okF;
      card.hidden = !ok;
      if (ok) visibles++;
    });
    const empty = document.getElementById('portfolio-empty');
    if (empty) empty.hidden = visibles > 0;
  };
  if (pfSearch) pfSearch.addEventListener('input', aplicar);
  pfFilters.addEventListener('click', e => {
    const b = e.target.closest('.pill'); if (!b) return;
    pfFilters.querySelectorAll('.pill').forEach(x => x.classList.toggle('on', x === b));
    filtro = b.dataset.filter || 'all'; aplicar();
  });
}

// ---- Presupuestos: filas dinámicas, subtotales y total ----
const presuForm = document.getElementById('presu-form');
if (presuForm) {
  const wrap = document.getElementById('presu-items');
  const tpl = document.getElementById('presu-item-template');
  const add = document.getElementById('presu-add');
  let next = 1;
  const money = n => { const v = Math.round((Number(n) || 0) * 100) / 100; return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: Number.isInteger(v) ? 0 : 2, maximumFractionDigits: 2 }); };
  const upd = row => {
    const c = parseFloat(row.querySelector('input[name*="[c]"]').value) || 0;
    const p = parseFloat(row.querySelector('input[name*="[p]"]').value) || 0;
    const s = Math.round(c * p * 100) / 100;
    const el = row.querySelector('[data-presu-subtotal]');
    if (el) el.textContent = money(s);
    return s;
  };
  const total = () => {
    let t = 0;
    wrap.querySelectorAll('.presu-item').forEach(r => { t += upd(r); });
    const el = presuForm.querySelector('[data-presu-total]');
    if (el) el.textContent = money(t);
  };
  const fillRow = (row, item) => {
    if (!row) return;
    const set = (selector, value) => { const input = row.querySelector(selector); if (input) input.value = String(value ?? ''); };
    set('input[name*="[d]"]', item.d);
    set('input[name*="[c]"]', item.c);
    set('input[name*="[p]"]', item.p);
  };
  const plantillasBox = presuForm.querySelector('[data-presu-templates]');
  let plantillasPresu = {};
  try { plantillasPresu = JSON.parse(plantillasBox?.dataset.presuTemplates || '{}'); } catch (error) { plantillasPresu = {}; }
  document.querySelectorAll('[data-presu-template]').forEach(button => {
    button.addEventListener('click', () => {
      const plantilla = plantillasPresu[button.dataset.presuTemplate];
      if (!plantilla || !tpl) return;
      const filas = Array.isArray(plantilla.items) ? plantilla.items : [];
      wrap.innerHTML = '';
      filas.forEach(item => {
        wrap.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(next++)));
        fillRow(wrap.lastElementChild, item);
      });
      const unidad = presuForm.querySelector('select[name="unidad_negocio"]');
      if (unidad) unidad.value = plantilla.unidad_negocio || 'audiovisual';
      const condiciones = presuForm.querySelector('textarea[name="condiciones"]');
      if (condiciones) condiciones.value = plantilla.condiciones || '';
      total();
    });
  });
  if (add) add.addEventListener('click', () => {
    if (!tpl) return;
    wrap.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(next++)));
    total();
  });
  wrap.addEventListener('input', e => { if (e.target.matches('input')) total(); });
  wrap.addEventListener('click', e => {
    const b = e.target.closest('.presu-del');
    if (!b) return;
    const row = b.closest('.presu-item');
    if (wrap.querySelectorAll('.presu-item').length <= 1) {
      row.querySelectorAll('input').forEach(i => {
        if (i.name.includes('[d]')) i.value = '';
        else if (i.name.includes('[c]')) i.value = '1';
        else if (i.name.includes('[p]')) i.value = '';
      });
    } else row.remove();
    total();
  });
  total();
}

(function () {
  const buttons = Array.from(document.querySelectorAll('[data-pwa-install]'));
  if (!buttons.length) return;
  let prompt = null;
  const aviso = msg => {
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
  };
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); prompt = e; });
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  window.addEventListener('appinstalled', () => {
    prompt = null;
    try { localStorage.setItem('blens-admin-pwa-tip', '1'); } catch (_) {}
    aviso('App instalada ✓');
  });
  buttons.forEach(btn => btn.addEventListener('click', async () => {
    if (prompt) {
      await prompt.prompt();
      await prompt.userChoice;
      prompt = null;
      return;
    }
    aviso(ios ? 'Tocá Compartir y luego “Agregar a inicio” para instalar el panel.' : 'Usá el menú del navegador para instalar esta app.');
  }));
})();

(function () {
  const resync = document.querySelector('[data-push-resync]');
  const delay = document.querySelector('[data-push-delay]');
  if (!resync && !delay) return;
  const diag = document.querySelector('[data-push-diag]');
  const botonPush = document.querySelector('[data-push]');
  const aviso = msg => {
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
    box._timer = setTimeout(() => { box.hidden = true; }, 9000);
  };
  const diagnosticar = (info, ok) => {
    const lineas = ['Dominio: ' + (info.host || '-'), 'HTTP: ' + (Number(info.status) || 0)];
    if (info.response) lineas.push('Cuerpo: ' + String(info.response).slice(0, 240));
    if (info.error) lineas.push('Error: ' + String(info.error).slice(0, 240));
    if (info.message) lineas.push('Servidor: ' + String(info.message).slice(0, 240));
    if (diag) { diag.textContent = lineas.join('\n'); diag.hidden = false; }
    aviso(ok ? 'Push de prueba enviado a este dispositivo.' : 'No se pudo enviar. Mirá el diagnóstico.');
  };
  const fallar = err => {
    const msg = err && err.message ? err.message : 'No se pudo sincronizar este dispositivo.';
    if (diag) { diag.textContent = 'Error: ' + msg; diag.hidden = false; }
    aviso(msg);
  };
  if (resync) resync.addEventListener('click', async e => {
    e.stopPropagation();
    const texto = resync.textContent;
    resync.disabled = true;
    resync.setAttribute('aria-busy', 'true');
    resync.textContent = 'Sincronizando…';
    try {
      if (typeof window.blensPushResync !== 'function') throw new Error('No se pudo cargar el módulo de notificaciones.');
      const info = await window.blensPushResync();
      diagnosticar(info, info.ok);
    } catch (err) { fallar(err); }
    finally {
      resync.disabled = false;
      resync.removeAttribute('aria-busy');
      resync.textContent = texto;
    }
  });
  if (delay) delay.addEventListener('click', async e => {
    e.stopPropagation();
    const texto = delay.textContent;
    delay.disabled = true;
    delay.setAttribute('aria-busy', 'true');
    delay.textContent = 'Enviando en 5s…';
    aviso('Bloqueá el celu ahora: el aviso se envía desde el servidor en 5 segundos.');
    try {
      if (typeof window.blensPushEndpoint !== 'function') throw new Error('No se pudo cargar el módulo de notificaciones.');
      const endpoint = await window.blensPushEndpoint();
      if (!endpoint) throw new Error('No hay una suscripción activa. Primero sincronizá este celu.');
      const token = botonPush && botonPush.dataset.pushToken ? botonPush.dataset.pushToken : '';
      const res = await fetch('/api/push-test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, endpoint, delay: 5 }),
      });
      let json = {};
      try { json = await res.json(); } catch (_) {}
      diagnosticar({
        ok: res.ok && json.ok !== false,
        status: Number(json.status || 0),
        host: json.host || endpoint,
        response: String(json.response || ''),
        error: String(json.error || ''),
        message: String(json.message || ''),
      }, res.ok && json.ok !== false);
    } catch (err) { fallar(err); }
    finally {
      delay.disabled = false;
      delay.removeAttribute('aria-busy');
      delay.textContent = texto;
    }
  });
})();

(function () {
  const toggles = Array.from(document.querySelectorAll('[data-more-toggle]'));
  const panel = document.querySelector('[data-more-panel]');
  if (!toggles.length || !panel) return;
  const cerrar = () => {
    panel.hidden = true;
    toggles.forEach(b => b.setAttribute('aria-expanded', 'false'));
  };
  toggles.forEach(btn => btn.addEventListener('click', e => {
    e.stopPropagation();
    const abrir = panel.hidden;
    panel.hidden = !abrir;
    toggles.forEach(b => b.setAttribute('aria-expanded', abrir ? 'true' : 'false'));
  }));
  document.addEventListener('click', e => { if (!panel.hidden && !panel.contains(e.target) && !toggles.some(b => b.contains(e.target))) cerrar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });
  panel.addEventListener('click', e => { if (e.target.closest('a,button,form')) cerrar(); });
})();
