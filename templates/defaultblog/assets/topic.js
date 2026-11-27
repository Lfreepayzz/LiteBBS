(() => {
  const pageHeader = document.getElementById('page-header');
  const pageName = document.getElementById('page-name');
  if (pageHeader && pageName) {
    const syncNavigation = () => {
      const scrolled = window.scrollY > pageHeader.offsetHeight - 60;
      pageHeader.classList.toggle('nav-fixed', scrolled);
      pageName.hidden = !scrolled;
    };
    syncNavigation();
    window.addEventListener('scroll', syncNavigation, { passive: true });
    window.addEventListener('resize', syncNavigation);
  }

  const original = document.querySelector('.topic-thread > .post-original');
  const tail = original?.nextElementSibling;
  if (tail?.matches('.default-post-tail')) {
    const topicLink = tail.querySelector('.post-copyright-title');
    const copy = tail.querySelector('[data-copy-topic]');
    const status = tail.querySelector('[role="status"]');
    const copyLink = async () => {
      try {
        await navigator.clipboard.writeText(topicLink.href);
        status.textContent = '已复制帖子链接';
      } catch {
        status.textContent = topicLink.href;
      }
    };
    copy.addEventListener('click', copyLink);
    tail.querySelector('[data-share-topic]').addEventListener('click', async () => {
      if (typeof navigator.share === 'function') {
        try {
          await navigator.share({ title: topicLink.textContent.trim(), url: topicLink.href });
          return;
        } catch (error) {
          if (error.name === 'AbortError') return;
        }
      }
      await copyLink();
    });
  }

  const composer = document.getElementById('reply-composer');
  const textarea = document.getElementById('reply-body');
  const replyTo = document.getElementById('reply-to-post');
  const thread = document.querySelector('.topic-thread');
  if (!thread || !composer || !textarea || !replyTo) return;

  const note = composer.querySelector('.reply-quote-note');
  thread.addEventListener('click', (event) => {
    const button = event.target.closest('.reply-floor');
    const post = button?.closest('article.post[id]');
    if (!post || !/^p\d+$/.test(post.id)) return;

    replyTo.value = post.id.slice(1);
    if (note) {
      note.querySelector('strong').textContent = `回复 ${button.dataset.floor} 楼`;
      note.querySelector('blockquote').textContent = post.querySelector('.body')?.innerText.trim() || '';
      note.hidden = false;
    }
    composer.scrollIntoView({ block: 'start' });
    textarea.focus({ preventScroll: true });
  });
})();
