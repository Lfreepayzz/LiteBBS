(() => {
  const query = new URLSearchParams(window.location.search);
  const shareSource = query.get('share_source');
  const shareToken = query.get('share_token');
  if (window.location.pathname !== '/new' || !shareSource || !/^[a-f0-9]{48}$/.test(shareToken || '')) return;

  fetch(`${shareSource.replace(/\/$/, '')}/extension-draft/${shareToken}`, { credentials: 'omit' })
    .then((response) => response.ok ? response.json() : Promise.reject())
    .then((draft) => {
      const title = document.querySelector('input[name="title"]');
      const body = document.querySelector('textarea[name="body"]');
      if (!(title instanceof HTMLInputElement) || !(body instanceof HTMLTextAreaElement)
        || typeof draft.title !== 'string' || typeof draft.body !== 'string'
        || draft.title.length > 120 || draft.body.length > 12000) return;
      title.value = draft.title;
      body.value = draft.body;
      title.dispatchEvent(new Event('input', { bubbles: true }));
      body.dispatchEvent(new Event('input', { bubbles: true }));
      history.replaceState(null, '', '/new');
    })
    .catch(() => {});
})();
