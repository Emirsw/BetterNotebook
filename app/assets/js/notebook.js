const __bnT=window.BetterNotebookI18n.t;const __bnHTML=window.BetterNotebookI18n.template;

  (()=>{
    const root=document.getElementById('bn-preview');
    const ico=name=>`<i data-lucide="${name}" aria-hidden="true"></i>`;
    const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const nav=[['today',__bnT('Bugün'),'sun'],['notes',__bnT('Not defterim'),'notebook-pen'],['files',__bnT('Dosyalar'),'folder-open'],['timer',__bnT('Odaklan'),'timer']];
    const noteSeed=window.BetterNotebookBootstrap.notes;
    const boot=window.BetterNotebookBootstrap;
    const saved={apps:[{...boot.preferences,variant:'workspace',view:boot.view}],study:{...boot.preferences.timer,sessions:boot.sessions}};
    async function api(action,payload={}){const response=await fetch('api/notebook',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':boot.csrf},credentials:'same-origin',body:JSON.stringify({action,...payload})});let result;try{result=await response.json();}catch{const reference=response.headers?.get('X-BetterNotebook-Request');throw new Error(__bnT('Sunucu JSON yerine hata sayfası döndürdü (HTTP ')+response.status+__bnT('). PHP hata kaydını kontrol et.')+(reference?__bnT(' İşlem kodu: ')+reference:''));}if(response.status===401){location.href='login';throw new Error(__bnT('Oturum sona erdi.'));}if(!response.ok||!result.success)throw new Error((window.BetterNotebookI18n.t(result.error)||__bnT('İşlem tamamlanamadı.'))+(result.reference?__bnT(' İşlem kodu: ')+result.reference:''));return result;}
    async function apiForm(form){const response=await fetch('api/notebook',{method:'POST',headers:{'X-CSRF-Token':boot.csrf},credentials:'same-origin',body:form});let result;try{result=await response.json();}catch{throw new Error(__bnT('Dosya yüklenemedi. Sunucunun yükleme boyutu sınırını kontrol et.'));}if(!response.ok||!result.success)throw new Error(window.BetterNotebookI18n.t(result.error)||__bnT('Dosya eklenemedi.'));return result;}

    const studyPhases={focus:__bnT('Odaklan'),short:__bnT('Kısa mola'),long:__bnT('Uzun mola')};
    function readStudy(value){
      const focusMinutes=Number.isInteger(value?.focusMinutes)&&value.focusMinutes>=1&&value.focusMinutes<=180?value.focusMinutes:25;
      const shortMinutes=Number.isInteger(value?.shortMinutes)&&value.shortMinutes>=1&&value.shortMinutes<=60?value.shortMinutes:5;
      const longMinutes=Number.isInteger(value?.longMinutes)&&value.longMinutes>=1&&value.longMinutes<=60?value.longMinutes:15;
      const phase=Object.hasOwn(studyPhases,value?.phase)?value.phase:'focus';
      const durationMs=({focus:focusMinutes,short:shortMinutes,long:longMinutes})[phase]*60000;
      return {focusMinutes,shortMinutes,longMinutes,phase,course:typeof value?.course==='string'?value.course.slice(0,80):'Devre Analizi',remainingMs:Number.isFinite(value?.remainingMs)?Math.min(durationMs,Math.max(0,value.remainingMs)):durationMs,elapsedMs:Number.isFinite(value?.elapsedMs)?Math.min(durationMs,Math.max(0,value.elapsedMs)):0,runningSince:Number.isFinite(value?.runningSince)&&value.runningSince>0?Math.min(Date.now(),value.runningSince):0,sessionStartedAt:Number.isFinite(value?.sessionStartedAt)?value.sessionStartedAt:0,sessionId:typeof value?.sessionId==='string'?value.sessionId.slice(0,5000):'',completedPhase:Object.hasOwn(studyPhases,value?.completedPhase)?value.completedPhase:'',updatedAt:Number.isFinite(value?.updatedAt)?value.updatedAt:0,sessions:Array.isArray(value?.sessions)?value.sessions.filter(row=>Array.isArray(row)&&row.length===6&&typeof row[0]==='string'&&typeof row[1]==='string'&&row[1].length<=80&&Number.isInteger(row[2])&&row[2]>0&&row[2]<=21600&&Number.isFinite(row[3])&&Number.isFinite(row[4])&&row[4]>=row[3]&&['completed','partial','manual'].includes(row[5])).slice(0,5000):[]};
    }
    let study=readStudy(saved?.study);
    const studyDuration=()=>({focus:study.focusMinutes,short:study.shortMinutes,long:study.longMinutes})[study.phase]*60000;
    const studyActive=()=>Boolean(study.sessionStartedAt);
    const studySeconds=()=>Math.max(0,Math.ceil(study.remainingMs/1000));
    const clockTime=seconds=>String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
    function studyTime(seconds){if(seconds===0)return __bnT('0 dk');if(seconds<60)return seconds+__bnT(' sn');const minutes=Math.floor(seconds/60),hours=Math.floor(minutes/60);return hours?(hours+__bnT(' sa')+(minutes%60?' '+minutes%60+__bnT(' dk'):'')):minutes+__bnT(' dk');}
    function resetStudy(phase=study.phase){study.phase=phase;study.remainingMs=studyDuration();study.elapsedMs=0;study.runningSince=0;study.sessionStartedAt=0;study.sessionId='';study.completedPhase='';}
    function recordStudy(seconds,start,end,status,id,course=study.course){if(seconds<1)return false;if(!study.sessions.some(row=>row[0]===id))study.sessions.unshift([id,course,seconds,start,end,status]);study.sessions.sort((a,b)=>b[4]-a[4]);study.sessions=study.sessions.slice(0,5000);return true;}
    function syncStudy(){
      if(!study.runningSince)return false;
      const now=Date.now(),spent=Math.min(study.remainingMs,Math.max(0,now-study.runningSince));const finishedAt=study.runningSince+spent;
      study.remainingMs-=spent;study.elapsedMs+=spent;study.runningSince=study.remainingMs>0?now:0;
      if(study.remainingMs>0)return false;
      if(study.phase==='focus')recordStudy(Math.floor(study.elapsedMs/1000),study.sessionStartedAt,finishedAt,'completed',study.sessionId);
      study.completedPhase=study.phase;study.sessionStartedAt=0;study.sessionId='';study.elapsedMs=0;study.updatedAt=Date.now();persist();return true;
    }
    function commitStudy(){study.updatedAt=Math.max(Date.now(),study.updatedAt+1);persist();for(const state of states){if(state.view==='timer')state.render();else state.paintStudy?.();}}
    const states=[];
    let preferencesTimer;
    const knownSessions=new Set(boot.sessions.map(r=>r[0]));
    const persist=()=>{
      clearTimeout(preferencesTimer);
      preferencesTimer=setTimeout(()=>{const state=states[0];if(!state)return;const {sessions,...timer}=study;api('preferences',{preferences:{widths:state.widths,order:state.order,minimized:[...state.minimized],noteId:state.noteId,readerReturnView:state.readerReturnView,qaFont:state.qaFont,timer}}).catch(error=>state.notify?.(error.message));},350);
      for(const row of study.sessions){if(knownSessions.has(row[0]))continue;knownSessions.add(row[0]);api('study',{session:row}).catch(error=>{knownSessions.delete(row[0]);states[0]?.notify?.(__bnT('Oturum kaydedilemedi: ')+error.message);});}
    };
    function icons(){if(globalThis.lucide)lucide.createIcons({attrs:{width:17,height:17}});}
    function action(label,act,icon,primary=false){if(act==='timer-toggle'&&!studyActive()&&study.phase!=='focus')label=study.completedPhase?__bnT('Odaklanmaya geç'):__bnT('Molayı başlat');return `<button type="button" class="bn-btn ${primary?'bn-btn-primary':''}" data-action="${act}">${icon?ico(icon):''}${label}</button>`;}
    root.querySelectorAll('[data-app]').forEach((host,index)=>{
      const variant=host.dataset.app;
      const remembered=saved?.apps?.find(a=>a.variant===variant);
      const allowed=[...nav.map(n=>n[0]),'reader','settings'];
      const state={host,variant,view:remembered&&allowed.includes(remembered.view)?remembered.view:(index?'today':'notes'),tab:['source','notes','questions','plan'].includes(remembered?.tab)?remembered.tab:'source',mode:['cloud','byok','local'].includes(remembered?.mode)?remembered.mode:'cloud',noteId:remembered?.noteId===2?2:1,notes:noteSeed.map(n=>({...n})),query:'',filter:'all',answer:false,response:'',seconds:1500,running:false,startedAt:0,remainingAtStart:1500,userNote:'Kirchhoff yasalarında önce akım yönlerini seç.\n\n• Düğüme giren ve çıkan akımları eşitle.\n• Her bağımsız çevre için gerilim denklemi kur.\n• Negatif sonuç, seçilen yönün tersini gösterir.',notice:'',split:false,taskChecks:new Set(),draft:''};
      state.widths=Array.isArray(remembered?.widths)&&remembered.widths.length===3&&remembered.widths.every(v=>Number.isFinite(v)&&v>0)?remembered.widths:[42,38,20];
      if(state.notes.some(n=>n.id===remembered?.noteId))state.noteId=remembered.noteId;
      const recentIds=Array.isArray(remembered?.recentNoteIds)?remembered.recentNoteIds.filter(id=>state.notes.some(n=>n.id===id)):[];
      state.lastWorkedNoteId=state.notes.some(n=>n.id===remembered?.lastWorkedNoteId)?remembered.lastWorkedNoteId:recentIds[0]||state.noteId;
      state.recentNoteIds=[...new Set([state.lastWorkedNoteId,...recentIds,...state.notes.map(n=>n.id)])].slice(0,12);state.readerReturnView=['today','notes','files'].includes(remembered?.readerReturnView)?remembered.readerReturnView:'notes';
      if(Array.isArray(remembered?.pinnedNotes))state.notes.forEach(note=>{const preference=remembered.pinnedNotes.find(value=>value.id===note.id&&typeof value.pinned==='boolean');if(preference)note.pinned=preference.pinned;});
      state.minimized=new Set(Array.isArray(remembered?.minimized)?remembered.minimized.filter(id=>[0,1,2].includes(id)):[]);state.readerFont=13;
      state.order=Array.isArray(remembered?.order)&&remembered.order.length===3&&new Set(remembered.order).size===3&&remembered.order.every(id=>[0,1,2].includes(id))?[...remembered.order]:[0,1,2];
      const sortedHeaders=new WeakSet();
      const selectBindings=new WeakMap();
      const windowMotion={animations:new Set(),ghosts:new Set()};
      state.courses=Array.isArray(remembered?.courses)?[...new Set(remembered.courses.filter(course=>typeof course==='string'&&course.trim()&&course.length<=80))].slice(0,30):['Devre Analizi','Veri Yapıları','Proje'];state.coursesOpen=false;state.profileOpen=false;state.studyFilter='all';state.manualOpen=false;state.durationsOpen=false;
      state.geminiModel='gemini-2.0-flash';state.geminiKey='';state.geminiSaved=false;state.cloudDetails=false;state.notesPage=1;
      states.push(state);
      host.innerHTML=__bnHTML`<div class="bn-shell"><div class="bn-main"><header class="bn-top"><div class="bn-logo"><span class="bn-logo-mark">${ico('notebook-pen')}</span><span class="bn-logo-name">BetterNotebook</span></div><div class="bn-global-controls"><nav class="bn-glass-group bn-glass-nav" aria-label="Ana gezinme"><span class="bn-glass-selection" aria-hidden="true"></span>${nav.map(([id,label,icon])=>`<button type="button" data-view="${id}" aria-label="${label}" data-tooltip="${label}">${ico(icon)}</button>`).join('')}</nav><div class="bn-profile-area"><nav class="bn-glass-group bn-glass-utilities" aria-label="Hesap ve ayarlar"><button type="button" data-view="settings" aria-label="Ayarlar" data-tooltip="Ayarlar">${ico('settings-2')}</button><button type="button" data-action="profile" aria-label="Profil" data-tooltip="Profil" aria-expanded="false" aria-controls="bn-profile-${index}">${ico('user-round')}</button></nav><section id="bn-profile-${index}" class="bn-profile-menu" aria-label="Profil bilgileri" hidden><div class="bn-row"><span class="bn-avatar">D</span><div><h3>Hesabım</h3><span class="bn-mini" data-mode-badge>Kişisel çalışma alanı</span></div><button type="button" class="bn-icon-btn" data-action="close-profile" aria-label="Profili kapat">${ico('x')}</button></div></section></div></div><span class="bn-crumb" hidden><strong data-breadcrumb>Not defterim</strong></span></header><main class="bn-content"></main><footer class="bn-status"><span data-mode-status>Sürüm v${esc(boot.version)}</span><span>Better Project</span></footer></div></div>`;
      const brand=host.querySelector('.bn-logo');brand.setAttribute('role','img');brand.setAttribute('aria-label','BetterNotebook');brand.querySelector('.bn-logo-mark').setAttribute('aria-hidden','true');brand.querySelector('.bn-logo-mark').innerHTML=ico('book-open');brand.querySelector('.bn-logo-name').innerHTML='<span class="bn-brand-better">Better</span><span class="bn-brand-notebook">Notebook</span>';
      const main=host.querySelector('main');
      const currentNote=()=>state.notes.find(n=>n.id===state.noteId);
      const isNotebook=n=>Boolean(n&&(n.custom||n.kind==='Not'));
      const noteSources=n=>n?.attachments||(n?.kind==='PDF'?[{id:'seed-'+n.id,name:n.title+'.pdf',pages:n.pages,sample:true}]:[]);
      const fileSize=size=>size>=1048576?(size/1048576).toFixed(1)+' MB':Math.max(1,Math.ceil(size/1024))+' KB';
      state.qaRefs=[];state.mention=null;
      function pdfPicker(label,mode='attach'){return `<label class="bn-file-picker">${ico('paperclip')}${label}<input type="file" accept="application/pdf,.pdf" multiple data-pdf-input data-upload-mode="${mode}" aria-label="${label}"></label>`;}
      function sourceRows(n){return noteSources(n).map(source=>__bnHTML`<div class="bn-source-row">${ico('file-text')}<div class="bn-source-info"><strong>${esc(source.name)}</strong><small>${source.pages?source.pages+__bnT(' sayfa'):fileSize(source.size||0)} · ${source.sample?__bnT('Örnek kaynak'):__bnT('PDF · Kaydedildi')}</small></div><button type="button" class="bn-btn" data-source-open="${esc(source.id)}" aria-label="${esc(source.name)} kaynağını aç">${ico('arrow-up-right')}</button></div>`).join('');}
      function attachmentSection(n){return __bnHTML`<section class="bn-attachment-section" aria-label="Nota eklenen PDF dosyaları"><div class="bn-row" style="justify-content:space-between"><h3>Kaynaklar <span class="bn-mini">${noteSources(n).length} PDF</span></h3>${pdfPicker(__bnT('Nota PDF ekle'))}</div><div class="bn-source-list">${sourceRows(n)||__bnT('<p class="bn-mini">Bu nota PDF ekleyebilir, AI sorularında @ ile seçebilirsin.</p>')}</div><span class="bn-mini">Dosyalar bu önizleme oturumunda yerel olarak ilişkilendirilir; sunucuya gönderilmez.</span></section>`;}
      function notebookSource(n){const sources=noteSources(n),selected=sources.find(s=>s.id===n.activeSource)||sources[0];return __bnHTML`<section class="bn-r-pane" data-pane="0" aria-label="PDF kaynağı"><div class="bn-r-bar">${ico('paperclip')}<span>Kaynaklar · ${sources.length} PDF</span>${minimizeButton(0)}</div><div class="bn-source-panel">${pdfPicker(__bnT('Kaynaklara PDF ekle'))}<div class="bn-source-list">${sourceRows(n)}</div>${selected?__bnHTML`<div class="bn-source-preview">${ico('file-text')}<h3>${esc(selected.name)}</h3><p class="bn-mini">PDF bu çalışma alanına eklendi. İçerik görüntüleme ve AI bağlantısı bu tasarım önizlemesinde çalıştırılmaz.</p><button type="button" class="bn-btn" data-action="write-in-note">${ico('notebook-pen')}Bu notta yaz</button></div>`:__bnHTML`<div class="bn-source-empty">${ico('file-plus-2')}<h3>Kaynak eklemek sana kalmış</h3><p>Doğrudan not yazabilir veya yukarıdan bir PDF ekleyebilirsin.</p></div>`}</div></section>`;}
      function flowDialog(title,body){const dialog=document.createElement('dialog');dialog.className='bn-flow-dialog';dialog.setAttribute('aria-label',title);dialog.innerHTML=__bnHTML`<div class="bn-flow-heading"><h2>${esc(title)}</h2><button type="button" class="bn-icon-btn" data-action="close-flow" aria-label="Pencereyi kapat">${ico('x')}</button></div>${body}`;host.append(dialog);const origin=document.activeElement;dialog.addEventListener('close',()=>{dialog.remove();if(origin?.isConnected)origin.focus({preventScroll:true});});dialog.showModal();icons();return dialog;}
      function showNewNote(){flowDialog(__bnT('Yeni not'),__bnHTML`<p class="bn-subtitle">Nasıl başlamak istersin?</p><div class="bn-flow-choices"><button type="button" class="bn-flow-choice" data-action="new-create">${ico('notebook-pen')}<strong>Oluştur</strong><span>Boş bir defter aç. Yaz, PDF ekle ve AI ile çalış.</span></button><button type="button" class="bn-flow-choice" data-action="new-upload">${ico('upload')}<strong>Yükle</strong><span>PDF ile başla. Yalnızca belgeni özetlet veya yanına not al.</span></button></div><div class="bn-upload-step" data-upload-step hidden><p class="bn-mini">Bir veya birden fazla PDF seç; yükleme şeklini sonraki adımda belirleyebilirsin.</p>${pdfPicker(__bnT('PDF seç'),'new')}</div><p class="bn-mini" data-upload-feedback role="status" aria-live="polite" style="margin-top:16px"></p>`);}
      function createNotebook(files=[]){const id=Math.max(Date.now(),...state.notes.map(n=>n.id))+1;const n={id,title:files.length?files[0].name.replace(/\.pdf$/i,''):__bnT('Yeni kişisel not'),course:state.courses.includes(state.filter)?state.filter:'',pages:0,kind:files.length?'PDF':'Not',custom:true,sourceOnly:files.length>0,pinned:false,edited:__bnT('Şimdi'),summary:files.length?files.length+__bnT(' PDF kaynağı · Not al veya AI ile özetle.'):__bnT('Düşüncelerini buraya yaz.'),body:'',attachments:files};state.notes.unshift(n);state.noteId=id;state.notesPage=1;state.tab='notes';state.userNote='';state.response='';state.draft='';state.qaRefs=[];state.mention=null;host.querySelector('.bn-flow-dialog')?.close();go('reader');if(!files.length)main.querySelector('[data-note-title]')?.focus({preventScroll:true});}
      async function attachPDFs(input){const files=Array.from(input.files||[]);if(!files.length)return;const n=currentNote(),mode=input.dataset.uploadMode,dialog=input.closest('dialog');const valid=[],errors=[];for(const file of files){try{if(!/\.pdf$/i.test(file.name)||(await file.slice(0,5).text())!=='%PDF-'){errors.push(file.name);continue;}valid.push({id:'pdf-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8),name:file.name,size:file.size,file});}catch{errors.push(file.name);}}if(!input.isConnected)return;input.value='';if(!valid.length){const message=__bnT('Geçerli bir PDF dosyası seç.');if(dialog)dialog.querySelector('[data-upload-feedback]').textContent=message;else notify(message);return;}if(mode==='new'){createNotebook(valid);if(errors.length)notify('Bazı dosyalar PDF olmadığı için eklenmedi.');return;}if(!n)return;if(!n.attachments)n.attachments=[...noteSources(n)];n.attachments.push(...valid);n.activeSource=valid[0].id;n.edited=__bnT('Şimdi');if(state.view==='reader'&&state.noteId===n.id){render();notify(valid.length+' PDF nota eklendi.'+(errors.length?' Geçersiz dosyalar atlandı.':''));}}
      function showSummary(scope='note'){const n=currentNote();if(!n)return;const sources=noteSources(n);const selected=scope==='note'||sources.some(s=>s.id===scope)?scope:'note';const dialog=flowDialog(__bnT('AI Özeti'),__bnHTML`<p class="bn-subtitle">${esc(n.title)} · Orijinal notun korunur.</p><fieldset class="bn-summary-scopes" style="padding:0;border:0"><legend class="bn-mini">Özet kapsamı</legend><label><input type="radio" name="summary-scope-${index}" value="note" data-summary-scope ${selected==='note'?'checked':''}>Notun tamamı · Yazı ve eklenen kaynaklar</label>${sources.map(s=>__bnHTML`<label><input type="radio" name="summary-scope-${index}" value="${esc(s.id)}" data-summary-scope ${selected===s.id?'checked':''}>Sadece ${esc(s.name)}</label>`).join('')}</fieldset><button type="button" class="bn-btn bn-btn-primary" data-action="prepare-summary">${ico('sparkles')}Özeti hazırla</button><div data-summary-result role="status" aria-live="polite"></div><p class="bn-mini" style="margin-top:18px">BetterNotebook · İlk prototip · Canlı AI çağrısı yapılmaz.</p>`);if(n.lastSummary?.scope===selected)paintSummary(dialog,n.lastSummary);}
      function paintSummary(dialog,result){dialog.querySelector('[data-summary-result]').innerHTML=`<div class="bn-summary-output"><span class="bn-chip">Özet penceresi · Önizleme</span><h3>${esc(result.title)}</h3><p>${esc(result.sources.length?result.sources.map(s=>s.name).join(' · '):__bnT('Yalnızca not yazısı'))}</p>${result.body?`<p class="bn-mini">Not yazısından alıntı · AI tarafından üretilmedi</p><blockquote>${esc(result.body.slice(0,800))}${result.body.length>800?'…':''}</blockquote>`:''}<p class="bn-mini">${result.sources.length?'Seçilen PDF içeriğinin özetlenmesi canlı AI ve PDF işleme bağlantısıyla burada gösterilecek.':'Canlı AI bağlandığında yazının özeti burada, notundan ayrı olarak gösterilecek.'}</p></div>`;}
      function prepareSummary(dialog){const n=currentNote();if(!n)return;const scope=dialog.querySelector('[data-summary-scope]:checked')?.value||'note';const sources=scope==='note'?noteSources(n):noteSources(n).filter(s=>s.id===scope);const body=scope==='note'?(isNotebook(n)?n.body||'':state.userNote):'';if(!sources.length&&!body.trim()){dialog.querySelector('[data-summary-result]').innerHTML=__bnT('<p class="bn-mini" role="alert" style="margin-top:16px">Önce not yaz veya bir PDF ekle.</p>');return;}n.lastSummary={scope,title:scope==='note'?__bnT('Notun tamamı'):sources[0].name,sources:sources.map(s=>({id:s.id,name:s.name})),body};paintSummary(dialog,n.lastSummary);}
      function paintQAContext(){const n=currentNote();const sources=noteSources(n);state.qaRefs=state.qaRefs.filter(id=>sources.some(s=>s.id===id));const chips=main.querySelector('[data-qa-context]');if(chips)chips.innerHTML=state.qaRefs.map(id=>{const s=sources.find(s=>s.id===id);return __bnHTML`<span class="bn-context-chip">${ico('file-text')}<span data-tooltip="${esc(s.name)}">${esc(s.name)}</span><button type="button" data-remove-context="${esc(id)}" aria-label="${esc(s.name)} seçimini kaldır">${ico('x')}</button></span>`;}).join('');const menu=main.querySelector('[data-mention-menu]');if(!menu)return;menu.hidden=!state.mention;if(state.mention){const query=state.mention.query.toLocaleLowerCase('tr');state.mention.items=sources.filter(s=>s.name.toLocaleLowerCase('tr').includes(query));state.mention.active=Math.min(state.mention.active,Math.max(0,state.mention.items.length-1));menu.innerHTML=__bnHTML`<span>Bu notun PDF kaynakları</span>${state.mention.items.length?state.mention.items.map((s,i)=>`<button type="button" data-mention-source="${esc(s.id)}" ${i===state.mention.active?'data-active':''}>${ico('file-text')}<span>${esc(s.name)}</span></button>`).join(''):__bnT('<span>Eşleşen PDF yok. Notuna PDF ekleyebilirsin.</span>')}`;}icons();placeMentionMenu();}
      function detectMention(input){const text=input.value.slice(0,input.selectionStart),match=text.match(/(?:^|\s)@([^@\n]*)$/);state.mention=match&&match[1].length<=100?{start:input.selectionStart-match[1].length-1,end:input.selectionStart,query:match[1],active:0,items:[]}:null;paintQAContext();}
      function chooseMention(id){const input=main.querySelector('[data-question]');if(!input||!state.mention)return;if(!noteSources(currentNote()).some(s=>s.id===id))return;const pos=state.mention.start;state.draft=input.value.slice(0,pos)+input.value.slice(state.mention.end);input.value=state.draft;if(!state.qaRefs.includes(id))state.qaRefs.push(id);state.mention=null;paintQAContext();input.focus({preventScroll:true});input.setSelectionRange(pos,pos);}
      function heading(kicker,title,sub,right=''){return `<div class="bn-heading"><div>${kicker?`<div class="bn-eyebrow">${kicker}</div>`:''}<h1>${title}</h1>${sub?`<p class="bn-subtitle">${sub}</p>`:''}</div>${right}</div>`;}
      function noteCard(n){const pinLabel=esc(n.title)+(n.pinned?__bnT(' sabitlemesini kaldır'):__bnT(' sabitle'));const fileLabel=n.kind==='PPTX'?'PowerPoint':n.kind==='Not'?__bnT('Kişisel not'):n.kind;return __bnHTML`<article class="bn-note-card"><button type="button" class="bn-note" data-note="${n.id}" aria-label="${esc(n.title)} aç"><span class="bn-card-top"><span class="bn-file-icon">${ico(n.kind==='PPTX'?'presentation':n.kind==='Not'?'file-pen-line':'file-text')}</span><span class="bn-card-file"><span>${esc(fileLabel)}</span><span>${n.pages?n.pages+__bnT(' sayfa'):n.attachments?.length?n.attachments.length+__bnT(' kaynak'):__bnT('Kendi notların')}</span></span></span><span class="bn-card-copy"><span class="bn-note-title">${esc(n.title)}</span><span class="bn-note-meta">${esc(noteDescription(n))}</span></span><span class="bn-note-footer"><span class="bn-card-context"><span class="bn-card-course">${ico('book-open')}${esc(n.course||__bnT('Ders atanmamış'))}</span><span class="bn-card-edited">${esc(formatEdited(n))}</span></span><span class="bn-card-open" aria-hidden="true">${ico('arrow-up-right')}</span></span></button><button type="button" class="bn-icon-btn bn-note-pin" data-pin-note="${n.id}" ${pendingPins.has(n.id)?'disabled aria-busy="true"':''} aria-label="${pinLabel}" data-tooltip="${n.pinned?__bnT('Sabitlemeyi kaldır'):__bnT('Notu sabitle')}" aria-pressed="${n.pinned}">${ico('pin')}</button>${trashButton('note',n.id,n.title,'bn-note-trash')}</article>`;}
      function readerButton(label,actionName,icon,extra=''){return `<button type="button" class="bn-r-button" data-action="${actionName}" ${extra}>${icon?ico(icon):''}${label}</button>`;}
      function visibleReaderPanes(){return state.order.filter(id=>!state.minimized.has(id));}
      const paneNames=['PDF','Not','AI'];const paneIcons=['file-text','notebook-pen','bot'];
      function minimizeButton(id){return __bnHTML`<button type="button" class="bn-r-button bn-r-minimize" data-action="minimize-${id}" aria-label="${paneNames[id]} panelini küçült" data-tooltip="${paneNames[id]} panelini küçült">${ico('minus')}</button>`;}
      function readerCoursePicker(n){const choices=['',...new Set([...state.courses,n.course].filter(Boolean))];return __bnHTML`<div class="bn-reader-course"><span>Ders</span><details class="bn-course-picker" data-course-picker><summary data-course-trigger aria-label="Notun dersi" aria-describedby="bn-course-value-${index}"><span id="bn-course-value-${index}" data-course-value>${esc(n.course||__bnT('Ders atanmamış'))}</span>${ico('chevron-down')}</summary><div class="bn-course-menu" role="group" aria-label="Notun dersini seç">${choices.map(course=>`<label class="bn-course-option"><input type="radio" name="bn-note-course-${index}" data-note-course value="${esc(course)}" aria-label="${esc(course||__bnT('Ders atanmamış'))}" ${course===n.course?'checked':''}><span class="bn-course-option-icon">${ico(course?'book-open':'minus')}</span><span class="bn-course-option-text">${esc(course||__bnT('Ders atanmamış'))}</span><span class="bn-course-option-check">${ico('check')}</span></label>`).join('')}</div></details></div>`;}
      function reader(){
        const n=state.notes.find(n=>n.id===state.noteId)||state.notes[0];
        const pdf=isNotebook(n)?notebookSource(n):__bnHTML`<section class="bn-r-pane" data-pane="0" aria-label="PDF kaynağı"><div class="bn-r-bar">${ico('paperclip')}<span>${esc(n.title)}.pdf</span>${minimizeButton(0)}</div><div class="bn-r-pdf-toolbar">${ico('menu')}<strong>Sunum</strong><span class="bn-r-page">1 / ${n.pages||24}</span><span>92%</span>${ico('rotate-cw')}</div><div class="bn-r-pdf-wrap"><div class="bn-r-paper"><h2>${n.id===1?'DEVRE ANALİZİ<br>KIRCHHOFF YASALARI':esc(n.title)}</h2><p>${esc(n.course)}<br>2026–2027 Güz Dönemi<br>Ders notları</p><small>1 · Temsili belge</small></div><div class="bn-r-paper-next"><h3>İÇİNDEKİLER</h3><p>1. Temel kavramlar<br>2. Temel yasalar ve denklemler<br>3. Çözümlü örnekler</p></div></div></section>`;
        const defaultNote=__bnHTML`<p>Bu ders notunda devre analizinin temel ilkelerini ve Kirchhoff yasalarını birlikte inceleyeceğiz. Konuyu anlamak için yükün ve enerjinin korunumu arasındaki ilişkiye odaklanalım.</p><h2>Konu Anlatımı & Temel Kavramlar</h2><h3>1. Kirchhoff’un Akım Yasası</h3><p>Bir düğüme giren akımların toplamı, o düğümden çıkan akımların toplamına eşittir. Bu ilke elektrik yükünün korunmasına dayanır.</p><div class="bn-r-equation">∑ I<sub>giren</sub> = ∑ I<sub>çıkan</sub></div><p>Akım yönünü başlangıçta serbestçe seçebilirsin. Hesap sonucunun negatif çıkması, akımın gerçekte seçtiğin yönün tersine aktığını gösterir.</p><h3>2. Kirchhoff’un Gerilim Yasası</h3><p>Kapalı bir çevrede gerilimlerin cebirsel toplamı sıfırdır. Çevre boyunca ilerlerken kaynak ve direnç gerilimlerinin işaretlerini tutarlı seç.</p><div class="bn-r-equation">∑ V = 0</div><h3>Çözümlü örnek</h3><p>Bir düğüme 5 A akım giriyor. Kollardan birinden 2 A akım çıkıyorsa diğer koldan çıkan akım:</p><div class="bn-r-equation">5 − 2 = 3 A</div>`;
        const note=__bnHTML`<section class="bn-r-pane" data-pane="1" aria-label="Not ve konu anlatımı"><div class="bn-r-bar">${ico('notebook-pen')}<span>${isNotebook(n)?__bnT('Not defterim'):__bnT('Not & Konu Anlatımı')}</span>${minimizeButton(1)}</div><article class="bn-r-copy">${isNotebook(n)||state.tab==='questions'||state.tab==='plan'||state.tab==='notes'?readerContent(n):defaultNote}</article></section>`;
        const qa=__bnHTML`<aside class="bn-r-pane bn-r-qa" data-pane="2" aria-label="AI soru paneli"><div class="bn-r-bar">${ico('bot')}<span><strong>AI Soru Paneli</strong><br><span style="color:#a1a1aa;font-size:11px">Not ve seçili PDF kaynakları</span></span><div class="bn-r-bar-actions">${readerButton('','clear-qa','rotate-ccw',__bnT('aria-label="Soru panelini temizle"'))}${minimizeButton(2)}</div></div><div class="bn-r-qa-space">${state.response?`<div class="bn-r-message" role="status">${esc(state.response)}</div>`:__bnHTML`<div class="bn-r-empty">${ico('at-sign')}<div>Notunla konuş</div><p>Notun hakkında soru sor. Belirli bir PDF için @ yazıp kaynağını seç.</p></div>`}</div><div class="bn-r-compose"><div class="bn-r-prompts">${readerButton(__bnT('PDF seç'),'mention-open','at-sign')}</div><div data-qa-context class="bn-context-chips" aria-label="Seçilen PDF kaynakları"></div><div data-mention-menu class="bn-mention-menu" role="group" aria-label="PDF kaynağı seç" hidden></div><div class="bn-r-input-row"><textarea data-question aria-label="Kaynak hakkında soru" placeholder="Soru sor veya @ ile PDF seç…" aria-expanded="false">${esc(state.draft)}</textarea><button type="button" class="bn-r-send" data-action="ask" aria-label="Soruyu gönder">${ico('send')}</button></div><p class="bn-mini" style="margin-top:8px">BetterNotebook · İlk prototip · Canlı AI bağlı değil.</p></div></aside>`;
        return __bnHTML`<header class="bn-r-header">${readerButton('','reader-back','book-open',__bnT('aria-label="Notlara dön"'))}<div class="bn-r-title"><h3>${esc(n.title)}</h3>${readerCoursePicker(n)}</div><div class="bn-r-tools"><div class="bn-r-dock" role="toolbar" aria-label="Küçültülen paneller" data-window-dock hidden></div>${readerButton('A−','font-down','', __bnT('aria-label="Not yazısını küçült"'))}${readerButton('A+','font-up','', __bnT('aria-label="Not yazısını büyüt"'))}${readerButton('','reader-back','x',__bnT('aria-label="Notu kapat"'))}</div></header><div class="bn-r-grid" style="--bn-reader-font:${state.readerFont}px">${pdf}${note}${qa}<div class="bn-r-empty-state" data-windows-empty hidden>${ico('panels-top-left')}<h3>Tüm paneller küçültüldü</h3><p>Üst çubuktaki simgelerden birine tıklayarak geri aç.</p></div></div><div class="bn-r-hint"><span>Panelleri − ile küçült, üstteki simgelerden geri aç.</span><span>Örnek belge ve AI içeriği</span></div>`;
      }
      function readerContent(n){
        if(isNotebook(n))return __bnHTML`<div class="bn-personal-editor"><input id="bn-personal-title-${index}" class="bn-personal-title" data-note-title aria-label="Not başlığı" maxlength="160" value="${esc(n.title)}" placeholder="Notuna bir başlık ver"><textarea id="bn-note-edit-${index}" class="bn-note-edit" data-note-edit aria-label="Kendi notların" placeholder="Aklındakileri yazmaya başla…">${esc(n.body||'')}</textarea><div class="bn-section-top"><span class="bn-mini">Değişiklikler otomatik kaydedilir.</span>${action(__bnT('Notu kaydet'),'save-note','check',true)}</div></div>`;
        if(state.tab==='notes')return `<textarea id="bn-note-edit-${index}" class="bn-note-edit" data-note-edit>${esc(state.userNote)}</textarea><div class="bn-section-top"><span class="bn-mini">Notların veritabanında saklanır.</span>${action(__bnT('Notu kaydet'),'save-note','check',true)}</div>`;
        if(state.tab==='questions')return `<div class="bn-question-card"><div class="bn-row" style="justify-content:space-between"><span class="bn-chip">Örnek AI çıktısı · Kavramsal</span><span class="bn-mini">1 / 5 soru</span></div><h3 style="margin:17px 0 10px">Bir düğüme 5 A akım giriyor. Kollardan birinde 2 A akım çıkıyorsa diğer koldaki akım kaç amperdir?</h3><p class="bn-muted">Kirchhoff’un akım yasasını kullanarak açıklayın.</p><div style="margin-top:18px">${action(state.answer?'Çözümü gizle':'Çözümü göster','answer','eye')}</div>${state.answer?'<div class="bn-answer"><strong>Çözüm: 3 A.</strong><br>Giren akım = çıkan akım. 5 = 2 + I olduğundan I = 3 A.<br><span class="bn-mini">Bu içerik tasarım için hazırlanmış örnek sorudur.</span></div>':''}</div><div class="bn-question-card"><span class="bn-chip">Örnek AI çıktısı · Sayısal</span><h3 style="margin:15px 0 8px">12 V kaynağa seri bağlı 2 Ω ve 4 Ω dirençlerden geçen akımı bulun.</h3><p class="bn-muted">Önce eşdeğer direnci, sonra akımı hesapla.</p></div>`;
        if(state.tab==='plan')return __bnHTML`<div class="bn-panel"><h3>Kirchhoff Yasaları · 3 günlük tekrar</h3><div class="bn-mini" style="margin-top:4px">Örnek çalışma planı</div>${[['01','Temel kavramlar',__bnT('Düğüm ve çevre yasalarını oku. 25 dk odaklan.')],['02','Çözümlü sorular','İki düğüm ve iki çevre sorusunu adım adım çöz.'],['03','Kendini dene','Çözümleri kapatarak soruları yeniden cevapla.']].map(([d,t,s])=>`<div class="bn-plan-day"><span class="bn-plan-num">${d}</span><div><strong>${t}</strong><p class="bn-subtitle">${s}</p></div></div>`).join('')}<div style="margin-top:18px">${action('İlk oturumu başlat','focus','play',true)}</div></div>`;
        const electrical=n.id===1;
        return __bnHTML`<div class="bn-reader-layout"><article class="bn-paper"><div class="bn-paper-kicker">${esc(n.kind)} önizlemesi · Örnek içerik</div><h2>${electrical?'Elektrik devrelerinin<br>temel yasaları':esc(n.title)}</h2>${electrical?'<p>Bir elektrik devresini çözmek için iki temel korunum ilkesinden yararlanırız: <mark>yükün korunumu ve enerjinin korunumu.</mark></p><h3>Kirchhoff’un akım yasası</h3><p>Bir düğüme giren akımların toplamı, o düğümden çıkan akımların toplamına eşittir.</p><div class="bn-formula">∑ I<sub>giren</sub> = ∑ I<sub>çıkan</sub></div><p>Akım yönünü başlangıçta serbestçe seçebiliriz. Hesapta negatif değer çıkması, gerçek yönün seçtiğimiz yönün tersi olduğunu gösterir.</p>':__bnT('<p>Bu bölüm kaynak, kişisel notlar ve AI araçlarını aynı çalışma alanında bir araya getirir.</p><p>Okuduğun önemli noktaları notlarına aktarabilir, örnek sorulara ve çalışma planına geçebilirsin.</p>')}<div class="bn-mini">Belge gösterimi temsilidir.</div></article><aside class="bn-ai"><div class="bn-ai-heading">${ico('sparkles')} Bu kaynakla çalış</div><div class="bn-mini">Örnek AI yanıtları · Canlı model bağlı değil</div>${action('Konu özeti','summary','align-left')}${action('Çözümlü soru üret','questions','list-checks')}${action('Çalışma planı hazırla','plan','calendar-check')}${action(__bnT('PDF’i böl / sayfa seç'),'split','scissors')}${state.split?__bnHTML`<div class="bn-split-controls"><label>Sayfa aralığı<input data-range value="1-8" aria-label="Ayrılacak sayfa aralığı"></label>${action('Bölümü oluştur','split-create','check')}</div>`:''}${state.response?`<div class="bn-ai-response" role="status">${esc(state.response)}</div>`:''}<textarea class="bn-question" data-question aria-label="Kaynak hakkında soru" placeholder="Anlamadığın noktayı sor…">${esc(state.draft)}</textarea>${action(__bnT('Soruyu gönder'),'ask','arrow-up',true)}</aside></div>`;
      }
      function lastNoteCard(last){return __bnHTML`<button type="button" class="bn-last-note" data-note="${last.id}" aria-label="${esc(last.title)} çalışmaya devam et"><span class="bn-last-top"><span class="bn-last-icon">${ico(last.kind==='PPTX'?'presentation':last.kind==='Not'?'file-pen-line':'file-text')}</span><span class="bn-last-label">${ico('history')}Son çalıştığın not</span></span><span class="bn-last-copy"><span class="bn-last-title">${esc(last.title)}</span><span class="bn-last-summary">${esc(last.summary)}</span></span><span class="bn-last-footer"><span class="bn-last-meta"><span>${esc(last.course||__bnT('Ders atanmamış'))}</span><span>${esc(last.kind==='PPTX'?'PowerPoint':last.kind==='Not'?__bnT('Kişisel not'):last.kind)}${last.pages?' · '+last.pages+__bnT(' sayfa'):''}</span></span><span class="bn-last-cta">Devam et ${ico('arrow-up-right')}</span></span></button>`;}
      function today(){const last=state.notes.find(n=>n.id===state.lastWorkedNoteId);const others=state.recentNoteIds.filter(id=>id!==last?.id).map(id=>state.notes.find(n=>n.id===id)).filter(Boolean);return `${heading('',__bnT('Son açılanlar'),'',__bnHTML`<button type="button" class="bn-btn" data-view="notes">Tüm notlar ${ico('arrow-right')}</button>`)}<div class="bn-recent-grid">${last?lastNoteCard(last):''}${others.map(noteCard).join('')}</div>`;}
      function courseOptions(selected='',unassigned=false){return `${unassigned?__bnT('<option value="">Ders atanmamış</option>'):''}${state.courses.map(course=>`<option value="${esc(course)}" ${course===selected?'selected':''}>${esc(course)}</option>`).join('')}`;}
      function courseManager(){return __bnHTML`<section class="bn-panel bn-course-manager" data-course-manager ${state.coursesOpen?'':'hidden'} aria-label="Dersleri düzenle"><div class="bn-row" style="justify-content:space-between"><div><h3>Dersler</h3><p class="bn-subtitle">Bir ders ekle, notlarına ata ve kolayca filtrele.</p></div><button type="button" class="bn-icon-btn" data-action="toggle-courses" aria-label="Dersleri kapat">${ico('x')}</button></div><div class="bn-course-add"><label for="bn-course-name-${index}">Ders adı<input id="bn-course-name-${index}" data-course-name maxlength="80" placeholder="Örn. Elektrik Makineleri"></label>${action(__bnT('Ders ekle'),'add-course','plus',true)}</div><div data-course-feedback role="status" aria-live="polite" class="bn-mini"></div><div class="bn-course-shortcuts">${state.courses.map(course=>__bnHTML`<div class="bn-trash-course"><button type="button" class="bn-btn" data-course-filter="${esc(course)}"><span>${esc(course)}</span><span class="bn-mini">${state.notes.filter(n=>n.course===course).length} not</span>${ico('arrow-right')}</button>${courseTrashButton(course)}</div>`).join('')}</div></section>`;}
      function addCourse(){const input=main.querySelector('[data-course-name]');const name=input.value.trim().replace(/\s+/g,' ');const feedback=main.querySelector('[data-course-feedback]');if(!name){feedback.setAttribute('role','alert');feedback.textContent=__bnT('Bir ders adı yaz.');input.focus();return;}if(state.courses.some(course=>course.toLocaleLowerCase('tr')===name.toLocaleLowerCase('tr'))){feedback.setAttribute('role','alert');feedback.textContent=__bnT('Bu ders zaten ekli.');input.focus();return;}state.courses.push(name);render();main.querySelector('[data-course-name]')?.focus();notify(name+' eklendi. Notu açarak dersini seçebilirsin.');}
      function studyCourses(){return [...new Set([...state.courses,study.course,...study.sessions.map(row=>row[1])])].filter(Boolean);}
      function studyCourseOptions(selected){return studyCourses().map(course=>`<option value="${esc(course)}" ${selected===course?'selected':''}>${esc(course)}</option>`).join('');}
      function localDateTime(){const now=new Date();return new Date(now.getTime()-now.getTimezoneOffset()*60000).toISOString().slice(0,16);}
      function studyStatus(){if(study.runningSince)return study.phase==='focus'?__bnT('Şimdi yalnızca bu derse odaklan.'):__bnT('Biraz uzaklaş. Mola süresi çalışmaya eklenmez.');if(studyActive())return __bnT('Oturum duraklatıldı. Hazır olduğunda devam et.');if(study.completedPhase)return study.completedPhase==='focus'?__bnT('Oturum kaydedildi. Bir molayı hak ettin.'):__bnT('Mola tamamlandı. Yeni bir oturuma hazırsın.');return study.phase==='focus'?__bnT('Küçük bir başlangıç, daha net bir zihin.'):__bnT('Dinlenmek de çalışma düzeninin bir parçası.');}
      function focusPage(){
        const total=study.sessions.reduce((sum,row)=>sum+row[2],0),todayKey=new Date().toDateString();const today=study.sessions.filter(row=>new Date(row[4]).toDateString()===todayKey).reduce((sum,row)=>sum+row[2],0);
        const grouped=studyCourses().map(course=>({course,rows:study.sessions.filter(row=>row[1]===course)})).map(item=>({...item,seconds:item.rows.reduce((sum,row)=>sum+row[2],0)})).sort((a,b)=>b.seconds-a.seconds);
        const rows=study.sessions.filter(row=>state.studyFilter==='all'||row[1]===state.studyFilter);
        const busy=studyActive(),duration=studyDuration()/1000,remaining=studySeconds();
        return __bnHTML`${heading(__bnT('Kendi ritmini bul'),__bnT('Odaklan'),__bnT('Bir ders seç. Zamanını ayır. İlerlemeni gör.'),__bnHTML`<div class="bn-pomo-today"><span>Bugünkü çalışma</span><strong>${studyTime(today)}</strong></div>`)}<div class="bn-pomo-layout"><section class="bn-pomo-studio" aria-label="Pomodoro sayacı"><div class="bn-pomo-modes" role="group" aria-label="Oturum türü">${Object.entries(studyPhases).map(([phase,label])=>`<button type="button" data-action="study-phase" data-phase="${phase}" aria-pressed="${study.phase===phase}" ${busy?'disabled':''}>${label}</button>`).join('')}</div><label class="bn-pomo-course" for="bn-study-course-${index}"><span>Bu oturumun dersi</span><select id="bn-study-course-${index}" data-study-course ${busy?'disabled':''}>${studyCourseOptions(study.course)}</select></label><div class="bn-pomo-clock" data-study-ring role="progressbar" aria-label="Kalan oturum süresi" aria-valuemin="0" aria-valuemax="${duration}" aria-valuenow="${remaining}" aria-valuetext="${clockTime(remaining)}" style="--bn-focus-progress:${study.remainingMs/studyDuration()*100}"><div><span class="bn-pomo-clock-label">${studyPhases[study.phase]}</span><div class="bn-pomo-digits" data-study-clock aria-live="off">${clockTime(remaining)}</div><span class="bn-pomo-clock-detail" data-study-elapsed>${study.phase==='focus'?__bnT('Çalışılan ')+studyTime(Math.floor(study.elapsedMs/1000)):__bnT('Mola süresi')}</span></div></div><p class="bn-pomo-status" data-study-status>${studyStatus()}</p><div class="bn-pomo-actions">${action(study.runningSince?__bnT('Duraklat'):busy?__bnT('Devam et'):study.completedPhase?__bnT('Yeni oturum başlat'):__bnT('Odaklanmaya başla'),'timer-toggle',study.runningSince?'pause':'play',true)}${busy?action(study.phase==='focus'?__bnT('Bitir ve kaydet'):__bnT('Molayı bitir'),'study-finish',study.phase==='focus'?'check':'skip-forward'):action(__bnT('Süreler'),'study-durations','sliders-horizontal')}</div>${study.completedPhase==='focus'?__bnHTML`<button type="button" class="bn-pomo-text-btn" data-action="study-phase" data-phase="short">${study.shortMinutes} dk mola ver ${ico('arrow-right')}</button>`:''}<div class="bn-pomo-durations" data-study-durations ${state.durationsOpen?'':'hidden'}>${[['focusMinutes',__bnT('Odak'),'180'],['shortMinutes',__bnT('Kısa mola'),'60'],['longMinutes',__bnT('Uzun mola'),'60']].map(([field,label,max])=>__bnHTML`<label>${label}<span><input type="number" data-study-duration="${field}" aria-label="${label} süresi (dakika)" min="1" max="${max}" step="1" value="${study[field]}" ${busy?'disabled':''}>dk</span></label>`).join('')}</div><div class="bn-pomo-footnote">${ico('circle-check')}${busy?__bnT('Ders ve süre, oturum bitene kadar sabit kalır.'):__bnT('Molalar çalışma toplamına eklenmez.')}</div></section><aside class="bn-pomo-summary"><div class="bn-pomo-summary-heading"><span class="bn-pomo-kicker">DERSLERE AYIRDIĞIN ZAMAN</span>${ico('chart-no-axes-column-increasing')}</div><div class="bn-pomo-total">${studyTime(total)}</div><p class="bn-pomo-summary-sub">${study.sessions.length} kayıtlı çalışma oturumu</p><div class="bn-pomo-course-totals">${grouped.map(({course,rows,seconds})=>`<div class="bn-pomo-course-total"><span class="bn-pomo-course-icon">${ico('book-open')}</span><div><strong>${esc(course)}</strong><span>${rows.length?rows.length+__bnT(' oturum'):__bnT('Henüz oturum yok')}</span></div><strong>${studyTime(seconds)}</strong></div>`).join('')}</div><p class="bn-pomo-summary-note">Her oturum kendi dersiyle kaydedilir. Toplamlar aşağıdaki çalışma kayıtlarından hesaplanır.</p></aside></div><section class="bn-pomo-history" aria-label="Çalışma geçmişi"><div class="bn-section-top"><div><h3>Çalışma geçmişi</h3><p class="bn-subtitle">Her oturum, ayrı bir adım.</p></div><div class="bn-row"><select class="bn-select" data-study-filter aria-label="Çalışma geçmişini derse göre filtrele"><option value="all">Tüm dersler</option>${studyCourseOptions(state.studyFilter)}</select>${action(__bnT('Oturum ekle'),'study-manual','plus')}</div></div><div class="bn-pomo-manual" data-study-manual ${state.manualOpen?'':'hidden'}><div class="bn-pomo-manual-heading"><h3>Geçmiş çalışma ekle</h3><button type="button" class="bn-icon-btn" data-action="study-manual" aria-label="Oturum eklemeyi kapat">${ico('x')}</button></div><div class="bn-pomo-manual-fields"><label>Ders<select data-manual-course aria-label="Geçmiş oturumun dersi">${studyCourseOptions(study.course)}</select></label><label>Çalışılan süre<span class="bn-pomo-number"><input type="number" data-manual-minutes aria-label="Geçmiş oturumun süresi (dakika)" value="25" min="1" max="360" step="1">dk</span></label><label>Bitiş zamanı<input type="datetime-local" data-manual-end aria-label="Geçmiş oturumun bitiş zamanı" value="${localDateTime()}" max="${localDateTime()}"></label>${action(__bnT('Oturumu kaydet'),'study-manual-save','check',true)}</div><div class="bn-mini" data-manual-feedback role="status" aria-live="polite"></div></div><div class="bn-pomo-records">${rows.length?rows.map(row=>{const date=new Date(row[4]),time=value=>new Date(value).toLocaleTimeString(window.BetterNotebookI18n.locale,{hour:'2-digit',minute:'2-digit'});return `<article class="bn-pomo-record"><span class="bn-pomo-record-icon">${ico(row[5]==='manual'?'pencil-line':'circle-check')}</span><div class="bn-pomo-record-name"><h3>${esc(row[1])}</h3><p>${date.toLocaleDateString(window.BetterNotebookI18n.locale,{day:'numeric',month:'short',year:'numeric'})} · ${time(row[3])}–${time(row[4])}</p></div><span class="bn-pomo-record-status">${{completed:__bnT('Tamamlandı'),partial:__bnT('Erken bitirildi'),manual:__bnT('Elle eklendi')}[row[5]]}</span><strong class="bn-pomo-record-duration">${studyTime(row[2])}</strong></article>`;}).join(''):__bnHTML`<div class="bn-pomo-empty">${ico('history')}<h3>${state.studyFilter==='all'?__bnT('İlk oturumunla başla'):__bnT('Bu ders için henüz oturum yok')}</h3><p>Tamamladığın çalışmalar burada tarih, ders ve süreleriyle görünecek.</p></div>`}</div><p class="bn-pomo-storage">Çalışma geçmişin veritabanında saklanır. Sayfalar arasında sayaç devam eder.</p></section>`;
      }
      function paintStudy(){
        host.querySelector('[data-view="timer"]')?.toggleAttribute('data-focus-running',Boolean(study.runningSince));
        if(state.view!=='timer')return;
        const clock=main.querySelector('[data-study-clock]'),ring=main.querySelector('[data-study-ring]');if(clock)clock.textContent=clockTime(studySeconds());
        if(ring){ring.style.setProperty('--bn-focus-progress',study.remainingMs/studyDuration()*100);ring.setAttribute('aria-valuemax',String(studyDuration()/1000));ring.setAttribute('aria-valuenow',String(studySeconds()));ring.setAttribute('aria-valuetext',clockTime(studySeconds()));}
        const elapsed=main.querySelector('[data-study-elapsed]');if(elapsed)elapsed.textContent=study.phase==='focus'?__bnT('Çalışılan ')+studyTime(Math.floor(study.elapsedMs/1000)):__bnT('Mola süresi');
      }
      function saveManualStudy(){const course=main.querySelector('[data-manual-course]').value,minutes=Number(main.querySelector('[data-manual-minutes]').value),end=new Date(main.querySelector('[data-manual-end]').value).getTime();const feedback=main.querySelector('[data-manual-feedback]');if(!course||!Number.isInteger(minutes)||minutes<1||minutes>360||!Number.isFinite(end)||end>Date.now()+60000){feedback.setAttribute('role','alert');feedback.textContent=__bnT('Ders, 1–360 dakika arasında süre ve geçmiş bir bitiş zamanı seç.');return;}recordStudy(minutes*60,end-minutes*60000,end,'manual',Date.now().toString(36)+Math.random().toString(36).slice(2,7),course);state.manualOpen=false;commitStudy();notify(__bnT('Çalışma oturumu kaydedildi.'));}
      function aiSettings(){
        const models=[];
        return __bnHTML`${heading('',__bnT('Ayarlar'),'')}<div class="bn-ai-settings-grid"><form class="bn-gemini-form" data-gemini-form><div class="bn-gemini-heading"><span>${ico('sparkles')}</span><div><h3>Google Gemini API</h3><p class="bn-subtitle">Notların için kişisel AI bağlantın.</p></div></div><div class="bn-gemini-field"><label for="bn-gemini-key-${index}">Gemini API anahtarı</label><div class="bn-gemini-input-row"><input id="bn-gemini-key-${index}" type="password" data-gemini-key value="${esc(state.geminiKey)}" placeholder="API anahtarını gir" autocomplete="off" spellcheck="false" aria-describedby="bn-gemini-help-${index}"><button type="button" data-action="toggle-gemini-key" aria-label="API anahtarını göster" aria-pressed="false">${ico('eye')}</button></div><div class="bn-row" style="justify-content:space-between"><span id="bn-gemini-help-${index}" class="bn-gemini-help">Google AI Studio’dan bir anahtar oluşturabilirsin.</span><a class="bn-ai-link" href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">API anahtarı al ${ico('external-link')}</a></div></div><div class="bn-gemini-field"><label for="bn-gemini-model-${index}">Gemini modeli</label><div class="bn-model-controls"><select id="bn-gemini-model-${index}" data-gemini-model>${models.map(([value,label])=>`<option value="${value}" ${state.geminiModel===value?'selected':''}>${label}</option>`).join('')}</select><button type="button" data-action="refresh-models" aria-label="Modelleri yenile" title="Modelleri yenile">${ico('refresh-cw')}</button></div><span data-model-status role="status" aria-live="polite"></span><span class="bn-gemini-help">Kullanım ve kota, Google hesabına ve seçtiğin modele bağlıdır.</span></div><a class="bn-ai-link" href="https://aistudio.google.com/usage" target="_blank" rel="noopener noreferrer">Google AI Studio’da kullanımını görüntüle ${ico('arrow-up-right')}</a><div class="bn-gemini-actions">${action(__bnT('Bağlantıyı test et'),'test-gemini','activity')}<button type="submit" class="bn-btn bn-btn-primary">${ico('check')}Ayarları kaydet</button></div><div class="bn-gemini-feedback" data-gemini-feedback role="status" aria-live="polite" ${state.geminiSaved?'':'hidden'}>${state.geminiSaved?__bnT('Gemini ayarları kaydedildi.'):''}</div><p class="bn-gemini-preview">API anahtarın tarayıcıya geri gönderilmez. Boş bırakırsan mevcut anahtarın korunur.</p></form><aside class="bn-cloud-offer" aria-label="BetterNotebook Cloud tanıtımı"><div class="bn-cloud-brand"><span>${ico('cloud')}BetterNotebook Cloud</span><span class="bn-cloud-soon">Yakında</span></div><h2>Sen notlarına odaklan.<br>Gerisini bize bırak.</h2><p class="bn-cloud-copy">Anahtar oluşturmak, kurulum yapmak ve bakım için zaman harcama. Hazır bir çalışma alanında doğrudan öğrenmeye başla.</p><ul class="bn-cloud-benefits"><li>${ico('key-round')}<span>API anahtarıyla uğraşmadan AI kullan.</span></li><li>${ico('monitor-smartphone')}<span>Notlarına farklı cihazlarından devam et.</span></li><li>${ico('shield-check')}<span>Barındırma ve yedeklemeyi bize bırak.</span></li></ul><button type="button" class="bn-btn bn-btn-primary" data-action="cloud-details" aria-expanded="${state.cloudDetails}" aria-controls="bn-cloud-details-${index}">Cloud’u keşfet ${ico('arrow-up-right')}</button><div id="bn-cloud-details-${index}" class="bn-cloud-details" data-cloud-details ${state.cloudDetails?'':'hidden'}>AI erişimi, depolama ve bakım tek yerde. Kendi bağlantınla kullanmaya devam edebilir, hazır olduğunda Cloud’a geçebilirsin.<p style="margin-top:10px">Cloud geliştirme aşamasında. Kullanım hakları ve fiyatlar duyurulduğunda burada paylaşılacak.</p></div></aside></div>`;
      }
      function geminiFeedback(message,error=false){const feedback=main.querySelector('[data-gemini-feedback]');if(!feedback)return;feedback.hidden=false;feedback.setAttribute('role',error?'alert':'status');feedback.textContent=message;}
      function validateGemini(){if(!state.geminiKey.trim()){geminiFeedback(__bnT('Önce API anahtarı alanını doldur.'),true);main.querySelector('[data-gemini-key]')?.focus();return false;}return true;}
      function saveGeminiSettings(){if(!validateGemini())return;state.geminiSaved=true;state.mode='byok';geminiFeedback('Tercihlerin bu önizleme oturumunda saklandı. Anahtar bir sunucuya gönderilmedi.');}
      function render(){
        closeAITools();
        unmountNoteEditor();
        syncStudy();
        cancelWindowMotion();
        closeProfile();
        state.notice='';
        host.querySelector('.bn-shell').classList.toggle('bn-reading',state.view==='reader');
        host.querySelectorAll('[data-view]').forEach(b=>{if(b.dataset.view===state.view||(state.view==='reader'&&b.dataset.view==='notes'))b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});
        host.querySelector('[data-breadcrumb]').textContent=state.view==='reader'?__bnT('Çalışma alanı'):([...nav,['settings',__bnT('Ayarlar')]].find(n=>n[0]===state.view)||[])[1]||__bnT('Not defterim');
        host.querySelector('[data-mode-badge]').textContent=__bnT('Kişisel çalışma alanı');
        host.querySelector('[data-mode-status]').textContent=__bnT('Sürüm v')+boot.version;
        const activeIndex=nav.findIndex(([id])=>id===(state.view==='reader'?'notes':state.view));const glassNav=host.querySelector('.bn-glass-nav');glassNav.style.setProperty('--bn-nav-index',Math.max(0,activeIndex));glassNav.toggleAttribute('data-has-selection',activeIndex>=0);
        if(state.view==='notes')main.innerHTML=__bnHTML`${heading('',__bnT('Not defterim'),__bnT('Ders notlarını bir araya getir, anlamaya zaman ayır.'),action(__bnT('Yeni not'),'new','plus',true))}<div class="bn-toolbar"><label class="bn-search">${ico('search')}<input data-search aria-label="Notlarda ara" placeholder="Notlarında ara…" value="${esc(state.query)}"></label><div class="bn-row"><select class="bn-select" data-filter aria-label="Derse göre filtrele"><option value="all">Tüm dersler</option>${courseOptions(state.filter)}<option value="unassigned">Ders atanmamış</option></select><button type="button" class="bn-btn" data-action="toggle-courses" aria-expanded="${state.coursesOpen}" aria-controls="bn-courses-${index}">${ico('book-open')}Dersler</button>${trashOpenButton()}</div></div><div id="bn-courses-${index}">${courseManager()}</div><div class="bn-section-top"><h3>Notlar & kaynaklar</h3><span class="bn-mini" data-count></span></div><div class="bn-notes" data-note-grid></div>`;
        else if(state.view==='files')main.innerHTML=filesPage();
        else if(state.view==='reader')main.innerHTML=reader();
        else if(state.view==='today')main.innerHTML=today();
        else if(state.view==='timer')main.innerHTML=focusPage();
        else if(state.view==='settings'){main.innerHTML=aiSettings();const save=main.querySelector('[data-gemini-form] button[type="submit"]');save.type='button';save.dataset.action='save-gemini';}
        if(state.view==='reader'){
          const tools=main.querySelector('.bn-r-tools');
          tools.querySelector('[data-action="reader-back"]').insertAdjacentHTML('beforebegin',aiToolsButton());

          const saveButton=main.querySelector('[data-action="save-note"]');
          if(saveButton){const oldFooter=saveButton.closest('.bn-section-top');tools.insertBefore(saveButton,tools.querySelector('[data-action="ai-tools"]'));saveButton.setAttribute('title',__bnT('Değişiklikler otomatik kaydedilir.'));oldFooter?.remove();}
          updateReaderWindows();
          paintQAContext();
          main.querySelectorAll('.bn-r-bar>span').forEach(label=>label.setAttribute('data-tooltip',label.innerText.replace(/\s+/g,' ').trim()));
        }
        main.insertAdjacentHTML('beforeend','<div class="bn-notice" role="status" aria-live="polite" data-notice></div>');
        if(state.view==='notes'){main.querySelector('[data-filter]').value=state.filter;filterNotes();}
        paintStudy();
        if(state.view==='settings'){languageSettings();initModelCatalog();}
        upgradeSelects();
        mountNoteEditor();
        applyQAFont();
        paintPDFStudy();
        paintQACapture();
        scrollQAEnd();
        icons();
      }
      function cancelWindowMotion(){
        windowMotion.animations.forEach(animation=>animation.cancel());windowMotion.animations.clear();
        windowMotion.ghosts.forEach(ghost=>ghost.remove());windowMotion.ghosts.clear();
      }
      function animateWindowElement(element,keyframes,label,onFinish){
        const animation=element.animate(keyframes,{duration:360,easing:'cubic-bezier(.22,.8,.22,1)',fill:'both'});
        animation.id='bn-window-'+label;windowMotion.animations.add(animation);
        function done(){windowMotion.animations.delete(animation);animation.cancel();onFinish?.();}
        animation.finished.then(done,()=>{windowMotion.animations.delete(animation);onFinish?.();});
      }
      function openNoteWithMotion(origin){
        cancelWindowMotion();
        const card=origin?.closest('.bn-note,.bn-continue,.bn-course-row')||origin;
        const source=card?.getBoundingClientRect();
        go('reader');
        if(matchMedia('(prefers-reduced-motion: reduce)').matches||!source?.width||!source.height)return;
        const reader=host.querySelector('.bn-main');const destination=reader.getBoundingClientRect();if(!destination.width||!destination.height)return;
        animateWindowElement(reader,[{transform:`translate(${source.left-destination.left}px,${source.top-destination.top}px) scale(${source.width/destination.width},${source.height/destination.height})`,opacity:.2,borderRadius:'16px',overflow:'hidden',zIndex:10},{transform:'none',opacity:1,borderRadius:'0px',overflow:'hidden',zIndex:10}],'note-open');
      }
      function closeNoteWithMotion(){
        cancelWindowMotion();
        const reader=host.querySelector('.bn-main');const source=reader.getBoundingClientRect();
        const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
        let ghost=null;
        if(!reduced&&source.width&&source.height){
          ghost=reader.cloneNode(true);ghost.removeAttribute('id');ghost.setAttribute('aria-hidden','true');ghost.inert=true;
          ghost.classList.add('bn-reading','bn-r-motion-ghost','bn-note-motion-ghost');ghost.querySelectorAll('[id]').forEach(el=>el.removeAttribute('id'));
          const fields=reader.querySelectorAll('input,textarea,select');ghost.querySelectorAll('input,textarea,select').forEach((field,i)=>{field.value=fields[i].value;if('checked' in field)field.checked=fields[i].checked;});
        }
        go(state.readerReturnView||'notes');
        if(!ghost)return;
        const shell=host.querySelector('.bn-shell');const shellRect=shell.getBoundingClientRect();
        const card=main.querySelector(`[data-note="${state.noteId}"]`)||main.querySelector('.bn-continue')||main.querySelector('.bn-heading');
        const destination=card.getBoundingClientRect();
        Object.assign(ghost.style,{left:(source.left-shellRect.left-shell.clientLeft)+'px',top:(source.top-shellRect.top-shell.clientTop)+'px',width:source.width+'px',height:source.height+'px'});
        shell.append(ghost);windowMotion.ghosts.add(ghost);
        animateWindowElement(ghost,[{transform:'translate(0,0) scale(1,1)',opacity:1,borderRadius:'0px'},{transform:`translate(${destination.left-source.left}px,${destination.top-source.top}px) scale(${destination.width/source.width},${destination.height/source.height})`,opacity:0,borderRadius:'16px'}],'note-close',()=>{ghost.remove();windowMotion.ghosts.delete(ghost);});
        animateWindowElement(card,[{transform:'scale(.96)',opacity:.5},{transform:'none',opacity:1}],'note-card');
        card.focus?.({preventScroll:true});
      }
      function animateReaderWindow(id,minimize){
        cancelWindowMotion();
        const shell=host.querySelector('.bn-shell');const grid=main.querySelector('.bn-r-grid');
        const pane=grid.querySelector(`[data-pane="${id}"]`);const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
        const before=new Map();grid.querySelectorAll('[data-pane]').forEach(el=>{if(!el.hidden)before.set(Number(el.dataset.pane),el.getBoundingClientRect());});
        const sourceRect=minimize?pane.getBoundingClientRect():main.querySelector(`[data-action="restore-${id}"]`)?.getBoundingClientRect();
        let ghost=null;
        if(!reduced&&minimize&&sourceRect?.width>0){
          const shellRect=shell.getBoundingClientRect();ghost=pane.cloneNode(true);ghost.removeAttribute('data-pane');ghost.removeAttribute('id');ghost.setAttribute('aria-hidden','true');ghost.inert=true;ghost.classList.add('bn-r-motion-ghost');
          ghost.querySelectorAll('[id]').forEach(el=>el.removeAttribute('id'));
          Object.assign(ghost.style,{left:(sourceRect.left-shellRect.left-shell.clientLeft)+'px',top:(sourceRect.top-shellRect.top-shell.clientTop)+'px',width:sourceRect.width+'px',height:sourceRect.height+'px'});
          shell.append(ghost);windowMotion.ghosts.add(ghost);
        }
        if(minimize)state.minimized.add(id);else state.minimized.delete(id);
        updateReaderWindows();icons();
        if(reduced)return;
        grid.querySelectorAll('[data-pane]').forEach(el=>{
          const paneId=Number(el.dataset.pane);if(el.hidden||paneId===id||!before.has(paneId))return;
          const old=before.get(paneId),next=el.getBoundingClientRect();if(!next.width||!next.height)return;
          if(Math.abs(old.left-next.left)+Math.abs(old.top-next.top)+Math.abs(old.width-next.width)+Math.abs(old.height-next.height)<1)return;
          animateWindowElement(el,[{transform:`translate(${old.left-next.left}px,${old.top-next.top}px) scale(${old.width/next.width},${old.height/next.height})`},{transform:'none'}],'reflow');
        });
        if(minimize&&ghost){
          const target=main.querySelector(`[data-action="restore-${id}"]`);const destination=target.getBoundingClientRect();const dx=destination.left-sourceRect.left,dy=destination.top-sourceRect.top;
          animateWindowElement(ghost,[{transform:'translate(0,0) scale(1,1)',opacity:1,borderRadius:'10px'},{transform:`translate(${dx*.5}px,${dy*.55}px) scale(.6,.6)`,opacity:.9,offset:.55},{transform:`translate(${dx}px,${dy}px) scale(${destination.width/sourceRect.width},${destination.height/sourceRect.height})`,opacity:0,borderRadius:'16px'}],'minimize',()=>{ghost.remove();windowMotion.ghosts.delete(ghost);});
          animateWindowElement(target,[{transform:'scale(.8)',opacity:.4},{transform:'scale(1)',opacity:1}],'dock');
        }else if(!minimize&&sourceRect?.width>0){
          const destination=pane.getBoundingClientRect();if(destination.width&&destination.height)animateWindowElement(pane,[{transform:`translate(${sourceRect.left-destination.left}px,${sourceRect.top-destination.top}px) scale(${sourceRect.width/destination.width},${sourceRect.height/destination.height})`,opacity:.15,zIndex:10,borderRadius:'18px'},{transform:'none',opacity:1,zIndex:10,borderRadius:'0px'}],'restore');
        }
      }
      function updateReaderWindows(){
        const grid=main.querySelector('.bn-r-grid');if(!grid)return;
        const ids=visibleReaderPanes();
        grid.querySelectorAll('.bn-r-divider').forEach(el=>el.remove());
        grid.querySelectorAll('[data-pane]').forEach(el=>{el.hidden=state.minimized.has(Number(el.dataset.pane));});
        const empty=grid.querySelector('[data-windows-empty]');
        state.order.forEach((id,index)=>{grid.querySelector(`[data-pane="${id}"]`).style.order=String(index*2);});
        ids.slice(1).forEach((id,i)=>{
          const left=ids[i];
          grid.querySelector(`[data-pane="${id}"]`).insertAdjacentHTML('beforebegin',__bnHTML`<button type="button" class="bn-r-divider" style="order:${state.order.indexOf(id)*2-1}" role="separator" aria-orientation="vertical" aria-label="${paneNames[left]} ile ${paneNames[id]} panel genişliğini ayarla" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(state.widths[left])}" data-resize-left="${left}" data-resize-right="${id}"></button>`);
        });
        grid.querySelector('[data-windows-empty]').hidden=ids.length>0;
        const dock=main.querySelector('[data-window-dock]');dock.hidden=state.minimized.size===0;
        dock.innerHTML=state.order.filter(id=>state.minimized.has(id)).map(id=>__bnHTML`<button type="button" class="bn-r-button bn-r-window-icon" data-action="restore-${id}" aria-label="${paneNames[id]} panelini geri aç" data-tooltip="${paneNames[id]} panelini geri aç">${ico(paneIcons[id])}</button>`).join('');
        setupResizing();setupPanelSorting();
      }
      function reorderPanel(source,target,before){
        if(source===target)return;
        const next=state.order.filter(id=>id!==source);const index=next.indexOf(target);if(index<0)return;
        next.splice(index+(before?0:1),0,source);if(next.join(',')===state.order.join(','))return;
        state.order=next;updateReaderWindows();icons();persist();
        main.querySelector(`[data-panel-grip="${source}"]`)?.focus({preventScroll:true});
        
      }
      function setupPanelSorting(){
        const grid=main.querySelector('.bn-r-grid');if(!grid)return;
        grid.querySelectorAll('[data-pane]').forEach(pane=>{
          const header=pane.querySelector('.bn-r-bar');if(!header||sortedHeaders.has(header))return;sortedHeaders.add(header);
          const id=Number(pane.dataset.pane);const grip=document.createElement('button');
          grip.type='button';grip.className='bn-r-grip';grip.dataset.panelGrip=String(id);grip.setAttribute('aria-label',paneNames[id]+__bnT(' panelini taşı'));grip.setAttribute('data-tooltip',__bnT('Sürükleyerek taşı · Ok tuşlarıyla sırala'));grip.innerHTML=ico('grip-vertical');
          const oldIcon=header.firstElementChild;if(oldIcon?.matches('i[data-lucide],svg'))oldIcon.replaceWith(grip);else header.prepend(grip);
          let drag=null;
          function clearPreview(){grid.removeAttribute('data-sorting');grid.querySelectorAll('[data-sort-source],[data-drop-side]').forEach(el=>{el.removeAttribute('data-sort-source');el.removeAttribute('data-drop-side');});}
          function finish(event,cancel=false){
            if(!drag||event.pointerId!==drag.pointer)return;const result=drag;drag=null;clearPreview();
            if(header.hasPointerCapture(event.pointerId))header.releasePointerCapture(event.pointerId);
            if(!cancel&&result.active&&result.target!==null)reorderPanel(id,result.target,result.before);
          }
          header.addEventListener('pointerdown',event=>{
            if(event.button!==0||event.target.closest('button')!==null&&!event.target.closest('[data-panel-grip]'))return;
            event.preventDefault();grip.focus({preventScroll:true});drag={pointer:event.pointerId,x:event.clientX,y:event.clientY,target:null,before:false,active:false};header.setPointerCapture(event.pointerId);
          });
          header.addEventListener('pointermove',event=>{
            if(!drag||event.pointerId!==drag.pointer)return;
            if(!drag.active&&Math.hypot(event.clientX-drag.x,event.clientY-drag.y)<6)return;
            event.preventDefault();drag.active=true;clearPreview();grid.setAttribute('data-sorting','');pane.setAttribute('data-sort-source','');drag.target=null;
            const stacked=getComputedStyle(grid).display==='flex';const area=grid.getBoundingClientRect();
            if(event.clientX<area.left||event.clientX>area.right||event.clientY<area.top||event.clientY>area.bottom)return;
            const coordinate=stacked?event.clientY:event.clientX;
            const candidates=visibleReaderPanes().filter(other=>other!==id).map(other=>{const el=grid.querySelector(`[data-pane="${other}"]`);const rect=el.getBoundingClientRect();return {id:other,el,start:stacked?rect.top:rect.left,end:stacked?rect.bottom:rect.right};});
            const target=candidates.find(item=>coordinate>=item.start&&coordinate<=item.end);if(!target)return;
            drag.target=target.id;drag.before=coordinate<(target.start+target.end)/2;target.el.setAttribute('data-drop-side',drag.before?'before':'after');
          });
          header.addEventListener('pointerup',event=>finish(event));header.addEventListener('pointercancel',event=>finish(event,true));header.addEventListener('lostpointercapture',event=>{if(drag)finish(event,true);});
          header.addEventListener('keydown',event=>{if(event.key==='Escape'&&drag){const pointer=drag.pointer;drag=null;clearPreview();if(header.hasPointerCapture(pointer))header.releasePointerCapture(pointer);}});
          grip.addEventListener('keydown',event=>{
            if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();
            const ids=visibleReaderPanes(),position=ids.indexOf(id);const direction=['ArrowLeft','ArrowUp'].includes(event.key)?-1:1;const target=ids[position+direction];
            if(target!==undefined)reorderPanel(id,target,direction<0);
          });
        });
      }
      function updateReaderColumns(){
        const grid=main.querySelector('.bn-r-grid');if(!grid)return;
        const ids=visibleReaderPanes();const handle='1px';
        grid.style.gridTemplateColumns=ids.length?ids.map(id=>`minmax(0,${state.widths[id]}fr)`).join(` ${handle} `):'minmax(0,1fr)';
        grid.querySelectorAll('[data-resize-left]').forEach(el=>el.setAttribute('aria-valuenow',String(Math.round(state.widths[Number(el.dataset.resizeLeft)]))));
        placeMentionMenu();
      }

      function setupResizing(){
        const grid=main.querySelector('.bn-r-grid');updateReaderColumns();
        grid.querySelectorAll('[data-resize-left]').forEach(handle=>{
          let drag=null;
          function snapshot(){const left=Number(handle.dataset.resizeLeft),right=Number(handle.dataset.resizeRight);return {left,right,leftPx:grid.querySelector(`[data-pane="${left}"]`).getBoundingClientRect().width,rightPx:grid.querySelector(`[data-pane="${right}"]`).getBoundingClientRect().width,leftWeight:state.widths[left],rightWeight:state.widths[right]};}
          function resize(start,delta){const total=start.leftPx+start.rightPx;if(total<=0)return;const desiredMinimum=[140,180,150];const scale=Math.min(1,total/(desiredMinimum[start.left]+desiredMinimum[start.right]+20));const minLeft=desiredMinimum[start.left]*scale,minRight=desiredMinimum[start.right]*scale;const px=Math.min(total-minRight,Math.max(minLeft,start.leftPx+delta));const weight=start.leftWeight+start.rightWeight;state.widths[start.left]=weight*px/total;state.widths[start.right]=weight-state.widths[start.left];updateReaderColumns();}
          function finish(event){if(!drag||event.pointerId!==drag.pointer)return;drag=null;grid.removeAttribute('data-dragging');handle.removeAttribute('data-dragging');if(handle.hasPointerCapture(event.pointerId))handle.releasePointerCapture(event.pointerId);persist();}
          handle.addEventListener('pointerdown',event=>{if(event.button!==0)return;event.preventDefault();handle.focus({preventScroll:true});drag={...snapshot(),x:event.clientX,pointer:event.pointerId};handle.setPointerCapture(event.pointerId);grid.setAttribute('data-dragging','');handle.setAttribute('data-dragging','');});
          handle.addEventListener('pointermove',event=>{if(drag&&event.pointerId===drag.pointer){event.preventDefault();resize(drag,event.clientX-drag.x);}});
          handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish);handle.addEventListener('lostpointercapture',event=>{if(drag)finish(event);});
          handle.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight'].includes(event.key))return;event.preventDefault();resize(snapshot(),(event.key==='ArrowLeft'?-1:1)*(event.shiftKey?40:16));persist();});
          handle.addEventListener('dblclick',()=>{state.widths=[42,38,20];updateReaderColumns();persist();});
        });
      }
      function filterNotes(){
        const grid=main.querySelector('[data-note-grid]');if(!grid)return;
        const matching=state.notes.filter(n=>(n.title+' '+n.summary).toLocaleLowerCase('tr').includes(state.query.toLocaleLowerCase('tr'))&&(state.filter==='all'||(state.filter==='unassigned'?!n.course:n.course===state.filter))).sort((a,b)=>Number(b.pinned)-Number(a.pinned));
        const pageSize=9,pageCount=Math.max(1,Math.ceil(matching.length/pageSize));state.notesPage=Math.min(pageCount,Math.max(1,state.notesPage));const start=(state.notesPage-1)*pageSize;
        grid.innerHTML=matching.length?matching.slice(start,start+pageSize).map(noteCard).join(''):'<p class="bn-muted">'+(state.notes.length?__bnT('Bu aramada not bulunamadı.'):__bnT('Henüz notun yok. Yeni not ile oluşturabilir veya PDF yükleyebilirsin.'))+'</p>';main.querySelector('[data-count]').textContent=matching.length+__bnT(' not');
        let pagination=main.querySelector('[data-note-pagination]');if(!pagination){pagination=document.createElement('div');pagination.className='bn-pagination';pagination.setAttribute('data-note-pagination','');grid.after(pagination);}
        pagination.hidden=!matching.length;
        const pages=Array.from({length:pageCount},(_,i)=>i+1).filter(page=>page===1||page===pageCount||Math.abs(page-state.notesPage)<=1);
        const pageButton=(page,label,content,disabled=false)=>`<button type="button" class="bn-btn" data-notes-page="${page}" aria-label="${label}" ${disabled?'disabled':''}>${content}</button>`;
        pagination.innerHTML=__bnHTML`<span class="bn-mini" role="status" aria-live="polite">${matching.length?start+1:0}–${Math.min(start+pageSize,matching.length)} / ${matching.length} not</span><nav class="bn-page-controls" aria-label="Not sayfaları">${pageButton(state.notesPage-1,__bnT('Önceki sayfa'),ico('chevron-left'),state.notesPage===1)}${pages.map((page,i)=>__bnHTML`${i&&page-pages[i-1]>1?'<span class="bn-page-gap" aria-hidden="true">…</span>':''}<button type="button" class="bn-btn" data-notes-page="${page}" aria-label="${page}. sayfa" ${page===state.notesPage?'aria-current="page"':''}>${page}</button>`).join('')}${pageButton(state.notesPage+1,__bnT('Sonraki sayfa'),ico('chevron-right'),state.notesPage===pageCount)}</nav>`;icons();
      }
      function notify(message){const el=main.querySelector('[data-notice]');if(el)el.textContent=message;}
      function closeProfile(){state.profileOpen=false;const panel=host.querySelector('.bn-profile-menu');if(panel)panel.hidden=true;host.querySelector('[data-action="profile"]')?.setAttribute('aria-expanded','false');}
      function closePicker(picker,restoreFocus=false){if(!picker)return;picker.open=false;if(restoreFocus)picker.querySelector('summary')?.focus({preventScroll:true});}
      function closeCoursePicker(restoreFocus=false){closePicker(main.querySelector('[data-course-picker]'),restoreFocus);}
      function positionPicker(picker){const menu=picker.querySelector('.bn-course-menu');if(!menu)return;menu.style.left='0px';menu.style.right='auto';menu.style.top='calc(100% + 9px)';menu.style.bottom='auto';picker.style.setProperty('--bn-menu-width',Math.min(340,Math.max(240,picker.getBoundingClientRect().width))+'px');const bounds=host.querySelector('.bn-shell').getBoundingClientRect(),rect=menu.getBoundingClientRect();const min=bounds.left+10,max=Math.max(min,bounds.right-10-rect.width);menu.style.left=(Math.min(max,Math.max(min,rect.left))-rect.left)+'px';const trigger=picker.getBoundingClientRect(),bottom=Math.min(bounds.bottom,window.innerHeight);if(rect.bottom>bottom-10&&trigger.top-rect.height>bounds.top+10){menu.style.top='auto';menu.style.bottom='calc(100% + 9px)';}}
      function upgradeSelects(){main.querySelectorAll('select').forEach((select,selectIndex)=>{
        if(select.hidden)return;
        const key=[...select.attributes].find(attr=>attr.name.startsWith('data-'))?.name||'select-'+selectIndex;
        const label=select.labels?.[0]?.cloneNode(true);if(label){label.querySelectorAll('select,.bn-course-picker').forEach(node=>node.remove());}
        const accessibleName=select.getAttribute('aria-label')||label?.textContent.trim()||__bnT('Seçim');
        const id='bn-select-'+index+'-'+key;const picker=document.createElement('details');picker.className='bn-course-picker bn-unified-select';picker.dataset.selectPicker='';picker.dataset.selectSource=key;picker.toggleAttribute('data-disabled',select.disabled);
        const selected=select.selectedOptions[0]?.textContent||'';
        picker.innerHTML=`<summary id="${id}-trigger" data-select-trigger aria-label="${esc(accessibleName)}" aria-describedby="${id}-value${select.getAttribute('aria-describedby')?' '+esc(select.getAttribute('aria-describedby')):''}" ${select.disabled?'aria-disabled="true"':''}><span id="${id}-value" data-select-value>${esc(selected)}</span>${ico('chevron-down')}</summary><div class="bn-course-menu" role="group" aria-label="${esc(accessibleName)}">${[...select.options].map(option=>`<label class="bn-course-option"><input type="radio" name="${id}-options" data-select-option value="${esc(option.value)}" aria-label="${esc(option.textContent)}" ${option.selected?'checked':''} ${option.disabled||select.disabled?'disabled':''}><span class="bn-course-option-icon">${ico(key==='data-gemini-model'?'sparkles':option.value==='all'?'layers':option.value==='unassigned'?'minus':'book-open')}</span><span class="bn-course-option-text">${esc(option.textContent)}</span><span class="bn-course-option-check">${ico('check')}</span></label>`).join('')}</div>`;
        if(select.disabled)picker.querySelector('summary').addEventListener('click',event=>event.preventDefault());
        const wrappingLabel=select.closest('label');if(wrappingLabel&&main.contains(wrappingLabel)){const field=document.createElement('div');[...wrappingLabel.attributes].forEach(attr=>{if(attr.name!=='for')field.setAttribute(attr.name,attr.value);});field.classList.add('bn-select-field');while(wrappingLabel.firstChild)field.append(wrappingLabel.firstChild);wrappingLabel.replaceWith(field);}
        select.hidden=true;select.insertAdjacentElement('afterend',picker);selectBindings.set(picker,select);
      });}
      function chooseGlassOption(input){const picker=input.closest('[data-select-picker]'),select=selectBindings.get(picker);if(!select||select.disabled||input.disabled)return;const key=picker.dataset.selectSource;select.value=input.value;picker.querySelector('[data-select-value]').textContent=select.selectedOptions[0]?.textContent||'';closePicker(picker);select.dispatchEvent(new Event('change',{bubbles:true}));main.querySelector(`[data-select-source="${key}"]>summary`)?.focus({preventScroll:true});}
      function chooseReaderCourse(input){const n=state.notes.find(n=>n.id===state.noteId);if(!n)return;n.course=input.value;const value=main.querySelector('[data-course-value]');if(value)value.textContent=n.course||__bnT('Ders atanmamış');closeCoursePicker(true);notify(n.course?'Not '+n.course+__bnT(' dersiyle eşleştirildi.'):__bnT('Notun ders eşleştirmesi kaldırıldı.'));}
      function markNoteWorked(){if(!state.notes.some(n=>n.id===state.noteId))return;state.lastWorkedNoteId=state.noteId;state.recentNoteIds=[state.noteId,...state.recentNoteIds.filter(id=>id!==state.noteId)].slice(0,12);}
      function go(view){if(view==='reader'){if(state.view!=='reader'){const n=currentNote();state.minimized=new Set(isNotebook(n)?n.sourceOnly?[1,2]:[0,2]:[]);state.readerReturnView=state.view==='today'?'today':'notes';state.qaRefs=[];state.mention=null;state.response='';state.draft='';}markNoteWorked();}state.view=view;render();persist();}
      host.addEventListener('click',async event=>{
        const b=event.target.closest('button');if(!b)return;
        if(b.dataset.sourceOpen){const n=currentNote();if(n){n.activeSource=b.dataset.sourceOpen;state.minimized.delete(0);render();}return;}
        if(b.dataset.summarySource){showSummary(b.dataset.summarySource);return;}
        if(b.dataset.mentionSource){chooseMention(b.dataset.mentionSource);return;}
        if(b.dataset.removeContext){state.qaRefs=state.qaRefs.filter(id=>id!==b.dataset.removeContext);paintQAContext();return;}
        if(b.dataset.view){go(b.dataset.view);return;}
        if(b.hasAttribute('data-notes-page')){if(b.disabled)return;state.notesPage=Number(b.dataset.notesPage);filterNotes();main.querySelector('[data-notes-page][aria-current="page"]')?.focus();return;}
        if(b.dataset.pinNote){await toggleNotePin(Number(b.dataset.pinNote));return;}
        if(b.dataset.courseFilter){state.filter=b.dataset.courseFilter;state.query='';state.notesPage=1;go('notes');return;}
        if(b.dataset.note){state.noteId=Number(b.dataset.note);state.userNote=currentNote()?.body||'';state.tab='source';state.response='';state.lastQuestion='';openNoteWithMotion(b);return;}
        if(b.dataset.tab){state.tab=b.dataset.tab;render();persist();return;}
        const a=b.dataset.action;if(!a)return;if(a==='edit-content'){state.tab='notes';render();return;}
        if(a==='new'){showNewNote();return;}
        if(a==='close-flow'){b.closest('dialog')?.close();return;}
        if(a==='new-create'){try{await createNotebook();}catch(error){const feedback=b.closest('dialog')?.querySelector('[data-upload-feedback]');if(feedback)feedback.textContent=error.message;else notify(error.message);}return;}
        if(a==='new-upload'){const step=b.closest('dialog').querySelector('[data-upload-step]');step.hidden=false;step.querySelector('input')?.focus();return;}
        if(a==='prepare-summary'){await prepareSummary(b.closest('dialog'));return;}
        if(a==='summary'){showSummary();return;}
        if(a==='open-qa'||a==='write-in-note'){state.minimized.delete(a==='open-qa'?2:1);if(a==='write-in-note'){currentNote().sourceOnly=false;state.tab='notes';}render();main.querySelector(a==='open-qa'?'[data-question]':'[data-note-edit]')?.focus({preventScroll:true});return;}
        if(a==='mention-open'){const input=main.querySelector('[data-question]');input.value+=(input.value&&!/\s$/.test(input.value)?' ':'')+'@';state.draft=input.value;input.focus();input.setSelectionRange(input.value.length,input.value.length);detectMention(input);return;}
        if(a==='study-phase'){if(studyActive()){notify(__bnT('Önce devam eden oturumu bitir.'));return;}if(!Object.hasOwn(studyPhases,b.dataset.phase))return;resetStudy(b.dataset.phase);commitStudy();return;}
        if(a==='study-durations'){state.durationsOpen=!state.durationsOpen;main.querySelector('[data-study-durations]').hidden=!state.durationsOpen;b.setAttribute('aria-expanded',String(state.durationsOpen));return;}
        if(a==='study-manual'){state.manualOpen=!state.manualOpen;main.querySelector('[data-study-manual]').hidden=!state.manualOpen;if(state.manualOpen)main.querySelector('[data-manual-course]')?.focus();return;}
        if(a==='study-manual-save'){saveManualStudy();return;}
        if(a==='timer-toggle'){
          if(syncStudy()){commitStudy();return;}
          if(study.runningSince)study.runningSince=0;
          else {if(study.remainingMs<=0)resetStudy(study.completedPhase&&study.phase!=='focus'?'focus':study.phase);if(!studyActive()){study.sessionStartedAt=Date.now();study.sessionId=Date.now().toString(36)+Math.random().toString(36).slice(2,7);}study.runningSince=Date.now();}
          commitStudy();main.querySelector('[data-action="timer-toggle"]')?.focus({preventScroll:true});return;
        }
        if(a==='study-finish'){
          if(syncStudy()){commitStudy();return;}const seconds=Math.floor(study.elapsedMs/1000),phase=study.phase;
          if(phase==='focus'&&seconds<1){notify(__bnT('Kaydetmek için en az 1 saniye çalışmış olmalısın.'));return;}
          if(phase==='focus')recordStudy(seconds,study.sessionStartedAt,Date.now(),'partial',study.sessionId);
          resetStudy();commitStudy();notify(phase==='focus'?studyTime(seconds)+__bnT(' çalışma ')+study.course+__bnT(' dersine kaydedildi.'):__bnT('Mola bitirildi.'));return;
        }
        if(a==='profile'){state.profileOpen=!state.profileOpen;host.querySelector('.bn-profile-menu').hidden=!state.profileOpen;b.setAttribute('aria-expanded',String(state.profileOpen));if(state.profileOpen)host.querySelector('[data-action="close-profile"]')?.focus({preventScroll:true});return;}
        if(a==='close-profile'){closeProfile();host.querySelector('[data-action="profile"]')?.focus({preventScroll:true});return;}
        if(a==='toggle-courses'){state.coursesOpen=!state.coursesOpen;main.querySelector('[data-course-manager]').hidden=!state.coursesOpen;main.querySelector('[aria-controls="bn-courses-'+index+'"]')?.setAttribute('aria-expanded',String(state.coursesOpen));if(state.coursesOpen)main.querySelector('[data-course-name]')?.focus();return;}
        if(a==='add-course'){addCourse();return;}
        if(a==='save-gemini'){saveGeminiSettings();return;}
        if(a==='toggle-gemini-key'){
          const input=main.querySelector('[data-gemini-key]');const show=input.type==='password';input.type=show?'text':'password';b.setAttribute('aria-label',show?__bnT('API anahtarını gizle'):__bnT('API anahtarını göster'));b.setAttribute('aria-pressed',String(show));b.innerHTML=ico(show?'eye-off':'eye');icons();return;
        }
        if(a==='test-gemini'){b.disabled=true;try{const result=await api('test_connection',{key:state.geminiKey});geminiFeedback(result.message);await loadModelCatalog(true);}catch(error){geminiFeedback(error.message,true);}finally{b.disabled=false;}return;}
        if(a==='cloud-details'){state.cloudDetails=!state.cloudDetails;main.querySelector('[data-cloud-details]').hidden=!state.cloudDetails;b.setAttribute('aria-expanded',String(state.cloudDetails));return;}
        if(a==='reader-back'){closeNoteWithMotion();return;}
        if(a==='review-note'){showSummary();return;}
        if(a.startsWith('minimize-')||a.startsWith('restore-')){
          const minimize=a.startsWith('minimize-');const id=Number(a.slice(a.indexOf('-')+1));if(![0,1,2].includes(id))return;
          animateReaderWindow(id,minimize);persist();
          main.querySelector(`[data-action="${minimize?'restore':'minimize'}-${id}"]`)?.focus({preventScroll:true});return;
        }
        if(a==='clear-qa'){state.lastQuestion='';state.response='';if(currentNote())currentNote().qaHistory=[];state.draft='';state.qaRefs=[];state.mention=null;render();return;}
        if(a==='font-up'||a==='font-down'){state.readerFont=Math.min(18,Math.max(11,state.readerFont+(a==='font-up'?1:-1)));main.querySelector('.bn-r-grid').style.setProperty('--bn-reader-font',state.readerFont+'px');return;}
        if(a==='formulas'||a==='pitfalls'){state.response=a==='formulas'?'Örnek formül özeti: Düğümde ∑Igiren = ∑Içıkan. Kapalı çevrede ∑V = 0.':'Örnek uyarı: Akım yönü ve gerilim işaretlerini tutarlı seç. Negatif akım, seçtiğin yönün tersini gösterir.';render();return;}
        if(a==='resume'){state.noteId=1;state.tab='source';openNoteWithMotion(b);}
        if(a==='focus')go('timer');
        if(a==='side-notes'){state.showNote=!state.showNote;render();}
        if(a==='answer'){state.answer=!state.answer;render();}
        if(a==='save-note'){await saveDraft(currentNote()).catch(()=>{});return;}
        if(['plan','questions','summary'].includes(a)){if(state.view!=='reader'){const selected=main.querySelector('[data-ai-source]');state.noteId=selected?Number(selected.value):1;}state.view='reader';state.tab=a==='plan'?'plan':a==='questions'?'questions':'source';state.response=a==='summary'?'Örnek özet: Kirchhoff yasaları, devrelerde yükün ve enerjinin korunmasını ifade eder. Düğümde akımlar, kapalı çevrede gerilimler dengelenir.':'';render();persist();}
        if(a==='split'){state.split=!state.split;render();}
        if(a==='split-create'){const value=main.querySelector('[data-range]')?.value.trim()||'';const match=value.match(/^(\d+)\s*-\s*(\d+)$/);const n=state.notes.find(n=>n.id===state.noteId);if(!match||Number(match[1])<1||Number(match[2])<Number(match[1])||Number(match[2])>(n?.pages||24)){notify(__bnT('Geçerli bir sayfa aralığı gir; örneğin 1-8.'));return;}notify('Örnek bölüm: '+value+__bnT(' sayfaları. Bu demoda gerçek dosya bölünmez.'));}
        if(a==='ask'){await askLive();return;}

      });
      host.addEventListener('input',event=>{const el=event.target;if(el.matches('[data-search]')){state.query=el.value;state.notesPage=1;filterNotes();}if(el.matches('[data-note-title]')){const n=currentNote();if(n){n.title=el.value.trim()||__bnT('Başlıksız not');const label=main.querySelector('[data-title-label]');if(label){label.textContent=n.title;label.closest('h3')?.setAttribute('aria-label',n.title);}const headerInput=main.querySelector('[data-header-title-input]');if(headerInput&&document.activeElement!==headerInput)headerInput.value=n.title;}}if(el.matches('[data-note-edit]')){state.userNote=el.value;const n=currentNote();if(n){n.body=el.value;n.content=el.value;n.summary=el.value.trim().replace(/\s+/g,' ').slice(0,120)||__bnT('Düşüncelerini buraya yaz.');n.edited=__bnT('Şimdi');}}if(el.matches('[data-question]')){state.draft=el.value;detectMention(el);}if(el.matches('[data-gemini-key]')){state.geminiKey=el.value;state.geminiSaved=false;}});
      host.addEventListener('change',event=>{if(event.target.matches('[data-pdf-input]'))attachPDFs(event.target).catch(()=>notify(__bnT('Dosya eklenemedi. Lütfen tekrar seç.')));if(event.target.matches('[data-summary-scope]'))event.target.closest('dialog').querySelector('[data-summary-result]').innerHTML='';});
      host.addEventListener('keydown',event=>{if(!event.target.matches('[data-question]')||!state.mention)return;const mention=state.mention;if(event.key==='Escape'){event.preventDefault();state.mention=null;paintQAContext();}else if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();const length=mention.items.length;mention.active=length?(mention.active+(event.key==='ArrowDown'?1:-1)+length)%length:0;paintQAContext();}else if(event.key==='Enter'&&mention.items.length){event.preventDefault();chooseMention(mention.items[mention.active].id);}});
      host.addEventListener('input',event=>{const el=event.target;if(!el.matches('[data-study-duration]')||studyActive())return;const field=el.dataset.studyDuration,value=Number(el.value),max=field==='focusMinutes'?180:60;if(!Number.isInteger(value)||value<1||value>max)return;study[field]=value;resetStudy();study.updatedAt=Math.max(Date.now(),study.updatedAt+1);persist();for(const state of states)state.paintStudy?.();});
      host.addEventListener('change',event=>{const el=event.target;if(el.matches('[data-filter]')){state.filter=el.value;state.notesPage=1;filterNotes();}if(el.matches('[data-gemini-model]')){state.geminiModel=el.value;state.geminiSaved=false;}if(el.matches('[data-task]')){const i=Number(el.dataset.task);if(el.checked)state.taskChecks.add(i);else state.taskChecks.delete(i);const c=main.querySelector('[data-task-counter]');if(c)c.textContent=state.taskChecks.size+'/3 tamamlandı';}});
      host.addEventListener('change',event=>{if(event.target.matches('[data-note-course]'))chooseReaderCourse(event.target);});
      host.addEventListener('click',event=>{if(event.target.matches('[data-note-course]')){const n=state.notes.find(n=>n.id===state.noteId);if(n?.course===event.target.value)closeCoursePicker(true);}});
      host.addEventListener('change',event=>{if(event.target.matches('[data-select-option]'))chooseGlassOption(event.target);});
      host.addEventListener('click',event=>{const option=event.target.closest('.bn-course-option'),input=option?.querySelector('[data-select-option]');if(!input)return;const picker=input.closest('[data-select-picker]'),select=selectBindings.get(picker);if(!select||select.disabled||input.disabled){event.preventDefault();return;}if(event.target!==input){event.preventDefault();if(select.value===input.value){closePicker(picker,true);return;}input.checked=true;chooseGlassOption(input);}else if(select.value===input.value)closePicker(picker,true);});
      root.addEventListener('pointerdown',event=>{main.querySelectorAll('.bn-course-picker[open]').forEach(picker=>{if(!picker.contains(event.target))closePicker(picker);});});
      host.addEventListener('toggle',event=>{const picker=event.target;if(!picker.matches('.bn-course-picker')||!picker.open)return;if(picker.hasAttribute('data-disabled')){picker.open=false;return;}main.querySelectorAll('.bn-course-picker[open]').forEach(other=>{if(other!==picker)closePicker(other);});positionPicker(picker);},true);
      host.addEventListener('focusin',event=>{main.querySelectorAll('.bn-course-picker[open]').forEach(picker=>{if(!picker.contains(event.target))closePicker(picker);});});
      host.addEventListener('keydown',event=>{const picker=event.target.closest('.bn-course-picker');if(!picker)return;if(picker.hasAttribute('data-disabled')){if(['Enter',' ','ArrowDown','ArrowUp'].includes(event.key))event.preventDefault();return;}if(event.key==='Escape'){event.preventDefault();closePicker(picker,true);return;}if(event.target.matches('[data-course-trigger],[data-select-trigger]')&&['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();picker.open=true;positionPicker(picker);const radios=[...picker.querySelectorAll('[data-note-course],[data-select-option]')].filter(input=>!input.disabled);(radios.find(input=>input.checked)||radios[event.key==='ArrowUp'?radios.length-1:0])?.focus();}if(event.target.matches('[data-note-course],[data-select-option]')&&event.key==='Enter'){event.preventDefault();event.target.checked=true;if(event.target.matches('[data-note-course]'))chooseReaderCourse(event.target);else chooseGlassOption(event.target);}});
      host.addEventListener('change',event=>{const el=event.target;if(el.matches('[data-study-course]')){if(studyActive()){el.value=study.course;return;}study.course=el.value;commitStudy();}if(el.matches('[data-study-filter]')){state.studyFilter=el.value;render();}if(el.matches('[data-study-duration]')){const field=el.dataset.studyDuration,value=Number(el.value),max=field==='focusMinutes'?180:60;if(studyActive()||!Number.isInteger(value)||value<1||value>max){el.value=study[field];notify('Süreyi 1–'+max+__bnT(' dakika arasında seç.'));return;}study[field]=value;resetStudy();commitStudy();}});
      host.addEventListener('keydown',event=>{if(event.target.matches('[data-course-name]')&&event.key==='Enter'){event.preventDefault();addCourse();}});
      host.addEventListener('click',event=>{if(state.profileOpen&&!event.target.closest('.bn-profile-area'))closeProfile();});
      host.addEventListener('keydown',event=>{if(event.key==='Escape'&&state.profileOpen){closeProfile();host.querySelector('[data-action="profile"]')?.focus({preventScroll:true});}});
      host.addEventListener('submit',event=>{if(!event.target.matches('[data-gemini-form]'))return;event.preventDefault();saveGeminiSettings();});
            // These implementations connect the approved interface to the existing PHP application.
      const saveTimers=new Map(),saveChains=new Map(),dirty=new Set(),pendingPins=new Set(),summaryLocks=new Map();
      const renderMD=text=>{const math=[];const raw=String(text||'').replace(/\$\$[\s\S]*?\$\$|\\\[[\s\S]*?\\\]|\\\([\s\S]*?\\\)|\$(?:\\[^\n]|[^$\\\n])+\$/g,value=>{math.push(value);return 'BNMATHPLACEHOLDER'+(math.length-1)+'END';});if(!window.DOMPurify||!window.marked)return '<p>'+esc(text||'').replace(/\n/g,'<br>')+'</p>';let html=marked.parse(raw);html=html.replace(/BNMATHPLACEHOLDER(\d+)END/g,(_,i)=>esc(math[Number(i)]));return DOMPurify.sanitize(html);};
      function renderMath(element){if(window.BetterNotebookRichText?.renderMathContent)window.BetterNotebookRichText.renderMathContent(element);}
      async function saveDraft(n,quiet=false){if(!n)return;if(summaryLocks.has(n.id)){await summaryLocks.get(n.id);return saveDraft(n,quiet);}clearTimeout(saveTimers.get(n.id));const snapshot={note_id:n.id,title:n.title,content:n.body??n.content??'',course:n.course||''};
        const previous=saveChains.get(n.id)||Promise.resolve();const task=previous.catch(()=>{}).then(()=>api('save',snapshot)).then(data=>{n.updatedAt=data.note.updatedAt;n.edited=data.note.edited;if(n.title===snapshot.title&&(n.body??n.content??'')===snapshot.content&&n.course===snapshot.course)dirty.delete(n.id);if(state.view==='reader'&&state.noteId===n.id)history.replaceState({view:'reader',noteId:n.id},'',noteRoute(n));if(!quiet)notify(__bnT('Not kaydedildi.'));return data;});saveChains.set(n.id,task);try{return await task;}catch(error){notify(error.message);throw error;}}
      function scheduleSave(n){if(!n)return;dirty.add(n.id);clearTimeout(saveTimers.get(n.id));saveTimers.set(n.id,setTimeout(()=>saveDraft(n,true).catch(()=>{}),650));}
      async function toggleNotePin(id){const n=state.notes.find(note=>note.id===id);if(!n||pendingPins.has(id))return;const previous=n.pinned,page=state.notesPage;pendingPins.add(id);n.pinned=!previous;if(n.pinned)state.notesPage=1;const paint=()=>{if(state.view==='notes')filterNotes();else if(state.view==='today')render();};paint();notify('');try{if(dirty.has(id))await saveDraft(n,true);const data=await api('pin',{note_id:id,pinned:n.pinned});n.pinned=data.note.pinned;notify(n.pinned?__bnT('Not sabitlendi.'):__bnT('Sabitleme kaldırıldı.'));}catch(error){n.pinned=previous;state.notesPage=page;notify(error.message);}finally{pendingPins.delete(id);paint();}}
      async function createNotebook(files=[]){const data=await api('create',{title:files.length?files[0].name.replace(/\.pdf$/i,''):__bnT('Yeni kişisel not'),course:state.courses.includes(state.filter)?state.filter:'',source_only:files.length>0});let n=data.note;
        state.notes.unshift(n);state.noteId=n.id;state.notesPage=1;state.tab='notes';state.userNote='';state.response='';state.draft='';state.qaRefs=[];state.mention=null;
        host.querySelector('.bn-flow-dialog')?.close();const origin=main.querySelector('[data-action="new"]');openNoteWithMotion(origin);
        if(files.length){try{n=await uploadFiles(n,files);n.sourceOnly=true;state.minimized=new Set([1,2]);render();}catch(error){notify(error.message);}}
        else main.querySelector('[data-note-title]')?.focus({preventScroll:true});}
      async function uploadFiles(n,files){const form=new FormData();form.append('action','upload');form.append('note_id',n.id);for(const item of files)form.append('files[]',item.file||item,item.name);const data=await apiForm(form);n.attachments=data.note.attachments;n.kind=data.note.kind;n.activeSource=n.attachments.at(-1)?.id;n.edited=data.note.edited;return n;}
      async function attachPDFs(input){const files=Array.from(input.files||[]);if(!files.length)return;const n=currentNote(),mode=input.dataset.uploadMode;input.value='';try{for(const file of files){if(!/\.pdf$/i.test(file.name)||!(await file.slice(0,1024).text()).includes('%PDF-'))throw new Error(__bnT('Geçerli bir PDF dosyası seç.'));if(file.size>50*1024*1024)throw new Error(__bnT('Bir PDF en fazla 50 MB olabilir.'));}if(mode==='new'){await createNotebook(files);return;}await saveDraft(n,true);await uploadFiles(n,files);if(state.noteId===n.id&&state.view==='reader'){render();notify(files.length+__bnT(' PDF kalıcı olarak nota eklendi.'));}}catch(error){const feedback=input.closest('dialog')?.querySelector('[data-upload-feedback]');if(feedback)feedback.textContent=error.message;else notify(error.message);}}
      function notebookSource(n){const sources=noteSources(n),selected=sources.find(s=>s.id===n.activeSource)||sources[0];const view=selected?(selected.type==='image'?`<img class="bn-live-image" src="${esc(selected.url)}" alt="${esc(selected.name)}">`:`<iframe class="bn-live-pdf" title="${esc(selected.name)}" src="pdf-viewer?note_id=${n.id}&source_id=${encodeURIComponent(selected.id)}"></iframe>`):__bnHTML`<div class="bn-source-empty">${ico('file-plus-2')}<h3>Kaynak eklemek sana kalmış</h3><p>Doğrudan not yazabilir veya bir PDF ekleyebilirsin.</p></div>`;
        return __bnHTML`<section class="bn-r-pane" data-pane="0" aria-label="PDF kaynağı"><div class="bn-r-bar">${ico('paperclip')}<span>${esc(selected?.name||__bnT('Kaynaklar'))}</span>${minimizeButton(0)}</div><div class="bn-live-source-toolbar">${pdfPicker(__bnT('PDF ekle'))}<details class="bn-live-source-picker"><summary>${sources.length} kaynak ${ico('chevron-down')}</summary><div class="bn-source-list">${sourceRows(n)}</div></details>${selected?`<a class="bn-btn" href="${esc(selected.url)}" target="_blank" rel="noopener">${ico('external-link')}</a>`:''}</div>${view}</section>`;}
      function attachmentSection(n){return __bnHTML`<section class="bn-attachment-section" aria-label="Nota eklenen PDF dosyaları"><div class="bn-row" style="justify-content:space-between"><h3>Kaynaklar <span class="bn-mini">${noteSources(n).length} dosya</span></h3>${pdfPicker(__bnT('Nota PDF ekle'))}</div><div class="bn-source-list">${sourceRows(n)||__bnT('<p class="bn-mini">PDF ekleyebilir, AI sorularında @ ile seçebilirsin.</p>')}</div></section>`;}
      function reader(){const n=currentNote();if(!n)return __bnT('<p>Not bulunamadı.</p>');const writable=isNotebook(n)||state.tab==='notes';
        const note=writable?readerContent({...n,custom:true}):`<div class="bn-live-markdown">${renderMD(n.content)}</div><div class="bn-row" style="margin-top:24px">${action(__bnT('Notu düzenle'),'edit-content','pencil-line')}</div>`;
        return __bnHTML`<header class="bn-r-header">${readerButton('','reader-back','book-open',__bnT('aria-label="Notlara dön"'))}<div class="bn-r-title">${readerTitle(n)}${readerCoursePicker(n)}</div><div class="bn-r-tools"><div class="bn-r-dock" role="toolbar" aria-label="Küçültülen paneller" data-window-dock hidden></div>${readerButton('A−','font-down','',__bnT('aria-label="Not yazısını küçült"'))}${readerButton('A+','font-up','',__bnT('aria-label="Not yazısını büyüt"'))}${readerButton('','reader-back','x',__bnT('aria-label="Notu kapat"'))}</div></header><div class="bn-r-grid" style="--bn-reader-font:${state.readerFont}px">${notebookSource(n)}<section class="bn-r-pane" data-pane="1" aria-label="Not ve konu anlatımı"><div class="bn-r-bar">${ico('notebook-pen')}<span>${writable?__bnT('Not defterim'):__bnT('Not & Konu Anlatımı')}</span>${minimizeButton(1)}</div><article class="bn-r-copy">${note}</article></section><aside class="bn-r-pane bn-r-qa" data-pane="2" aria-label="AI soru paneli"><div class="bn-r-bar">${ico('bot')}<span><strong>AI Soru Paneli</strong><br><span class="bn-mini">Not ve seçili PDF kaynakları</span></span><div class="bn-r-bar-actions">${readerButton('','clear-qa','rotate-ccw',__bnT('aria-label="Soru panelini temizle"'))}${minimizeButton(2)}</div></div><div class="bn-qa-text-tools" role="group" aria-label="AI yazı boyutu"><span>Yazı boyutu</span>${readerButton('A−','qa-font-down','',__bnT('aria-label="AI yazısını küçült"'))}${readerButton(state.qaFont+'px','qa-font-reset','',__bnT('data-qa-font-value aria-label="AI yazı boyutunu sıfırla"'))}${readerButton('A+','qa-font-up','',__bnT('aria-label="AI yazısını büyüt"'))}</div><div class="bn-r-qa-space">${qaConversation(n)}</div><div class="bn-r-compose"><div class="bn-r-prompts">${readerButton(__bnT('PDF seç'),'mention-open','at-sign')}</div><div data-qa-context class="bn-context-chips" aria-label="Seçilen PDF kaynakları"></div><div data-mention-menu class="bn-mention-menu" role="group" aria-label="PDF kaynağı seç" hidden></div><div class="bn-r-input-row"><textarea data-question aria-label="Kaynak hakkında soru" placeholder="Soru sor veya @ ile PDF seç…">${esc(state.draft)}</textarea><button type="button" class="bn-r-send" data-action="ask" aria-label="Soruyu gönder" ${state.aiPending?'disabled':''}>${ico(state.aiPending?'loader-circle':'send')}</button></div>${state.aiPending&&state.aiPendingNoteId===n.id?__bnT('<p data-qa-status class="bn-mini" role="status" style="margin-top:8px">Yanıt hazırlanıyor…</p>'):boot.settings.keyConfigured?'':__bnT('<p class="bn-mini" style="margin-top:8px">AI bağlantısını Ayarlar sayfasından yapılandır.</p>')}</div></aside><div class="bn-r-empty-state" data-windows-empty hidden>${ico('panels-top-left')}<h3>Tüm paneller küçültüldü</h3><p>Üstteki simgelerden geri aç.</p></div></div>`;}
      async function addCourse(){const input=main.querySelector('[data-course-name]');const name=input.value.trim().replace(/\s+/g,' ');if(!name){notify(__bnT('Bir ders adı yaz.'));return;}try{const data=await api('course',{name});if(!state.courses.includes(data.course.name))state.courses.push(data.course.name);if(!boot.courses.some(course=>course.id===data.course.id))boot.courses.push(data.course);render();notify(__bnT('Ders eklendi.'));}catch(error){notify(error.message);}}
      function chooseReaderCourse(input){const n=currentNote();if(!n)return;n.course=input.value;const value=main.querySelector('[data-course-value]');if(value)value.textContent=n.course||__bnT('Ders atanmamış');closeCoursePicker(true);scheduleSave(n);}
      function validateGemini(){if(!state.geminiKey.trim()&&!boot.settings.keyConfigured){geminiFeedback(__bnT('Önce API anahtarını ekle.'),true);return false;}return true;}
      async function saveGeminiSettings(){if(!validateGemini())return;if(!await validateModelChoice())return;try{const data=await api('settings',{key:state.geminiKey,model:state.geminiModel});boot.settings.keyConfigured=data.keyConfigured;boot.settings.model=state.geminiModel;state.geminiKey='';modelCatalogSaved();state.geminiSaved=true;const field=main.querySelector('[data-gemini-key]');if(field){field.value='';field.placeholder=__bnT('Kayıtlı anahtarı değiştirmek için yeni anahtar gir');}geminiFeedback(__bnT('Gemini ayarları kaydedildi. Boş bıraktığında mevcut anahtar korunur.'));}catch(error){geminiFeedback(error.message,true);}}

      function markNoteWorked(){if(!currentNote())return;state.lastWorkedNoteId=state.noteId;state.recentNoteIds=[state.noteId,...state.recentNoteIds.filter(id=>id!==state.noteId)].slice(0,12);api('open',{note_id:state.noteId}).catch(error=>notify(error.message));}
      function noteRoute(n){const title=String(n?.title||'note').replace(/[ıİşŞğĞüÜöÖçÇ]/g,c=>({'ı':'i','İ':'i','ş':'s','Ş':'s','ğ':'g','Ğ':'g','ü':'u','Ü':'u','ö':'o','Ö':'o','ç':'c','Ç':'c'}[c]));const slug=title.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,90).replace(/-+$/,'')||'note';return '/notes?'+slug+'-'+n.id;}
      function go(view,record=true){if(view==='reader'){if(state.view!=='reader'){const n=currentNote();state.minimized=new Set(isNotebook(n)?n.sourceOnly?[1,2]:[0,2]:[]);state.readerReturnView=['today','files'].includes(state.view)?state.view:'notes';state.qaRefs=[];state.mention=null;state.response='';state.lastQuestion='';state.draft='';state.userNote=n?.body||'';}markNoteWorked();}if(view==='files'){mediaStore.items=null;mediaStore.error='';}state.view=view;render();persist();const route={today:'/',notes:'/notes',reader:currentNote()?noteRoute(currentNote()):'/notes',timer:'/pomodoro',files:'/files/',settings:'/settings'}[view];if(record&&route){const url=new URL(route,location.href);if(url.href!==location.href)history.pushState({view,noteId:state.noteId},'',url);}}
      function today(){const last=state.notes.find(n=>n.id===state.lastWorkedNoteId);const others=state.recentNoteIds.filter(id=>id!==last?.id).map(id=>state.notes.find(n=>n.id===id)).filter(Boolean);return `${heading('',__bnT('Son açılanlar'),'',__bnHTML`<button type="button" class="bn-btn" data-view="notes">Tüm notlar ${ico('arrow-right')}</button>`)}${state.notes.length?`<div class="bn-recent-grid">${last?lastNoteCard(last):''}${others.map(noteCard).join('')}</div>`:__bnHTML`<div class="bn-live-empty">${ico('notebook-pen')}<h2>İlk notunla başla.</h2><p>Boş bir defter oluştur veya PDF yükleyerek çalışma alanını kur.</p>${action(__bnT('Yeni not'),'new','plus',true)}</div>`}`;}
      state.courses=boot.courses.map(c=>c.name);state.geminiModel=boot.settings.model;
      if(!state.courses.includes(study.course)&&!studyActive())study.course=state.courses[0]||__bnT('Serbest çalışma');
      if(!state.notes.some(n=>n.id===state.noteId))state.noteId=state.notes[0]?.id;
      state.recentNoteIds=[...new Set([...boot.recentNoteIds,...state.notes.map(n=>n.id)])].slice(0,12);state.lastWorkedNoteId=state.recentNoteIds[0];
      if(boot.noteId&&state.notes.some(n=>n.id===boot.noteId)){state.noteId=boot.noteId;state.view='reader';}
      history.replaceState({view:state.view,noteId:state.noteId},'',location.href);
      window.addEventListener('popstate',event=>{const view=event.state?.view||({index:'today',notes:'notes',pomodoro:'timer',files:'files',settings:'settings'}[location.pathname.replace(/\/$/,'').split('/').at(-1)?.replace('.php','')]||'today');if(event.state?.noteId)state.noteId=event.state.noteId;go(view,false);});
      // User-editable text stays local while a debounced, serialized save is in flight.
      host.addEventListener('input',event=>{if(event.target.matches('[data-note-title],[data-note-edit]'))scheduleSave(currentNote());});
      window.addEventListener('beforeunload',event=>{if(dirty.size){event.preventDefault();event.returnValue='';}});
      window.addEventListener('pagehide',()=>{for(const n of state.notes.filter(n=>dirty.has(n.id))){const payload={action:'save',csrf:boot.csrf,note_id:n.id,title:n.title,content:n.body??n.content??'',course:n.course||''};navigator.sendBeacon('api/notebook',new Blob([JSON.stringify(payload)],{type:'application/json'}));}});

      function placeMentionMenu(){const menu=main.querySelector('[data-mention-menu]'),compose=menu?.closest('.bn-r-compose'),pane=compose?.closest('.bn-r-pane');if(!menu||!compose||!pane)return;const input=compose.querySelector('[data-question]');input?.setAttribute('aria-expanded',String(!menu.hidden));compose.querySelector('[data-action="mention-open"]')?.setAttribute('aria-expanded',String(!menu.hidden));if(menu.hidden)return;const rect=compose.getBoundingClientRect(),panel=pane.getBoundingClientRect(),bar=pane.querySelector('.bn-r-bar')?.getBoundingClientRect();const room=Math.max(44,Math.min(260,rect.top-(bar?.bottom||panel.top)-12));menu.style.setProperty('--bn-mention-room',room+'px');menu.style.bottom='calc(100% + 8px)';menu.id='bn-pdf-mentions-'+index;input?.setAttribute('aria-controls',menu.id);}
      window.addEventListener('resize',placeMentionMenu);
      const savedQAFont=Number(boot.preferences?.qaFont);
      state.qaFont=Number.isFinite(savedQAFont)?Math.max(12,Math.min(28,Math.round(savedQAFont))):15;
      function applyQAFont(){
        const pane=main.querySelector('.bn-r-qa');if(!pane)return;
        pane.style.setProperty('--bn-qa-font',state.qaFont+'px');
        const label=pane.querySelector('[data-qa-font-value]');if(label)label.textContent=state.qaFont+'px';
        const down=pane.querySelector('[data-action="qa-font-down"]'),up=pane.querySelector('[data-action="qa-font-up"]');if(down)down.disabled=state.qaFont<=12;if(up)up.disabled=state.qaFont>=28;
      }
      host.addEventListener('click',event=>{const button=event.target.closest('[data-action^="qa-font-"]');if(!button||button.disabled)return;event.preventDefault();
        const space=main.querySelector('.bn-r-qa-space'),atBottom=space&&space.scrollHeight-space.clientHeight-space.scrollTop<24;
        state.qaFont=button.dataset.action==='qa-font-reset'?15:Math.max(12,Math.min(28,state.qaFont+(button.dataset.action==='qa-font-up'?1:-1)));
        applyQAFont();if(atBottom)space.scrollTop=space.scrollHeight;persist();
      });
      function paintLiveQA(n,scroll=true){
        if(state.view!=='reader'||currentNote()?.id!==n.id)return;
        const pane=main.querySelector('.bn-r-qa'),space=pane?.querySelector('.bn-r-qa-space');if(!pane||!space)return;
        const previous=space.scrollTop;space.innerHTML=qaConversation(n);space.querySelectorAll('.bn-live-markdown').forEach(renderMath);
        space.scrollTop=scroll?space.scrollHeight:previous;
        const button=pane.querySelector('[data-action="ask"]');if(button){button.disabled=state.aiPending;button.innerHTML=ico(state.aiPending?'loader-circle':'send');}
        let status=pane.querySelector('[data-qa-status]');if(!status){status=document.createElement('p');status.dataset.qaStatus='';status.className='bn-mini';status.setAttribute('role','status');pane.querySelector('.bn-r-compose').append(status);}
        status.textContent=state.aiPending&&state.aiPendingNoteId===n.id?__bnT('Yanıt hazırlanıyor…'):'';status.hidden=!status.textContent;
        const input=pane.querySelector('[data-question]');if(input&&input.value!==state.draft)input.value=state.draft;
        paintQACapture();icons();
      }
      function paintQACapture(){
        const compose=main.querySelector('.bn-r-compose');if(!compose)return;
        let box=compose.querySelector('[data-qa-capture]');if(!box){box=document.createElement('div');box.dataset.qaCapture='';box.className='bn-qa-capture';compose.querySelector('.bn-r-input-row').before(box);}
        const image=state.qaCapture;if(!image||image.noteId!==currentNote()?.id){box.replaceChildren();box.hidden=true;return;}
        box.hidden=false;box.innerHTML=__bnHTML`<img alt="Seçilen PDF alanı"><span>${esc(image.name)} · ${image.page}. sayfa</span><button type="button" data-remove-capture aria-label="Ekran alıntısını kaldır">${ico('x')}</button>`;
        box.querySelector('img').src=image.dataUrl;
      }
      host.addEventListener('click',event=>{if(event.target.closest('[data-remove-capture]')){state.qaCapture=null;paintQACapture();}});
      window.addEventListener('message',event=>{
        if(event.origin!==location.origin||event.data?.type!=='bn:pdf-capture'||state.view!=='reader')return;
        const frame=main.querySelector('iframe.bn-live-pdf'),n=currentNote();if(!frame||event.source!==frame.contentWindow||!n)return;
        const url=new URL(frame.src,location.href),data=event.data;
        if(Number(url.searchParams.get('note_id'))!==n.id||url.searchParams.get('source_id')!==data.sourceId||!Number.isInteger(data.page)||data.page<1)return;
        if(typeof data.dataUrl!=='string'||!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(data.dataUrl)||data.dataUrl.length>4200000)return;
        const source=noteSources(n).find(s=>s.id===data.sourceId);if(!source)return;
        state.qaCapture={noteId:n.id,sourceId:source.id,name:source.name,page:data.page,dataUrl:data.dataUrl};
        if(state.minimized.has(2)){state.minimized.delete(2);updateReaderWindows();persist();}
        paintQACapture();main.querySelector('[data-question]')?.focus({preventScroll:true});
      });
      async function askLive(){
        const n=currentNote(),draft=state.draft.trim(),capture=state.qaCapture?.noteId===n?.id?state.qaCapture:null;
        const question=draft||(capture?__bnT('Seçtiğim görseldeki konuyu açıklar mısın?'):'');
        if(!n||state.aiPending)return;if(state.mention){notify(__bnT('@ listesinden bir PDF seç veya Escape ile kapat.'));return;}if(!question){notify(__bnT('Bir soru yaz.'));return;}
        const ids=[...state.qaRefs];state.aiPending=true;state.aiPendingNoteId=n.id;state.lastQuestion=question;state.response='';paintLiveQA(n);
        try{
          await saveDraft(n,true);
          const payload={note_id:n.id,kind:'question',question,source_ids:ids};if(capture)payload.capture={source_id:capture.sourceId,page:capture.page,data:capture.dataUrl.split(',')[1]};
          const data=await api('ai',payload);n.qaHistory=data.qaHistory||[...(n.qaHistory||[]),data.answer];
          if(state.noteId===n.id){state.response=data.response;if(state.draft.trim()===draft)state.draft='';if(state.qaCapture===capture)state.qaCapture=null;}
        }catch(error){if(state.noteId===n.id)state.response=error.message;else notify(error.message);}
        finally{state.aiPending=false;state.aiPendingNoteId=null;const visible=currentNote();if(visible)paintLiveQA(visible,visible.id===n.id);}
      }
      const annotationPending=new Set();
      let studyState=null;
      async function pdfNoteAPI(action,payload){
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),180000);
        try{let body=JSON.stringify({action,...payload}),headers={'Content-Type':'application/json','X-CSRF-Token':boot.csrf};
          if(action==='annotation_generate'){
            const bytes=Uint8Array.from(atob(payload.image),c=>c.charCodeAt(0));body=new FormData();
            body.set('action',action);for(const key of ['note_id','source_id','page','revision','source_stamp'])body.set(key,String(payload[key]??''));
            body.set('page_image',new Blob([bytes],{type:'image/png'}),'page.png');headers={'X-CSRF-Token':boot.csrf};
          }
          const response=await fetch('/api/notebook',{method:'POST',signal:controller.signal,credentials:'same-origin',headers,body});
          const raw=await response.text();let result;try{result=JSON.parse(raw.replace(/^\uFEFF/,''));}catch{
            const status=response.status;let hint='PHP hata kaydındaki aynı saate ait BetterNotebook hatasını kontrol et.';
            if(status===403||status===406)hint='İstek sunucu güvenlik filtresi tarafından reddedilmiş olabilir. Hosting desteğine bu isteğin ModSecurity kaydını kontrol ettir.';
            else if(status===413)hint=__bnT('Sunucunun istek veya dosya yükleme boyutu sınırı aşıldı.');
            else if(status===502||status===504)hint=__bnT('Sunucu bir hata sayfası döndürdü. Nedenini PHP veya hosting hata kaydından kontrol et.');
            else if(response.redirected&&/\/login(?:\.php)?(?:[?#]|$)/.test(response.url))hint='Oturumun sona ermiş olabilir. Tekrar giriş yap.';
            const reference=response.headers?.get('X-BetterNotebook-Request');throw new Error('PDF AI isteği geçerli JSON döndürmedi (HTTP '+status+'). '+hint+(reference?' '+window.BetterNotebookI18n.t(__bnT('İşlem kodu: '))+reference:''));
          }
          if(!response.ok||!result.success)throw new Error((window.BetterNotebookI18n.t(result.error)||'PDF notları işlenemedi.')+(result.reference?' '+window.BetterNotebookI18n.t(__bnT('İşlem kodu: '))+result.reference:''));return result;
        }catch(error){if(error.name==='AbortError')throw new Error('İstek zaman aşımına uğradı. İşlem sunucuda devam ediyor olabilir; katmanı yenileyerek kontrol et.');throw error;}
        finally{clearTimeout(timer);}
      }
      function paintPDFStudy(){
        const pane=main.querySelector('.bn-r-qa');if(!pane)return;let box=pane.querySelector('[data-pdf-study]');
        if(!box){box=document.createElement('section');box.dataset.pdfStudy='';box.className='bn-pdf-study';pane.querySelector('.bn-r-qa-space').before(box);}
        const sourceId=new URL(main.querySelector('iframe.bn-live-pdf')?.src||location.href,location.href).searchParams.get('source_id');
        if(!studyState||studyState.sourceId!==sourceId||studyState.noteId!==currentNote()?.id||!studyState.steps.length){box.hidden=true;return;}
        box.hidden=false;box.innerHTML=__bnHTML`<div class="bn-mini">PDF çalışma notları · ${studyState.page}. sayfa</div><h3>Çözümü adım adım izle</h3><div>${studyState.steps.map((step,i)=>`<button type="button" data-pdf-step="${esc(step.id)}" ${studyState.active===step.id?'aria-current="step"':''}><span>${i+1}</span>${esc(step.title)}</button>`).join('')}</div>`;
      }
      host.addEventListener('click',event=>{const b=event.target.closest('[data-pdf-step]');if(!b)return;const frame=main.querySelector('iframe.bn-live-pdf');frame?.contentWindow.postMessage({type:'bn:annotation-focus',id:b.dataset.pdfStep,page:studyState.page},location.origin);});
      window.addEventListener('message',async event=>{
        if(event.origin!==location.origin||!String(event.data?.type||'').startsWith('bn:annotation-'))return;
        const frame=main.querySelector('iframe.bn-live-pdf'),n=currentNote();if(!frame||!n||event.source!==frame.contentWindow)return;
        const url=new URL(frame.src,location.href),sourceId=url.searchParams.get('source_id'),message=event.data;
        if(Number(url.searchParams.get('note_id'))!==n.id||message.sourceId!==sourceId)return;
        const reply=value=>{if(frame.isConnected)event.source.postMessage({type:'bn:annotation-response',requestId:message.requestId,...value},location.origin);};
        if(message.type==='bn:annotation-state'){
          if(!Array.isArray(message.steps))return;studyState={noteId:n.id,sourceId,page:message.page,active:message.active,steps:message.steps.slice(0,6)};paintPDFStudy();return;
        }
        if(message.type==='bn:annotation-ask'){
          if(typeof message.image!=='string'||message.image.length>4200000||!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(message.image))return;
          const source=noteSources(n).find(s=>s.id===sourceId);if(!source)return;
          state.qaCapture={noteId:n.id,sourceId,name:source.name,page:message.page,dataUrl:message.image};state.qaRefs=[];state.draft=__bnT('Bu çözüm adımını daha ayrıntılı açıklar mısın? ')+String(message.title||'').slice(0,120);
          if(state.minimized.has(2)){state.minimized.delete(2);updateReaderWindows();persist();}
          const input=main.querySelector('[data-question]');if(input){input.value=state.draft;input.focus({preventScroll:true});}paintQACapture();return;
        }
        const actions={'bn:annotation-load':'annotation_get','bn:annotation-generate':'annotation_generate','bn:annotation-save':'annotation_save'},action=actions[message.type];if(!action||typeof message.requestId!=='string')return;
        const key=n.id+':'+sourceId+':'+message.page;if(action==='annotation_generate'&&annotationPending.has(key)){reply({error:__bnT('Bu sayfa için bir açıklama hazırlanıyor.')});return;}
        if(action==='annotation_generate')annotationPending.add(key);
        try{const payload={note_id:n.id,source_id:sourceId,page:message.page,revision:message.revision||0,source_stamp:message.sourceStamp,steps:message.steps,image:message.image};const result=await pdfNoteAPI(action,payload);reply({result});}
        catch(error){reply({error:error.message});}finally{annotationPending.delete(key);}
      });
      const mediaStore={items:null,promise:null,error:''};
      state.mediaQuery='';state.mediaNote='all';state.mediaPage=1;
      async function loadMedia(force=false){
        if(mediaStore.promise)return mediaStore.promise;if(mediaStore.items&&!force)return mediaStore.items;
        mediaStore.error='';mediaStore.promise=api('media').then(data=>mediaStore.items=data.items).catch(error=>{mediaStore.error=error.message;throw error;}).finally(()=>mediaStore.promise=null);return mediaStore.promise;
      }
      function mediaMatches(items,query,note='all'){
        const term=query.trim().toLocaleLowerCase(window.BetterNotebookI18n.locale);
        return (items||[]).filter(item=>(!term||(item.name+' '+item.notes.map(n=>n.title).join(' ')).toLocaleLowerCase(window.BetterNotebookI18n.locale).includes(term))&&(note==='all'||(note==='unassigned'?!item.notes.length:item.notes.some(n=>n.id===Number(note)))));
      }
      function mediaCard(item,chooser=null){
        const attached=chooser&&item.notes.some(n=>n.id===chooser.note?.id),disabled=!item.available||item.kind==='other'||attached||!!chooser?.busy||(chooser?.selected.size>=20&&!chooser.selected.has(item.id));
        const preview=item.available&&item.kind==='image'?`<img src="${esc(item.url)}" loading="lazy" alt="">`:ico(item.kind==='image'?'image':item.kind==='pdf'?'file-text':'file');
        const notes=item.notes.length?item.notes.map(n=>`<button type="button" class="bn-media-note" data-media-note="${n.id}">${ico('notebook-pen')}${esc(n.title)}</button>`).join(''):__bnT('<span class="bn-mini">Henüz bir nota eklenmemiş</span>');
        return `<article class="bn-media-card ${chooser?.selected.has(item.id)?'is-selected':''}"><div class="bn-media-preview">${preview}${chooser?`<label class="bn-media-check"><input type="checkbox" data-media-select="${item.id}" aria-label="${esc(item.name)} seç" ${chooser.selected.has(item.id)?'checked':''} ${disabled?'disabled':''}></label>`:''}</div><strong title="${esc(item.name)}">${esc(item.name)}</strong><div class="bn-media-meta">${item.kind==='image'?__bnT('Görsel'):item.kind==='pdf'?'PDF':__bnT('Dosya')} · ${fileSize(item.size)}</div><div class="bn-media-links">${notes}</div><div class="bn-media-bottom">${!item.available?__bnT('<span class="bn-mini">Dosya bulunamadı</span>'):attached?__bnT('<span class="bn-mini">Bu notta mevcut</span>'):chooser?__bnT('<span class="bn-mini">Eklemek için seç</span>'):__bnHTML`<a class="bn-btn" href="${esc(item.url)}" target="_blank" rel="noopener" aria-label="${esc(item.name)} dosyasını aç">${ico('arrow-up-right')}Aç</a>`}${chooser?'':trashButton('file',item.id,item.name)}</div></article>`;
      }
      function mediaPagination(page,total,dialog=false){const pages=Math.max(1,Math.ceil(total/12));return __bnHTML`<div class="bn-media-pagination"><button type="button" class="bn-btn" data-media-page="${page-1}" ${page<=1?'disabled':''}>${ico('chevron-left')}Önceki</button><span>${page} / ${pages}</span><button type="button" class="bn-btn" data-media-page="${page+1}" ${page>=pages?'disabled':''}>Sonraki${ico('chevron-right')}</button></div>`;}
      function mediaResults(){
        const matches=mediaMatches(mediaStore.items,state.mediaQuery,state.mediaNote),pages=Math.max(1,Math.ceil(matches.length/12));state.mediaPage=Math.min(pages,Math.max(1,state.mediaPage));
        const items=matches.slice((state.mediaPage-1)*12,state.mediaPage*12),groups=new Map();
        for(const item of items){const notes=state.mediaNote==='all'?item.notes:state.mediaNote==='unassigned'?[]:item.notes.filter(n=>n.id===Number(state.mediaNote));for(const note of notes.length?notes:[{id:0,title:__bnT('Henüz bir nota eklenmemiş')}]){if(!groups.has(note.id))groups.set(note.id,{note,items:[]});groups.get(note.id).items.push(item);}}
        return `${[...groups.values()].map(group=>`<section class="bn-media-group"><div class="bn-section-top"><h3>${group.note.id?ico('notebook-pen'):ico('folder-open')}${esc(group.note.title)}</h3>${group.note.id?__bnHTML`<button type="button" class="bn-btn" data-media-note="${group.note.id}">Notu aç${ico('arrow-up-right')}</button>`:''}</div><div class="bn-media-grid">${group.items.map(item=>mediaCard(item)).join('')}</div></section>`).join('')||'<div class="bn-live-empty">'+ico('folder-open')+__bnT('<h3>Dosya bulunamadı</h3><p>Bir dosya yükle veya filtreleri değiştir.</p></div>')}${mediaPagination(state.mediaPage,matches.length)}`;
      }
      function filesPage(){
        if(!mediaStore.items&&!mediaStore.promise&&!mediaStore.error)loadMedia().then(()=>{if(state.view==='files')render();}).catch(()=>{if(state.view==='files')render();});
        return __bnHTML`${heading('',__bnT('Dosyalar'),__bnT('Yüklediğin kaynaklar, ilişkili oldukları notlarla bir arada.'),__bnHTML`<div class="bn-row bn-trash-actions-inline"><button type="button" class="bn-btn bn-btn-primary" data-media-upload-open>${ico('upload')}Dosya yükle</button>${trashOpenButton()}</div>`)}<div class="bn-toolbar"><label class="bn-search">${ico('search')}<input data-media-search placeholder="Dosya veya not adı ara…" aria-label="Dosyalarda ara" value="${esc(state.mediaQuery)}"></label><div class="bn-filter-controls bn-media-filter"><select data-media-note-filter aria-label="İlişkili nota göre filtrele"><option value="all" ${state.mediaNote==='all'?'selected':''}>Tüm notlar</option><option value="unassigned" ${state.mediaNote==='unassigned'?'selected':''}>Nota eklenmemiş</option>${state.notes.map(n=>`<option value="${n.id}" ${state.mediaNote===String(n.id)?'selected':''}>${esc(n.title)}</option>`).join('')}</select><button type="button" class="bn-btn" data-media-refresh aria-label="Dosyaları yenile">${ico('refresh-cw')}</button></div></div><p class="bn-mini" data-media-total>${mediaStore.items?mediaStore.items.length+__bnT(' dosya'):''}</p><div data-media-results>${mediaStore.error?`<p role="alert">${esc(mediaStore.error)}</p>`:mediaStore.items?mediaResults():__bnT('<p role="status">Dosyalar yükleniyor…</p>')}</div>`;
      }
      function filterMediaPage(){const result=main.querySelector('[data-media-results]');if(result){result.innerHTML=mediaResults();icons();}}
      function pdfPicker(label,mode='attach'){
        if(mode==='new')return `<label class="bn-file-picker">${ico('paperclip')}${label}<input type="file" accept="application/pdf,.pdf" multiple data-pdf-input data-upload-mode="new" aria-label="${label}"></label>`;
        return __bnHTML`<button type="button" class="bn-btn" data-media-choose>${ico('paperclip')}Kaynak ekle</button>`;
      }
      function showMediaLibrary(note=null,tab='library'){
        const selected=new Set(),dialog=flowDialog(__bnT('Dosya kütüphanesi'),__bnHTML`<div class="bn-media-tabs" role="tablist"><button type="button" role="tab" data-media-tab="upload">Dosya yükle</button><button type="button" role="tab" data-media-tab="library">Kütüphane</button></div><section data-media-upload><label class="bn-media-drop">${ico('upload-cloud')}<strong>Dosyalarını buraya sürükle</strong><span>veya dosya seç</span><input type="file" data-media-upload-input multiple accept="application/pdf,image/png,image/jpeg,image/webp" aria-label="Kütüphaneye dosya yükle"><small>PDF, PNG, JPG, WEBP · Dosya başına en fazla 50 MB</small></label>${note?__bnHTML`<label class="bn-btn bn-media-split">${ico('scissors')}PDF böl / sayfa seç<input type="file" multiple accept="application/pdf,.pdf" data-pdf-input data-upload-mode="attach" aria-label="PDF bölerek yükle"></label>`:''}</section><section data-media-library><label class="bn-search">${ico('search')}<input data-media-dialog-search placeholder="Dosya veya not adı ara…" aria-label="Kütüphanede ara"></label><div data-media-dialog-items></div></section><p class="bn-mini" data-media-feedback role="status"></p><footer class="bn-media-footer"><span data-media-selected-count></span><button type="button" class="bn-btn bn-btn-primary" data-media-attach ${note?'':'hidden'}>Seçilenleri ekle</button><button type="button" class="bn-btn" data-action="close-flow">Kapat</button></footer>`);
        dialog.classList.add('bn-media-dialog');const model={note,selected,page:1,query:'',tab};dialog.mediaModel=model;
        function paint(){if(!dialog.isConnected)return;model.busy=!!dialog.dataset.busy;dialog.querySelector('[data-media-upload-input]').disabled=model.busy;dialog.querySelector('[data-media-upload]').hidden=model.tab!=='upload';dialog.querySelector('[data-media-library]').hidden=model.tab!=='library';dialog.querySelectorAll('[data-media-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.mediaTab===model.tab)));
          const matches=mediaMatches(mediaStore.items,model.query);model.page=Math.min(Math.max(1,Math.ceil(matches.length/12)),Math.max(1,model.page));
          const box=dialog.querySelector('[data-media-dialog-items]'),scroll=box.scrollTop,focused=document.activeElement?.dataset.mediaSelect;box.innerHTML=mediaStore.items?`<div class="bn-media-grid">${matches.slice((model.page-1)*12,model.page*12).map(item=>mediaCard(item,note?model:null)).join('')||__bnT('<p class="bn-mini">Dosya bulunamadı</p>')}</div>${mediaPagination(model.page,matches.length,true)}`:__bnT('<p>Dosyalar yükleniyor…</p>');
          box.scrollTop=scroll;if(focused)box.querySelector('[data-media-select="'+focused+'"]')?.focus({preventScroll:true});
          dialog.querySelector('[data-media-selected-count]').textContent=note?selected.size+__bnT(' dosya seçildi'):'';dialog.querySelector('[data-media-attach]').disabled=!selected.size||!!dialog.dataset.busy;icons();
        }
        const feedback=dialog.querySelector('[data-media-feedback]');
        async function upload(files){if(dialog.dataset.busy||!files.length)return;dialog.dataset.busy='1';feedback.textContent=__bnT('Dosyalar yükleniyor…');paint();const form=new FormData();form.append('action','media_upload');for(const file of files)form.append('files[]',file,file.name);
          try{const data=await apiForm(form);mediaStore.items=data.items;for(const id of data.ids)if(note&&selected.size<20)selected.add(id);model.tab='library';model.query='';model.page=1;dialog.querySelector('[data-media-dialog-search]').value='';feedback.textContent=__bnT('Dosyalar kütüphaneye yüklendi.');if(state.view==='files')render();}
          catch(error){feedback.textContent=error.message;}finally{delete dialog.dataset.busy;paint();}}
        dialog.addEventListener('click',async event=>{const b=event.target.closest('button');if(!b)return;
          if(b.dataset.mediaTab){if(!dialog.dataset.busy){model.tab=b.dataset.mediaTab;paint();}return;}
          if(b.hasAttribute('data-media-page')){model.page=Number(b.dataset.mediaPage);paint();return;}
          if(b.hasAttribute('data-media-note')){event.stopPropagation();const target=state.notes.find(n=>n.id===Number(b.dataset.mediaNote));if(target){dialog.close();state.noteId=target.id;go('reader');}return;}
          if(!b.hasAttribute('data-media-attach')||!note||dialog.dataset.busy)return;dialog.dataset.busy='1';feedback.textContent=__bnT('Kaynaklar nota ekleniyor…');paint();
          try{await saveDraft(note,true);const data=await api('media_attach',{note_id:note.id,media_ids:[...selected]});note.attachments=data.note.attachments;note.kind=data.note.kind;note.edited=data.note.edited;note.updatedAt=data.note.updatedAt;mediaStore.items=null;
            if(state.view==='reader'&&state.noteId===note.id&&data.added){note.activeSource=note.attachments.at(-1)?.id;const old=main.querySelector('[data-pane="0"]'),holder=document.createElement('div');holder.innerHTML=notebookSource(note);old?.replaceWith(holder.firstElementChild);state.minimized.delete(0);updateReaderWindows();updateReaderColumns();paintQAContext();icons();persist();}
            dialog.close();notify(data.added?__bnT('Kaynaklar nota eklendi.'):__bnT('Seçilen kaynaklar bu notta zaten mevcut.'));
          }catch(error){feedback.textContent=error.message;}finally{delete dialog.dataset.busy;paint();}
        });
        dialog.addEventListener('input',event=>{if(event.target.matches('[data-media-dialog-search]')){model.query=event.target.value;model.page=1;paint();}});
        dialog.addEventListener('change',event=>{if(event.target.matches('[data-media-select]')){const id=Number(event.target.dataset.mediaSelect);event.target.checked?selected.add(id):selected.delete(id);paint();}if(event.target.matches('[data-media-upload-input]')){const files=[...event.target.files];event.target.value='';upload(files);}});
        const drop=dialog.querySelector('.bn-media-drop');drop.addEventListener('dragover',event=>{event.preventDefault();drop.classList.add('is-over');});drop.addEventListener('dragleave',()=>drop.classList.remove('is-over'));drop.addEventListener('drop',event=>{event.preventDefault();drop.classList.remove('is-over');upload([...event.dataTransfer.files]);});
        paint();loadMedia(true).then(paint).catch(error=>feedback.textContent=error.message);
      }
      host.addEventListener('click',async event=>{const b=event.target.closest('button');if(!b)return;
        if(b.hasAttribute('data-media-choose')){showMediaLibrary(currentNote());return;}
        if(b.hasAttribute('data-media-upload-open')){showMediaLibrary(null,'upload');return;}
        if(b.hasAttribute('data-media-note')&&!b.closest('.bn-media-dialog')){const note=state.notes.find(n=>n.id===Number(b.dataset.mediaNote));if(note){state.noteId=note.id;go('reader');}return;}
        if(b.hasAttribute('data-media-page')&&!b.closest('.bn-media-dialog')){state.mediaPage=Number(b.dataset.mediaPage);filterMediaPage();return;}
        if(b.hasAttribute('data-media-refresh')){b.disabled=true;try{await loadMedia(true);filterMediaPage();main.querySelector('[data-media-total]').textContent=mediaStore.items.length+__bnT(' dosya');}catch(error){notify(error.message);}finally{b.disabled=false;}}
      });
      host.addEventListener('input',event=>{if(event.target.matches('[data-media-search]')){state.mediaQuery=event.target.value;state.mediaPage=1;filterMediaPage();}});
      host.addEventListener('change',event=>{if(event.target.matches('[data-media-note-filter]')){state.mediaNote=event.target.value;state.mediaPage=1;filterMediaPage();}});
      let headerTitleSession=null;
      function readerTitle(n){return __bnHTML`<div class="bn-header-title-edit"><h3 aria-label="${esc(n.title)}"><button type="button" class="bn-title-edit-button" data-header-title aria-label="Başlığı düzenle" data-tooltip="Başlığı düzenle"><span data-title-label>${esc(n.title)}</span>${ico('pencil')}</button></h3><input type="text" class="bn-header-title-input" data-header-title-input aria-label="Not başlığını düzenle" maxlength="160" value="${esc(n.title)}" hidden></div>`;}
      function finishHeaderTitle(input,cancel=false){const session=headerTitleSession;if(!session)return;headerTitleSession=null;const n=state.notes.find(note=>note.id===session.noteId);const wrap=input.closest('.bn-header-title-edit'),button=wrap?.querySelector('[data-header-title]');input.hidden=true;if(wrap)wrap.querySelector('h3').hidden=false;if(!n||cancel){if(button&&cancel)button.focus({preventScroll:true});return;}const title=input.value.trim();if(!title){input.value=n.title;notify(__bnT('Not başlığı boş bırakılamaz.'));button?.focus({preventScroll:true});return;}if(title===n.title)return;n.title=title;const label=wrap?.querySelector('[data-title-label]');if(label){label.textContent=title;label.closest('h3')?.setAttribute('aria-label',title);}const other=main.querySelector('[data-note-title]');if(other)other.value=title;dirty.add(n.id);saveDraft(n,true).catch(()=>{});}
      host.addEventListener('click',event=>{const button=event.target.closest('[data-header-title]');if(!button)return;const n=currentNote(),wrap=button.closest('.bn-header-title-edit'),input=wrap.querySelector('[data-header-title-input]');if(!n)return;headerTitleSession={noteId:n.id};wrap.querySelector('h3').hidden=true;input.hidden=false;input.value=n.title;input.focus({preventScroll:true});input.select();});
      host.addEventListener('keydown',event=>{if(!event.target.matches('[data-header-title-input]'))return;if(event.key==='Enter'){event.preventDefault();finishHeaderTitle(event.target);main.querySelector('[data-header-title]')?.focus({preventScroll:true});}else if(event.key==='Escape'){event.preventDefault();finishHeaderTitle(event.target,true);}});
      host.addEventListener('focusout',event=>{if(event.target.matches('[data-header-title-input]'))finishHeaderTitle(event.target);});
      let noteRichEditor=null;
      function unmountNoteEditor(){if(noteRichEditor){noteRichEditor.destroy();noteRichEditor=null;}}
      function mountNoteEditor(){const input=main.querySelector('[data-note-edit]');if(!input||!window.BetterNotebookRichText)return;const n=currentNote();noteRichEditor=window.BetterNotebookRichText.mount(input,{html:renderMD(input.value),toHTML:renderMD,t:window.BetterNotebookI18n.t,icon:ico,onUpdate(text){const note=currentNote();if(!note||note.id!==n.id)return;const plain=text.trim().replace(/\s+/g,' ');note.summary=plain.slice(0,150)||window.BetterNotebookI18n.t(__bnT('Düşüncelerini buraya yaz.'));note.summaryPlaceholder=!plain;}});const label=main.querySelector(`label[for="${input.id}"]`);label?.addEventListener('click',()=>noteRichEditor?.focus());}
      function syncRichNoteContent(input,body){if(noteRichEditor?.input===input)noteRichEditor.setContent(renderMD(body));}
      function focusRichNoteEnd(input){if(noteRichEditor?.input!==input)return false;noteRichEditor.focusEnd();const copy=input.closest('.bn-r-copy');if(copy)copy.scrollTop=copy.scrollHeight;return true;}
      function showSummary(scope='note'){const n=currentNote();if(!n)return;const sources=noteSources(n),selected=scope==='note'||sources.some(s=>s.id===scope)?scope:'note';const dialog=flowDialog(__bnT('AI Özeti'),__bnHTML`<p class="bn-subtitle">${esc(n.title)} · Mevcut yazın korunur.</p><fieldset class="bn-summary-scopes" style="padding:0;border:0"><legend class="bn-mini">Özet kapsamı</legend><label><input type="radio" name="summary-scope" value="note" data-summary-scope ${selected==='note'?'checked':''}>Notun tamamı · Yazı ve eklenen kaynaklar</label>${sources.map(s=>__bnHTML`<label><input type="radio" name="summary-scope" value="${esc(s.id)}" data-summary-scope ${selected===s.id?'checked':''}>Sadece ${esc(s.name)}</label>`).join('')}</fieldset><button type="button" class="bn-btn bn-btn-primary" data-action="prepare-summary">${ico('sparkles')}Özeti hazırla</button><div data-summary-result role="status" aria-live="polite"></div><p class="bn-mini" style="margin-top:18px">Özet, mevcut yazının sonuna otomatik eklenir. AI kullanım sınırları geçerlidir.</p>`);dialog.dataset.noteId=String(n.id);if(n.lastSummary?.scope===selected)paintSummary(dialog,n.lastSummary);}
      function paintSummary(dialog,result){dialog.querySelector('[data-summary-result]').innerHTML=`<div class="bn-summary-output"><span class="bn-chip">${result.applied?__bnT('Notuna eklenmiş AI özeti'):__bnT('Kaydedilmiş AI özeti')}</span><p class="bn-mini">${esc(result.sources?.map(s=>s.name).join(' · ')||__bnT('Not yazısı'))}</p><div class="bn-live-markdown">${renderMD(result.text)}</div>${result.applied?'':__bnHTML`<button type="button" class="bn-btn bn-btn-primary" data-action="append-saved-summary" data-summary-id="${result.id}">${ico('notebook-pen')}Nota ekle</button>`}</div>`;renderMath(dialog);icons();}
      async function withNoteSummary(n,request){if(summaryLocks.has(n.id))throw new Error(__bnT('Bu not için bir özet işlemi sürüyor.'));const baseline=n.body??n.content??'';const initialSave=saveDraft(n,true);let release;summaryLocks.set(n.id,new Promise(resolve=>{release=resolve;}));let data,needsSave=false;
        try{await initialSave;data=await request();const local=n.body??n.content??'',changed=local!==baseline;if(data.summary)n.lastSummary=data.summary;if(data.qaHistory)n.qaHistory=data.qaHistory;if(!changed)n.body=data.note.body;else if(!data.alreadyApplied)n.body=local===''?(data.summary?.section||data.appendSection):local+'\n\n---\n\n'+(data.summary?.section||data.appendSection);n.content=n.body;n.sourceOnly=false;n.updatedAt=data.note.updatedAt;n.edited=data.note.edited;n.summaryPlaceholder=false;n.summary=changed?n.body.replace(/\s+/g,' ').slice(0,150):data.note.summary;needsSave=n.body!==data.note.body||n.title!==data.note.title||n.course!==data.note.course;if(needsSave)dirty.add(n.id);else dirty.delete(n.id);if(state.noteId===n.id){const copy=main.querySelector('.bn-r-copy'),copyScroll=copy?.scrollTop;state.userNote=n.body;const editor=main.querySelector('[data-note-edit]');if(editor){const start=editor.selectionStart,end=editor.selectionEnd,scroll=editor.scrollTop;editor.value=n.body;syncRichNoteContent(editor,n.body);editor.setSelectionRange(start,end);editor.scrollTop=scroll;}if(copy)copy.scrollTop=copyScroll;}}
        finally{summaryLocks.delete(n.id);release();}
        if(needsSave)await saveDraft(n,true);return data;}
      function revealNoteSummary(n,dialog){
        if(dialog.isConnected&&dialog.open){
          dialog.close();
          if(state.view==='reader'&&state.noteId===n.id){
            const copy=main.querySelector('.bn-r-copy'),scroll=copy?.scrollTop||0,editor=main.querySelector('[data-note-edit]');
            // A rich editor was already updated by withNoteSummary. Preserve its instance.
            if(!editor){const content=copy?.querySelector('.bn-live-markdown');if(content){content.innerHTML=renderMD(n.body);renderMath(content);}}
            if(state.minimized.has(1)){state.minimized.delete(1);updateReaderWindows();persist();}
            if(copy)copy.scrollTop=scroll;
          }else if(['notes','today'].includes(state.view))render();
        }
        notify(__bnT('Özet notuna eklendi.'));
      }
      async function prepareSummary(dialog){const n=dialog.dataset.noteId?state.notes.find(note=>note.id===Number(dialog.dataset.noteId)):currentNote();if(!n||dialog.dataset.busy)return;const button=dialog.querySelector('[data-action="prepare-summary"]'),out=dialog.querySelector('[data-summary-result]');dialog.dataset.busy='1';button.disabled=true;const scope=dialog.querySelector('[data-summary-scope]:checked')?.value||'note';out.innerHTML=__bnT('<p class="bn-mini" style="margin-top:20px">Özet hazırlanıyor. Tamamlandığında notuna eklenecek…</p>');try{await withNoteSummary(n,()=>api('ai',{note_id:n.id,kind:'summary',scope,source_ids:scope==='note'?[]:[scope]}));revealNoteSummary(n,dialog);}catch(error){if(dialog.isConnected)out.innerHTML=`<p role="alert" style="margin-top:20px">${esc(error.message)}</p>`;}finally{delete dialog.dataset.busy;button.disabled=false;}}
      host.addEventListener('click',async event=>{const button=event.target.closest('[data-action="append-saved-summary"]');if(!button)return;const dialog=button.closest('dialog'),n=dialog.dataset.noteId?state.notes.find(note=>note.id===Number(dialog.dataset.noteId)):currentNote();if(!n||dialog.dataset.busy)return;dialog.dataset.busy='1';button.disabled=true;try{await withNoteSummary(n,()=>api('apply_summary',{note_id:n.id,summary_id:Number(button.dataset.summaryId)}));revealNoteSummary(n,dialog);}catch(error){if(dialog.isConnected)dialog.querySelector('[data-summary-result]').innerHTML=`<p role="alert">${esc(error.message)}</p>`;}finally{delete dialog.dataset.busy;button.disabled=false;}});
      const pendingAnswerAdds=new Set();
      function qaConversation(n){const history=n.qaHistory||[];let html=history.map(row=>`${row.question?`<div class="bn-live-question">${esc(row.question)}</div>`:''}<article class="bn-r-message bn-answer-message" data-answer-card="${esc(row.id)}"><button type="button" class="bn-answer-add" data-answer-add="${esc(row.id)}" aria-label="${row.addedToNote?__bnT('Notuna eklendi'):__bnT('Yanıtı nota ekle')}" data-tooltip="${row.addedToNote?__bnT('Notuna eklendi'):__bnT('Yanıtı nota ekle')}" ${row.addedToNote||pendingAnswerAdds.has(row.id)?'disabled':''}>${ico(row.addedToNote?'check':'plus')}</button><div class="bn-live-markdown">${renderMD(row.answer)}</div></article>`).join('');if(state.aiPending&&state.aiPendingNoteId===n.id&&state.lastQuestion)html+=`<div class="bn-live-question">${esc(state.lastQuestion)}</div>`;if(!state.aiPending&&state.response&&state.response!==history.at(-1)?.answer)html+=`<div class="bn-r-message bn-answer-error" role="alert">${esc(state.response)}</div>`;return html||__bnHTML`<div class="bn-r-empty">${ico('at-sign')}<div>Notunla konuş</div><p>Belirli bir PDF hakkında soru sormak için @ yazıp kaynağını seç.</p></div>`;}
      function scrollQAEnd(){const space=main.querySelector('.bn-r-qa-space');if(space)space.scrollTop=space.scrollHeight;}
      host.addEventListener('click',async event=>{const button=event.target.closest('[data-answer-add]');if(!button||button.disabled)return;const n=currentNote(),id=button.dataset.answerAdd;if(!n||pendingAnswerAdds.has(id))return;pendingAnswerAdds.add(id);button.disabled=true;try{while(summaryLocks.has(n.id))await summaryLocks.get(n.id);await withNoteSummary(n,()=>api('add_answer',{note_id:n.id,answer_id:id}));if(state.view==='reader'&&state.noteId===n.id){const space=main.querySelector('.bn-r-qa-space'),scroll=space?.scrollTop||0;state.tab='notes';state.minimized.delete(1);render();const updated=main.querySelector('.bn-r-qa-space');if(updated)updated.scrollTop=scroll;const copy=main.querySelector('.bn-r-copy');if(copy)copy.scrollTop=copy.scrollHeight;}notify(__bnT('Yanıt notuna eklendi.'));}catch(error){button.disabled=false;notify(error.message);}finally{pendingAnswerAdds.delete(id);}});
      let pdfLibraryPromise;
      function loadPDFLibrary(){if(window.PDFLib)return Promise.resolve(window.PDFLib);if(!pdfLibraryPromise)pdfLibraryPromise=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='/assets/vendor/pdf-lib.min.js?v=1.17.1';script.onload=()=>resolve(window.PDFLib);script.onerror=()=>{script.remove();pdfLibraryPromise=null;reject(new Error(__bnT('PDF aracı yüklenemedi. Bağlantını kontrol edip tekrar dene.')));};document.head.append(script);});return pdfLibraryPromise;}
      async function readPDFBytes(file){try{return await file.arrayBuffer();}catch{throw new Error(__bnT('PDF dosyası değişmiş veya okunamıyor. Dosyayı yeniden seç.'));}}
      function pdfImportCount(count,unit){const en=window.BetterNotebookI18n.language==='en';const label=unit==='page'?(en?(count===1?'page':'pages'):__bnT('sayfa')):unit==='note'?(en?(count===1?'separate note':'separate notes'):__bnT('ayrı not')):(en?(count===1?'PDF part':'PDF parts'):__bnT('PDF parçası'));return count+' '+label;}
      function planPDFImport(entries,mode,size,start,end){if(mode==='auto'&&(!Number.isInteger(size)||size<1||size>1000))throw new Error(__bnT('Parça başına 1–1000 arasında sayfa seç.'));if(mode==='range'&&(entries.length!==1||!Number.isInteger(start)||!Number.isInteger(end)||start<1||end<start||end>entries[0].pages))throw new Error(__bnT('PDF içindeki geçerli bir başlangıç ve bitiş sayfası seç.'));const pieces=[];for(const [fileIndex,entry]of entries.entries()){const first=mode==='range'?start:1,last=mode==='range'?end:entry.pages,step=mode==='auto'?size:last-first+1;let part=1;for(let from=first;from<=last;from+=step)pieces.push({fileIndex,start:from,end:Math.min(from+step-1,last),part:part++,whole:mode==='whole',clientId:crypto.randomUUID()});}return pieces;}
      async function attachPDFs(input){const files=Array.from(input.files||[]);if(!files.length)return;const target=input.dataset.uploadMode==='new'?null:currentNote();input.value='';if(files.length>20){notify(__bnT('Tek seferde en fazla 20 PDF seçebilirsin.'));return;}for(const file of files){if(!/\.pdf$/i.test(file.name)||!(await file.slice(0,1024).text()).includes('%PDF-')){notify(__bnT('Geçerli bir PDF dosyası seç.'));return;}if(file.size>512*1024*1024){notify(__bnT('Tarayıcıda bölmek için en fazla 512 MB boyutunda bir PDF seç.'));return;}}
        host.querySelectorAll('.bn-flow-dialog').forEach(d=>d.close());
        const dialog=flowDialog(__bnT('PDF içe aktar'),__bnHTML`<p class="bn-subtitle">PDF’ini tek parça yükle, otomatik böl veya yalnızca ihtiyacın olan sayfaları ayır.</p><div class="bn-import-files" data-import-files></div><fieldset class="bn-import-options" data-import-options hidden><legend>PDF nasıl eklensin?</legend><div class="bn-import-modes"><label><input type="radio" name="pdf-import-mode" value="whole" data-import-option checked><span>Tek parça</span></label><label><input type="radio" name="pdf-import-mode" value="auto" data-import-option><span>Otomatik böl</span></label><label><input type="radio" name="pdf-import-mode" value="range" data-import-option ${files.length>1?'disabled':''}><span>Sayfa aralığı</span></label></div><div data-import-chunk hidden><label class="bn-import-number">Parça başına sayfa<input type="number" data-import-size data-import-option min="1" max="1000" value="10"></label></div><div class="bn-import-range" data-import-range hidden><label>Başlangıç<input type="number" data-import-from data-import-option min="1" value="1"></label><label>Bitiş<input type="number" data-import-to data-import-option min="1" value="1"></label></div>${target?__bnT('<p class="bn-mini">Parçalar açık notuna ayrı PDF kaynakları olarak eklenir. Not yazın korunur.</p>'):__bnHTML`<fieldset class="bn-import-destination" data-import-destination hidden><legend>Parçaları kaydet</legend><label><input type="radio" name="pdf-import-destination" value="separate" data-import-option checked>Her parça ayrı not</label><label><input type="radio" name="pdf-import-destination" value="together" data-import-option>Tek notta PDF kaynakları</label></fieldset>`}<p class="bn-mini" data-import-plan aria-live="polite"></p></fieldset><div class="bn-import-progress" hidden data-import-progress><progress max="1" value="0" aria-label="PDF aktarım ilerlemesi"></progress><p class="bn-mini" data-import-status role="status" aria-live="polite"></p></div><p class="bn-import-feedback" data-import-feedback role="status" aria-live="polite">PDF hazırlanıyor…</p><div class="bn-import-actions"><button type="button" class="bn-btn" data-pdf-stop hidden>Durdur</button><button type="button" class="bn-btn bn-btn-primary" data-pdf-start disabled>İçe aktar</button><button type="button" class="bn-btn bn-btn-primary" data-pdf-done hidden>${target?__bnT('Nota dön'):__bnT('Notları gör')}</button></div><p class="bn-mini bn-import-footnote">Bölme tarayıcında yapılır. Yalnızca seçtiğin PDF’ler veya parçalar kaydedilir. Bu işlem AI kotası kullanmaz.</p>`);
        dialog.classList.add('bn-pdf-import');const close=dialog.querySelector('[data-action="close-flow"]');close.removeAttribute('data-action');
        const status=dialog.querySelector('[data-import-status]'),feedback=dialog.querySelector('[data-import-feedback]'),startButton=dialog.querySelector('[data-pdf-start]'),stopButton=dialog.querySelector('[data-pdf-stop]'),doneButton=dialog.querySelector('[data-pdf-done]');
        const job={entries:[],pieces:null,next:0,sentRequest:false,running:false,stop:false,groupId:target?.id||0,separate:false,results:new Map(),course:state.courses.includes(state.filter)?state.filter:'',cache:null};
        const text=value=>window.BetterNotebookI18n.t(value);
        function describe(){if(job.pieces)return;const mode=dialog.querySelector('[name="pdf-import-mode"]:checked')?.value||'whole';dialog.querySelector('[data-import-chunk]').hidden=mode!=='auto';dialog.querySelector('[data-import-range]').hidden=mode!=='range';const destination=dialog.querySelector('[data-import-destination]');if(destination)destination.hidden=mode!=='auto';try{if(mode==='whole'&&job.entries.some(entry=>entry.file.size>50*1024*1024))throw new Error(__bnT('Tek parça yükleme sınırı 50 MB. Otomatik bölmeyi veya sayfa aralığını seç.'));const pieces=planPDFImport(job.entries,mode,Number(dialog.querySelector('[data-import-size]').value),Number(dialog.querySelector('[data-import-from]').value),Number(dialog.querySelector('[data-import-to]').value));const separate=!target&&mode==='auto'&&dialog.querySelector('[name="pdf-import-destination"]:checked').value==='separate';dialog.querySelector('[data-import-plan]').textContent=pdfImportCount(pieces.length,'part')+' · '+(separate?pdfImportCount(pieces.length,'note'):text(__bnT('Tek not')));startButton.disabled=false;feedback.textContent='';}catch(error){startButton.disabled=true;dialog.querySelector('[data-import-plan]').textContent='';feedback.textContent=text(error.message);}}
        function requestStop(){job.stop=true;stopButton.disabled=true;status.textContent=text(__bnT('Geçerli parça kaydedildikten sonra duracak.'));}
        close.addEventListener('click',()=>{if(job.running)requestStop();else dialog.close();});dialog.addEventListener('cancel',event=>{if(job.running){event.preventDefault();requestStop();}});dialog.addEventListener('close',()=>{job.cache=null;job.entries=[];if(job.results.size)render();});stopButton.addEventListener('click',requestStop);dialog.addEventListener('input',event=>{if(event.target.matches('[data-import-option]'))describe();});
        doneButton.addEventListener('click',()=>{dialog.close();if(target){if(state.noteId===target.id)render();}else{state.query='';state.filter='all';state.notesPage=1;go('notes');}});
        startButton.addEventListener('click',async()=>{if(job.running)return;job.running=true;job.stop=false;startButton.disabled=true;stopButton.hidden=false;stopButton.disabled=false;feedback.textContent='';try{
            if(!job.pieces){const mode=dialog.querySelector('[name="pdf-import-mode"]:checked').value;job.pieces=planPDFImport(job.entries,mode,Number(dialog.querySelector('[data-import-size]').value),Number(dialog.querySelector('[data-import-from]').value),Number(dialog.querySelector('[data-import-to]').value));job.separate=!target&&mode==='auto'&&dialog.querySelector('[name="pdf-import-destination"]:checked').value==='separate';dialog.querySelectorAll('[data-import-option]').forEach(el=>el.disabled=true);}
            if(target)await saveDraft(target,true);const lib=await loadPDFLibrary(),progress=dialog.querySelector('progress');dialog.querySelector('[data-import-progress]').hidden=false;progress.max=job.pieces.length;
            while(job.next<job.pieces.length&&!job.stop){const piece=job.pieces[job.next],entry=job.entries[piece.fileIndex],base=entry.file.name.replace(/\.pdf$/i,'');status.textContent=text(__bnT('Parça hazırlanıyor'))+' '+(job.next+1)+' / '+job.pieces.length+' · '+entry.file.name+' · '+piece.start+'–'+piece.end;await new Promise(resolve=>requestAnimationFrame(resolve));let file=entry.file;
                if(!piece.whole){if(job.cache?.fileIndex!==piece.fileIndex)job.cache={fileIndex:piece.fileIndex,doc:await lib.PDFDocument.load(await readPDFBytes(entry.file))};const result=await lib.PDFDocument.create();const indices=Array.from({length:piece.end-piece.start+1},(_,i)=>piece.start-1+i);for(const page of await result.copyPages(job.cache.doc,indices))result.addPage(page);file=new File([await result.save()],base+'-'+piece.start+'-'+piece.end+'.pdf',{type:'application/pdf'});}
                if(file.size>50*1024*1024)throw new Error(__bnT('Her PDF parçası en fazla 50 MB olabilir. Daha az sayfalı parçalar seç.'));
                const form=new FormData();form.append('action','import_pdf');form.append('client_id',piece.clientId);form.append('note_id',String(job.separate?0:job.groupId));form.append('course',job.course);form.append('title',piece.whole?base:base+' · '+text(__bnT('Sayfa'))+' '+piece.start+'–'+piece.end);form.append('file',file,file.name);
                status.textContent=text(__bnT('Parça kaydediliyor'))+' '+(job.next+1)+' / '+job.pieces.length;job.sentRequest=true;const data=await apiForm(form),saved=data.note;const existing=state.notes.find(n=>n.id===saved.id);if(existing)Object.assign(existing,saved);else state.notes.unshift(saved);job.results.set(saved.id,saved);if(!job.separate)job.groupId=saved.id;job.next++;progress.value=job.next;status.textContent=job.next+' / '+job.pieces.length+' '+text(__bnT('parça kaydedildi.'));
            }
            if(job.next===job.pieces.length){feedback.textContent=text(__bnT('PDF aktarımı tamamlandı.'));startButton.hidden=true;doneButton.hidden=false;job.cache=null;}else feedback.textContent=text(__bnT('Aktarım duraklatıldı. Kaydedilen parçalar korunuyor.'));
        }catch(error){if(job.next===0&&!job.sentRequest){job.pieces=null;dialog.querySelectorAll('[data-import-option]').forEach(el=>el.disabled=el.value==='range'&&files.length>1);}feedback.textContent=text(error.message)+' '+text(__bnT('Kaydedilen parçalar korunuyor. Tekrar deneyebilirsin.'));}
        finally{job.running=false;stopButton.hidden=true;startButton.disabled=false;startButton.textContent=text(__bnT('Devam et'));}});
        try{const lib=await loadPDFLibrary();for(const file of files){if(!dialog.open)return;let doc;try{doc=await lib.PDFDocument.load(await readPDFBytes(file));}catch{throw new Error(__bnT('PDF okunamadı. Şifreliyse önce şifresiz bir kopyasını seç.'));}if(!doc.getPageCount())throw new Error(__bnT('PDF içinde sayfa bulunamadı.'));job.entries.push({file,pages:doc.getPageCount()});dialog.querySelector('[data-import-files]').innerHTML=job.entries.map(entry=>`<div><strong>${esc(entry.file.name)}</strong><span>${pdfImportCount(entry.pages,'page')} · ${fileSize(entry.file.size)}</span></div>`).join('');}
            if(!dialog.open)return;const total=job.entries[0].pages;dialog.querySelector('[data-import-to]').value=total;dialog.querySelector('[data-import-to]').max=total;dialog.querySelector('[data-import-from]').max=total;dialog.querySelector('[data-import-options]').hidden=false;describe();
        }catch(error){feedback.textContent=text(error.message);startButton.disabled=true;}
      }
      // Summary entry points share one menu; opening it never contacts the API.
      let aiToolsMenu=null,aiToolsTrigger=null;
      function aiToolsButton(){return readerButton(__bnT('AI araçları'),'ai-tools','sparkles',__bnHTML`aria-label="AI araçları" aria-haspopup="menu" aria-expanded="false" aria-controls="bn-ai-tools-${index}"`);}
      function selectedReaderPDF(){
        const n=currentNote();if(!n)return null;
        const sources=noteSources(n);let selected=sources.find(s=>s.id===n.activeSource)||sources[0];
        const frame=main.querySelector('iframe.bn-live-pdf');
        if(frame){const url=new URL(frame.src,location.href);if(Number(url.searchParams.get('note_id'))===n.id)selected=sources.find(s=>s.id===url.searchParams.get('source_id'));}
        const type=String(selected?.type||'').toLowerCase();
        return selected&&(type==='pdf'||!type&&/\.pdf$/i.test(selected.name||''))?selected:null;
      }
      function closeAITools(restoreFocus=false){
        const trigger=aiToolsTrigger;aiToolsMenu?.remove();aiToolsMenu=null;aiToolsTrigger=null;
        trigger?.setAttribute('aria-expanded','false');if(restoreFocus&&trigger?.isConnected)trigger.focus({preventScroll:true});
      }
      function positionAITools(){
        if(!aiToolsMenu||!aiToolsTrigger?.isConnected){closeAITools();return;}
        const r=aiToolsTrigger.getBoundingClientRect(),box=aiToolsMenu.getBoundingClientRect();
        const viewport=window.visualViewport,left=viewport?.offsetLeft||0,top=viewport?.offsetTop||0,width=viewport?.width||innerWidth,height=viewport?.height||innerHeight;
        const x=Math.max(left+8,Math.min(r.right-box.width,left+width-box.width-8));
        const y=r.bottom+box.height+8<top+height?r.bottom+8:Math.max(top+8,r.top-box.height-8);
        aiToolsMenu.style.left=x+'px';aiToolsMenu.style.top=y+'px';
      }
      function openAITools(trigger,last=false){
        closeAITools();const pdf=selectedReaderPDF(),t=window.BetterNotebookI18n.t;
        aiToolsTrigger=trigger;aiToolsMenu=document.createElement('div');aiToolsMenu.className='bn-ai-tools-menu';aiToolsMenu.id='bn-ai-tools-'+index;
        aiToolsMenu.setAttribute('role','menu');aiToolsMenu.setAttribute('aria-label',t(__bnT('AI araçları')));
        aiToolsMenu.innerHTML=`<button type="button" role="menuitem" data-ai-summary="note" tabindex="-1">${ico('notebook-pen')}<span><strong>${esc(t(__bnT('Notu özetle')))}</strong><small>${esc(t(__bnT('Notun tamamı · Yazı ve eklenen kaynaklar')))}</small></span></button><button type="button" role="menuitem" data-ai-summary="pdf" tabindex="-1" ${pdf?'':'disabled aria-describedby="bn-ai-pdf-reason-'+index+'"'}>${ico('file-text')}<span><strong>${esc(t(__bnT('Seçili PDF’yi özetle')))}</strong><small ${pdf?'title="'+esc(pdf.name)+'"':'id="bn-ai-pdf-reason-'+index+'"'}>${esc(pdf?.name||t(__bnT('Kaynak panelinde açık bir PDF yok.')))}</small></span></button>`;
        host.append(aiToolsMenu);trigger.setAttribute('aria-expanded','true');positionAITools();icons();
        const items=[...aiToolsMenu.querySelectorAll('button:not(:disabled)')];(last?items.at(-1):items[0])?.focus({preventScroll:true});
      }
      host.addEventListener('click',event=>{
        const trigger=event.target.closest('[data-action="ai-tools"]');
        if(trigger){event.preventDefault();if(aiToolsMenu)closeAITools(true);else openAITools(trigger);return;}
        const button=event.target.closest('[data-ai-summary]');if(!button||button.disabled||!aiToolsMenu?.contains(button))return;
        event.preventDefault();const scope=button.dataset.aiSummary==='note'?'note':selectedReaderPDF()?.id;
        closeAITools(true);if(scope)showSummary(scope);
      });
      host.addEventListener('keydown',event=>{
        const trigger=event.target.closest('[data-action="ai-tools"]');
        if(trigger&&['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();openAITools(trigger,event.key==='ArrowUp');return;}
        if(!aiToolsMenu)return;
        if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeAITools(true);return;}
        if(!aiToolsMenu.contains(event.target))return;
        const items=[...aiToolsMenu.querySelectorAll('button:not(:disabled)')],position=items.indexOf(document.activeElement);
        if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){
          event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?items.length-1:(position+(event.key==='ArrowUp'?-1:1)+items.length)%items.length;
          items[next]?.focus({preventScroll:true});
        }
        if(event.key==='Tab')closeAITools(true);
      });
      document.addEventListener('pointerdown',event=>{if(aiToolsMenu&&!aiToolsMenu.contains(event.target)&&!aiToolsTrigger?.contains(event.target))closeAITools();},true);
      document.addEventListener('focusin',event=>{if(aiToolsMenu&&!aiToolsMenu.contains(event.target)&&event.target!==aiToolsTrigger)closeAITools();});
      window.addEventListener('blur',()=>{if(aiToolsMenu)requestAnimationFrame(()=>{if(document.activeElement?.tagName==='IFRAME')closeAITools();});});
      window.addEventListener('resize',positionAITools);window.visualViewport?.addEventListener('resize',positionAITools);
      const modelCatalog={items:null,key:null,loadedAt:0,busy:false,error:'',job:null};
      function paintModelCatalog(){
        if(state.view!=='settings')return;const select=main.querySelector('[data-gemini-model]');if(!select)return;
        const t=window.BetterNotebookI18n.t,key=state.geminiKey.trim(),matching=modelCatalog.key===key,items=matching?modelCatalog.items:null;
        const hasKey=!!key||boot.settings.keyConfigured;const available=items?.some(m=>m.id===state.geminiModel);
        let status=modelCatalog.busy?t(__bnT('Modeller alınıyor…')):!hasKey?t(__bnT('Modelleri görmek için API anahtarı ekle.')):modelCatalog.error?t(modelCatalog.error):!matching?t(__bnT('API anahtarı değişti. Listeyi yenile.')):!available?t(__bnT('Kaydedilmiş model listede yok. Yeni bir model seç.')):t(__bnT('Model listesi Google’dan güncellendi.'));
        const placeholder=modelCatalog.busy?t(__bnT('Modeller alınıyor…')):t(__bnT('Bir model seç'));
        select.replaceChildren();if(!available){const first=new Option(placeholder,'',false,true);first.disabled=true;select.add(first);}
        for(const model of items||[])select.add(new Option(model.name,model.id,false,state.geminiModel===model.id));
        select.disabled=modelCatalog.busy||!items?.length;
        const picker=main.querySelector('[data-select-source="data-gemini-model"]');picker?.remove();select.hidden=false;upgradeSelects();
        const message=main.querySelector('[data-model-status]');if(message){message.textContent=status;message.setAttribute('role',modelCatalog.error?'alert':'status');}
        const refresh=main.querySelector('[data-action="refresh-models"]');if(refresh){refresh.disabled=modelCatalog.busy||!hasKey;refresh.setAttribute('aria-busy',String(modelCatalog.busy));}
        const save=main.querySelector('[data-action="save-gemini"]');if(save)save.disabled=modelCatalog.busy;icons();
      }
      async function loadModelCatalog(force=false){
        if(modelCatalog.busy)return modelCatalog.job;
        const key=state.geminiKey.trim();if(!key&&!boot.settings.keyConfigured){modelCatalog.items=null;modelCatalog.key=null;paintModelCatalog();return;}
        if(!force&&modelCatalog.key===key&&modelCatalog.items&&Date.now()-modelCatalog.loadedAt<300000){paintModelCatalog();return;}
        modelCatalog.busy=true;modelCatalog.error='';paintModelCatalog();
        const job=(async()=>{try{const result=await api('models',{key});if(!Array.isArray(result.models)||!result.models.length)throw new Error(__bnT('Bu bağlantıda uygun metin modeli bulunamadı.'));
          modelCatalog.items=result.models;modelCatalog.key=key;modelCatalog.loadedAt=Date.now();
        }catch(error){modelCatalog.items=null;modelCatalog.key=key;modelCatalog.error=error.message;}
        finally{modelCatalog.busy=false;modelCatalog.job=null;paintModelCatalog();}})();modelCatalog.job=job;return job;
      }
      function initModelCatalog(){loadModelCatalog();}
      async function validateModelChoice(){
        await loadModelCatalog();
        if(modelCatalog.error||!modelCatalog.items?.some(m=>m.id===state.geminiModel)||modelCatalog.key!==state.geminiKey.trim()){
          geminiFeedback(window.BetterNotebookI18n.t(modelCatalog.error||__bnT('Listeden kullanılabilir bir model seç.')),true);return false;
        }return true;
      }
      function modelCatalogSaved(){modelCatalog.key='';}
      host.addEventListener('click',event=>{if(event.target.closest('[data-action="refresh-models"]'))loadModelCatalog(true);});
      host.addEventListener('change',event=>{if(event.target.matches('[data-gemini-model],[data-gemini-key]'))paintModelCatalog();});
      function noteDescription(n){return n.summaryPlaceholder?window.BetterNotebookI18n.t(n.summary):n.summary;}
      function formatEdited(note){if(!note.updatedAt)return window.BetterNotebookI18n.t(note.edited||'');const date=new Date(note.updatedAt.replace(' ','T'));if(Number.isNaN(date.getTime()))return note.edited;return date.toLocaleString(window.BetterNotebookI18n.locale,{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});}
      function languageSettings(){const language=window.BetterNotebookI18n.language;main.querySelector('.bn-heading')?.insertAdjacentHTML('afterend',__bnHTML`<section class="bn-language-settings" aria-label="Arayüz dili"><div><h3>Dil</h3><p class="bn-subtitle">Dil tercihin hesabına kaydedilir.</p></div><fieldset aria-label="Arayüz dili"><label><input type="radio" name="bn-language" data-language value="tr" ${language==='tr'?'checked':''}><span>Türkçe</span></label><label><input type="radio" name="bn-language" data-language value="en" ${language==='en'?'checked':''}><span>English</span></label></fieldset></section>`);}
      function updateLanguageChrome(){const t=window.BetterNotebookI18n.t;const labels={today:__bnT('Bugün'),notes:__bnT('Not defterim'),files:__bnT('Dosyalar'),timer:__bnT('Odaklan'),settings:__bnT('Ayarlar')};for(const item of nav)item[1]=t(labels[item[0]]);for(const button of host.querySelectorAll('.bn-global-controls [data-view]')){const label=t(labels[button.dataset.view]);button.setAttribute('aria-label',label);button.setAttribute('data-tooltip',label);}host.querySelector('.bn-glass-nav').setAttribute('aria-label',t(__bnT('Ana gezinme')));host.querySelector('.bn-glass-utilities').setAttribute('aria-label',t(__bnT('Hesap ve ayarlar')));const profile=host.querySelector('[data-action="profile"]');profile.setAttribute('aria-label',t(__bnT('Profil')));profile.setAttribute('data-tooltip',t(__bnT('Profil')));const menu=host.querySelector('.bn-profile-menu');menu.setAttribute('aria-label',t(__bnT('Profil bilgileri')));menu.querySelector('[data-mode-badge]').textContent=t(__bnT('Kişisel çalışma alanı'));menu.querySelector('[data-action="close-profile"]').setAttribute('aria-label',t(__bnT('Profili kapat')));const logout=menu.querySelector('[href="logout"]');if(logout)logout.textContent=t(__bnT('Çıkış yap'));studyPhases.focus=t(__bnT('Odaklan'));studyPhases.short=t(__bnT('Kısa mola'));studyPhases.long=t(__bnT('Uzun mola'));paneNames[1]=window.BetterNotebookI18n.language==='en'?'Note':'Not';}
      host.addEventListener('change',async event=>{const input=event.target;if(!input.matches('[data-language]'))return;const value=input.value,old=window.BetterNotebookI18n.language;if(value===old)return;const inputs=[...main.querySelectorAll('[data-language]')];inputs.forEach(el=>el.disabled=true);try{await api('language',{language:value});boot.language=value;window.BetterNotebookI18n.setLanguage(value);updateLanguageChrome();render();notify(window.BetterNotebookI18n.t(__bnT('Dil tercihi kaydedildi.')));}catch(error){inputs.forEach(el=>{el.checked=el.value===old;});notify(window.BetterNotebookI18n.t(error.message));}finally{inputs.forEach(el=>el.disabled=false);}});

      const trashCopy = {
        title: ['Çöp kutusu', 'Trash'],
        remove: ['Sil', 'Delete'],
        restore: ['Geri yükle', 'Restore'],
        cancel: ['Vazgeç', 'Cancel'],
        loading: ['Çöp kutusu yükleniyor…', 'Loading trash…'],
        empty: ['Çöp kutusu boş.', 'Trash is empty.'],
        retention: ['Öğeleri 30 gün içinde geri yükleyebilirsin.', 'You can restore items for 30 days.'],
        expires: ['Son gün', 'Restore by'],
        confirm: ['Çöp kutusuna taşı?', 'Move to trash?'],
        moved: ['Çöp kutusuna taşındı.', 'Moved to trash.'],
        restored: ['Öğe geri yüklendi.', 'Item restored.'],
        emptyButton: ['Çöp kutusunu boşalt', 'Empty trash'],
        emptyConfirm: ['Çöp kutusu boşaltılsın mı?', 'Empty the trash?'],
        emptyWarning: ['Bu işlem geri alınamaz. Silinen dosyalar bağlı notlardan da ayrılır.', 'This cannot be undone. Deleted files are also detached from linked notes.'],
        emptyDone: ['Çöp kutusu boşaltıldı.', 'Trash emptied.'],
        note: ['Not', 'Note'],
        file: ['Dosya', 'File'],
        course: ['Ders', 'Course'],
        fileHelp: ['Dosya kütüphaneden kaldırılır. 30 gün dolduğunda bağlı notlardan da ayrılır. Not metinleri korunur.', 'The file leaves the library. After 30 days it is also detached from linked notes. Note text is kept.'],
        courseHelp: ['Ders filtrelerden kaldırılır. Bu derse bağlı notlar korunur.', 'The course is hidden from filters. Its notes are kept.'],
      };

      function trashText(key) {
        return trashCopy[key][window.BetterNotebookI18n.language === 'en' ? 1 : 0];
      }

      function trashButton(kind, id, name, extraClass = '') {
        return `<button type="button" class="bn-icon-btn bn-trash-trigger ${extraClass}"
          data-trash-kind="${kind}" data-trash-id="${Number(id)}" data-trash-name="${esc(name)}"
          aria-label="${esc(trashText('remove') + ': ' + name)}" title="${trashText('remove')}">${ico('trash-2')}</button>`;
      }

      function trashOpenButton() {
        return `<button type="button" class="bn-btn" data-open-trash>${ico('trash-2')}${trashText('title')}</button>`;
      }

      async function refreshAfterTrash() {
        const current = await api('bootstrap');
        const priorNotes = new Map(state.notes.map(note => [note.id, note]));
        state.notes = current.notes.map(note => {
          const prior = priorNotes.get(note.id);
          return prior ? Object.assign(prior, {
            course: note.course, courseId: note.courseId, attachments: note.attachments,
          }) : note;
        });
        boot.courses = current.courses;
        state.courses = current.courses.map(course => course.name);
        state.recentNoteIds = current.recentNoteIds;
        state.lastWorkedNoteId = current.recentNoteIds[0] || null;
        if (!['all', 'unassigned', ...state.courses].includes(state.filter)) state.filter = 'all';
        if (state.mediaNote !== 'all' && state.mediaNote !== 'unassigned'
          && !state.notes.some(note => String(note.id) === state.mediaNote)) state.mediaNote = 'all';
        if (mediaStore.promise) await mediaStore.promise.catch(() => {});
        mediaStore.items = null;
        mediaStore.error = '';
        render();
      }

      async function loadTrash(dialog) {
        const list = dialog.querySelector('[data-trash-list]');
        const emptyButton = dialog.querySelector('[data-trash-empty]');
        emptyButton.disabled = true;
        list.textContent = trashText('loading');
        try {
          const {items, total} = await api('trash_list');
          if (!dialog.open) return;
          dialog.dataset.trashTotal = String(total);
          emptyButton.disabled = !total;
          if (!items.length) {
            list.textContent = trashText('empty');
            return;
          }
          list.innerHTML = items.map(item => {
            const expiry = new Date(Number(item.expiresAt) * 1000)
              .toLocaleDateString(window.BetterNotebookI18n.locale, {dateStyle: 'medium'});
            return `<div class="bn-trash-row"><div><strong>${esc(item.name)}</strong>
              <span class="bn-mini">${trashText(item.kind)} · ${trashText('expires')} ${esc(expiry)}</span></div>
              <button type="button" class="bn-btn" data-trash-restore="${Number(item.id)}"
                data-kind="${item.kind}">${ico('rotate-ccw')}${trashText('restore')}</button></div>`;
          }).join('');
          icons();
        } catch (error) {
          list.textContent = error.message;
        }
      }

      function openTrash() {
        const dialog = flowDialog(trashText('title'), `<p class="bn-mini">${trashText('retention')}</p>
          <div class="bn-trash-list" data-trash-list role="region" aria-label="${trashText('title')}"></div>
          <p data-trash-feedback class="bn-mini" role="status"></p>
          <div class="bn-row bn-trash-actions"><button type="button" class="bn-btn" data-trash-empty disabled>
            ${ico('trash-2')}${trashText('emptyButton')}</button></div>`);
        dialog.addEventListener('click', async event => {
          if (event.target.closest('[data-trash-empty]') && !dialog.dataset.busy) {
            confirmEmptyTrash(dialog);
            return;
          }
          const button = event.target.closest('[data-trash-restore]');
          if (!button || dialog.dataset.busy) return;
          dialog.dataset.busy = '1';
          button.disabled = true;
          const feedback = dialog.querySelector('[data-trash-feedback]');
          try {
            await api('trash_restore', {kind: button.dataset.kind, id: Number(button.dataset.trashRestore)});
            await refreshAfterTrash();
            await loadTrash(dialog);
            feedback.textContent = trashText('restored');
          } catch (error) {
            feedback.textContent = error.message;
          } finally {
            delete dialog.dataset.busy;
            button.disabled = false;
          }
        });
        loadTrash(dialog);
      }

      function confirmEmptyTrash(trashDialog) {
        const count = Number(trashDialog.dataset.trashTotal);
        if (!count) return;
        const formatted = new Intl.NumberFormat(window.BetterNotebookI18n.locale).format(count);
        const label = window.BetterNotebookI18n.language === 'en'
          ? `${formatted} ${count === 1 ? 'item' : 'items'}` : `${formatted} öğe`;
        const dialog = flowDialog(trashText('emptyConfirm'), `<p class="bn-trash-name">${esc(label)}</p>
          <p class="bn-mini">${trashText('emptyWarning')}</p>
          <p class="bn-mini" data-trash-feedback role="status"></p>
          <div class="bn-row bn-trash-actions">
            <button type="button" class="bn-btn" data-action="close-flow" autofocus>${trashText('cancel')}</button>
            <button type="button" class="bn-btn bn-btn-primary" data-trash-empty-confirm>
              ${ico('trash-2')}${trashText('emptyButton')}</button>
          </div>`);
        const button = dialog.querySelector('[data-trash-empty-confirm]');
        button.addEventListener('click', async () => {
          button.disabled = true;
          const feedback = trashDialog.querySelector('[data-trash-feedback]');
          try {
            await api('trash_empty', {confirm: 'empty-trash'});
            dialog.close();
            await loadTrash(trashDialog);
            await refreshAfterTrash();
            feedback.textContent = trashText('emptyDone');
          } catch (error) {
            const target = dialog.open ? dialog.querySelector('[data-trash-feedback]') : feedback;
            target.textContent = error.message;
          } finally {
            button.disabled = false;
          }
        });
      }

      function confirmTrash({trashKind, trashId, trashName}) {
        const extra = trashKind === 'file' ? trashText('fileHelp') : trashKind === 'course' ? trashText('courseHelp') : '';
        const dialog = flowDialog(trashText('confirm'), `<p class="bn-trash-name">${esc(trashName)}</p>
          <p class="bn-mini">${trashText('retention')}</p>
          ${extra ? `<p class="bn-mini">${extra}</p>` : ''}
          <p class="bn-mini" data-trash-feedback role="status"></p>
          <div class="bn-row bn-trash-actions">
            <button type="button" class="bn-btn" data-action="close-flow">${trashText('cancel')}</button>
            <button type="button" class="bn-btn bn-btn-primary" data-trash-confirm>${ico('trash-2')}${trashText('remove')}</button>
          </div>`);
        const button = dialog.querySelector('[data-trash-confirm]');
        button.addEventListener('click', async () => {
          button.disabled = true;
          try {
            await api('trash_move', {kind: trashKind, id: Number(trashId)});
            dialog.close();
            await refreshAfterTrash();
            notify(trashText('moved'));
          } catch (error) {
            if (dialog.open) dialog.querySelector('[data-trash-feedback]').textContent = error.message;
            else notify(error.message);
          } finally {
            button.disabled = false;
          }
        });
      }

      host.addEventListener('click', event => {
        const button = event.target.closest('[data-open-trash], [data-trash-kind]');
        if (!button) return;
        if (button.hasAttribute('data-open-trash')) openTrash();
        else confirmTrash(button.dataset);
      });

      function courseTrashButton(name) {
        const course = boot.courses.find(item => item.name === name);
        return course ? trashButton('course', course.id, name) : '';
      }
      const originalRender=render;
      state.render=render;state.paintStudy=paintStudy;state.notify=notify;render();
      host.querySelectorAll('.bn-live-markdown').forEach(el=>{el.dataset.mathReady='1';renderMath(el);});
      const observer=new MutationObserver(()=>{host.querySelectorAll('.bn-live-markdown:not([data-math-ready])').forEach(el=>{el.dataset.mathReady='1';renderMath(el);});});observer.observe(main,{childList:true,subtree:true});
      host.querySelector('.bn-avatar').textContent=boot.user.name.slice(0,1).toLocaleUpperCase('tr');host.querySelector('.bn-profile-menu h3').textContent=boot.user.name;
      host.querySelector('.bn-profile-menu').insertAdjacentHTML('beforeend',__bnT('<a class="bn-btn" href="logout">Çıkış yap</a>'));
      updateLanguageChrome();render();

    });
    setInterval(()=>{const finished=syncStudy();for(const state of states){if(finished&&state.view==='timer'){state.render();state.notify(study.completedPhase==='focus'?__bnT('Çalışma oturumu kaydedildi. Mola verebilirsin.'):__bnT('Mola tamamlandı.'));}else state.paintStudy?.();}},1000);
  })();
