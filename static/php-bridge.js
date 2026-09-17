// Keep the original UI modules; route requests through index.php on any host/subfolder.
(() => {
  const base = document.querySelector('meta[name="app-base"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  window.kbUrl = path => base + '/index.php?r=' + encodeURIComponent(path);
  const keyName = 'kunskapstratten:' + base + ':ai-api-key';
  const validKey = value => {
    const key = value.trim();
    if (key.length > 8192 || /[^\x21-\x7e]/.test(key)) throw new Error('API-nyckeln innehåller ogiltiga tecken eller är för lång.');
    return key;
  };
  window.kbApiKey = {
    read() {
      try { return validKey(localStorage.getItem(keyName) || ''); }
      catch (error) { throw new Error('Kan inte läsa lokal API-nyckel: ' + error.message); }
    },
    save(value) {
      const key = validKey(value);
      if (!key) return this.remove();
      localStorage.setItem(keyName, key);
      if (this.read() !== key) throw new Error('Webbläsaren kunde inte bekräfta att API-nyckeln sparats.');
    },
    remove() {
      localStorage.removeItem(keyName);
      if (localStorage.getItem(keyName) !== null) throw new Error('API-nyckeln kunde inte raderas.');
    }
  };
  const aiRoutes = new Set(['/api/chat', '/api/skills/run', '/api/chat/temp-file', '/api/reindex', '/api/settings/models', '/api/settings/test']);
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, options = {}) => {
    if (typeof input === 'string' && /^\/(api|browse|doc|skills|images|chat)(\/|$)/.test(input)) input = window.kbUrl(input);
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (url.origin === location.origin) {
      const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
      headers.set('X-CSRF-Token', csrf);
      const method = (options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
      if (url.pathname === base + '/index.php' && method === 'POST' && aiRoutes.has(url.searchParams.get('r'))) {
        try {
          if (!headers.has('X-KB-AI-Key')) {
            const key = window.kbApiKey.read();
            if (key) headers.set('X-KB-AI-Key', key);
          }
        } catch (error) { return Promise.reject(error); }
      }
      options = {...options, headers};
    }
    return originalFetch(input, options);
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[method="post"]').forEach(form => {
      const field = document.createElement('input'); field.type = 'hidden'; field.name = '_csrf'; field.value = csrf; form.append(field);
    });
  });
})();
