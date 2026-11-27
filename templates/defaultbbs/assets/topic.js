(() => {
  const composer = document.querySelector('#reply-composer');
  const textarea = document.querySelector('#reply-body');
  if (!composer || !textarea) return;

  const note = composer.querySelector('.reply-quote-note');
  const quoteInput = document.querySelector('#reply-to-post');
  document.querySelectorAll('.reply-floor').forEach((button) => {
    button.addEventListener('click', () => {
      const post = button.closest('.post');
      const floor = button.dataset.floor || '';
      if (quoteInput) quoteInput.value = post?.id.replace(/^p/, '') || '';
      if (note) {
        note.hidden = false;
        note.querySelector('strong').textContent = `回复 ${floor} 楼`;
        note.querySelector('blockquote').textContent = post?.querySelector('.body')?.textContent?.trim() || '';
      }
      composer.scrollIntoView({ behavior: 'smooth', block: 'start' });
      textarea.focus({ preventScroll: true });
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
  });
})();
