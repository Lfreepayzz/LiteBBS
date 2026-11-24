(function () {
    'use strict';

    function init() {
        var update = document.getElementById('program-update');
        var checkButton = document.getElementById('program-update-check');
        var status = document.getElementById('program-update-status');
        var progress = document.getElementById('program-update-progress');
        var filePanel = document.getElementById('program-update-files');
        var csrf = document.getElementById('program-update-csrf');

        if (!update || !checkButton || !status || !progress || !filePanel || !csrf) return;

        function setStatus(state, message) {
            status.dataset.state = state;
            status.textContent = message;
            progress.hidden = state !== 'busy';
        }

        function renderUpdateFiles(names) {
            var details = document.createElement('details');
            details.className = 'update-file-list';
            details.open = true;

            var summary = document.createElement('summary');
            summary.textContent = '更新文件清单（' + names.length + ' 个）';
            var list = document.createElement('pre');
            list.textContent = names.join('\n');
            details.append(summary, list);

            var form = document.createElement('form');
            form.method = 'post';
            form.id = 'program-update-download';

            var token = document.createElement('input');
            token.type = 'hidden';
            token.name = 'csrf';
            token.value = csrf.value;

            var action = document.createElement('input');
            action.type = 'hidden';
            action.name = 'action';
            action.value = 'download';

            var button = document.createElement('button');
            button.className = 'primary';
            button.type = 'submit';
            button.textContent = '下载并准备更新';
            form.append(token, action, button);
            filePanel.replaceChildren(details, form);
        }

        function checkUpdates() {
            checkButton.disabled = true;
            setStatus('busy', '正在读取 GitHub 文件清单…');

            fetch('/admin/extensions?action=check', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data || !data.ok) {
                            throw new Error((data && data.error) || '检查更新失败。');
                        }
                        return data;
                    }, function () {
                        throw new Error('无法读取更新检查结果。');
                    });
                })
                .then(function (data) {
                    var revision = data.version ? '（GitHub 文件树 ' + String(data.version).slice(0, 12) + '）' : '';
                    if (!Array.isArray(data.files) || data.files.length === 0) {
                        filePanel.replaceChildren();
                        setStatus('success', '未发现需要更新的程序文件' + revision + '。');
                        return;
                    }
                    renderUpdateFiles(data.files);
                    setStatus('available', '发现 ' + data.files.length + ' 个有差异的文件' + revision + '。');
                })
                .catch(function (error) {
                    setStatus('error', error && error.message ? error.message : '检查更新失败。');
                })
                .finally(function () {
                    checkButton.disabled = false;
                });
        }

        checkButton.addEventListener('click', checkUpdates);

        update.addEventListener('submit', function (event) {
            var form = event.target;
            var message;
            if (form.id === 'program-update-download') {
                message = '正在下载 GitHub 更新包，请不要关闭页面…';
            } else if (form.id === 'program-update-apply') {
                message = '正在覆盖文件，请不要关闭页面…';
            } else {
                return;
            }

            var button = form.querySelector('button[type="submit"], button');
            if (button) button.disabled = true;
            setStatus('busy', message);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
