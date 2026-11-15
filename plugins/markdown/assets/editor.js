(() => {
  const insert = (textarea, before, after, placeholder) => {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selected = textarea.value.slice(start, end) || placeholder;
    textarea.setRangeText(before + selected + after, start, end, 'select');
    textarea.focus();
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const addButton = (toolbar, label, title, action) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.addEventListener('click', action);
    toolbar.append(button);
    return button;
  };

  const copyText = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.append(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();
    if (!copied) throw new Error('copy unavailable');
  };

  const enhanceCodeBlocks = (root = document) => {
    root.querySelectorAll('.markdown-body pre').forEach((pre) => {
      if (pre.parentElement?.classList.contains('markdown-code-block')) return;
      const block = document.createElement('div');
      block.className = 'markdown-code-block';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'markdown-code-copy';
      button.setAttribute('aria-label', '复制代码');
      button.textContent = '复制';
      pre.before(block);
      block.append(button, pre);
    });
  };

  document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
    const button = event.target.closest('.markdown-code-copy');
    if (!button) return;
    const code = button.closest('.markdown-code-block')?.querySelector('pre code');
    if (!code || button.disabled) return;
    const original = button.textContent;
    button.disabled = true;
    try {
      await copyText(code.textContent || '');
      button.textContent = '已复制';
    } catch {
      button.textContent = '复制失败';
    }
    window.setTimeout(() => {
      button.textContent = original;
      button.disabled = false;
    }, 1600);
  });

  const setup = (mount) => {
    const form = mount.closest('form');
    const textarea = form?.querySelector('textarea[name="body"]');
    if (!textarea || textarea.dataset.markdownEditorReady === '1') return;
    textarea.dataset.markdownEditorReady = '1';
    const editor = document.createElement('section');
    editor.className = 'markdown-editor';
    const toolbar = document.createElement('div');
    toolbar.className = 'markdown-editor-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Markdown 编辑工具');
    const preview = document.createElement('div');
    preview.className = 'markdown-editor-preview';
    preview.setAttribute('aria-live', 'polite');
    let previewRequest = null;
    let previewTimer = null;
    let previewRevision = 0;
    const refresh = async () => {
      const revision = ++previewRevision;
      previewRequest?.abort();
      previewRequest = new AbortController();
      preview.classList.add('is-loading');
      preview.classList.remove('has-error');
      preview.textContent = '正在生成预览...';
      const csrf = form.querySelector('input[name="csrf"]')?.value || '';
      try {
        const response = await fetch('/plugin/markdown/preview', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams({ csrf, body: textarea.value }),
          signal: previewRequest.signal,
        });
        const html = await response.text();
        if (revision !== previewRevision) return;
        if (!response.ok) throw new Error(html || `HTTP ${response.status}`);
        preview.innerHTML = html || '<p class="markdown-editor-empty">还没有可预览的内容。</p>';
      } catch (error) {
        if (error.name === 'AbortError' || revision !== previewRevision) return;
        preview.textContent = `预览失败：${error.message || 'Markdown 服务暂时不可用'}`;
        preview.classList.add('has-error');
      } finally {
        if (revision === previewRevision) {
          preview.classList.remove('is-loading');
          previewRequest = null;
        }
      }
    };

    addButton(toolbar, 'B', '粗体', () => insert(textarea, '**', '**', '粗体文本'));
    addButton(toolbar, 'I', '斜体', () => insert(textarea, '*', '*', '斜体文本'));
    addButton(toolbar, 'S', '删除线', () => insert(textarea, '~~', '~~', '删除文本'));
    addButton(toolbar, '</>', '行内代码', () => insert(textarea, '`', '`', '代码'));
    addButton(toolbar, '链', '插入链接', () => insert(textarea, '[', '](https://example.com)', '链接文字'));
    addButton(toolbar, '图', '插入图片', () => insert(textarea, '![', '](https://example.com/image.jpg)', '图片说明'));
    addButton(toolbar, '引', '引用', () => insert(textarea, '> ', '', '引用内容'));
    addButton(toolbar, '列', '无序列表', () => insert(textarea, '- ', '', '列表项目'));
    addButton(toolbar, '序', '有序列表', () => insert(textarea, '1. ', '', '列表项目'));
    addButton(toolbar, '表', '插入表格', () => insert(textarea, '\n| 列 1 | 列 2 |\n| --- | --- |\n| 内容 | 内容 |\n', '', ''));
    addButton(toolbar, '任务', '插入任务列表', () => insert(textarea, '- [ ] ', '', '待办事项'));
    addButton(toolbar, '脚注', '插入脚注', () => insert(textarea, '[^1]', '\n\n[^1]: 脚注内容', ''));
    addButton(toolbar, '代码', '插入代码块', () => insert(textarea, '```\n', '\n```', '代码'));
    const previewButton = addButton(toolbar, '预览', '切换预览', () => {
      const visible = preview.classList.toggle('is-visible');
      previewButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
      if (visible) refresh();
      else {
        clearTimeout(previewTimer);
        previewRequest?.abort();
      }
    });
    previewButton.className = 'markdown-editor-preview-toggle';
    previewButton.setAttribute('aria-pressed', 'false');

    const help = document.createElement('div');
    help.className = 'markdown-editor-help';
    help.textContent = '支持 Markdown：Ctrl+B 粗体，Ctrl+I 斜体，Ctrl+K 链接。';
    editor.append(toolbar, help, preview);
    mount.replaceWith(editor);
    textarea.addEventListener('input', () => {
      if (!preview.classList.contains('is-visible')) return;
      clearTimeout(previewTimer);
      previewTimer = setTimeout(refresh, 250);
    });
    textarea.addEventListener('keydown', (event) => {
      if (!(event.ctrlKey || event.metaKey)) return;
      if (event.key.toLowerCase() === 'b') { event.preventDefault(); insert(textarea, '**', '**', '粗体文本'); }
      if (event.key.toLowerCase() === 'i') { event.preventDefault(); insert(textarea, '*', '*', '斜体文本'); }
      if (event.key.toLowerCase() === 'k') { event.preventDefault(); insert(textarea, '[', '](https://example.com)', '链接文字'); }
    });
  };

  document.addEventListener('DOMContentLoaded', () => {
    enhanceCodeBlocks();
    document.querySelectorAll('[data-markdown-editor]').forEach(setup);
  });
})();
