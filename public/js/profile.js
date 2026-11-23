// Profile page, WhatsApp style (Stable round 4, owner request). Laravel-only script: the pinned native account-ui.js and
// avatar-editor.js still do all saving, cropping and validation through the same element IDs; this file only adds the
// photo menu (View / Take / Upload / Remove), the crop screen's +/- and Upload buttons, and the inline name editor.
(()=>{
  const $id=id=>document.getElementById(id);
  const trigger=$id('avatar-trigger'),menu=$id('avatar-menu'),avatar=document.querySelector('[data-profile-initials]');
  const dialog=$id('avatar-dialog'),zoom=$id('avatar-zoom'),file=$id('avatar-file'),camera=$id('avatar-camera');
  const viewDialog=$id('avatar-view-dialog'),viewImage=$id('avatar-view-image');
  if(!trigger||!menu||!avatar||!dialog)return;
  const items=()=>[...menu.querySelectorAll('[role="menuitem"]')].filter(item=>!item.hidden&&!item.disabled);
  const photo=()=>avatar.querySelector('img');

  // View photo only when there is one; Take photo only on touch phones (the system picker opens the camera).
  const sync=()=>{$id('avatar-view').hidden=!photo();};
  new MutationObserver(sync).observe(avatar,{childList:true,subtree:true});sync();
  $id('avatar-take').hidden=!matchMedia('(pointer: coarse)').matches;

  function openMenu(focusFirst){sync();menu.hidden=false;trigger.setAttribute('aria-expanded','true');if(focusFirst)items()[0]?.focus();}
  function closeMenu(focusTrigger){if(menu.hidden)return;menu.hidden=true;trigger.setAttribute('aria-expanded','false');if(focusTrigger)trigger.focus();}
  trigger.addEventListener('click',event=>{if(menu.hidden)openMenu(event.detail===0);else closeMenu(false);});
  trigger.addEventListener('keydown',event=>{if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();openMenu(true);}});
  menu.addEventListener('keydown',event=>{
    const list=items(),at=list.indexOf(document.activeElement);
    if(event.key==='Escape'){event.preventDefault();closeMenu(true);}
    else if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();list[(at+(event.key==='ArrowDown'?1:list.length-1))%list.length]?.focus();}
    else if(event.key==='Home'||event.key==='End'){event.preventDefault();(event.key==='Home'?list[0]:list.at(-1))?.focus();}
    else if(event.key==='Tab')closeMenu(false);
  });
  document.addEventListener('click',event=>{if(!menu.hidden&&!menu.contains(event.target)&&!trigger.contains(event.target))closeMenu(false);});
  // Upload and Remove keep their pinned handlers (avatar-editor.js); every item closes the menu first.
  menu.addEventListener('click',event=>{if(event.target.closest('[role="menuitem"]'))closeMenu(false);},true);

  $id('avatar-view').addEventListener('click',()=>{const img=photo();if(!img||!viewDialog)return;viewImage.src=img.currentSrc||img.src;viewDialog.showModal();});
  $id('avatar-view-close')?.addEventListener('click',()=>viewDialog.close());
  viewDialog?.addEventListener('click',event=>{if(event.target===viewDialog)viewDialog.close();});
  viewDialog?.addEventListener('close',()=>trigger.focus());

  // A camera shot goes through the same file input, so the pinned editor validates and opens it like an upload.
  $id('avatar-take').addEventListener('click',()=>camera?.click());
  camera?.addEventListener('change',()=>{const shot=camera.files?.[0];camera.value='';if(!shot)return;
    const transfer=new DataTransfer();transfer.items.add(shot);file.files=transfer.files;file.dispatchEvent(new Event('change',{bubbles:true}));});

  // Crop screen: +/- step the zoom slider the editor reads; Upload closes this crop (the editor cannot reopen an open
  // dialog) and opens the picker again in the same click.
  const step=delta=>{const next=Math.min(Number(zoom.max),Math.max(Number(zoom.min),Number(zoom.value)+delta));zoom.value=String(Math.round(next*100)/100);zoom.dispatchEvent(new Event('input',{bubbles:true}));};
  $id('avatar-zoom-in')?.addEventListener('click',()=>step(.1));
  $id('avatar-zoom-out')?.addEventListener('click',()=>step(-.1));
  $id('avatar-reupload')?.addEventListener('click',()=>{$id('avatar-cancel').click();file.click();});
  $id('avatar-canvas')?.addEventListener('wheel',event=>{if(!dialog.open)return;event.preventDefault();step(event.deltaY<0?.1:-.1);},{passive:false});
  new MutationObserver(()=>{if(dialog.open)requestAnimationFrame(()=>{if(document.activeElement===zoom||!dialog.contains(document.activeElement))$id('avatar-zoom-in')?.focus();});}).observe(dialog,{attributes:true,attributeFilter:['open']});
  dialog.addEventListener('close',()=>setTimeout(()=>{if(!document.activeElement||document.activeElement===document.body||menu.contains(document.activeElement))trigger.focus();},0));

  // Inline name editor: the pencil opens the pinned profile form; Enter submits it, Escape or X cancels.
  const view=$id('profile-name-view'),form=$id('profile-form'),input=$id('display-name'),feedback=$id('profile-feedback');
  function editName(){view.hidden=true;form.hidden=false;feedback.hidden=true;input.focus();input.select();}
  function closeEditor(focusPencil){form.hidden=true;view.hidden=false;if(focusPencil)$id('name-edit').focus();}
  $id('name-edit').addEventListener('click',editName);
  $id('profile-reset').addEventListener('click',()=>setTimeout(()=>closeEditor(true),0));
  input.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();$id('profile-reset').click();}});
  // account-ui.js reports a saved name as a success message; then the editor folds away and shows the new name.
  new MutationObserver(()=>{if(!feedback.hidden&&feedback.classList.contains('success'))closeEditor(false);}).observe(feedback,{attributes:true,attributeFilter:['hidden','class']});
})();
