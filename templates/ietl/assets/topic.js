(() => {
  const composer = document.querySelector('#reply-composer');
  const textarea = document.querySelector('#reply-body');
  const target = document.querySelector('#reply-to-post');
  if (!composer || !textarea || !target) return;

  const note = composer.querySelector('.reply-quote-note');
  document.querySelectorAll('.reply-floor').forEach((button) => {
    button.addEventListener('click', () => {
      const post = button.closest('.post');
      if (!post?.id) return;
      target.value = post.id.replace(/^p/, '');
      if (note) {
        note.querySelector('strong').textContent = `回复 ${button.dataset.floor || ''} 楼`;
        note.querySelector('blockquote').textContent = post.querySelector('.body')?.textContent?.trim() || '';
        note.hidden = false;
      }
      composer.scrollIntoView({ behavior: 'smooth', block: 'start' });
      textarea.focus({ preventScroll: true });
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
  });
})();
