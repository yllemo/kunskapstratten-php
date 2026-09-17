// Finish import through the visitor's Ollama; PHP validates and saves the result.
window.formatImportedDocuments = async result => {
  const ingest=result.ingest,paths=ingest?.format_documents||[];
  let completed=0;
  for(const relpath of paths){
    showToast(`AI formaterar och uppdaterar taggar: ${completed+1}/${paths.length} – ${relpath}`);
    try {
      const prepared=await postJSON('/api/markdown/import-prepare',{relpath,bank:result.bank});
      let response;
      if(prepared.ai.provider==='ollama'){
        response='';
        await window.localOllama.stream({...prepared.ai,temperature:0,format:prepared.schema},prepared.messages,token=>{response+=token;});
      }
      await postJSON('/api/markdown/import-apply',{relpath,bank:prepared.bank,hash:prepared.hash,ai_revision:prepared.ai_revision,response});
      ingest.ai_formatted=(ingest.ai_formatted||0)+1;
    }catch(error){
      ingest.warnings.push(`${relpath}: AI-formatering/taggar inte klara. Texten behölls. ${error.message} Försök igen med Uppdatera.`);
    }
    completed++;
  }
  return result;
};
