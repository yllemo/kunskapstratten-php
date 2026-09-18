// Shared Mermaid runtime for document views and chat Markdown.
(() => {
  let runtime, queue=Promise.resolve(), diagramId=0;
  const load=()=>runtime ||= import('https://cdn.jsdelivr.net/npm/mermaid@latest/dist/mermaid.esm.min.mjs')
    .then(({default:mermaid})=>{
      mermaid.initialize({startOnLoad:false,securityLevel:'strict',theme:'default',htmlLabels:false,
        flowchart:{htmlLabels:false},suppressErrorRendering:true});
      return mermaid;
    }).catch(error=>{runtime=null;throw error;});
  window.renderMermaidDiagram=(target,source)=>{
    const job=async()=>{
      try {
        const mermaid=await load();
        const {svg}=await mermaid.render('kb-mermaid-'+(++diagramId),source);
        target.innerHTML=DOMPurify.sanitize(svg,{USE_PROFILES:{svg:true,svgFilters:true},FORBID_TAGS:['foreignObject','a','image']});
      } catch(error) {
        target.textContent='Diagrammet kunde inte renderas. Kontrollera Mermaid-koden eller anslutningen till CDN.';
        target.classList.add('diagram-error');
        if(target.nextElementSibling?.tagName==='DETAILS')target.nextElementSibling.open=true;
      }
    };
    queue=queue.then(job,job);
    return queue;
  };
  const pending=[];
  document.querySelectorAll('.doc-body pre > code').forEach(code=>{
    if(![...code.classList].some(name=>name.toLowerCase()==='language-mermaid'))return;
    const pre=code.parentElement,source=code.textContent;
    const frame=document.createElement('section');frame.className='mermaid-diagram';
    const diagram=document.createElement('div');diagram.className='mermaid-viewport';
    diagram.setAttribute('role','img');diagram.setAttribute('aria-label','Mermaid-diagram');
    diagram.textContent='Renderar diagram…';
    const details=document.createElement('details'),summary=document.createElement('summary');
    summary.textContent='Visa Mermaid-kod';details.append(summary);
    pre.replaceWith(frame);details.append(pre);frame.append(diagram,details);
    pending.push(window.renderMermaidDiagram(diagram,source));
  });
  window.documentMermaidReady=Promise.all(pending);
})();
