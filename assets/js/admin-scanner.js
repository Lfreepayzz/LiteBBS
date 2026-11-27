(() => {
    const forms = document.querySelectorAll('[data-security-scan-form]');
    for (const form of forms) form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        const progress = form.querySelector('.security-live-progress');
        const status = form.querySelector('[data-security-scan-status]');
        for (const submit of document.querySelectorAll('[data-security-scan-form] button[type="submit"]')) submit.disabled = true;
        if (button) button.textContent = '正在扫描';
        if (progress) progress.hidden = false;
        if (status) { status.hidden = false; status.textContent = '正在等待本地扫描和 VirusTotal 返回结果，请勿关闭此页面。'; }
        document.documentElement.setAttribute('aria-busy', 'true');
    });
})();
