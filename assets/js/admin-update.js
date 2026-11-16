(function () {
    'use strict';

    function init() {

    var checkButton = document.getElementById('program-update-check');
    var status = document.getElementById('program-update-status');
    var progress = document.getElementById('program-update-progress');
    var filePanel = document.getElementById('program-update-files');

    if (!checkButton || !status || !progress || !filePanel) return;

    function matchStatusToCheckButton() {
        var style = window.getComputedStyle(checkButton);
        var properties = [
            'backgroundColor', 'backgroundImage', 'border', 'borderRadius',
            'boxShadow', 'color', 'fontFamily', 'fontSize', 'fontWeight',
            'lineHeight', 'minHeight', 'padding'
        ];

        properties.forEach(function (property) {
            status.style[property] = style[property];
        });

        status.style.width = status.textContent === '尚未检查。' ? checkButton.offsetWidth + 'px' : 'auto';
    }

    matchStatusToCheckButton();

    function csrfToken() {
        var input = document.querySelector('#program-update input[name="csrf"], #program-update-csrf');
        return input ? input.value : '';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function setProgress(value) {
        progress.hidden = false;
        progress.removeAttribute('indeterminate');
        progress.value = value;
    }

    function setBusy(button, busy) {
        if (button) button.disabled = busy;
    }

    function showError(error) {
        status.textContent = error && error.message ? error.message : '更新操作失败。';
        matchStatusToCheckButton();
        progress.value = 0;
    }

    function renderUpdateFiles(data) {
        var names = Array.isArray(data.files) ? data.files : [];
        var list = names.map(escapeHtml).join('\n');
        var token = escapeHtml(csrfToken());

        filePanel.innerHTML =
            '<details class="update-file-list" open>' +
                '<summary>更新文件清单（' + names.length + ' 个）</summary>' +
                '<pre>' + list + '</pre>' +
            '</details>' +
            '<form method="post" id="program-update-download">' +
                '<input type="hidden" name="csrf" value="' + token + '">' +
                '<input type="hidden" name="action" value="download">' +
                '<button class="primary" type="submit">下载并准备更新</button>' +
            '</form>';
    }

    function checkUpdates() {
        setBusy(checkButton, true);
        setProgress(15);
        status.textContent = '正在读取 GitHub 文件清单...';
        matchStatusToCheckButton();

        fetch('/admin/extensions?action=check', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || '检查更新失败。');
                    }
                    return data;
                });
            })
            .then(function (data) {
                setProgress(35);
                if (!Array.isArray(data.files) || data.files.length === 0) {
                    status.textContent = '当前已是最新版本（' + String(data.version || '').slice(0, 12) + '）。';
                    matchStatusToCheckButton();
                    filePanel.innerHTML = '';
                    return;
                }
                status.textContent = '发现 ' + data.files.length + ' 个更新文件，版本 ' + String(data.version || '').slice(0, 12) + '。';
                matchStatusToCheckButton();
                renderUpdateFiles(data);
                setProgress(45);
            })
            .catch(showError)
            .finally(function () {
                setBusy(checkButton, false);
            });
    }

    checkButton.addEventListener('click', checkUpdates);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = form.querySelector('button[type="submit"], button');

        if (form.id === 'program-update-download') {
            setBusy(button, true);
            setProgress(60);
            status.textContent = '正在下载 GitHub 更新包，请不要关闭页面...';
            matchStatusToCheckButton();
            return;
        }

        if (form.id === 'program-update-apply') {
            setBusy(button, true);
            progress.hidden = false;
            progress.removeAttribute('value');
            status.textContent = '正在覆盖文件，请不要关闭页面...';
            matchStatusToCheckButton();
        }
    });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
