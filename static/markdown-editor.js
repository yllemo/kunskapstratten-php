(() => {
  const data = JSON.parse(document.getElementById('editor-data').textContent);
  const area = document.getElementById('editArea'), status = document.getElementById('saveStatus');
  const save = document.getElementById(data.saveId), format = document.getElementById('formatMarkdownBtn'), undo = document.getElementById('undoFormatBtn');
  const level = document.getElementById('formatLevel');
  let editor = null, previous = null, busy = false;
  const read = () => editor ? editor.getValue() : area.value;
  function replace(text) {
    if (editor) {
      editor.executeEdits('ai-format', [{range:editor.getModel().getFullModelRange(),text}]);
      if (editor.getValue() !== text) editor.setValue(text);
      if (editor.getValue() !== text) throw new Error('Redigeraren kunde inte visa det formaterade dokumentet.');
    }
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
    busy=value;level.disabled=value; save.disabled=format.disabled=value; undo.disabled=value;
    document.getElementById('deleteItemBtn').disabled=value;
    area.readOnly=value; editor?.updateOptions({readOnly:value});
  }
  undo.onclick = () => {if(previous!==null){replace(previous);previous=null;undo.hidden=true;status.textContent='Uppsnyggningen ångrades. Filen på disk är oförändrad.';}};
  format.title='Snyggar till markerad text, eller hela dokumentet om inget är markerat.';
  format.onclick = async () => {
    const original=read();
    const selection=editor?editor.getSelection():null;
    const start=editor?editor.getModel().getOffsetAt(selection.getStartPosition()):area.selectionStart;
    const end=editor?editor.getModel().getOffsetAt(selection.getEndPosition()):area.selectionEnd;
    const selected=end>start, source=selected?original.slice(start,end):original;
    const frontmatter=original.match(/^(?:\uFEFF)?---\r?\n[\s\S]*?\r?\n(?:---|\.\.\.)[ \t]*(?:\r?\n|$)/);
    if(selected&&frontmatter&&start<frontmatter[0].length){status.textContent='Markera text efter YAML-frontmatter, eller avmarkera för att snygga till hela dokumentet.';return;}
    const merge=text=>selected?original.slice(0,start)+text+original.slice(end):text;
    let cleaned=null, cleanedDocument=null;
    const payload={content:source,selection:selected,relpath:data.relpath,kind:data.kind,level:level.value};
    lock(true);status.textContent=selected?'Städar markerad text med kod…':'Städar hela dokumentet med kod…';
    format.classList.add('is-formatting');
    format.setAttribute('aria-busy','true');
    const started=Date.now();
    const progress=()=>{format.textContent='Arbetar… '+Math.floor((Date.now()-started)/1000)+' s';};
    progress();
    const timer=setInterval(progress,1000);
    try {
      cleaned=await postJSON('/api/markdown/clean',payload);
      if(read()!==original)throw new Error('Texten ändrades under städningen. Förslaget tillämpades inte.');
      previous=original;cleanedDocument=merge(cleaned.content);replace(cleanedDocument);undo.hidden=false;
      payload.content=cleaned.content;
      if(!cleaned.ai_enabled){status.textContent=(cleaned.cleanup_changed?'Kodstädning klar.':'Texten är redan städad.')+(selected?' Markerad text behandlad; övrig text och frontmatter behölls.':' Rubriker/tabeller och frontmatter behandlade. Taggar: '+cleaned.tags.join(', ')+'.')+' AI är avstängd. Granska och klicka Spara.';return;}
      status.textContent='Kodstädning klar. AI arbetar med '+level.options[level.selectedIndex].text.toLowerCase()+(selected?' på markerad text…':' och frontmatter…');
      const plan=await postJSON('/api/markdown/plan',payload);
      const parts=[];
      for(let index=0;index<plan.chunks.length;index++){
        status.textContent='AI bearbetar avsnitt '+(index+1)+' av '+plan.chunks.length+'…';
        let part;
        for(let attempt=0;attempt<2;attempt++){
          const request={...payload,content:plan.chunks[index],selection:true,ai_revision:plan.ai_revision,retry:attempt>0};
          const prepared=await postJSON('/api/markdown/prepare',request);
          if(prepared.ai_revision!==plan.ai_revision)throw new Error('AI-inställningarna ändrades. Starta uppsnyggningen igen.');
          if(prepared.ai.provider==='ollama'){
            let response='';
            await window.localOllama.stream({...prepared.ai,temperature:0.15,format:prepared.schema},prepared.messages,token=>{response+=token;});
            part=await postJSON('/api/markdown/validate',{...request,response});
          }else part=await postJSON('/api/markdown/format',request);
          if(part.structure_changed||level.value==='light')break;
          if(attempt===0)status.textContent='Avsnitt '+(index+1)+': gör en fördjupad genomgång…';
        }
        parts.push(part);
      }
      status.textContent='Kontrollerar och sammanfogar avsnitten…';
      const result=await postJSON('/api/markdown/assemble',{...payload,parts,ai_revision:plan.ai_revision});
      if(read()!==cleanedDocument)throw new Error('Texten ändrades under bearbetningen. Förslaget tillämpades inte.');
      previous=original;replace(merge(result.content));undo.hidden=false;
      status.textContent=(selected?'Markerad text behandlad. ':'Kodstädning klar. ')+(result.structure_changed ? level.value==='intensive'?'Text och struktur omarbetade.':'Markdown-strukturen förbättrad.' : 'AI gav ingen ändring av textens struktur efter genomgången.')+(selected?' Texten utanför markeringen behölls.':' Frontmatter uppdaterad. Taggar: '+result.tags.join(', ')+'.')+' Granska och klicka Spara.';
    } catch(error) {status.textContent=(cleaned&&read()===cleanedDocument?'Kodstädningen behölls, men AI-steget blev inte klart: ':'')+error.message;}
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
