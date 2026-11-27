(() => {
    const selector = '[data-forum-share]';

    document.addEventListener('click', async (event) => {
        const button = event.target.closest(selector);
        if (!button) return;

        const body = new URLSearchParams({
            csrf: button.dataset.forumCsrf || '',
            kind: button.dataset.forumKind || '',
            name: button.dataset.forumName || '',
        });
        const originalLabel = button.textContent;
        const forumWindow = window.open('', '_blank');
        if (forumWindow) forumWindow.opener = null;
        button.disabled = true;
        button.textContent = '正在打包...';

        try {
            const response = await fetch('/admin/extension-share', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body,
                credentials: 'same-origin',
            });
            if (!response.ok) {
                if (response.status === 403) throw new Error('会话已更新，请刷新后台页面后重试。');
                const message = (await response.text()).trim();
                throw new Error(message || '分享包创建失败，请重试。');
            }
            const share = await response.json();
            button.textContent = '正在打开论坛发帖页';
            if (typeof share.forum_url !== 'string' || !share.forum_url.startsWith('https://')) throw new Error('分享论坛地址无效。');
            if (forumWindow) forumWindow.location = share.forum_url;
            else window.open(share.forum_url, '_blank', 'noopener,noreferrer');
        } catch (error) {
            if (forumWindow) forumWindow.close();
            button.textContent = error instanceof Error ? error.message : '打包失败，请重试';
        }
        window.setTimeout(() => { button.textContent = originalLabel; button.disabled = false; }, 2600);
    });
})();
