<?php
declare(strict_types=1);

/** Administrative core update endpoint and verified GitHub release client. */

function handle_admin_program_update(\LiteBBS\Framework\Request $request): void
{
    $path = $request->path;
    $method = $request->method;
    $query = $request->query;
    $post = $request->post;
    if ($path !== '/admin/extensions' || !in_array($method, ['GET', 'POST'], true)) return;

    admin();
    if ($method === 'GET' && ($query['action'] ?? '') === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $remote = program_update_remote();
            $changed = array_keys(program_update_changes($remote));
            echo json_encode(['ok'=>true, 'version'=>$remote['version'], 'files'=>$changed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log('program update check failed: '.$error->getMessage());
            http_response_code(502);
            echo json_encode(['ok'=>false, 'error'=>'无法检查在线更新。'], JSON_UNESCAPED_UNICODE);
        }
        return;
    }

    if ($method === 'POST') {
        require_post();
        $action = (string)($post['action'] ?? '');
        if ($action === 'cleanup_cache_locks') {
            $removed = clean_old_cache_locks();
            audit('cache.locks.cleaned', 'cache', null, ['removed'=>$removed]);
            redirect('/admin/extensions?cache_locks_cleaned='.$removed);
        }
        if ($action === 'download') {
            $lock = program_update_lock();
            try {
                @set_time_limit(0);
                $remote = program_update_remote();
                $updated = program_update_apply($remote, program_update_changes($remote));
                program_update_state(['status'=>'applied', 'version'=>$remote['version'], 'files'=>$updated, 'applied_at'=>gmdate('c')]);
                audit('program.updated', 'program', null, ['version'=>$remote['version'], 'files'=>$updated]);
                redirect('/admin/extensions?program_updated='.$updated);
            } catch (\LiteBBS\Framework\ResponseHalt $halt) {
                throw $halt;
            } catch (Throwable $error) {
                error_log('program update failed: '.$error->getMessage());
                fail(502, '在线更新失败，程序文件未完成替换。请查看服务器错误日志。');
            } finally {
                fclose($lock);
            }
        }
        fail(400, '扩展操作无效');
    }

    $csrf = e(csrf());
    $cacheMaintenance = '<section class="program-update-panel" aria-labelledby="cache-maintenance-heading"><h2 id="cache-maintenance-heading">缓存维护</h2><div class="program-update-panel-body"><p>清理超过 48 小时的旧缓存锁文件，每次最多清理 200 个。</p>'.(isset($query['cache_locks_cleaned']) && is_string($query['cache_locks_cleaned']) && ctype_digit($query['cache_locks_cleaned']) ? '<p class="program-update-notice" data-state="success">已清理 '.(int)$query['cache_locks_cleaned'].' 个旧缓存锁文件。</p>' : '').'<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="cleanup_cache_locks"><button type="submit">清理旧缓存锁</button></form></div></section>';
    $updatedResult = isset($query['program_updated']) && is_string($query['program_updated']) && ctype_digit($query['program_updated']) ? '已安装 '.$query['program_updated'].' 个程序文件。' : '';
    $status = $updatedResult !== '' ? $updatedResult : '尚未检查更新。';
    $body = '<div class="program-update-page"><section class="program-update-panel" aria-labelledby="program-update-heading"><header class="program-update-heading"><h2 id="program-update-heading">程序文件</h2><a class="program-feedback-link" href="https://bolgk.eu.org/" target="_blank" rel="noopener noreferrer">BUG反馈 <span aria-hidden="true">↗</span></a></header><div class="program-update-panel-body" id="program-update"><dl class="program-update-info"><div><dt>核心版本</dt><dd><code>'.e(LITEBBS_VERSION).'</code></dd></div><div><dt>更新源</dt><dd><a href="https://github.com/'.e(program_update_repository()).'">GitHub <code>'.e(program_update_repository()).'</code></a></dd></div></dl><p>在线更新会替换官方核心发布文件及 database/schema.sql；不会替换 database/config.php、上传、缓存、插件或模板目录。</p><input type="hidden" id="program-update-csrf" value="'.$csrf.'"><p id="program-update-status" class="program-update-notice" data-state="'.($updatedResult !== '' ? 'success' : 'idle').'" role="status" aria-atomic="true">'.$status.'</p><progress id="program-update-progress" max="100" aria-label="程序更新进度" hidden></progress><div class="program-update-actions"><button type="button" class="primary" id="program-update-check">检查更新</button></div><div id="program-update-files"></div></div></section>'.$cacheMaintenance.admin_database_upgrade_panel($query).'</div><script src="/assets/js/admin-update.js?v='.asset_version(__DIR__.'/../assets/js/admin-update.js').'" defer></script>';
    admin_page('程序更新', $body);
}

function program_update_root(): string { return dirname(__DIR__); }
function program_update_repository(): string { return 'Lfreepayzz/LiteBBS'; }
function program_update_lock() {
    $file = config_directory().'/program-update.lock';
    $handle = @fopen($file, 'c');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        if ($handle) fclose($handle);
        throw new RuntimeException('已有另一个更新任务正在执行');
    }
    return $handle;
}
function program_update_state_file(): string { return config_directory().'/program-update-state.php'; }
function program_update_state(?array $next=null): ?array {
    $file = program_update_state_file();
    if ($next !== null) {
        $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($tmp, "<?php\nreturn ".var_export($next, true).";\n", LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存更新状态');
        }
        return $next;
    }
    if (!is_file($file)) return null;
    $value = require $file;
    return is_array($value) ? $value : null;
}
function program_update_http(string $url): string {
    $context = stream_context_create(['http'=>['method'=>'GET', 'timeout'=>20, 'ignore_errors'=>false, 'header'=>"Accept: application/vnd.github+json\r\nUser-Agent: LiteBBS-updater\r\n"]]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) throw new RuntimeException('无法连接 GitHub');
    return $data;
}
function program_update_path_allowed(string $path): bool {
    if ($path === '' || strpos($path, '\\') !== false || preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?/|(^|/)\.\.?$)~', $path)) return false;
    if (!preg_match('~^(?:index\.php|install\.php|\.htaccess|PLUGIN\.md|app/(?:admin|core|kernel|plugin|scanner|share|template|update|version)\.php|assets/(?:index\.svg|css/[A-Za-z0-9._-]+\.css|js/[A-Za-z0-9._-]+\.js)|database/schema\.sql|scripts/[A-Za-z0-9._-]+\.(?:php|ps1))$~', $path)) return false;
    return true;
}
function program_update_remote(): array {
    $repository = program_update_repository();
    $tree = json_decode(program_update_http('https://api.github.com/repos/'.$repository.'/git/trees/main?recursive=1'), true);
    if (!is_array($tree) || !isset($tree['sha'], $tree['tree']) || !is_array($tree['tree'])) throw new RuntimeException('GitHub 更新清单无效');
    $files = [];
    foreach ($tree['tree'] as $item) {
        $path = (string)($item['path'] ?? '');
        if (($item['type'] ?? '') !== 'blob' || !program_update_path_allowed($path)) continue;
        $sha = (string)($item['sha'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $sha)) throw new RuntimeException('GitHub 文件哈希无效');
        $files[$path] = $sha;
    }
    if (!$files) throw new RuntimeException('GitHub 更新清单为空');
    return ['version'=>(string)$tree['sha'], 'files'=>$files, 'raw_base'=>'https://raw.githubusercontent.com/'.$repository.'/'.rawurlencode((string)$tree['sha']).'/'];
}
function program_update_local_files(): array {
    $root = program_update_root();
    $files = [];
    foreach (array_keys(program_update_core_file_paths()) as $relative) {
        $file = $root.'/'.$relative;
        if (!is_file($file) || is_link($file)) continue;
        $data = @file_get_contents($file);
        if ($data !== false) $files[$relative] = sha1('blob '.strlen($data)."\0".$data);
    }
    foreach (['assets/css', 'assets/js', 'scripts'] as $directory) {
        $fullDirectory = $root.'/'.$directory;
        foreach (is_dir($fullDirectory) ? new DirectoryIterator($fullDirectory) : [] as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = $directory.'/'.$file->getFilename();
            if (!program_update_path_allowed($relative)) continue;
            $data = @file_get_contents($file->getPathname());
            if ($data !== false) $files[$relative] = sha1('blob '.strlen($data)."\0".$data);
        }
    }
    return $files;
}
function program_update_core_file_paths(): array
{
    static $paths = [
        'index.php'=>true, 'install.php'=>true, '.htaccess'=>true, 'PLUGIN.md'=>true, 'database/schema.sql'=>true,
        'app/admin.php'=>true, 'app/core.php'=>true, 'app/kernel.php'=>true, 'app/plugin.php'=>true, 'app/scanner.php'=>true, 'app/share.php'=>true, 'app/template.php'=>true, 'app/update.php'=>true, 'app/version.php'=>true,
    ];
    return $paths;
}
function program_update_changes(array $remote): array {
    $local = program_update_local_files();
    $changed = [];
    foreach ($remote['files'] as $file=>$sha) if (($local[$file] ?? null) !== $sha) $changed[$file] = $sha;
    return $changed;
}
function program_update_apply(array $remote, array $changed): int {
    $root = program_update_root();
    $stage = config_directory().'/program-update-stage-'.bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700, true)) throw new RuntimeException('无法创建更新暂存目录');
    $staged = [];
    try {
        foreach ($changed as $relative=>$sha) {
            if (!program_update_path_allowed($relative) || !preg_match('/^[a-f0-9]{40}$/', $sha)) throw new RuntimeException('更新文件清单无效');
            $data = program_update_http((string)$remote['raw_base'].implode('/', array_map('rawurlencode', explode('/', $relative))));
            if (!hash_equals($sha, sha1('blob '.strlen($data)."\0".$data))) throw new RuntimeException('更新文件校验失败：'.$relative);
            $staged[$relative] = $stage.'/'.hash('sha256', $relative);
            if (file_put_contents($staged[$relative], $data, LOCK_EX) === false) throw new RuntimeException('无法暂存更新文件：'.$relative);
        }

        $applied = [];
        foreach ($staged as $relative=>$source) {
            $destination = $root.'/'.$relative;
            if (is_link($destination)) throw new RuntimeException('拒绝覆盖符号链接：'.$relative);
            $parent = dirname($destination);
            $parentReal = realpath($parent);
            $rootReal = realpath($root);
            if ($rootReal === false || ($parentReal !== false && !str_starts_with(str_replace('\\', '/', $parentReal).'/', str_replace('\\', '/', $rootReal).'/'))) throw new RuntimeException('更新目标目录无效：'.$relative);
            if (!is_dir($parent) && !@mkdir($parent, 0750, true)) throw new RuntimeException('无法创建程序目录：'.$relative);
            $backup = $stage.'/backup-'.hash('sha256', $relative);
            $hadOriginal = is_file($destination);
            if ($hadOriginal && !@rename($destination, $backup)) throw new RuntimeException('无法备份程序文件：'.$relative);
            if (!@rename($source, $destination)) {
                if ($hadOriginal) @rename($backup, $destination);
                throw new RuntimeException('无法覆盖文件：'.$relative);
            }
            $applied[] = ['destination'=>$destination, 'backup'=>$backup, 'had_original'=>$hadOriginal];
        }
        return count($applied);
    } catch (Throwable $error) {
        if (isset($applied)) {
            foreach (array_reverse($applied) as $file) {
                @unlink($file['destination']);
                if ($file['had_original']) @rename($file['backup'], $file['destination']);
            }
        }
        throw $error;
    } finally {
        remove_tree($stage);
    }
}
