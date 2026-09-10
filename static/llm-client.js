// Browser-side Ollama client. Localhost now means the visitor's computer.
(() => {
  function baseUrl(value) {
    return value.trim().replace(/\/$/, '').replace(/\/(?:v1|api)$/i, '');
  }

  async function request(url, options = {}, timeoutMs = 10000) {
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);
    if (options.signal) options.signal.addEventListener('abort', () => controller.abort(), {once:true});
    try {
      const response = await fetch(url, {...options, signal:controller.signal});
      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || `Ollama svarade med HTTP ${response.status}`);
      }
      return response;
    } catch (error) {
      if (error.name === 'AbortError' && options.signal?.aborted) throw error;
      if (error.name === 'AbortError' && timedOut) throw new Error('Ollama svarade inte inom tidsgränsen.');
      if (error instanceof TypeError) throw new Error('Webbläsaren kunde inte nå Ollama. Starta Ollama och tillåt webbplatsens adress med OLLAMA_ORIGINS.');
      throw error;
    } finally { clearTimeout(timer); }
  }

  async function models(config) {
    const response = await request(baseUrl(config.base_url) + '/api/tags');
    const data = await response.json();
    return (data.models || []).map(item => item.name).filter(Boolean).sort((a,b) => a.localeCompare(b, 'sv'));
  }

  async function complete(config, messages) {
    const response = await request(baseUrl(config.base_url) + '/api/chat', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({model:config.model,messages,stream:false,options:{temperature:config.temperature}}),
    });
    const data = await response.json();
    if (typeof data.message?.content !== 'string') throw new Error('Ollama skickade inget textsvar.');
    return data.message.content;
  }

  async function stream(config, messages, onToken, signal) {
    const response = await request(baseUrl(config.base_url) + '/api/chat', {
      method:'POST', headers:{'Content-Type':'application/json'}, signal,
      body:JSON.stringify({model:config.model,messages,stream:true,options:{temperature:config.temperature}}),
    }, Number(config.timeout || 120) * 1000);
    const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
    while(true){const {value,done}=await reader.read();buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});let pos;
      while((pos=buffer.indexOf('\n'))!==-1){const line=buffer.slice(0,pos).trim();buffer=buffer.slice(pos+1);if(!line)continue;const data=JSON.parse(line);if(data.error)throw new Error(data.error);if(data.message?.content)onToken(data.message.content);}
      if(done)break;
    }
    if(buffer.trim()){const data=JSON.parse(buffer);if(data.error)throw new Error(data.error);if(data.message?.content)onToken(data.message.content);}
  }

  window.localOllama={models,complete,stream};
})();
