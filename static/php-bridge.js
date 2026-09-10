// Keep the original UI modules; route requests through index.php on any host/subfolder.
(() => {
  const base = document.querySelector('meta[name="app-base"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  window.kbUrl = path => base + '/index.php?r=' + encodeURIComponent(path);
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, options = {}) => {
    if (typeof input === 'string' && /^\/(api|browse|doc|skills|images|chat)(\/|$)/.test(input)) input = window.kbUrl(input);
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (url.origin === location.origin) {
      const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
      headers.set('X-CSRF-Token', csrf);
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
