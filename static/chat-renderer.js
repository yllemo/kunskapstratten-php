/* AI-output är opålitlig Markdown: sanera alltid före HTML-infogning. */
(() => {
  function copyButton(source) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code-copy';
    button.setAttribute('aria-live', 'polite');
    button.textContent = 'Kopiera kod';
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(source);
        button.textContent = 'Kopierat!';
      } catch (_) {
        // Äldre webbläsare / HTTP utan Clipboard API.
        const area = document.createElement('textarea');
        area.value = source;
        area.style.cssText = 'position:fixed;left:-10000px;top:0';
        document.body.append(area);
        area.select();
        let copied = false;
        try { copied = document.execCommand('copy'); } catch (_) {}
        area.remove();
        button.focus();
        button.textContent = copied ? 'Kopierat!' : 'Kunde inte kopiera – markera koden';
      }
      setTimeout(() => { button.textContent = 'Kopiera kod'; }, 2500);
    });
    return button;
  }

  window.renderChatMarkdown = async (bubble, source) => {
    if (!window.marked || !window.DOMPurify) {
      bubble.textContent = source;
      return;
    }
    bubble.classList.add('chat-markdown');
    bubble.innerHTML = DOMPurify.sanitize(marked.parse(source, {gfm:true, breaks:true}), {
      USE_PROFILES:{html:true}, FORBID_TAGS:['img','style','form','button'],
      FORBID_ATTR:['style','id','name']
    });
    bubble.querySelectorAll('input').forEach(input => {
      if (input.type !== 'checkbox') return input.remove();
      input.disabled = true;
      input.setAttribute('aria-label', input.checked ? 'Klar' : 'Inte klar');
      input.closest('li')?.classList.add('task-list-item');
      input.closest('ul, ol')?.classList.add('task-list');
    });
    bubble.querySelectorAll('a').forEach(link => {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    });
    const pending = [];
    bubble.querySelectorAll('pre > code').forEach(code => {
      const source = code.textContent;
      const pre = code.parentElement;
      const frame = document.createElement('section');
      frame.className = 'chat-code-block';
      const toolbar = document.createElement('div');
      toolbar.className = 'code-toolbar';
      const label = document.createElement('span');
      const language = [...code.classList].find(name => name.startsWith('language-'))?.slice(9) || 'kod';
      label.textContent = language;
      toolbar.append(label, copyButton(source));
      pre.replaceWith(frame);
      frame.append(toolbar, pre);
      if (language.toLowerCase() === 'mermaid') {
        const diagram = document.createElement('div');
        diagram.className = 'chat-diagram';
        diagram.setAttribute('role', 'img');
        diagram.setAttribute('aria-label', 'Mermaid-diagram');
        diagram.textContent = 'Renderar diagram…';
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = 'Visa Mermaid-kod';
        details.append(summary, pre);
        frame.append(diagram, details);
        pending.push(window.renderMermaidDiagram(diagram, source));
      }
    });
    await Promise.all(pending);
  };
})();
