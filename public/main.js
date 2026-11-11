try{gsap.registerPlugin(ScrollTrigger)}catch(e){console.error('GSAP no cargó',e)}
const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
const RM=matchMedia('(prefers-reduced-motion: reduce)').matches;   // el sistema pide menos movimiento
const reduce=!window.gsap;                                         // sin GSAP no hay animaciones
const soft=RM;                                                     // con menos movimiento: solo aparecen suavemente, sin desplazamientos
const coarse=matchMedia('(hover:none)').matches;                   // celular o tablet
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* ---------- Lazy loading de video ---------- */
const loadVideo=v=>{ if(v.dataset.ready) return; $$('source',v).forEach(s=>s.src=s.dataset.src); v.load(); v.dataset.ready=1; if(v.hasAttribute('data-autoplay')&&!RM) v.play().catch(()=>{}); };
const io=new IntersectionObserver(es=>es.forEach(e=>{ if(e.isIntersecting){ if(!e.target.hasAttribute('data-hover')) loadVideo(e.target); io.unobserve(e.target);} }),{rootMargin:'300px'});
const watch=root=>$$('video[data-lazy]',root).forEach(v=>io.observe(v));
watch(document);

/* ---------- Tarjetas: el video se carga recién al pasar el mouse / tocar ---------- */
function bindCards(){ $$('.card').forEach(c=>{ if(c.dataset.b) return; c.dataset.b=1; const v=$('video',c);
  if(c.classList.contains('nop')){ v.preload='metadata'; $$('source',v).forEach(s=>s.dataset.src+='#t=0.5'); v.removeAttribute('data-hover'); }
  const on=()=>{ loadVideo(v); v.play().catch(()=>{}); }, off=()=>v.pause();
  c.addEventListener('mouseenter',on); c.addEventListener('mouseleave',off);
  c.addEventListener('focusin',on); c.addEventListener('focusout',off); c.tabIndex=0;
}); }
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
const track=$('#grid'), pvB=$('.gnav.prev'), nxB=$('.gnav.next');
function navUpdate(){
  if(!track||!pvB) return;
  const max=track.scrollWidth-track.clientWidth, ov=max>4;
  pvB.hidden=nxB.hidden=!ov; pvB.disabled=track.scrollLeft<4; nxB.disabled=track.scrollLeft>max-4;
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
const dpr=window.devicePixelRatio||1, saver=(navigator.connection||{}).saveData;
const mp4src=u=>u?`<source data-src="${esc(u)}" type="video/mp4">`:'';
const srcCard=p=>mp4src(p.video_480||p.video_mp4);                         // tarjetas: versión liviana
const srcShow=p=>mp4src((!saver&&dpr>=2&&p.video_1080)||p.video_mp4);      // Nosotros: la mejor para esta pantalla
const CAT={reels:'Reels',cobertura:'Cobertura',estrategia:'Estrategia'};
const cardHTML=p=>`<article class="card${p.ancho==1?' wide':''}${p.poster?'':' nop'}" data-cat="${esc(p.categoria)}">${p.poster?`<img loading="lazy" src="${esc(p.poster)}" alt="${esc(p.titulo)}" width="800" height="500">`:''}<video muted loop playsinline preload="none" data-lazy data-hover>${srcCard(p)}</video>${p.id?`<a class="stretch" href="proyecto.php?id=${p.id}" aria-label="Ver proyecto: ${esc(p.titulo)}"></a>`:''}<span class="chip">${esc(CAT[p.categoria]||'')}</span><span class="pl" aria-hidden="true"><svg viewBox="0 0 24 24" width="15" height="15"><path d="M7 4v16l13-8z" fill="currentColor"/></svg></span><div class="meta">${p.cliente?`<span class="cl">${esc(p.cliente)}</span>`:''}<h3>${esc(p.titulo)}</h3>${p.descripcion?`<p>${esc(p.descripcion)}</p>`:''}</div></article>`;
function render(d){
  const g=$('#grid');
  g.innerHTML=d.proyectos.length?d.proyectos.map(cardHTML).join(''):'<p class="mut empty">Pronto sumaremos nuevos casos.</p>';
  bindCards(); watch(g); revealCards(); navUpdate();
  const s=d.showreel;
  if(s&&s.video_mp4){ const V=$('#sr'); V.innerHTML=srcShow(s); V.poster=s.poster||''; delete V.dataset.ready; io.observe(V); }
  if(d.clientes&&d.clientes.length){ $('#logos').innerHTML=d.clientes.map(c=>`<span class="lg"><img loading="lazy" src="media/logos/${esc(c.logo)}" alt="${esc(c.nombre)}"></span>`).join(''); $('#clientes').hidden=false; }
  if(d.wa&&/^\d{8,15}$/.test(d.wa)&&!$('#wa')){ const a=document.createElement('a'); a.id='wa'; a.className='wa'; a.target='_blank'; a.rel='noopener';
    a.href='https://wa.me/'+d.wa+'?text='+encodeURIComponent('Hola, quiero consultar por contenido audiovisual para mi empresa.'); a.setAttribute('aria-label','Escribinos por WhatsApp');
    a.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M3 3h18v14H9l-4 4v-4H3z"/></svg><span>WhatsApp</span>'; document.body.appendChild(a); }
  ScrollTrigger.refresh();
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
  reveal('.h2'); reveal('.svc li'); reveal('.big,.about .mut,.vals li'); reveal('#form > *'); revealCards();
  if(!soft&&!coarse) $$('.mag').forEach(b=>{ const x=gsap.quickTo(b,'x',{duration:.4}), y=gsap.quickTo(b,'y',{duration:.4});
    b.addEventListener('mousemove',e=>{const r=b.getBoundingClientRect();x((e.clientX-r.left-r.width/2)*.25);y((e.clientY-r.top-r.height/2)*.4)});
    b.addEventListener('mouseleave',()=>{x(0);y(0)}); });
}
}catch(e){console.error('animaciones',e)}

/* ---------- Filtros ---------- */
$$('.filters button').forEach(btn=>btn.addEventListener('click',()=>{
  $$('.filters button').forEach(b=>b.classList.toggle('on',b===btn));
  const f=btn.dataset.f, all=$$('.card'), show=all.filter(c=>f==='all'||c.dataset.cat===f);
  all.forEach(c=>c.style.display=show.includes(c)?'':'none');
  track.scrollTo({left:0}); navUpdate();
  if(!reduce) gsap.fromTo(show,{opacity:0,y:24},{opacity:1,y:0,duration:.5,stagger:.08,ease:'power3.out'});
  ScrollTrigger.refresh();
}));

/* ---------- Video de presentación: se pausa al salir de pantalla ---------- */
const V=$('#sr');
ScrollTrigger.create({trigger:'#player',start:'top 15%',end:'bottom 5%',onLeave:()=>V.pause(),onLeaveBack:()=>V.pause()});

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
