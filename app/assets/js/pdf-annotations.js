const {buildInkGrid,rectFits,findWhiteSpace,expandSourceRegion}=await import('./pdf-annotation-layout.js?v='+document.body.dataset.layoutVersion);

export function installPDFNotes({host,getPage,go}){
  const t=window.BetterNotebookI18n.t,source=new URL(document.body.dataset.sourceUrl,location.href),sourceId=source.searchParams.get('source_id'),noteId=Number(source.searchParams.get('note_id'));
  const generate=document.getElementById('annotate'),toggle=document.getElementById('annotation-toggle'),reload=document.getElementById('annotation-reload'),saveButton=document.getElementById('annotation-save');
  const status=document.getElementById('annotation-status'),nav=document.getElementById('annotation-controls');
  const docs=new Map(),pending=new Map(),active=new Map(),placements=new Map(),grids=new WeakMap(),sourceImages=new WeakMap(),saveTimers=new Map(),saving=new Set();let stamp='',busy=false,shown=true,regionEdit=null,lastPage=0;
  let loadingTimer=null;
  function stopLoading(){clearInterval(loadingTimer);loadingTimer=null;status.classList.remove('is-loading');}
  const message=text=>{if(busy&&status.classList.contains('is-loading'))return;stopLoading();status.textContent=t(text);status.hidden=!text;};
  function showLoading(page){
    stopLoading();status.replaceChildren();status.hidden=false;status.classList.add('is-loading');
    const card=document.createElement('div');card.className='pdf-ai-loading';
    const spinner=document.createElement('span');spinner.className='pdf-ai-spinner';spinner.setAttribute('aria-hidden','true');
    const copy=document.createElement('div');copy.className='pdf-ai-loading-copy';
    const title=document.createElement('strong');title.textContent=t('Çalışma notların hazırlanıyor');
    const detail=document.createElement('span');detail.textContent=t('Açıklamalar ve kaynak işaretleri oluşturuluyor.');copy.append(title,detail);
    const meta=document.createElement('div');meta.className='pdf-ai-loading-meta';meta.setAttribute('aria-hidden','true');
    const pageLabel=document.createElement('span');pageLabel.textContent=page+t('. sayfa');
    const elapsed=document.createElement('span');elapsed.className='pdf-ai-elapsed';elapsed.title=t('Geçen süre');elapsed.textContent='00:00';meta.append(pageLabel,elapsed);
    card.append(spinner,copy,meta);status.append(card);const started=performance.now();
    loadingTimer=setInterval(()=>{const seconds=Math.floor((performance.now()-started)/1000);elapsed.textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');},1000);
  }
  window.addEventListener('pagehide',stopLoading);
  function sendState(){const page=getPage(),doc=docs.get(page);if(window.parent!==window)window.parent.postMessage({type:'bn:annotation-state',sourceId,page,active:active.get(page),steps:(doc?.steps||[]).map(s=>({id:s.id,title:s.title}))},location.origin);}
  function request(type,payload={}){
    if(window.parent===window){const actions={load:'annotation_get',generate:'annotation_generate',save:'annotation_save'};const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),180000);
      return fetch('/api/notebook',{method:'POST',signal:controller.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':document.body.dataset.csrf},body:JSON.stringify({action:actions[type],note_id:noteId,source_id:sourceId,source_stamp:payload.sourceStamp,...payload})}).then(async r=>{const data=await r.json();if(!r.ok||!data.success)throw new Error(data.error||t('PDF notları işlenemedi.'));return data;}).finally(()=>clearTimeout(timer));}
    return new Promise((resolve,reject)=>{const requestId=crypto.randomUUID(),timer=setTimeout(()=>{pending.delete(requestId);reject(new Error(t('İstek zaman aşımına uğradı. Katmanı yenileyerek kontrol et.')));},185000);pending.set(requestId,{resolve,reject,timer});window.parent.postMessage({type:'bn:annotation-'+type,sourceId,requestId,...payload},location.origin);});
  }
  window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==window.parent)return;const data=event.data;
    if(data?.type==='bn:annotation-response'){const job=pending.get(data.requestId);if(!job)return;clearTimeout(job.timer);pending.delete(data.requestId);data.error?job.reject(new Error(data.error)):job.resolve(data.result);}
    if(data?.type==='bn:annotation-focus'&&docs.has(data.page)){go(data.page,false);focusStep(data.page,data.id);}
  });
  function ui(){const doc=docs.get(getPage());generate.disabled=busy||!stamp;generate.textContent=t(busy?'Hazırlanıyor…':doc?'Yeniden hazırla':'AI notu hazırla');toggle.hidden=!docs.size;toggle.setAttribute('aria-pressed',String(shown));saveButton.hidden=!doc;saveButton.disabled=!doc?.dirty||saving.has(getPage());reload.disabled=busy;generate.setAttribute('aria-busy',String(busy));
    nav.replaceChildren();nav.hidden=!doc;if(doc){const steps=doc.steps;let index=steps.findIndex(s=>s.id===active.get(getPage()));if(index<0){index=0;active.set(getPage(),steps[0].id);}for(const delta of [-1,1]){const b=document.createElement('button');b.type='button';b.textContent=delta<0?'‹':'›';b.setAttribute('aria-label',t(delta<0?'Önceki çözüm adımı':'Sonraki çözüm adımı'));b.disabled=index+delta<0||index+delta>=steps.length;b.onclick=()=>{focusStep(getPage(),steps[index+delta].id);};nav.append(b);if(delta<0){const label=document.createElement('span');label.textContent=(index+1)+' / '+steps.length+' · '+steps[index].title;nav.append(label);}}
      const label=document.createElement('small');label.textContent=t('AI taslağı · İşaretleri düzenleyebilirsin');nav.append(label);
    }sendState();
  }
  function pageElement(page){return host.querySelector('.pdf-page[data-number="'+page+'"]');}
  function focusStep(page,id){if(!docs.get(page)?.steps.some(s=>s.id===id))return;active.set(page,id);draw(page);pageElement(page)?.querySelector('.pdf-annotation-note[data-step="'+id+'"]')?.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
  function gridFor(canvas){if(grids.has(canvas))return grids.get(canvas);const sample=document.createElement('canvas');sample.width=Math.min(480,canvas.width);sample.height=Math.round(canvas.height*sample.width/canvas.width);const ctx=sample.getContext('2d',{willReadFrequently:true});ctx.drawImage(canvas,0,0,sample.width,sample.height);const grid=buildInkGrid(ctx.getImageData(0,0,sample.width,sample.height).data,sample.width,sample.height);grids.set(canvas,grid);return grid;}
  function snapshot(page,region=null){const canvas=pageElement(page)?.querySelector('canvas');if(!canvas)throw new Error(t('Önce PDF sayfasının yüklenmesini bekle.'));const out=document.createElement('canvas');const crop=region?[region[0]*canvas.width/1000,region[1]*canvas.height/1000,region[2]*canvas.width/1000,region[3]*canvas.height/1000]:[0,0,canvas.width,canvas.height];let scale=Math.min(1,1600/Math.max(crop[2],crop[3])),data;
    do{out.width=Math.max(1,Math.round(crop[2]*scale));out.height=Math.max(1,Math.round(crop[3]*scale));out.getContext('2d').drawImage(canvas,...crop,0,0,out.width,out.height);data=out.toDataURL('image/png');scale*=.75;}while(data.length>4000000&&Math.max(out.width,out.height)>400);
    if(data.length>4000000)throw new Error(t('Sayfa görseli çok büyük. Daha küçük bir PDF bölümü kullan.'));return data;
  }
  function markDirty(page){const doc=docs.get(page);doc.dirty=true;clearTimeout(saveTimers.get(page));saveTimers.set(page,setTimeout(()=>save(page),700));ui();}
  async function save(page){const doc=docs.get(page);if(!doc?.dirty||saving.has(page))return;saving.add(page);ui();const steps=structuredClone(doc.steps),original=JSON.stringify(steps);
    try{const data=await request('save',{page,revision:doc.revision,sourceStamp:stamp,steps});doc.revision=data.document.revision;doc.dirty=JSON.stringify(doc.steps)!==original;message('PDF notları kaydedildi.');if(doc.dirty)markDirty(page);}
    catch(error){message(error.message);}finally{saving.delete(page);ui();}
  }
  function svgElement(tag,attributes={}){const el=document.createElementNS('http://www.w3.org/2000/svg',tag);for(const [name,value]of Object.entries(attributes))el.setAttribute(name,value);return el;}
  function edit(page,step){if(busy)return;const d=document.createElement('dialog');d.className='pdf-note-editor';const title=document.createElement('input'),body=document.createElement('textarea'),form=document.createElement('form'),label=document.createElement('h3');label.textContent=t('Açıklamayı düzenle');title.value=step.title;title.maxLength=120;title.setAttribute('aria-label',t('Adım başlığı'));body.value=step.body;body.maxLength=4000;body.rows=8;body.setAttribute('aria-label',t('Adım açıklaması'));form.append(label,title,body);
    for(const text of ['Kaydet','İptal']){const b=document.createElement('button');b.textContent=t(text);b.type=text==='Kaydet'?'submit':'button';if(text==='İptal')b.onclick=()=>d.close();form.append(b);}form.onsubmit=event=>{event.preventDefault();if(!title.value.trim()||!body.value.trim())return;step.title=title.value.trim();step.body=body.value.trim();step.position=null;markDirty(page);d.close();draw(page);};d.append(form);document.body.append(d);d.onclose=()=>d.remove();d.showModal();}
  function setRegion(page,step){if(busy)return;regionEdit={page,step};host.classList.add('pdf-region-edit');message('İlgili bölgeyi PDF üzerinde sürükleyerek seç. Escape ile iptal et.');}
  function sourceImage(page,region){
    const canvas=pageElement(page)?.querySelector('canvas');if(!canvas||!region)return null;
    let cached=sourceImages.get(canvas);if(!cached){
      const sample=document.createElement('canvas');sample.width=Math.min(800,canvas.width);sample.height=Math.max(1,Math.round(canvas.height*sample.width/canvas.width));
      const ctx=sample.getContext('2d',{willReadFrequently:true});ctx.drawImage(canvas,0,0,sample.width,sample.height);
      cached={grid:buildInkGrid(ctx.getImageData(0,0,sample.width,sample.height).data,sample.width,sample.height,160),images:new Map()};sourceImages.set(canvas,cached);
    }
    const key=JSON.stringify(region);if(!cached.images.has(key)){
      if(cached.images.size>=12)cached.images.clear();cached.images.set(key,snapshot(page,expandSourceRegion(cached.grid,region)));
    }return cached.images.get(key);
  }
  function showSource(image,page,title){
    const dialog=document.createElement('dialog');dialog.className='pdf-source-preview';
    const heading=document.createElement('h3');heading.textContent=title;
    const label=document.createElement('p');label.textContent=t('Kaynak PDF bölgesi')+' · '+page+t('. sayfa');
    const img=document.createElement('img');img.src=image;img.alt=title;
    const close=document.createElement('button');close.type='button';close.textContent=t('Kapat');close.onclick=()=>dialog.close();
    dialog.append(heading,label,img,close);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();
  }
  function makeNote(page,step,index,width,font){
    const note=document.createElement('article');note.className='pdf-annotation-note';note.dataset.step=step.id;note.style.width=width+'px';note.style.fontSize=font+'px';if(active.get(page)===step.id)note.classList.add('is-active');
    const heading=document.createElement('button');heading.type='button';heading.className='pdf-note-handle';heading.textContent=(index+1)+'. '+step.title;heading.title=t('Boş alanda sürükleyerek taşı');heading.onclick=()=>{active.set(page,step.id);draw(page);};note.append(heading);
    const image=sourceImage(page,step.source_region||step.region);if(image){
      const sourceButton=document.createElement('button');sourceButton.type='button';sourceButton.className='pdf-note-source';sourceButton.setAttribute('aria-label',t('Kaynak görüntüsünü büyüt')+' · '+step.title);
      const img=document.createElement('img');img.src=image;img.alt=t('Bu adımın kaynak PDF bölgesi');img.draggable=false;
      const caption=document.createElement('span');caption.textContent=t('Kaynak PDF bölgesi')+' · '+page+t('. sayfa')+' ↗';
      sourceButton.append(img,caption);sourceButton.onclick=()=>showSource(image,page,step.title);note.append(sourceButton);
    }
    const body=document.createElement('div');body.className='pdf-note-body';body.textContent=step.body;note.append(body);
    const tools=document.createElement('div');tools.className='pdf-note-tools';
    for(const [text,run]of [['Düzenle',()=>edit(page,step)],['İşareti düzelt',()=>setRegion(page,step)],['Bu adımı sor',()=>{if(window.parent!==window)window.parent.postMessage({type:'bn:annotation-ask',sourceId,page,title:step.title,image:snapshot(page,step.region)},location.origin);}]]){const b=document.createElement('button');b.type='button';b.textContent=t(text);b.onclick=run;tools.append(b);}note.append(tools);
    if(!step.region){const info=document.createElement('small');info.textContent=t('Bölge eşleştirmesi belirsiz');note.append(info);}
    return note;
  }
  function bindDrag(note,page,step,canvas,grid,boxes){const handle=note.querySelector('.pdf-note-handle');let drag=null,moved=false;
    handle.addEventListener('pointerdown',event=>{if(busy||event.button!==0)return;const rect=note.getBoundingClientRect(),base=canvas.getBoundingClientRect();drag={x:event.clientX,y:event.clientY,left:rect.left-base.left,top:rect.top-base.top,base};moved=false;handle.setPointerCapture(event.pointerId);event.preventDefault();});
    handle.addEventListener('pointermove',event=>{if(!drag)return;const dx=event.clientX-drag.x,dy=event.clientY-drag.y;moved=moved||Math.abs(dx)+Math.abs(dy)>4;note.style.left=(drag.left+dx)+'px';note.style.top=(drag.top+dy)+'px';});
    handle.addEventListener('pointerup',event=>{if(!drag)return;const box=[(drag.left+event.clientX-drag.x)*1000/drag.base.width,(drag.top+event.clientY-drag.y)*1000/drag.base.height,note.offsetWidth*1000/drag.base.width,note.offsetHeight*1000/drag.base.height];drag=null;
      if(moved&&rectFits(grid,box,[...boxes.entries()].filter(([id])=>id!==step.id).map(([,r])=>r))){step.position=box;markDirty(page);}else if(moved)message('Belgeyi örtmemesi için başka bir boş alan seç.');draw(page);
    });handle.addEventListener('pointercancel',()=>{drag=null;draw(page);});handle.addEventListener('click',event=>{if(moved){event.preventDefault();event.stopImmediatePropagation();moved=false;}},true);
  }
  function draw(page){
    const element=pageElement(page),canvas=element?.querySelector('canvas');if(!canvas)return;
    element.querySelectorAll('.pdf-annotation-layer,.pdf-note-margin').forEach(e=>e.remove());const rect=canvas.getBoundingClientRect(),doc=docs.get(page);if(!doc||!shown){ui();return;}
    canvas.style.height=rect.height+'px';element.style.height='auto';
    if(!active.has(page))active.set(page,doc.steps[0].id);
    const grid=gridFor(canvas),layer=document.createElement('div');layer.className='pdf-annotation-layer';layer.style.height=rect.height+'px';element.append(layer);
    const svg=svgElement('svg',{viewBox:'0 0 '+rect.width+' '+rect.height,width:rect.width,height:rect.height,'aria-hidden':'true'});layer.append(svg);
    const defs=svgElement('defs'),marker=svgElement('marker',{id:'pdf-arrow-'+page,viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:6,markerHeight:6,orient:'auto-start-reverse'});marker.append(svgElement('path',{d:'M 0 1 L 9 5 L 0 9',fill:'none',stroke:'#2563eb','stroke-width':1.5}));defs.append(marker);svg.append(defs);
    const boxes=new Map(),margin=document.createElement('section');margin.className='pdf-note-margin';
    for(const [index,step]of doc.steps.entries()){
      const width=Math.min(rect.width-16,Math.max(150,(step.position?.[2]||330)*rect.width/1000)),font=Math.min(22,Math.max(12,rect.width*.021)),note=makeNote(page,step,index,width,font);layer.append(note);note.style.visibility='hidden';window.BetterNotebookRichText?.renderMathContent(note);
      const boxSize=[width*1000/rect.width,(note.offsetHeight+8)*1000/rect.height],reserved=[...boxes.values()];let box=step.position?[step.position[0],step.position[1],...boxSize]:null;
      if(!rectFits(grid,box,reserved))box=findWhiteSpace(grid,...boxSize,reserved);
      note.style.visibility='';if(box){boxes.set(step.id,box);note.style.left=box[0]*rect.width/1000+'px';note.style.top=box[1]*rect.height/1000+'px';bindDrag(note,page,step,canvas,grid,boxes);}
      else{note.classList.add('in-margin');note.style.width='';margin.append(note);}
      const region=step.region;if(region){const [x,y,w,h]=region.map((v,i)=>v*(i%2?rect.height:rect.width)/1000),selected=active.get(page)===step.id;
        svg.append(svgElement('rect',{x,y,width:w,height:h,rx:4,fill:'#facc15','fill-opacity':selected ? .25 : .12,stroke:'#2563eb','stroke-opacity':selected ? .8 : .35,'stroke-width':1.2}));
        const pin=svgElement('g',{'data-step':step.id,role:'button',tabindex:0,'aria-label':(index+1)+'. '+step.title});const px=Math.min(rect.width-13,x+w),py=Math.max(13,y);
        pin.append(svgElement('circle',{cx:px,cy:py,r:11,fill:'#fff',stroke:'#2563eb','stroke-width':1.5}));const text=svgElement('text',{x:px,y:py+4,'text-anchor':'middle',fill:'#1d4ed8','font-size':12,'font-family':'system-ui'});text.textContent=index+1;pin.append(text);pin.onclick=()=>{active.set(page,step.id);draw(page);};pin.onkeydown=event=>{if(['Enter',' '].includes(event.key)){event.preventDefault();pin.onclick();}};svg.append(pin);
        if(box){const tx=box[0]*rect.width/1000,ty=(box[1]+Math.min(box[3]/2,25))*rect.height/1000,mx=(px+tx)/2;svg.append(svgElement('path',{d:`M ${tx} ${ty} C ${mx} ${ty}, ${mx} ${py}, ${px} ${py}`,fill:'none',stroke:'#2563eb','stroke-opacity':selected ? .8 : .35,'stroke-width':1.4,'marker-end':'url(#pdf-arrow-'+page+')'}));}
      }
    }
    placements.set(page,boxes);if(margin.children.length)element.append(margin);ui();
  }
  host.addEventListener('pointerdown',event=>{if(!regionEdit||event.button!==0)return;const canvas=event.target.closest('canvas');if(!canvas||Number(canvas.closest('.pdf-page').dataset.number)!==regionEdit.page)return;event.preventDefault();event.stopImmediatePropagation();const rect=canvas.getBoundingClientRect(),box=document.createElement('div');box.className='pdf-region-selection';canvas.closest('.pdf-page').append(box);regionEdit.drag={canvas,rect,box,x:event.clientX,y:event.clientY,pointer:event.pointerId};canvas.setPointerCapture(event.pointerId);},true);
  host.addEventListener('pointermove',event=>{const d=regionEdit?.drag;if(!d)return;event.preventDefault();event.stopImmediatePropagation();const x=Math.max(d.rect.left,Math.min(d.rect.right,event.clientX)),y=Math.max(d.rect.top,Math.min(d.rect.bottom,event.clientY));Object.assign(d.box.style,{left:Math.min(d.x,x)-d.rect.left+'px',top:Math.min(d.y,y)-d.rect.top+'px',width:Math.abs(x-d.x)+'px',height:Math.abs(y-d.y)+'px'});},true);
  host.addEventListener('pointerup',event=>{const edit=regionEdit,d=edit?.drag;if(!d)return;event.preventDefault();event.stopImmediatePropagation();const x=Math.max(d.rect.left,Math.min(d.rect.right,event.clientX)),y=Math.max(d.rect.top,Math.min(d.rect.bottom,event.clientY));
    if(Math.abs(x-d.x)>8&&Math.abs(y-d.y)>8){edit.step.region=[(Math.min(d.x,x)-d.rect.left)*1000/d.rect.width,(Math.min(d.y,y)-d.rect.top)*1000/d.rect.height,Math.abs(x-d.x)*1000/d.rect.width,Math.abs(y-d.y)*1000/d.rect.height];edit.step.source_region=null;edit.step.confidence=1;markDirty(edit.page);}d.box.remove();regionEdit=null;host.classList.remove('pdf-region-edit');draw(edit.page);},true);
  host.addEventListener('pointercancel',()=>{regionEdit?.drag?.box.remove();regionEdit=null;host.classList.remove('pdf-region-edit');},true);
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&regionEdit){regionEdit.drag?.box.remove();regionEdit=null;host.classList.remove('pdf-region-edit');message('');}});
  generate.onclick=async()=>{if(busy||!stamp)return;const page=getPage(),doc=docs.get(page);regionEdit?.drag?.box.remove();regionEdit=null;host.classList.remove('pdf-region-edit');busy=true;showLoading(page);ui();
    try{const image=snapshot(page).split(',')[1],result=await request('generate',{page,revision:doc?.revision||0,sourceStamp:stamp,image});docs.set(page,result.document);active.set(page,result.document.steps[0].id);busy=false;message('AI notları hazır. İşaretleri ve açıklamaları düzenleyebilirsin.');draw(page);}
    catch(error){busy=false;message(error.message);}finally{busy=false;stopLoading();ui();}
  };
  toggle.onclick=()=>{shown=!shown;for(const page of docs.keys())draw(page);ui();};saveButton.onclick=()=>save(getPage());
  async function load(){if(busy)return;generate.disabled=true;message('PDF notları yükleniyor…');try{const result=await request('load');if(stamp&&stamp!==result.sourceStamp){docs.clear();active.clear();}stamp=result.sourceStamp;for(const doc of result.pages)if(!docs.get(doc.page)?.dirty)docs.set(doc.page,doc);for(const page of docs.keys())draw(page);message('');}catch(error){message(error.message);}finally{ui();}}
  reload.onclick=load;load();window.addEventListener('load',()=>{for(const page of docs.keys())draw(page);});document.fonts?.addEventListener('loadingdone',()=>{for(const page of docs.keys())draw(page);});
  return {onPageReady(page){draw(page);},currentChanged(){if(getPage()!==lastPage){lastPage=getPage();ui();}}};
}
