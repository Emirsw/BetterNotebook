export function cropCoordinates(start,end,rect,width,height){
  const clamp=(v,max)=>Math.max(0,Math.min(max,v));
  const left=clamp(Math.min(start.x,end.x)-rect.left,rect.width),top=clamp(Math.min(start.y,end.y)-rect.top,rect.height);
  const right=clamp(Math.max(start.x,end.x)-rect.left,rect.width),bottom=clamp(Math.max(start.y,end.y)-rect.top,rect.height);
  const x=Math.floor(left*width/rect.width),y=Math.floor(top*height/rect.height);
  return {x,y,width:Math.min(width-x,Math.ceil((right-left)*width/rect.width)),height:Math.min(height-y,Math.ceil((bottom-top)*height/rect.height)),left,top,cssWidth:right-left,cssHeight:bottom-top};
}
export function installCapture(host){
  const button=document.getElementById('capture');if(!button)return;if(window.parent===window){button.hidden=true;return;}
  const t=window.BetterNotebookI18n.t;
  const hint=document.createElement('div');hint.className='pdf-capture-hint';hint.setAttribute('role','status');hint.hidden=true;host.before(hint);
  let active=false,selection=null;
  function reset(){selection?.overlay.remove();selection=null;active=false;host.classList.remove('pdf-capture-active');button.textContent=t('Alanı sor');button.setAttribute('aria-pressed','false');hint.hidden=true;}
  button.addEventListener('click',()=>{if(active){reset();return;}active=true;host.classList.add('pdf-capture-active');button.textContent=t('İptal');button.setAttribute('aria-pressed','true');hint.textContent=t('PDF üzerinde soracağın alanı sürükleyerek seç. Escape ile iptal et.');hint.hidden=false;});
  document.addEventListener('keydown',event=>{if(active&&event.key==='Escape')reset();});
  host.addEventListener('pointerdown',event=>{
    const canvas=event.target.closest('canvas');if(!active||!canvas||event.button!==0)return;event.preventDefault();
    const rect=canvas.getBoundingClientRect(),page=canvas.closest('.pdf-page'),overlay=document.createElement('div');overlay.className='pdf-capture-selection';page.append(overlay);
    selection={canvas,page,rect,overlay,start:{x:event.clientX,y:event.clientY},pointer:event.pointerId};canvas.setPointerCapture?.(event.pointerId);
  });
  host.addEventListener('pointermove',event=>{if(!selection||event.pointerId!==selection.pointer)return;event.preventDefault();const s=selection,c=cropCoordinates(s.start,{x:event.clientX,y:event.clientY},s.rect,s.canvas.width,s.canvas.height);Object.assign(s.overlay.style,{left:c.left+'px',top:c.top+'px',width:c.cssWidth+'px',height:c.cssHeight+'px'});});
  host.addEventListener('pointercancel',()=>{if(active)reset();});
  host.addEventListener('pointerup',event=>{
    if(!selection||event.pointerId!==selection.pointer)return;event.preventDefault();const s=selection;
    try{
      if(!s.canvas.isConnected){reset();return;}
      const c=cropCoordinates(s.start,{x:event.clientX,y:event.clientY},s.rect,s.canvas.width,s.canvas.height);
      if(c.cssWidth<8||c.cssHeight<8){s.overlay.remove();selection=null;hint.textContent=t('Biraz daha geniş bir alan seç.');return;}
      const output=document.createElement('canvas');let scale=Math.min(1,1600/Math.max(c.width,c.height)),data;
      do{output.width=Math.max(1,Math.round(c.width*scale));output.height=Math.max(1,Math.round(c.height*scale));output.getContext('2d').drawImage(s.canvas,c.x,c.y,c.width,c.height,0,0,output.width,output.height);data=output.toDataURL('image/png');scale*=.75;}while(data.length>4000000&&Math.max(output.width,output.height)>400);
      if(data.length>4000000)throw new Error(t('Daha küçük bir alan seç.'));
      const source=new URL(document.body.dataset.sourceUrl,location.href);
      window.parent.postMessage({type:'bn:pdf-capture',dataUrl:data,sourceId:source.searchParams.get('source_id'),page:Number(s.page.dataset.number)},location.origin);reset();
    }catch(error){reset();hint.hidden=false;hint.textContent=error.message||t('Ekran alıntısı oluşturulamadı.');}
  });
}
