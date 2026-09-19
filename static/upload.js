(() => {
  const input=document.getElementById('fileInput');
  if(!input)return;
  const zone=document.getElementById('dropZone'),overlay=document.getElementById('uploadDropOverlay');
  const summary=document.getElementById('fileSummary'),section=document.getElementById('selectedFiles');
  const list=document.getElementById('selectedFileList'),form=document.getElementById('uploadForm');
  const empty=document.getElementById('selectedFilesEmpty'),count=document.getElementById('selectedFileCount');
  const clear=document.getElementById('clearSelectedFiles');
  const files=[];
  const identity=file=>[file.name,file.size,file.lastModified].join('\u0000');
  const formatSize=size=>size<1024?size+' B':size<1048576?(size/1024).toFixed(1)+' KB':(size/1048576).toFixed(1)+' MB';
  function sync(){
    const transfer=new DataTransfer();files.forEach(entry=>transfer.items.add(entry.file));input.files=transfer.files;
    zone.classList.toggle('has-files',files.length>0);
    section.classList.toggle('has-files',files.length>0);
    empty.hidden=files.length>0;
    clear.disabled=files.length===0;
    count.textContent='('+files.length+')';
    const total=files.reduce((sum,entry)=>sum+entry.file.size,0);
    summary.textContent=files.length?files.length+' '+(files.length===1?'fil vald':'filer valda')+' · '+formatSize(total):'Inga filer valda ännu · flera filer kan väljas';
    list.replaceChildren();
    files.forEach((entry,index)=>{
      const file=entry.file;
      const item=document.createElement('li');item.className='selected-file-item';
      const name=document.createElement('span');name.className='selected-file-name';name.textContent=file.name;name.title=file.name;
      const ext=file.name.slice(file.name.lastIndexOf('.'));
      const rename=document.createElement('label');rename.className='selected-file-rename';
      const caption=document.createElement('span');caption.textContent='Nytt namn (valfritt)';
      const field=document.createElement('span');field.className='selected-file-rename-field';
      const edit=document.createElement('input');edit.name='upload_names[]';edit.type='text';edit.maxLength=120;
      edit.placeholder=file.name.slice(0,-ext.length);edit.value=entry.rename;
      edit.setAttribute('aria-label','Nytt namn för '+file.name);
      edit.addEventListener('input',()=>{entry.rename=edit.value;});
      const suffix=document.createElement('span');suffix.textContent=ext;
      field.append(edit,suffix);rename.append(caption,field);
      const size=document.createElement('span');size.className='selected-file-size';size.textContent=formatSize(file.size);
      const remove=document.createElement('button');remove.type='button';remove.className='selected-file-remove';
      remove.textContent='Ta bort';remove.setAttribute('aria-label','Ta bort '+file.name);
      remove.addEventListener('click',()=>{files.splice(index,1);sync();});
      item.append(name,rename,size,remove);list.append(item);
    });
  }
  function add(incoming){
    const known=new Set(files.map(entry=>identity(entry.file)));
    for(const file of incoming){if(!(file instanceof File)||!file.name)continue;const key=identity(file);if(!known.has(key)){files.push({file,rename:''});known.add(key);}}
    sync();
  }
  input.addEventListener('change',()=>add(Array.from(input.files)));
  clear.addEventListener('click',()=>{files.length=0;sync();});
  const fileDrag=event=>Array.from(event.dataTransfer?.types||[]).includes('Files');
  let leaveTimer;
  function show(){clearTimeout(leaveTimer);overlay.hidden=false;zone.classList.add('is-dragging');}
  function hide(){clearTimeout(leaveTimer);overlay.hidden=true;zone.classList.remove('is-dragging');}
  document.addEventListener('dragenter',event=>{if(fileDrag(event)){event.preventDefault();show();}});
  document.addEventListener('dragover',event=>{if(fileDrag(event)){event.preventDefault();event.dataTransfer.dropEffect='copy';show();}});
  document.addEventListener('dragleave',event=>{if(fileDrag(event))leaveTimer=setTimeout(hide,80);});
  document.addEventListener('drop',event=>{if(!fileDrag(event))return;event.preventDefault();hide();add(Array.from(event.dataTransfer.files));});
  zone.addEventListener('dragover',event=>{if(fileDrag(event)){event.preventDefault();zone.classList.add('is-dragging');}});
  zone.addEventListener('drop',event=>{if(fileDrag(event)){event.preventDefault();event.stopPropagation();hide();add(Array.from(event.dataTransfer.files));}});
  window.addEventListener('blur',hide);
  form.addEventListener('submit',event=>{if(!files.length){event.preventDefault();input.reportValidity();return;}sync();});
  const process=document.getElementById('processUploaded');
  if(process)process.addEventListener('click',async()=>{
    process.disabled=true;process.textContent='Bearbetar…';
    try{const result=await postJSON('/api/reindex',{});if(result.ingest.failed||result.ingest.warnings.length)showToast([...result.ingest.errors,...result.ingest.warnings].join('; '));else location.href=window.kbUrl('/browse');}
    catch(error){showToast(error.message);}finally{process.disabled=false;process.textContent='Bearbeta nu';}
  });
})();
