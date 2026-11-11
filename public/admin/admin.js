document.addEventListener('submit', e => {
  const f = e.target;
  if (f.enctype === 'multipart/form-data') { const b = f.querySelector('button:not(.g)'); if (b) setTimeout(() => { b.disabled = true; b.textContent = 'Guardando… no cierres la página'; }, 0); }
});
document.addEventListener('click', e => { if (e.target.closest('[data-print]')) window.print(); });
document.querySelectorAll('select.tipo-sel').forEach(sel => {
  const m = sel.closest('form').querySelector('.monto-in');
  const fill = () => { const v = sel.selectedOptions[0].dataset.m; if (v && v !== '0') m.value = v; };
  sel.addEventListener('change', fill); fill();
});
const todos = document.getElementById('todos');
if (todos) todos.addEventListener('change', () => document.querySelectorAll('.sel').forEach(c => c.checked = todos.checked));

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
if (document.querySelector('[data-proc]')) setTimeout(() => location.reload(), 8000);
