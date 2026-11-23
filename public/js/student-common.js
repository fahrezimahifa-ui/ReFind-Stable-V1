window.StudentUI=(()=>{
  const app=window.ReFind;
  const statusNames={OPEN:'Reported',UNDER_REVIEW:'Under review',POTENTIAL_MATCH:'Possible match',VERIFICATION:'Claim review',HANDOVER:'Ready for pickup',RESOLVED:'Returned',UNCLAIMED_CLOSED:'Never retrieved'};
  const claimNames={PENDING:'Pending review',NEED_MORE_INFO:'More info requested',APPROVED:'Approved',REJECTED:'Rejected',WITHDRAWN:'Withdrawn'};
  const pickupNames={PENDING:'Awaiting scheduling',SCHEDULED:'Scheduled',COMPLETED:'Returned'};
  const stateKinds={OPEN:'neutral',UNDER_REVIEW:'pending',POTENTIAL_MATCH:'possible',VERIFICATION:'pending',HANDOVER:'pickup',RESOLVED:'done',UNCLAIMED_CLOSED:'neutral',PENDING:'pending',NEED_MORE_INFO:'attention',APPROVED:'done',REJECTED:'rejected',WITHDRAWN:'neutral',SCHEDULED:'pending',COMPLETED:'done'};
  const itemUrl=id=>app.base+'item.html?id='+encodeURIComponent(id);
  const claimUrl=id=>app.base+'claim.html?id='+encodeURIComponent(id);
  // Round 4: a claim's own detail page lives in My Activity (no tab), so no server route is needed.
  const claimDetailUrl=id=>app.base+'my-reports.html?claim='+encodeURIComponent(id);
  const navAction=(label,href)=>$('<a>').addClass('btn btn-outline navigation-action').attr('href',href).text(label);
  const urlParams=()=>new URLSearchParams(location.search);
  function date(value,time=false){if(!value)return 'Not specified';const d=/^\d{4}-\d{2}-\d{2}$/.test(value)?new Date(value+'T00:00:00+07:00'):new Date(value);return new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Jakarta',day:'numeric',month:'short',year:'numeric',...(time?{hour:'2-digit',minute:'2-digit'}:{})}).format(d)+(time?' WIB':'');}
  function eventDate(item){return date(item.event_date)+(item.event_time?' · '+item.event_time.slice(0,5)+' WIB':' · Time not specified');}
  function locationText(item){return [item.location_name||'Location not specified',item.location_detail||item.floor_name||''].filter(Boolean).join(' · ');}
  function chip(label,kind=''){return $('<span>').addClass('item-chip '+kind).text(label);}
  function stateChip(code,label){return chip(label||claimNames[code]||statusNames[code]||pickupNames[code]||code,'state '+(stateKinds[code]||'neutral')).attr('data-state',code);}
  function titleParts(title){const match=/^Demo B06 · (C[0-9]{2}) (.+) \((lost|found)\)$/.exec(title||'');if(match)return{display:match[2][0].toUpperCase()+match[2].slice(1),demo:'Classroom example · '+match[1]};if((title||'').startsWith('Demo · '))return{display:title.slice(7),demo:'Classroom example'};return{display:title||'',demo:null};}
  function demoChip(title){const parts=titleParts(title);return parts.demo?chip(parts.demo,'demo'):null;}
  // One coloured chip per report: its status. Lost/Found is said in words where the report is described.
  function chips(item){const box=$('<div>').addClass('chips').append(stateChip(item.status_code,statusNames[item.status_code]||item.status_name));const demo=demoChip(item.title);if(demo)box.append(demo);return box;}
  // Card time line: "Found 20 Nov 2026, 10:15 WIB" (no time when it was not recorded); the type word leads.
  function eventLine(item){return $('<p>').addClass('card-meta card-when').append($('<span>').addClass('card-type').text(item.item_type==='FOUND'?'Found':'Lost'),' '+date(item.event_date)+(item.event_time?', '+item.event_time.slice(0,5)+' WIB':''));}
  // One link per card: the title link (a.card-details) stretches over the whole card in polish.css, so the
  // photo is decorative and keyboard users get a single, descriptive tab stop.
  // Small crossed-frame icon for reports without a public photo (decorative; the text says it).
  const noPhotoIcon=()=>$('<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M4 5l16 14"/></svg>');
  const noPhoto=()=>$('<span>').addClass('no-photo').append(noPhotoIcon(),$('<span>').text('No photo'));
  // Photos fade in once decoded instead of popping in; a failed photo falls back to the no-photo strip.
  function fadeImage(img,onError){img.addClass('is-loading').one('load',()=>img.removeClass('is-loading')).one('error',()=>{img.removeClass('is-loading');if(onError)onError();});return img;}
  function card(item,options={}){const display=titleParts(item.title).display,article=$('<article>').addClass('discovery-card').attr('data-item-id',item.item_id);const media=$('<div>').addClass('card-media');if(item.photos?.length){const img=$('<img>').attr({alt:'',loading:'lazy',decoding:'async',width:480,height:360});fadeImage(img,()=>img.replaceWith(noPhoto())).attr('src',thumbUrl(item.photos[0]));media.append(img);}else media.append(noPhoto());if(item.match_score!==undefined&&options.showScore!==false)media.append($('<span>').addClass('similarity-label').text('Similarity '+Number(item.match_score)+'/100'));const title=$('<h2>').attr('title',item.title).append($('<a>').addClass('card-details').attr('href',itemUrl(item.item_id)).text(display));const body=$('<div>').addClass('discovery-body').append(chips(item),title,$('<p>').addClass('card-meta card-where').text(locationText(item)),eventLine(item));return article.append(media,body);}
  // Card-sized copy of an item photo (api/photos.php?w=480); other photo URLs are returned unchanged.
  function thumbUrl(url){return /api\/photos\.php\?kind=item&id=\d+$/.test(url||'')?url+'&w=480':url;}
  function skeleton(count){return Array.from({length:count},()=>$('<div>').addClass('discovery-card skeleton-card').attr('aria-hidden','true').append($('<div>').addClass('card-media'),$('<div>').addClass('discovery-body').append($('<span>').addClass('skeleton-line chip'),$('<span>').addClass('skeleton-line w-90'),$('<span>').addClass('skeleton-line w-70'),$('<span>').addClass('skeleton-line w-40'))));}
  // Count text with proper singular/plural ("1 report", "12 reports").
  function plural(count,noun,pluralNoun=noun+'s'){return count+' '+(Number(count)===1?noun:pluralNoun);}
  function focusRecord(element){if(!element)return;const header=document.querySelector('.app-header');element.style.scrollMarginTop=((header?.getBoundingClientRect().height||70)+16)+'px';if(!element.matches('button,input,select,textarea,a[href],summary'))element.setAttribute('tabindex','-1');element.focus({preventScroll:true});element.scrollIntoView({behavior:'instant',block:'start'});}
  function revealField(selector){const element=document.querySelector(selector);if(!element)return;$(element).parents('details').prop('open',true);element.focus();element.style.scrollMarginTop='100px';element.scrollIntoView({behavior:'instant',block:'center'});}
  function pickupSummary(snapshot,at){if(snapshot.status==='COMPLETED')return 'Returned'+(at?' · '+date(at,true):'')+(snapshot.location?' · '+snapshot.location:'');if(snapshot.status==='RELEASED')return 'Pickup released · the item is available again';return(snapshot.status==='SCHEDULED'?'Scheduled · '+date(snapshot.scheduled_at,true):'Awaiting scheduling')+(snapshot.location?' · '+snapshot.location:'');}
  function pickupMessage(event,at){const op=event.operation;return({schedule:'Pickup scheduled',reschedule:'Pickup appointment changed',cancel_schedule:'Appointment cancelled',complete:'Item return confirmed',release:'Pickup released'}[op]||'Pickup updated')+' by '+event.actor+'.'+(op==='cancel_schedule'?' Ownership stays approved.':'')+'\n'+pickupSummary(event.after,at)+(event.reason?'\n'+(['reschedule','cancel_schedule','release'].includes(op)?'Reason: ':'Note: ')+event.reason:'');}
  function empty(title,detail,href,label){const box=$('<div>').addClass('empty-state').append($('<h2>').text(title),$('<p>').text(detail));if(href)box.append($('<a>').addClass('btn btn-primary').attr('href',href).text(label));return box;}
  // Help/contact details for Building Management. The office location is labelled so it is never read as a
  // pickup place or as where an item is stored; a scheduled pickup area is shown separately.
  // Each line keeps its "Label: value" text; the label is wrapped so it reads before the value.
  function deskCard(desk){if(!desk)return null;const line=(label,value)=>$('<p>').append($('<span>').addClass('desk-label').text(label+':'),' ',value);return $('<div>').addClass('desk-card').append($('<strong>').text(desk.name),line('Office',desk.location),line('Hours',desk.hours),line('Contact',$('<span>').addClass('desk-contact').text(desk.contact)));}
  // Display-only: a scheduled appointment whose time has passed. Workflow state is never changed here.
  function pickupPastDue(pickup){return pickup?.handover_status==='SCHEDULED'&&!!pickup.scheduled_at&&new Date(pickup.scheduled_at)<new Date();}
  // One-shot success message that survives a redirect; sessionStorage keeps it to this tab.
  function flash(message){try{sessionStorage.setItem('refind-flash',message);}catch{}}
  function notify(message){$('#shell-feedback').addClass('success').attr('role','status').text(message).prop('hidden',false);}
  function showFlash(){let message=null;try{message=sessionStorage.getItem('refind-flash');sessionStorage.removeItem('refind-flash');}catch{}if(message)notify(message);}
  // Return navigation: a list page remembers, in this tab only, its exact URL, scroll position and the record that was
  // opened, so a detail page's Back link and the browser Back button return to the same place in the same list.
  const RETURN_KEY='refind-return',KEEP_FOR=7200000,RECORD_ATTRS=['data-item-id','data-claim-id','data-record-id'];
  const here=()=>location.pathname+location.search;
  function readReturn(){try{const v=JSON.parse(sessionStorage.getItem(RETURN_KEY)||'null');return v&&typeof v.url==='string'&&v.url.startsWith(app.base)&&Date.now()-v.at<KEEP_FOR?v:null;}catch{return null;}}
  function writeReturn(value){try{sessionStorage.setItem(RETURN_KEY,JSON.stringify(value));}catch{}}
  // options.selector limits which links record the return point; options.onLeave=false skips saving on pagehide, so a
  // detail page can name itself for the pages it links to without replacing the list's own return point on browser Back.
  function trackReturn(key,label,options={}){
    try{history.scrollRestoration='manual';}catch{}
    let opened=null;
    const save=()=>writeReturn({key,label,url:here(),y:Math.round(scrollY),opened,at:Date.now(),restore:false});
    $(document).on('click.return',options.selector||'a[href]',function(){const record=this.closest(RECORD_ATTRS.map(a=>'['+a+']').join(','));const attr=record&&RECORD_ATTRS.find(a=>record.hasAttribute(a));opened=attr?{attr,id:record.getAttribute(attr)}:null;save();});
    if(options.onLeave!==false)addEventListener('pagehide',save);
  }
  function returnTarget(){return readReturn();}
  function markReturn(){const value=readReturn();if(value)writeReturn({...value,restore:true});}
  // Restores only when arriving through a Back link, the browser Back button or a reload; a fresh visit starts at the top.
  function restoreReturn(key){
    const value=readReturn();if(!value||value.key!==key||value.url!==here())return false;
    const navigation=performance.getEntriesByType?.('navigation')?.[0]?.type;
    if(!value.restore&&navigation!=='back_forward'&&navigation!=='reload')return false;
    writeReturn({...value,restore:false});
    requestAnimationFrame(()=>{window.scrollTo({top:value.y,behavior:'instant'});const record=value.opened&&[...document.querySelectorAll('['+value.opened.attr+']')].find(el=>el.getAttribute(value.opened.attr)===String(value.opened.id));const target=record&&(record.matches('a[href],button')?record:record.querySelector('a[href],button'));if(target)target.focus({preventScroll:true});});
    return true;
  }
  // Unsaved changes: warn before leaving a changed form. release() stops warning just before a deliberate redirect.
  const guards=[];
  function guardUnsaved(form,extra=()=>''){
    const element=$(form)[0];if(!element)return {reset(){},release(){}};
    const snapshot=()=>JSON.stringify([...element.querySelectorAll('input,select,textarea')].filter(f=>f.id&&f.type!=='file').map(f=>['checkbox','radio'].includes(f.type)?f.checked:f.value).concat(String(extra())));
    let saved=snapshot(),active=true;
    const guard={reset(){saved=snapshot();},release(){active=false;}};
    addEventListener('beforeunload',event=>{if(active&&snapshot()!==saved){event.preventDefault();event.returnValue='';}});
    guards.push(guard);return guard;
  }
  // Session expiry: keep the typed text (never files or passwords) in this tab, then put it back once after signing in.
  let beforeExpire=null;
  function onSessionExpired(fn){beforeExpire=fn;}
  function expired(){try{beforeExpire?.();}catch{}guards.forEach(g=>g.release());location.replace(app.loginUrl());}
  const draftKey=name=>'refind-draft:'+name+':'+here();
  function saveDraft(name,form,extra={}){const fields={};$(form).find('input,select,textarea').each(function(){if(!this.id||['file','password','hidden'].includes(this.type))return;fields[this.id]=['checkbox','radio'].includes(this.type)?this.checked:this.value;});try{sessionStorage.setItem(draftKey(name),JSON.stringify({at:Date.now(),fields,extra}));}catch{}}
  function takeDraft(name){const key=draftKey(name);let draft=null;try{draft=JSON.parse(sessionStorage.getItem(key)||'null');sessionStorage.removeItem(key);}catch{}return draft&&draft.fields&&Date.now()-draft.at<KEEP_FOR?draft:null;}
  function applyDraft(draft){for(const [id,value] of Object.entries(draft.fields)){const field=document.getElementById(id);if(!field||field.type==='file')continue;if(typeof value==='boolean')field.checked=value;else field.value=value;field.dispatchEvent(new Event('change',{bubbles:true}));}}
  function showError(e,selector='#page-feedback'){if(e.status===401){expired();return;}$(selector).removeClass('success').attr('role','alert').text(e.message||String(e)).prop('hidden',false);}
  async function formError(e,map){if(e.status===401){expired();return;}if(e.code==='csrf_invalid'){const state=await app.session().catch(()=>null);if(state&&!state.authenticated){expired();return;}}for(const [key,message]of Object.entries(e.fields||{})){ $('[data-error="'+key+'"]').text(message).prop('hidden',false);if(map[key]){$(map[key]).attr('aria-invalid','true');linkError(map[key],key);}}showError(e,'#form-feedback');const key=Object.keys(e.fields||{}).find(k=>map[k]);if(key)revealField(map[key]);else $('#form-feedback').trigger('focus');}
  // Screen readers announce an inline error with its field once the two are linked.
  function linkError(selector,key){const span=$('[data-error="'+key+'"]').first();if(!span.length)return;const id=span.attr('id')||'error-'+key.replace(/[^a-z0-9_-]/gi,'');span.attr('id',id);const field=$(selector),ids=(field.attr('aria-describedby')||'').split(/\s+/).filter(Boolean);if(!ids.includes(id))field.attr('aria-describedby',[...ids,id].join(' '));}
  function clearErrors(){ $('.field-error').prop('hidden',true).text('');$('input,select,textarea').attr('aria-invalid','false');$('#form-feedback,#page-feedback').prop('hidden',true);}
  function required(map){let first=null;for(const [key,selector]of Object.entries(map)){if(!String($(selector).val()||'').trim()){ $(selector).attr('aria-invalid','true');$('[data-error="'+key+'"]').text('This field is required.').prop('hidden',false);linkError(selector,key);first||=selector;}}if(first)revealField(first);return !first;}
  // After a page change, move the reader to the top of the new results instead of leaving them at the pager.
  function pager(data,load,focusSelector){const box=$('#pager').empty();if(data.pages<=1)return;const go=async next=>{await load(next);const target=focusSelector&&document.querySelector(focusSelector);if(target)focusRecord(target);};box.append($('<button>').addClass('btn btn-outline').attr('type','button').prop('disabled',data.page<=1).text('Previous').on('click',()=>go(data.page-1)),$('<span>').text('Page '+data.page+' of '+data.pages),$('<button>').addClass('btn btn-outline').attr('type','button').prop('disabled',data.page>=data.pages).text('Next').on('click',()=>go(data.page+1)));}
  function fillSelect(selector,values,label,idKey='id',nameKey='name'){const select=$(selector).empty().append($('<option>').val('').text(label));values.forEach(value=>select.append($('<option>').val(value[idKey]).text(value[nameKey])));}
  function campusDay(value=new Date()){const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date(value));return ['year','month','day'].map(k=>parts.find(p=>p.type===k).value).join('-');}
  // Round 4 (owner): report, activity, BM queue and notification cards float and tilt toward the mouse like 3D cards.
  // Only for a fine pointer that can hover and never with reduced motion. This only sets CSS variables (at most 6deg);
  // polish.css draws it with transform, so the layout never moves. Touch and keyboard keep the static card.
  (()=>{
    const selector='.discovery-card,.activity-card,.bm-queue-card,.notification-card',fine=matchMedia('(hover:hover) and (pointer:fine)'),calm=matchMedia('(prefers-reduced-motion: reduce)');
    let card=null,point=null,frame=0;
    const clear=()=>{if(card){card.classList.remove('is-tilting');for(const name of ['--rx','--ry','--mx','--my'])card.style.removeProperty(name);}card=null;point=null;};
    const draw=()=>{frame=0;if(!card||!point)return;const box=card.getBoundingClientRect();if(!box.width||!box.height)return;
      const x=Math.min(1,Math.max(0,(point.x-box.left)/box.width)),y=Math.min(1,Math.max(0,(point.y-box.top)/box.height));
      card.style.setProperty('--ry',((x-.5)*12).toFixed(2)+'deg');card.style.setProperty('--rx',((.5-y)*12).toFixed(2)+'deg');
      card.style.setProperty('--mx',(x*100).toFixed(1)+'%');card.style.setProperty('--my',(y*100).toFixed(1)+'%');card.classList.add('is-tilting');};
    document.addEventListener('pointermove',event=>{
      if(event.pointerType!=='mouse'||!fine.matches||calm.matches){if(card)clear();return;}
      const next=event.target instanceof Element?event.target.closest(selector):null;if(next!==card){clear();card=next;}
      if(card){point={x:event.clientX,y:event.clientY};if(!frame)frame=requestAnimationFrame(draw);}
    },{passive:true});
    document.addEventListener('pointerout',event=>{if(card&&!(event.relatedTarget instanceof Node&&card.contains(event.relatedTarget)))clear();},{passive:true});
    addEventListener('blur',clear);
  })();
  return {fadeImage,noPhoto,navAction,statusNames,claimNames,pickupNames,stateKinds,stateChip,titleParts,demoChip,focusRecord,revealField,pickupSummary,pickupMessage,deskCard,pickupPastDue,flash,notify,showFlash,itemUrl,claimUrl,claimDetailUrl,urlParams,date,eventDate,locationText,chip,chips,card,thumbUrl,skeleton,plural,empty,showError,formError,clearErrors,required,pager,fillSelect,campusDay,trackReturn,returnTarget,markReturn,restoreReturn,guardUnsaved,onSessionExpired,saveDraft,takeDraft,applyDraft};
})();
