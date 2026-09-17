(() => {
  const data = JSON.parse(document.getElementById('editor-data').textContent);
  const area = document.getElementById('editArea'), status = document.getElementById('saveStatus');
  const save = document.getElementById(data.saveId), format = document.getElementById('formatMarkdownBtn'), undo = document.getElementById('undoFormatBtn');
  let editor = null, previous = null, busy = false;
  const read = () => editor ? editor.getValue() : area.value;
  function replace(text) {
    if (editor) editor.executeEdits('ai-format', [{range:editor.getModel().getFullModelRange(),text}]);
    area.value = text;
  }
  if (window.require && !matchMedia('(max-width:760px)').matches) {
    require.config({paths:{vs:'https://cdn.jsdelivr.net/npm/monaco-editor@latest/min/vs'}});
    require(['vs/editor/editor.main'], () => {
      editor = monaco.editor.create(document.getElementById('monacoEditor'), {value:area.value,language:'markdown',automaticLayout:true,wordWrap:'on',readOnly:busy});
      document.getElementById('monacoEditor').classList.add('is-ready'); area.classList.add('is-hidden');
    });
  }
  function lock(value) {
    busy=value; save.disabled=format.disabled=value; undo.disabled=value;
    document.getElementById('deleteItemBtn').disabled=value;
    area.readOnly=value; editor?.updateOptions({readOnly:value});
  }
  undo.onclick = () => {if(previous!==null){replace(previous);previous=null;undo.hidden=true;status.textContent='Uppsnyggningen ångrades. Filen på disk är oförändrad.';}};
  format.onclick = async () => {
    const original=read();let cleaned=null;const payload={content:original,relpath:data.relpath,kind:data.kind};
    lock(true);status.textContent='Städar Markdown med kod…';
    format.classList.add('is-formatting');
    format.setAttribute('aria-busy','true');
    const started=Date.now();
    const progress=()=>{format.textContent='Arbetar… '+Math.floor((Date.now()-started)/1000)+' s';};
    progress();
    const timer=setInterval(progress,1000);
    try {
      cleaned=await postJSON('/api/markdown/clean',payload);
      if(read()!==original)throw new Error('Texten ändrades under städningen. Förslaget tillämpades inte.');
      previous=original;replace(cleaned.content);undo.hidden=false;
      payload.content=cleaned.content;
      if(!cleaned.ai_enabled){status.textContent=(cleaned.cleanup_changed?'Kodstädning klar.':'Texten är redan städad.')+' AI är avstängd; taggarna behölls. Granska och klicka Spara.';return;}
      status.textContent='Kodstädning klar. AI snyggar till struktur och frontmatter…';
      const prepared=await postJSON('/api/markdown/prepare',payload);
      let result;
      if(prepared.ai.provider==='ollama') {
        let response='';
        await window.localOllama.stream({...prepared.ai,temperature:0},prepared.messages,token=>{response+=token;});
        status.textContent='Kontrollerar ord, kod, länkar och frontmatter…';
        result=await postJSON('/api/markdown/validate',{...payload,response});
      } else result=await postJSON('/api/markdown/format',payload);
      if(read()!==cleaned.content)throw new Error('Texten ändrades under bearbetningen. Förslaget tillämpades inte.');
      previous=original;replace(result.content);undo.hidden=false;
      status.textContent='Kodstädning klar. '+(result.structure_changed ? 'Markdown-strukturen förbättrad.' : 'Markdown-strukturen behölls.')+' Frontmatter uppdaterad. Taggar: '+result.tags.join(', ')+'. Granska och klicka Spara.';
    } catch(error) {status.textContent=(cleaned&&read()===cleaned.content?'Kodstädningen behölls, men AI-steget blev inte klart: ':'')+error.message;}
    finally {
      clearInterval(timer);
      format.classList.remove('is-formatting');
      format.setAttribute('aria-busy','false');
      format.textContent='Snygga till';
      lock(false);
    }
  };
  save.onclick=async()=>{
    lock(true);status.textContent='Sparar…';
    try {await postJSON(data.saveUrl,{content:read()});location.href=data.backUrl;}
    catch(error){status.textContent=error.message;lock(false);}
  };
})();
