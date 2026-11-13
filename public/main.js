document.querySelectorAll('link[data-fonts]').forEach(l=>{ l.media='all'; });
try{gsap.registerPlugin(ScrollTrigger)}catch(e){console.error('GSAP no cargó',e)}
const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
const RM=matchMedia('(prefers-reduced-motion: reduce)').matches;   // el sistema pide menos movimiento
const reduce=!window.gsap;                                         // sin GSAP no hay animaciones
const soft=RM;                                                     // con menos movimiento: solo aparecen suavemente, sin desplazamientos
const coarse=matchMedia('(hover:none)').matches;                   // celular o tablet
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm=s=>String(s??'').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');

/* ---------- WhatsApp: número y mensaje (se reemplazan por los de la API si existen) ---------- */
const WA_NUMERO='5493764588477';                                   // número de WhatsApp (formato internacional, solo dígitos)
const WA_MSG='Hola Blens, quiero consultar por producción de video para mi empresa.';
const waLink=num=>'https://wa.me/'+(num||WA_NUMERO)+'?text='+encodeURIComponent(WA_MSG);

/* ---------- Lazy loading de video ---------- */
const loadVideo=v=>{ if(v.dataset.ready) return; $$('source',v).forEach(s=>s.src=s.dataset.src); v.load(); v.dataset.ready=1; if(v.hasAttribute('data-autoplay')&&!RM) v.play().catch(()=>{}); };
const io=new IntersectionObserver(es=>es.forEach(e=>{ if(e.isIntersecting){ if(!e.target.hasAttribute('data-hover')) loadVideo(e.target); io.unobserve(e.target);} }),{rootMargin:'300px'});
const watch=root=>$$('video[data-lazy]',root).forEach(v=>io.observe(v));
watch(document);

/* ---------- Tarjetas: preview de video al pasar el mouse (solo con hover fino) ---------- */
const fineHover=matchMedia('(hover:hover) and (pointer:fine)').matches;
function bindCards(){
  $$('.card').forEach(c=>{
    if(c.dataset.b) return; c.dataset.b=1;
    const v=$('video',c);
    if(c.classList.contains('nop')){
      // sin póster: el video hace de imagen fija y se reproduce al pasar el mouse
      if(v){ v.preload='metadata'; $$('source',v).forEach(s=>s.dataset.src+='#t=0.5'); v.removeAttribute('data-hover'); }
      const on=()=>{ if(v){ loadVideo(v); v.play().catch(()=>{}); } c.classList.add('playing'); };
      const off=()=>{ if(v) v.pause(); c.classList.remove('playing'); };
      c.addEventListener('mouseenter',on); c.addEventListener('mouseleave',off);
      c.addEventListener('focusin',on); c.addEventListener('focusout',off); c.tabIndex=0;
      return;
    }
    // con póster: el video de muestra se crea/activa recién al pasar el mouse
    const src=c.dataset.vid;
    c.tabIndex=0;
    const on=()=>{
      if(!v||!src) return;
      if(!v.getAttribute('src')){ if(!fineHover) return; v.src=src; v.load(); }  // en la PC se prepara el video; en el celular no
      v.hidden=false;
      c.classList.add('playing');
      v.play().catch(()=>{});
    };
    const off=()=>{
      c.classList.remove('playing');
      if(!v) return;
      v.pause(); try{ v.currentTime=0; }catch(e){}
      v.hidden=true; if(v.getAttribute('src')){ v.removeAttribute('src'); v.load(); }   // se libera la memoria del video
    };
    c.addEventListener('mouseenter',on); c.addEventListener('mouseleave',off);
    c.addEventListener('focusin',on); c.addEventListener('focusout',off);
  });
}
bindCards();

/* ---------- Apariciones al hacer scroll (suaves; no se borran al salir de pantalla) ---------- */
let cardTr=[];
const seen=new WeakSet();
function reveal(sel){
  if(reduce) return [];
  const dy=soft?0:40;
  // lo que está debajo de la pantalla arranca oculto; lo demás aparece enseguida
  $$(sel).forEach(el=>{ if(el.getBoundingClientRect().top>innerHeight*.9) gsap.set(el,{opacity:0,y:dy}); });
  const first=b=>{ b.forEach(e=>seen.add(e)); gsap.fromTo(b,{y:dy,opacity:0},{y:0,opacity:1,duration:.9,stagger:.12,ease:'power3.out',overwrite:'auto'}); };
  const again=b=>{ if(coarse||soft||!b.length) return; gsap.fromTo(b,{y:14,opacity:.6},{y:0,opacity:1,duration:.6,stagger:.06,ease:'power2.out',overwrite:'auto'}); };
  const go=b=>{ const n=b.filter(e=>!seen.has(e)); if(n.length) first(n); again(b.filter(e=>!n.includes(e))); };
  return ScrollTrigger.batch(sel,{start:'top 90%',onEnter:go,onEnterBack:b=>again(b.filter(e=>seen.has(e)))});
}
const revealCards=()=>{ cardTr.forEach(t=>t.kill()); cardTr=reveal('.card'); };

/* ---------- Carrusel de proyectos: 4 a la vista en la PC, 2 en el celular; se desliza ---------- */
const track=$('#grid'), pvB=$('.gnav.prev'), nxB=$('.gnav.next'), swipeHint=$('#swipe-hint');
function navUpdate(){
  if(!track||!pvB) return;
  const max=track.scrollWidth-track.clientWidth, ov=max>4;
  pvB.hidden=nxB.hidden=!ov; pvB.disabled=track.scrollLeft<4; nxB.disabled=track.scrollLeft>max-4;
  if(swipeHint) swipeHint.hidden=!(ov&&innerWidth<=800);
}
if(track&&pvB){
  track.addEventListener('scroll',navUpdate,{passive:true}); addEventListener('resize',navUpdate);
  pvB.onclick=()=>track.scrollBy({left:-track.clientWidth*.92,behavior:'smooth'});
  nxB.onclick=()=>track.scrollBy({left:track.clientWidth*.92,behavior:'smooth'});
  // con el mouse también se puede arrastrar (en el celular se desliza con el dedo)
  let sx=0,sl=0,drag=false,moved=false;
  track.addEventListener('pointerdown',e=>{ if(e.pointerType!=='mouse'||e.button!==0) return; drag=true; moved=false; sx=e.clientX; sl=track.scrollLeft; });
  addEventListener('pointermove',e=>{ if(!drag) return; const dx=e.clientX-sx; if(Math.abs(dx)>6){ moved=true; track.classList.add('drag'); } if(moved) track.scrollLeft=sl-dx; });
  addEventListener('pointerup',()=>{ if(!drag) return; drag=false; track.classList.remove('drag'); setTimeout(()=>{moved=false},0); });
  track.addEventListener('click',e=>{ if(moved){ e.preventDefault(); e.stopPropagation(); } },true);
  track.addEventListener('dragstart',e=>e.preventDefault());
  navUpdate();
}

/* ---------- Contenido editable desde el panel (api.php) ---------- */
const mp4src=u=>u?`<source data-src="${esc(u)}" type="video/mp4">`:'';
const srcCard=p=>mp4src(p.video_480||p.video_mp4);                         // tarjetas: versión liviana para no bajar 1080p
const srcShow=p=>mp4src(p.video_1080||p.video_mp4||p.video_480);             // Nosotros: máxima definición disponible
let CAT={reels:'Reels',cobertura:'Cobertura',estrategia:'Estrategia'};
const catLabel=c=>CAT[c]||c||'';
const cardHTML=p=>{ const vid=p.video_480||p.video_1080||p.video_mp4||''; const cli=p.cliente?String(p.cliente):'';
  const media=p.poster
    ? `<img loading="lazy" src="${esc(p.poster)}" alt="${esc(p.titulo)}" width="800" height="500"><video class="hv" muted loop playsinline preload="none" hidden></video>`
    : `<video class="hv" muted loop playsinline preload="none" data-lazy data-hover>${srcCard(p)}</video>`;
  return `<article class="card${p.ancho==1?' wide':''}${p.poster?'':' nop'}" data-cat="${esc(p.categoria)}" data-cliente="${esc(cli)}" data-vid="${esc(vid)}">${media}${p.id?`<a class="stretch" href="proyecto.php?id=${p.id}" aria-label="Ver proyecto: ${esc(p.titulo)}"></a>`:''}<span class="chip">${esc(catLabel(p.categoria))}</span><span class="pl" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15"><path d="M7 4v16l13-8z" fill="currentColor"/></svg></span><div class="meta">${cli?`<span class="cl">${esc(cli)}</span>`:''}<h3>${esc(p.titulo)}</h3>${p.descripcion?`<p>${esc(p.descripcion)}</p>`:''}</div></article>`; };
function renderFilters(d){
  const box=$('.filters'); if(!box) return;
  const base=['reels','cobertura','estrategia'];
  const found=[...new Set((d.proyectos||[]).map(p=>p.categoria).filter(Boolean))];
  const keys=[...new Set([...base,...found])];
  box.innerHTML=['all',...keys].map(k=>`<button${k==='all'?' class="on"':''} data-f="${esc(k)}">${esc(k==='all'?'Todos':catLabel(k))}</button>`).join('');
}
function renderHeroFeature(d){
  const box=$('#hero-feature'); if(!box) return;
  const s=d.showreel;
  const p=s&&(s.video_480||s.video_mp4||s.video_1080)?s:(d.proyectos||[]).find(x=>x.video_480||x.video_mp4||x.video_1080);
  if(!p){ box.hidden=true; return; }
  const isSr=p===s, src=p.video_480||p.video_mp4||p.video_1080;
  const href=isSr?'#nosotros':(p.id?`proyecto.php?id=${p.id}`:'#proyectos');
  const title=isSr?'Conocé nuestro trabajo':p.titulo;
  const meta=isSr?(p.cliente||'Showreel'):(p.cliente||catLabel(p.categoria));
  box.innerHTML=`<a class="hf-link" href="${esc(href)}"><video muted loop playsinline preload="none" data-lazy data-autoplay${p.poster?` poster="${esc(p.poster)}"`:''}>${mp4src(src)}</video><span class="hf-body"><span class="hf-k">${isSr?'Showreel':'Destacado'}</span><span class="hf-t">${esc(title||'')}</span>${meta?`<span class="hf-m">${esc(meta)}</span>`:''}</span><span class="hf-play" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M7 4v16l13-8z" fill="currentColor"/></svg></span></a>`;
  box.hidden=false;
  watch(box);
}
function render(d){
  if(d.categorias) CAT={...CAT,...d.categorias};
  const g=$('#grid');
  g.innerHTML=d.proyectos.length?d.proyectos.map(cardHTML).join(''):'<p class="mut empty">Pronto sumaremos nuevos casos.</p>';
  renderFilters(d); renderHeroFeature(d);
  bindCards(); watch(g); revealCards(); navUpdate();
  syncCat(); setChip(); applyFilter(false);
  const s=d.showreel, ph=$('.about .phone');
  if(s&&(s.video_1080||s.video_mp4||s.video_480)&&$('#sr')){ const V=$('#sr'); V.innerHTML=srcShow(s); V.poster=s.poster||''; delete V.dataset.ready; io.observe(V); if(ph) ph.hidden=false; }
  else if(ph) ph.hidden=true;
  if(d.clientes&&d.clientes.length){ $('#logos').innerHTML=d.clientes.map(c=>`<button class="lg" type="button" data-client="${esc(c.nombre)}" title="Ver proyectos de ${esc(c.nombre)}" aria-label="Ver proyectos de ${esc(c.nombre)}"><img loading="lazy" src="media/logos/${esc(c.logo)}" alt="${esc(c.nombre)}" width="180" height="80"></button>`).join(''); $('#clientes').hidden=false; }
  const waNum=/^\d{8,15}$/.test(d.wa||'')?d.wa:WA_NUMERO;
  $$('[data-wa]').forEach(a=>{ a.href=waLink(waNum); });
  if(/^\d{8,15}$/.test(d.wa||'')&&!$('#wa')){ const a=document.createElement('a'); a.id='wa'; a.className='wa'; a.target='_blank'; a.rel='noopener';
    a.href=waLink(d.wa); a.setAttribute('aria-label','Escribinos por WhatsApp');
    a.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M3 3h18v14H9l-4 4v-4H3z"/></svg><span>WhatsApp</span>'; document.body.appendChild(a); }
  if(window.ScrollTrigger) ScrollTrigger.refresh();
}
fetch('api.php',{headers:{Accept:'application/json'}}).then(r=>r.ok&&/json/.test(r.headers.get('content-type')||'')?r.json():Promise.reject()).then(render).catch(()=>{ const g=$('#grid'); if(g) g.innerHTML='<p class="mut empty">No pudimos cargar los proyectos. Recargá la página en unos segundos.</p>'; });

/* ---------- Header ---------- */
try{ScrollTrigger.create({start:80,onUpdate:s=>$('#nav').classList.toggle('solid',s.scroll()>80)})}catch(e){console.error(e)}

/* ---------- Animaciones ---------- */
try{ if(!reduce){
  $$('.ln').forEach(l=>l.innerHTML=`<span>${l.textContent}</span>`);
  const tl=gsap.timeline({defaults:{ease:'power4.out'}});
  if(soft){ tl.from('.frames,.ln>span,.lead,.cta',{opacity:0,duration:1,stagger:.15,ease:'power2.out'}); }
  else{
    tl.from('.f1',{x:-30,y:-30,opacity:0,duration:1.1})
      .from('.f2',{x:30,y:30,opacity:0,duration:1.1},'<')
      .from('.f3',{opacity:0,scale:.5,svgOrigin:'72 85',duration:.7},'-=.25')
      .from('.ln>span',{yPercent:110,duration:1,stagger:.12},'-=.9')
      .from('.lead,.cta',{y:20,opacity:0,duration:.8,stagger:.1},'-=.5');
    gsap.to('.frames',{y:-80,scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:true}});
    // Hero: en la PC se vuelve a armar al regresar arriba (en el celular no, para que no moleste)
    if(!coarse) ScrollTrigger.create({trigger:'.hero',start:'bottom 75%',end:'bottom 25%',onEnterBack:()=>tl.restart()});
  }
  // Secciones: entran cada vez que aparecen, subiendo o bajando
  reveal('.h2'); reveal('.svc li'); reveal('.big,.about .mut,.vals li'); reveal('#form > *'); reveal('.steps li'); revealCards();
  if(!soft&&!coarse) $$('.mag').forEach(b=>{ const x=gsap.quickTo(b,'x',{duration:.4}), y=gsap.quickTo(b,'y',{duration:.4});
    b.addEventListener('mousemove',e=>{const r=b.getBoundingClientRect();x((e.clientX-r.left-r.width/2)*.25);y((e.clientY-r.top-r.height/2)*.4)});
    b.addEventListener('mouseleave',()=>{x(0);y(0)}); });
}
}catch(e){console.error('animaciones',e)}

/* ---------- Filtros (por categoría y por cliente) ---------- */
const state={cat:'all',client:null};
const chip=$('#client-chip');
function setChip(){
  if(!chip) return;
  if(state.client){ chip.hidden=false; chip.innerHTML=`Cliente: ${esc(state.client)}<span class="x" aria-hidden="true">×</span>`; chip.setAttribute('aria-label',`Quitar el filtro de cliente: ${state.client}`); }
  else{ chip.hidden=true; chip.textContent=''; chip.removeAttribute('aria-label'); }
}
function syncCat(){
  $$('.filters button').forEach(b=>b.classList.toggle('on',b.dataset.f===state.cat));
}
function applyFilter(animate=true){
  const g=$('#grid'); if(!g) return;
  const all=$$('.card',g), cn=state.client?norm(state.client):'';
  const show=all.filter(c=>{
    if(state.cat!=='all'&&c.dataset.cat!==state.cat) return false;
    if(cn){ const cl=norm(c.dataset.cliente); if(!(cl&&(cl===cn||cl.includes(cn)||cn.includes(cl)))) return false; }
    return true;
  });
  all.forEach(c=>{ c.style.display=show.includes(c)?'':'none'; });
  const old=g.querySelector('.empty'); if(old) old.remove();
  if(!show.length){ const p=document.createElement('p'); p.className='mut empty';
    p.textContent=state.client?`Todavía no publicamos proyectos de ${state.client}.`:'Pronto sumaremos nuevos casos.'; g.appendChild(p); }
  if(track){ try{ track.scrollTo({left:0}); }catch(e){} } navUpdate();
  if(animate&&!reduce) gsap.fromTo(show,{opacity:0,y:24},{opacity:1,y:0,duration:.5,stagger:.08,ease:'power3.out'});
  if(window.ScrollTrigger) ScrollTrigger.refresh();
}
const filters=$('.filters');
if(filters) filters.addEventListener('click',e=>{
  const btn=e.target.closest('button[data-f]'); if(!btn) return;
  state.cat=btn.dataset.f; syncCat(); applyFilter();
});
if(chip) chip.addEventListener('click',()=>{ state.client=null; setChip(); applyFilter(); });

/* ---------- Los logos de clientes filtran la galería ---------- */
const logosBox=$('#logos');
if(logosBox) logosBox.addEventListener('click',e=>{
  const b=e.target.closest('[data-client]'); if(!b) return;
  const name=b.dataset.client||'';
  state.client=(state.client&&norm(state.client)===norm(name))?null:name;   // clic de nuevo: quita el filtro
  state.cat='all'; syncCat(); setChip(); applyFilter();
  const sec=$('#proyectos');
  if(sec) sec.scrollIntoView({behavior:'smooth',block:'start'});
});

/* ---------- Video de presentación: se pausa al salir de pantalla ---------- */
const V=$('#sr');
if(V&&window.ScrollTrigger) ScrollTrigger.create({trigger:'#player',start:'top 15%',end:'bottom 5%',onLeave:()=>V.pause(),onLeaveBack:()=>V.pause()});

/* ---------- Formulario ---------- */
const form=$('#form'), st=$('#status'); $('#ts').value=Date.now();
form.addEventListener('submit',async e=>{
  e.preventDefault();
  if(!form.checkValidity()){ st.className='err'; st.textContent='Completá nombre, email y mensaje con datos válidos.'; form.reportValidity(); return; }
  const btn=$('button[type=submit]',form); btn.disabled=true; st.className=''; st.textContent='Enviando…';
  try{
    const r=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});
    const j=await r.json();
    st.className=j.ok?'ok':'err'; st.textContent=j.message;
    if(j.ok){ form.reset(); $('#ts').value=Date.now(); }
  }catch{ st.className='err'; st.textContent='No pudimos enviar la consulta. Probá de nuevo en unos minutos.'; }
  btn.disabled=false;
});
