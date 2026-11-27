// Laravel-only (Stable round 5, photo search): a Lens-style "search by photo" control shared by Explore (Students) and
// the BM Reports tab. A camera button inside the search field opens the picker (camera or gallery on phones), a crop
// screen with the box preset to the whole photo prepares each photo (cropped, at most 1200 px, JPEG on white, at most
// 250 KB, which also drops EXIF), up to 3 photos sit in a chip under the search row, and every change re-runs the
// search. Photos stay in this tab's memory only; the server compares them and discards them.
window.PhotoSearch=class{
  constructor({field,after,endpoint,fields=()=>({}),onStart=()=>{},onResults=()=>{},onError=()=>{},onClear=()=>{}}){
    Object.assign(this,{endpoint,fields,onStart,onResults,onError,onClear});
    this.files=[];this.sequence=0;this.busy=false;
    const camera='<svg class="ui-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 8.5A2.5 2.5 0 0 1 6.5 6h1.6l1.2-1.8c.3-.4.7-.7 1.2-.7h3c.5 0 .9.3 1.2.7L15.9 6h1.6A2.5 2.5 0 0 1 20 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><circle cx="12" cy="12.5" r="3.25" stroke="currentColor" stroke-width="1.75"/></svg>';
    this.field=$(field).addClass('photo-search-field');
    this.button=$('<button>').attr({type:'button','aria-label':'Search by photo',title:'Search by photo'}).addClass('photo-search-camera').html(camera).appendTo(this.field);
    this.input=$('<input>').attr({type:'file',accept:'image/jpeg,image/png,image/webp','aria-hidden':'true',tabindex:'-1'}).addClass('visually-hidden photo-search-input').appendTo('body');
    this.chip=$('<div>').addClass('photo-search-chip').attr({role:'group','aria-label':'Photo search'}).prop('hidden',true).insertAfter(after);
    this.status=$('<p>').addClass('visually-hidden').attr({role:'status','aria-live':'polite'}).appendTo(this.chip);
    this.dialog=$(`<dialog class="avatar-dialog avatar-stage photo-crop" aria-labelledby="photo-crop-title">
      <div class="avatar-stage-bar"><button class="avatar-stage-icon" type="button" data-crop-close aria-label="Cancel photo search"><svg class="ui-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></button><h2 id="photo-crop-title">Crop to the item</h2></div>
      <div class="avatar-stage-area photo-crop-area"><div class="photo-crop-frame"><img class="photo-crop-image" alt="Photo to search with"><div class="photo-crop-box" tabindex="0" role="slider" aria-label="Crop area. Drag or use the arrow keys to move it; hold Shift with the arrow keys to resize." aria-valuetext="Whole photo"><span data-handle="nw"></span><span data-handle="ne"></span><span data-handle="sw"></span><span data-handle="se"></span></div></div></div>
      <div class="photo-crop-foot"><p class="photo-crop-tip">Drag the corners close around the item. Fewer surroundings give better matches.</p><p class="photo-crop-note">Your photo is sent to an AI service to compare it and isn't saved.</p><p class="photo-crop-error" role="alert" hidden></p>
      <div class="photo-crop-actions"><button class="avatar-stage-text" type="button" data-crop-whole>Use whole photo</button><button class="btn btn-primary" type="button" data-crop-search>Search</button></div></div></dialog>`).appendTo('body');
    this.box={x:0,y:0,w:1,h:1};
    this.button.on('click',()=>this.pick());
    this.input.on('change',()=>{const file=this.input[0].files[0];this.input.val('');if(file)this.crop(file);});
    this.bindCrop();
  }
  active(){return this.files.length>0;}
  pick(){if(this.busy)return;if(this.files.length>=3){this.say('You can search with up to 3 photos. Remove one to add another.');return;}this.input.trigger('click');}
  say(text){this.status.text('');setTimeout(()=>this.status.text(text),30);}
  // Crop screen ----------------------------------------------------------------------------------------------------
  async crop(file){
    const d=this.dialog,err=d.find('.photo-crop-error').prop('hidden',true);
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>20000000){this.say('Choose a JPEG, PNG or WebP photo under 20 MB.');return;}
    let bitmap;
    try{bitmap=await createImageBitmap(file,{imageOrientation:'from-image'});}catch{this.say('That photo could not be opened. Try another one.');return;}
    if(bitmap.width*bitmap.height>64000000){bitmap.close();this.say('That photo is too large. Try a smaller one.');return;}
    this.bitmap?.close?.();this.bitmap=bitmap;this.setBox({x:0,y:0,w:1,h:1});
    const url=URL.createObjectURL(file);d.find('.photo-crop-image').one('load',()=>URL.revokeObjectURL(url)).attr('src',url);
    err.text('');d[0].showModal();d.find('[data-crop-search]').trigger('focus');
  }
  setBox(b){
    const min=.08;b.w=Math.max(min,Math.min(1,b.w));b.h=Math.max(min,Math.min(1,b.h));b.x=Math.max(0,Math.min(1-b.w,b.x));b.y=Math.max(0,Math.min(1-b.h,b.y));
    this.box=b;const whole=b.x<.005&&b.y<.005&&b.w>.99&&b.h>.99;
    this.dialog.find('.photo-crop-box').css({left:b.x*100+'%',top:b.y*100+'%',width:b.w*100+'%',height:b.h*100+'%'}).attr('aria-valuetext',whole?'Whole photo':Math.round(b.w*100)+'% by '+Math.round(b.h*100)+'% of the photo');
  }
  bindCrop(){
    const d=this.dialog,frame=d.find('.photo-crop-frame'),boxEl=d.find('.photo-crop-box');let drag=null;
    boxEl.on('pointerdown',e=>{const r=frame[0].getBoundingClientRect();drag={handle:e.target.dataset.handle||'move',x:e.clientX,y:e.clientY,w:r.width,h:r.height,start:{...this.box}};boxEl[0].setPointerCapture(e.pointerId);e.preventDefault();});
    boxEl.on('pointermove',e=>{if(!drag)return;const dx=(e.clientX-drag.x)/drag.w,dy=(e.clientY-drag.y)/drag.h,s=drag.start,b={...s};
      if(drag.handle==='move'){b.x=s.x+dx;b.y=s.y+dy;}
      else{if(drag.handle.includes('w')){b.x=Math.min(s.x+dx,s.x+s.w-.08);b.w=s.w-(b.x-s.x);}if(drag.handle.includes('e'))b.w=Math.min(1-s.x,s.w+dx);if(drag.handle.includes('n')){b.y=Math.min(s.y+dy,s.y+s.h-.08);b.h=s.h-(b.y-s.y);}if(drag.handle.includes('s'))b.h=Math.min(1-s.y,s.h+dy);if(b.x<0){b.w+=b.x;b.x=0;}if(b.y<0){b.h+=b.y;b.y=0;}}
      this.setBox(b);});
    const end=()=>{drag=null;};boxEl.on('pointerup pointercancel lostpointercapture',end);
    boxEl.on('keydown',e=>{const step=.02,keys={ArrowLeft:[-step,0],ArrowRight:[step,0],ArrowUp:[0,-step],ArrowDown:[0,step]};if(!keys[e.key])return;e.preventDefault();const [dx,dy]=keys[e.key],b={...this.box};if(e.shiftKey){b.w+=dx;b.h+=dy;}else{b.x+=dx;b.y+=dy;}this.setBox(b);});
    d.on('click','[data-crop-close]',()=>d[0].close());
    d.on('click','[data-crop-whole]',()=>{this.setBox({x:0,y:0,w:1,h:1});this.confirm();});
    d.on('click','[data-crop-search]',()=>this.confirm());
    d.on('close',()=>{this.button.trigger('focus');});
  }
  async confirm(){
    const d=this.dialog,go=d.find('[data-crop-search],[data-crop-whole]').prop('disabled',true);
    try{const blob=await this.prepare();d[0].close();this.files.push({blob,url:URL.createObjectURL(blob)});this.render();await this.search();}
    catch(e){d.find('.photo-crop-error').text(e.message||'That photo could not be prepared. Try another one.').prop('hidden',false);}
    finally{go.prop('disabled',false);}
  }
  async prepare(){
    const b=this.box,bm=this.bitmap,sx=Math.round(b.x*bm.width),sy=Math.round(b.y*bm.height),sw=Math.max(1,Math.round(b.w*bm.width)),sh=Math.max(1,Math.round(b.h*bm.height));
    for(let side=1200;side>=400;side=Math.round(side*.8)){
      const scale=Math.min(1,side/Math.max(sw,sh)),c=document.createElement('canvas');c.width=Math.max(1,Math.round(sw*scale));c.height=Math.max(1,Math.round(sh*scale));
      const g=c.getContext('2d');g.fillStyle='#fff';g.fillRect(0,0,c.width,c.height);g.drawImage(bm,sx,sy,sw,sh,0,0,c.width,c.height);
      for(const q of [.85,.72,.6,.45]){const blob=await new Promise(r=>c.toBlob(r,'image/jpeg',q));if(blob&&blob.size<=250000)return blob;}
    }
    throw new Error('That photo is too detailed to send. Crop closer to the item.');
  }
  // Chip and search ------------------------------------------------------------------------------------------------
  render(){
    const c=this.chip.prop('hidden',!this.files.length);c.children(':not([role=status])').remove();
    if(!this.files.length)return;
    const thumbs=$('<span>').addClass('photo-search-thumbs');
    this.files.forEach((f,i)=>thumbs.append($('<span>').addClass('photo-search-thumb').append($('<img>').attr({src:f.url,alt:''}),$('<button>').attr({type:'button','aria-label':'Remove photo '+(i+1)}).addClass('photo-search-remove').text('×').on('click',()=>this.remove(i)))));
    const label=$('<span>').addClass('photo-search-label').text(this.busy?'Searching by photo…':'Searching by photo · '+this.files.length+' photo'+(this.files.length===1?'':'s'));
    const add=$('<button>').attr('type','button').addClass('text-button photo-search-add').text('+ Add').prop('hidden',this.files.length>=3).on('click',()=>this.pick());
    const clear=$('<button>').attr('type','button').addClass('text-button photo-search-clear').text('× Clear').on('click',()=>this.clear());
    c.prepend(thumbs,label,$('<span>').addClass('photo-search-actions').append(add,clear));
  }
  remove(index){if(this.busy)return;URL.revokeObjectURL(this.files[index].url);this.files.splice(index,1);if(!this.files.length){this.clear();return;}this.render();this.search();}
  clear(restore=true){this.sequence++;this.busy=false;this.files.forEach(f=>URL.revokeObjectURL(f.url));this.files=[];this.render();this.onClear(restore);}
  async search(){
    if(!this.files.length)return;
    const seq=++this.sequence,body=new FormData();
    this.files.forEach((f,i)=>body.append('photo[]',f.blob,'photo-'+(i+1)+'.jpg'));
    for(const [k,v] of Object.entries(this.fields()))if(v)body.append(k,v);
    this.busy=true;this.render();this.onStart();
    try{const response=await window.ReFind.request(this.endpoint,body);if(seq!==this.sequence)return;this.busy=false;this.render();this.onResults(response);}
    catch(error){if(seq!==this.sequence)return;this.busy=false;this.render();this.onError(error);}
  }
};
