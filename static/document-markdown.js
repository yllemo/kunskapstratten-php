(() => {
  const source=document.getElementById('document-markdown-data'),body=document.querySelector('.doc-body');
  if(!source||!body||!window.marked||!window.DOMPurify)return;
  const data=JSON.parse(source.textContent);
  body.innerHTML=DOMPurify.sanitize(marked.parse(data.body,{gfm:true,breaks:false}),{
    USE_PROFILES:{html:true},FORBID_TAGS:['style','form','button','iframe'],FORBID_ATTR:['style','id','name']
  });
  const headings=new Set();
  body.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(heading=>{
    const slug=heading.textContent.toLowerCase().trim().replace(/[^\p{L}\p{N} _-]/gu,'').replace(/\s+/g,'-')||'avsnitt';
    let id=slug,n=1;while(headings.has(id)||document.getElementById(id))id=slug+'-'+n++;
    headings.add(id);heading.id=id;
  });
  body.querySelectorAll('input').forEach(input=>{
    if(input.type!=='checkbox')return input.remove();
    input.disabled=true;input.setAttribute('aria-label',input.checked?'Klar':'Inte klar');
    input.closest('li')?.classList.add('task-list-item');input.closest('ul,ol')?.classList.add('task-list');
  });
  const routeUrl=url=>url.startsWith('/')&&!url.startsWith('//')&&/^\/(images|doc|browse|chat|skills)(\/|$)/.test(url)?window.kbUrl(url):url;
  body.querySelectorAll('a').forEach(link=>{
    const href=link.getAttribute('href')||'';
    if(/^(?![a-z]+:|\/|#).+\.md(?:#.*)?$/i.test(href)){
      const [path,hash]=href.split('#');
      const resolved=new URL(path,'https://kb.invalid/'+data.relpath).pathname.slice(1);
      link.href=window.kbUrl('/doc/'+decodeURIComponent(resolved))+(hash?'#'+hash:'');
    }else link.setAttribute('href',routeUrl(href));
    if(/^https?:\/\//i.test(href)){link.target='_blank';link.rel='noopener noreferrer';}
  });
  body.querySelectorAll('img').forEach(image=>{
    const src=image.getAttribute('src')||'';
    image.setAttribute('src',/^images\//.test(src)?window.kbUrl('/'+src):routeUrl(src));
    image.loading='lazy';window.makeMediaZoomable?.(image);
  });
  body.querySelectorAll('table').forEach(table=>{
    const wrapper=document.createElement('div');wrapper.className='markdown-table-wrap';
    wrapper.tabIndex=0;wrapper.setAttribute('role','region');wrapper.setAttribute('aria-label','Tabell, rulla horisontellt vid behov');
    table.replaceWith(wrapper);wrapper.append(table);
  });
  if(location.hash){const target=document.getElementById(decodeURIComponent(location.hash.slice(1)));target?.scrollIntoView();}
})();
