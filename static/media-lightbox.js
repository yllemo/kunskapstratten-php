// Dependency-free image/SVG lightbox with mouse, keyboard and touch controls.
(() => {
  let dialog,stage,canvas,label,zoomLabel,returnFocus,scale=1,x=0,y=0,width=1,height=1;
  const pointers=new Map();
  function draw(){canvas.style.transform=`translate(-50%, -50%) translate(${x}px, ${y}px) scale(${scale})`;zoomLabel.textContent=Math.round(scale*100)+' %';}
  function zoom(value,px=stage.clientWidth/2,py=stage.clientHeight/2){
    const next=Math.min(12,Math.max(.02,value)),ratio=next/scale,cx=px-stage.clientWidth/2,cy=py-stage.clientHeight/2;
    x=cx-(cx-x)*ratio;y=cy-(cy-y)*ratio;scale=next;draw();
  }
  function fit(){if(!dialog.open)return;scale=Math.min((stage.clientWidth-40)/width,(stage.clientHeight-40)/height,2);scale=Math.max(.02,scale);x=y=0;draw();}
  function create(){
    dialog=document.createElement('dialog');dialog.className='media-lightbox';dialog.setAttribute('aria-label','Förstorad bild eller diagram');
    dialog.innerHTML='<div class="media-lightbox-toolbar"><strong class="media-lightbox-title"></strong><span class="media-lightbox-zoom" aria-live="polite"></span><button type="button" data-action="out" aria-label="Zooma ut">−</button><button type="button" data-action="in" aria-label="Zooma in">+</button><button type="button" data-action="fit">Anpassa</button><button type="button" data-action="actual">100 %</button><button type="button" data-action="close" aria-label="Stäng">✕</button></div><p class="media-lightbox-hint">Dra för att panorera · Scrolla eller nyp för att zooma · Esc stänger</p><div class="media-lightbox-stage"><div class="media-lightbox-canvas"></div></div>';
    document.body.append(dialog);stage=dialog.querySelector('.media-lightbox-stage');canvas=dialog.querySelector('.media-lightbox-canvas');label=dialog.querySelector('strong');zoomLabel=dialog.querySelector('.media-lightbox-zoom');
    dialog.querySelector('.media-lightbox-toolbar').onclick=e=>{
      const action=e.target.closest('button')?.dataset.action;
      if(action==='in')zoom(scale*1.25);if(action==='out')zoom(scale/1.25);
      if(action==='fit')fit();if(action==='actual'){scale=1;x=y=0;draw();}if(action==='close')dialog.close();
    };
    dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
    dialog.addEventListener('close',()=>{pointers.clear();stage.classList.remove('is-dragging');canvas.replaceChildren();returnFocus?.focus();});
    dialog.addEventListener('keydown',e=>{
      if(e.key==='+'||e.key==='='){zoom(scale*1.25);e.preventDefault();}
      if(e.key==='-'){zoom(scale/1.25);e.preventDefault();}
      if(e.key==='0'){fit();e.preventDefault();}
      const moves={ArrowLeft:[40,0],ArrowRight:[-40,0],ArrowUp:[0,40],ArrowDown:[0,-40]};
      if(moves[e.key]){x+=moves[e.key][0];y+=moves[e.key][1];draw();e.preventDefault();}
    });
    stage.addEventListener('wheel',e=>{e.preventDefault();const r=stage.getBoundingClientRect();zoom(scale*Math.exp(-e.deltaY*.002),e.clientX-r.left,e.clientY-r.top);},{passive:false});
    stage.addEventListener('pointerdown',e=>{if(e.button!==0&&e.pointerType==='mouse')return;pointers.set(e.pointerId,{x:e.clientX,y:e.clientY});stage.setPointerCapture(e.pointerId);stage.classList.add('is-dragging');e.preventDefault();});
    stage.addEventListener('pointermove',e=>{
      if(!pointers.has(e.pointerId))return;
      const old=[...pointers.values()],before=pointers.get(e.pointerId);pointers.set(e.pointerId,{x:e.clientX,y:e.clientY});
      const now=[...pointers.values()];
      if(now.length===2){
        const distance=a=>Math.hypot(a[0].x-a[1].x,a[0].y-a[1].y),d=distance(old);
        if(d>0){const r=stage.getBoundingClientRect();zoom(scale*distance(now)/d,(old[0].x+old[1].x)/2-r.left,(old[0].y+old[1].y)/2-r.top);}
        x+=(now[0].x+now[1].x-old[0].x-old[1].x)/2;y+=(now[0].y+now[1].y-old[0].y-old[1].y)/2;
      }else{x+=e.clientX-before.x;y+=e.clientY-before.y;}draw();
    });
    const release=e=>{pointers.delete(e.pointerId);if(!pointers.size)stage.classList.remove('is-dragging');};
    stage.addEventListener('pointerup',release);stage.addEventListener('pointercancel',release);stage.addEventListener('lostpointercapture',release);
    window.addEventListener('resize',()=>{if(dialog.open)fit();});
  }
  function open(target){
    const media=target.tagName==='IMG'?target:target.querySelector('svg');if(!media)return;
    if(!dialog)create();returnFocus=target;
    canvas.replaceChildren();const clone=media.cloneNode(true);
    label.textContent=media.getAttribute('alt')||(media.tagName==='IMG'?'Bild':'Mermaid-diagram');
    if(media.tagName==='IMG'){
      width=media.naturalWidth||media.width||800;height=media.naturalHeight||media.height||600;
      clone.removeAttribute('loading');clone.onload=()=>{if(!canvas.contains(clone))return;width=clone.naturalWidth||width;height=clone.naturalHeight||height;size();fit();};
    }else{
      const box=media.viewBox?.baseVal;width=box?.width||media.getBoundingClientRect().width||800;height=box?.height||media.getBoundingClientRect().height||600;
      // Keep fragment references valid without duplicating IDs from the page.
      const ids=new Map();[clone,...clone.querySelectorAll('[id]')].filter(node=>node.id).forEach(node=>{const id=node.id;ids.set(id,'lightbox-'+id);node.id='lightbox-'+id;});
      clone.querySelectorAll('*').forEach(node=>{for(const attr of [...node.attributes]){let value=attr.value;for(const [a,b] of ids){value=value.split('url(#'+a+')').join('url(#'+b+')');if(value==='#'+a)value='#'+b;}if(value!==attr.value)node.setAttribute(attr.name,value);}});
      clone.querySelectorAll('style').forEach(style=>{for(const [a,b] of [...ids].sort((a,b)=>b[0].length-a[0].length))style.textContent=style.textContent.split('#'+a).join('#'+b);});
    }
    function size(){canvas.style.width=width+'px';canvas.style.height=height+'px';clone.style.cssText='display:block;width:100%;height:100%;max-width:none;max-height:none;';}
    size();canvas.append(clone);if(!dialog.open)dialog.showModal();fit();dialog.querySelector('[data-action="close"]').focus();
  }
  window.makeMediaZoomable=target=>{
    if(target.dataset.lightboxReady)return;target.dataset.lightboxReady='true';target.classList.add('media-zoomable');
    target.tabIndex=0;target.setAttribute('role','button');target.setAttribute('aria-label',(target.getAttribute('alt')||target.getAttribute('aria-label')||'Diagram')+' – öppna och zooma');
    target.title='Öppna för att zooma och panorera';
    target.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();open(target);});
    target.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open(target);}});
  };
})();
