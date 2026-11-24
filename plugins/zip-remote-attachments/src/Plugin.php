<?php
declare(strict_types=1);

final class ZipRemoteAttachmentsPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        $context->assets()->css('assets/style.css');
        $context->slots()->add('topic.create.fields', static fn(array $view): string => '<details class="plugin-attachment-fields"><summary><span><strong>添加 ZIP / 远程附件</strong><small>可选，仅支持一个 ZIP 文件或一个远程 ZIP 地址</small></span><span class="plugin-attachment-badge">ZIP ONLY</span></summary><div class="plugin-attachment-content"><div class="plugin-attachment-heading"><div><h2>选择附件来源</h2><p>本地上传和远程地址二选一，远程文件必须使用 HTTPS。</p></div></div><div class="plugin-attachment-sources"><label><span>本地 ZIP 文件</span><input type="file" name="plugin_zip_attachment" accept=".zip,application/zip,application/x-zip-compressed"><small>单个文件最大 10 MiB</small></label><span class="plugin-attachment-or" aria-hidden="true">或</span><label><span>远程 ZIP 地址</span><input type="url" name="plugin_remote_attachment" maxlength="2048" placeholder="https://example.com/file.zip"><small>仅接受以 .zip 结尾的 HTTPS 地址</small></label></div><div class="plugin-attachment-access"><label><span>下载权限</span><select name="plugin_attachment_access"><option value="login">登录后下载</option><option value="reply">回复主题后下载</option><option value="points">积分购买后下载</option></select><small>下载者需满足所选条件。</small></label><label><span>积分价格</span><input type="number" name="plugin_attachment_points" min="1" max="100000" value="1" inputmode="numeric"><small>仅“积分购买后下载”时扣除积分。</small></label></div></div></details>');
        $context->slots()->add('post.edit.fields', static function (array $view): string { return zip_attachment_render_edit($view); });
        $context->events()->listen('topic.create.preflight', static function (array $event): void { zip_attachment_prepare_create($event); });
        $context->events()->listen('topic.created', static function (array $event): void { zip_attachment_create($event); });
        $context->events()->listen('post.created', static function (array $event): void { zip_attachment_grant_reply($event); });
        $context->events()->listen('post.updated', static function (array $event): void { zip_attachment_update($event); });
        $context->preloads()->add('post.after-body', static function (array $view): void { zip_attachment_preload($view); });
        $context->slots()->add('post.after-body', static function (array $view): string { return zip_attachment_render($view); }, 20);
        $context->routes()->front('GET', '~^/attachments/download/(?P<id>[0-9]+)$~', static function (\LiteBBS\Framework\Request $request, array $params): \LiteBBS\Framework\Response { return zip_attachment_download((int)$params['id']); });
        $context->routes()->front('POST', '~^/attachments/unlock/(?P<id>[0-9]+)$~', static function (\LiteBBS\Framework\Request $request, array $params): \LiteBBS\Framework\Response {
            require_post();
            return zip_attachment_unlock((int)$params['id']);
        });
        $context->routes()->front('POST', '~^/attachments/external-download/(?P<id>[0-9]+)$~', static function (\LiteBBS\Framework\Request $request, array $params): \LiteBBS\Framework\Response {
            require_post();
            return zip_attachment_external_download((int)$params['id']);
        });
    }
}

function &zip_attachment_request_cache(): array
{
    static $cache = ['files'=>[], 'grants'=>[], 'prepared'=>[]];
    return $cache;
}

function zip_attachment_preload(array $view): void
{
    $posts = $view['posts'] ?? []; $viewer = $view['viewer'] ?? null;
    $ids = array_values(array_unique(array_map(static fn(array $post): int => (int)$post['id'], $posts)));
    if (!$ids) return;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $cache =& zip_attachment_request_cache();
    foreach (h('SELECT * FROM plg_zip_remote_attachments_files WHERE post_id IN ('.$marks.') AND status="active" ORDER BY id', $ids)->fetchAll() as $file) $cache['files'][(int)$file['post_id']][] = $file;
    if ($viewer) foreach (h('SELECT attachment_id FROM plg_zip_remote_attachments_grants WHERE user_id=? AND attachment_id IN (SELECT id FROM plg_zip_remote_attachments_files WHERE post_id IN ('.$marks.') AND status="active")', array_merge([(int)$viewer['id']], $ids))->fetchAll(PDO::FETCH_COLUMN) as $attachmentId) $cache['grants'][(int)$attachmentId] = true;
}

function zip_attachment_prepare_key(array $event): string
{
    $file=$event['files']['plugin_zip_attachment']??[];
    return hash('sha256',(string)($event['user']['id']??'').'|'.(string)($file['tmp_name']??'').'|'.(string)($event['post']['plugin_remote_attachment']??''));
}

function zip_attachment_prepare_create(array $event): void
{
    if (empty($event['post']) || empty($event['user']['id'])) return;
    $file = $event['files']['plugin_zip_attachment'] ?? null;
    $remote = trim((string)($event['post']['plugin_remote_attachment'] ?? ''));
    if ((!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) && $remote === '') return;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $remote !== '') fail(400, 'ZIP 文件和远程地址只能选择一种');
    $mode = (string)($event['post']['plugin_attachment_access'] ?? 'login');
    $points = (int)($event['post']['plugin_attachment_points'] ?? 0);
    if (!in_array($mode, ['login','reply','points'], true) || ($mode === 'points' && ($points < 1 || $points > 100000))) fail(400, '附件访问设置无效');
    $kind = 'remote'; $name = ''; $size = null; $hash = null;
    if ($remote !== '') {
        $parts = parse_url($remote);
        if (!zip_attachment_remote_url_valid($remote)) fail(400, '远程附件必须是可公开访问的 HTTPS ZIP 地址');
        $name = basename((string)$parts['path']);
    } else {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] < 1 || (int)$file['size'] > 10485760) fail(400, 'ZIP 文件无效或超过 10 MiB');
        $name = trim(basename(str_replace('\\', '/', (string)$file['name'])));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
        if ($name === '' || text_length($name) > 255 || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip' || !in_array($mime, ['application/zip','application/x-zip-compressed'], true)) fail(400, '只能上传 ZIP 文件');
        zip_attachment_validate_archive((string)$file['tmp_name'], (int)$file['size']);
        $kind = 'upload'; $size = (int)$file['size']; $hash = hash_file('sha256', (string)$file['tmp_name']);
        if ($hash === false) fail(400, 'ZIP 文件校验失败');
    }
    $cache =& zip_attachment_request_cache();
    $cache['prepared'][zip_attachment_prepare_key($event)]=['kind'=>$kind,'remote'=>$remote,'name'=>$name,'size'=>$size,'hash'=>$hash,'mode'=>$mode,'points'=>$points,'tmp_name'=>$kind==='upload'?(string)$file['tmp_name']:''];
}

function zip_attachment_create(array $event): void
{
    $cache =& zip_attachment_request_cache();
    $key=zip_attachment_prepare_key($event);
    if (!isset($cache['prepared'][$key])) zip_attachment_prepare_create($event);
    $prepared=$cache['prepared'][$key]??null;
    if (!is_array($prepared)) return;
    $storage = null;
    $storedPath = null;
    try {
        if ($prepared['kind'] === 'upload') {
            // Keep the quota check and record insertion under the same user-row
            // lock so concurrent topic submissions cannot exceed the quota.
            zip_attachment_check_user_quota((int)$event['user']['id'], (int)$prepared['size']);
            $directory = dirname(__DIR__, 3).'/uploads/plugin-zip';
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) fail(500, '插件附件目录不可用');
            $storage = bin2hex(random_bytes(32));
            $storedPath = $directory.'/'.$storage;
            if (!move_uploaded_file((string)$prepared['tmp_name'], $storedPath)) fail(500, 'ZIP 文件保存失败');
        }
        h('INSERT INTO plg_zip_remote_attachments_files(post_id,user_id,source_kind,storage_name,remote_url,original_name,file_size,sha256,access_mode,point_cost,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [(int)$event['post_id'], (int)$event['user']['id'], $prepared['kind'], $storage, $prepared['remote'] ?: null, $prepared['name'], $prepared['size'], $prepared['hash'], $prepared['mode'], $prepared['mode'] === 'points' ? $prepared['points'] : 0]);
    } catch (Throwable $error) {
        if ($storedPath !== null) @unlink($storedPath);
        throw $error;
    }
}

function zip_attachment_grant_reply(array $event): void
{
    if (empty($event['topic_id']) || empty($event['user']['id'])) return;
    h('INSERT IGNORE INTO plg_zip_remote_attachments_grants(user_id,attachment_id,grant_kind,created_at) SELECT ?,id,"reply",UTC_TIMESTAMP(6) FROM plg_zip_remote_attachments_files WHERE post_id=(SELECT MIN(id) FROM posts WHERE topic_id=?) AND access_mode="reply" AND status="active"', [(int)$event['user']['id'], (int)$event['topic_id']]);
}

function zip_attachment_render_edit(array $view): string
{
    $post = $view['post'] ?? [];
    if (empty($post['id'])) return '';
    $file = h('SELECT * FROM plg_zip_remote_attachments_files WHERE post_id=? AND status="active" ORDER BY id LIMIT 1', [(int)$post['id']])->fetch();
    $mode = (string)($file['access_mode'] ?? 'login');
    $points = max(1, (int)($file['point_cost'] ?? 1));
    $options = '';
    foreach (['login'=>'登录后下载','reply'=>'回复主题后下载','points'=>'积分购买后下载'] as $value=>$label) {
        $options .= '<option value="'.$value.'"'.($mode===$value?' selected':'').'>'.$label.'</option>';
    }
    $current = $file
        ? '<div class="plugin-attachment-current"><span>当前附件</span><strong>'.e($file['original_name']).'</strong><small>'.($file['source_kind']==='remote'?'远程 ZIP':'本地 ZIP').'</small><label class="plugin-attachment-remove"><input type="checkbox" name="plugin_attachment_remove" value="1"> 删除当前附件</label></div>'
        : '<div class="plugin-attachment-current is-empty"><span>当前附件</span><strong>尚未添加附件</strong></div>';
    return '<details class="plugin-attachment-fields plugin-attachment-edit" open><summary><span><strong>ZIP / 远程附件</strong><small>管理该帖子的附件与下载权限</small></span><span class="plugin-attachment-badge">EDIT</span></summary><div class="plugin-attachment-content">'.$current.'<div class="plugin-attachment-sources plugin-attachment-edit-source"><label><span>'.($file?'替换为远程 ZIP':'添加远程 ZIP').'</span><input type="url" name="plugin_remote_attachment" maxlength="2048" placeholder="https://example.com/file.zip"><small>编辑页支持远程 ZIP；本地 ZIP 请在发布主题时上传。</small></label></div><div class="plugin-attachment-access"><label><span>下载权限</span><select name="plugin_attachment_access">'.$options.'</select><small>下载者需满足所选条件。</small></label><label><span>积分价格</span><input type="number" name="plugin_attachment_points" min="1" max="100000" value="'.$points.'" inputmode="numeric"><small>仅“积分购买后下载”时扣除积分。</small></label></div></div></details>';
}

function zip_attachment_update(array $event): void
{
    $postId = (int)($event['post_id'] ?? 0);
    if ($postId < 1) return;
    $input = $event['post'] ?? [];
    $remoteInput = $input['plugin_remote_attachment'] ?? null;
    $removeInput = $input['plugin_attachment_remove'] ?? null;
    $modeInput = $input['plugin_attachment_access'] ?? null;
    $pointsInput = $input['plugin_attachment_points'] ?? null;
    if ($remoteInput === null && $removeInput === null && $modeInput === null && $pointsInput === null) return;

    $file = h('SELECT * FROM plg_zip_remote_attachments_files WHERE post_id=? AND status="active" ORDER BY id LIMIT 1 FOR UPDATE', [$postId])->fetch();
    $remote = is_string($remoteInput) ? trim($remoteInput) : '';
    $remove = is_string($removeInput) && $removeInput === '1';
    $mode = is_string($modeInput) ? $modeInput : (string)($file['access_mode'] ?? 'login');
    $points = is_string($pointsInput) ? (int)$pointsInput : (int)($file['point_cost'] ?? 0);
    if (!in_array($mode, ['login','reply','points'], true) || ($mode === 'points' && ($points < 1 || $points > 100000))) fail(400, '附件访问设置无效');
    if ($remote !== '' && !zip_attachment_remote_url_valid($remote)) fail(400, '远程附件必须是可公开访问的 HTTPS ZIP 地址');
    if ($remote !== '' && !$remove && $file) fail(400, '替换附件时请勾选删除当前附件');

    if ($file && ($remove || $remote !== '')) {
        h('UPDATE plg_zip_remote_attachments_files SET status="removed" WHERE id=?', [(int)$file['id']]);
        $file = null;
    } elseif ($file) {
        if ($mode !== (string)$file['access_mode']) {
            h('DELETE FROM plg_zip_remote_attachments_grants WHERE attachment_id=? AND grant_kind="reply"', [(int)$file['id']]);
        }
        h('UPDATE plg_zip_remote_attachments_files SET access_mode=?,point_cost=? WHERE id=?', [$mode,$mode==='points'?$points:0,(int)$file['id']]);
    }
    if ($remote !== '') {
        $name = basename((string)parse_url($remote, PHP_URL_PATH));
        $ownerId = (int)h('SELECT user_id FROM posts WHERE id=? AND status="normal"', [$postId])->fetchColumn();
        if ($ownerId < 1) fail(404, '帖子不存在');
        h('INSERT INTO plg_zip_remote_attachments_files(post_id,user_id,source_kind,storage_name,remote_url,original_name,file_size,sha256,access_mode,point_cost,created_at) VALUES(?,? ,"remote",NULL,?,?,NULL,NULL,?,?,UTC_TIMESTAMP(6))', [$postId,$ownerId,$remote,$name,$mode,$mode==='points'?$points:0]);
    }
}

function zip_attachment_render(array $view): string
{
    $post = $view['post'] ?? []; $viewer = $view['viewer'] ?? null;
    if (empty($post['id'])) return '';
    $cache =& zip_attachment_request_cache();
    $files = $cache['files'][(int)$post['id']] ?? [];
    if (!$files) return '';
    $html = '<section class="plugin-attachments">';
    foreach ($files as $file) {
        $grant = !empty($cache['grants'][(int)$file['id']]);
        $allowed = $viewer && (($viewer['role'] ?? '') === 'admin' || (int)$viewer['id'] === (int)$file['user_id'] || $file['access_mode'] === 'login' || $grant);
        $label = $file['access_mode'] === 'reply' ? '回复后下载' : ($file['access_mode'] === 'points' ? $file['point_cost'].' 积分购买' : '登录后下载');
        $html .= '<article><div><strong>'.e($file['original_name']).'</strong><small>'.e($label).'</small></div>';
        if ($allowed) $html .= '<a class="attachment-download" href="/attachments/download/'.(int)$file['id'].'">下载附件</a>';
        elseif (!$viewer) $html .= '<a class="attachment-download" href="/login">登录后下载</a>';
        else $html .= '<form method="post" action="/attachments/unlock/'.(int)$file['id'].'"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button class="attachment-download">'.e($file['access_mode'] === 'points' ? '购买下载' : $label).'</button></form>';
        $html .= '</article>';
    }
    return $html.'</section>';
}

function zip_attachment_download(int $id): \LiteBBS\Framework\Response
{
    $viewer = current_user();
    if (!$viewer) redirect('/login');
    $file = zip_attachment_available_to($id, $viewer);
    if ($file['source_kind'] === 'remote') {
        $host = (string)(parse_url((string)$file['remote_url'], PHP_URL_HOST) ?: '');
        $body = '<section class="plugin-attachments"><h1>即将离开本站</h1><p>该附件托管在外部网站 <strong>'.e($host).'</strong>。请确认该来源可信后继续下载。</p><form method="post" action="/attachments/external-download/'.$id.'"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button class="attachment-download">继续前往外部下载</button></form></section>';
        ob_start();
        try {
            page('即将离开本站', $body);
            return new \LiteBBS\Framework\Response((string)ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
    $path = dirname(__DIR__, 3).'/uploads/plugin-zip/'.basename((string)$file['storage_name']);
    if (!is_file($path)) fail(404, '附件文件不存在');
    $downloadName=rawurlencode((string)$file['original_name']);
    if (getenv('LITEBBS_X_ACCEL_REDIRECT') === '1') return (new \LiteBBS\Framework\Response(''))->header('Content-Type','application/zip')->header('Cache-Control','private, no-store')->header('Pragma','no-cache')->header('X-Content-Type-Options','nosniff')->header('Content-Disposition',"attachment; filename*=UTF-8''".$downloadName)->header('X-Accel-Redirect','/__litebbs-uploads/plugin-zip/'.rawurlencode(basename((string)$file['storage_name'])));
    return (new \LiteBBS\Framework\Response())
        ->header('Content-Type', 'application/zip')
        ->header('Cache-Control', 'private, no-store')
        ->header('Pragma', 'no-cache')
        ->header('X-Content-Type-Options', 'nosniff')
        ->header('Content-Disposition', "attachment; filename*=UTF-8''".$downloadName)
        ->header('Content-Length', (string)filesize($path))
        ->stream(static function () use ($path): void { readfile($path); });
}

function zip_attachment_external_download(int $id): \LiteBBS\Framework\Response
{
    $viewer = current_user();
    if (!$viewer) redirect('/login');
    $file = zip_attachment_available_to($id, $viewer);
    if ($file['source_kind'] !== 'remote') fail(400, '该附件不是远程附件');
    $url = (string)$file['remote_url'];
    if (!zip_attachment_remote_url_valid($url)) fail(400, '远程附件地址无效');
    redirect_external_https($url, 303);
}

function zip_attachment_validate_archive(string $path, int $compressedBytes): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) fail(400, 'ZIP 文件结构无效');
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 1000) fail(400, 'ZIP 文件数量超出限制');
        $total = 0;
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $entryName = is_array($entry) ? (string)($entry['name'] ?? '') : '';
            $size = is_array($entry) ? (int)($entry['size'] ?? -1) : -1;
            if ($entryName === '' || str_contains($entryName, "\0") || str_contains($entryName, '\\') || str_starts_with($entryName, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $entryName) || isset($names[$entryName]) || $size < 0 || $size > 20971520) fail(400, 'ZIP 包含不安全或过大的文件');
            $names[$entryName] = true;
            $total += $size;
            if ($total > 52428800 || ($compressedBytes > 0 && $total > $compressedBytes * 50)) fail(400, 'ZIP 解压后体积超出限制');
            $operatingSystem = 0;
            $attributes = 0;
            if (method_exists($zip, 'getExternalAttributesIndex') && $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                if ((($attributes >> 16) & 0xF000) === 0xA000) fail(400, 'ZIP 不允许符号链接');
            }
        }
    } finally {
        $zip->close();
    }
}

function zip_attachment_remote_url_valid(string $url): bool
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || !preg_match('/\.zip$/i', (string)($parts['path'] ?? ''))) return false;
    if (strlen(basename((string)$parts['path'])) > 255 || request_url_parts($url) === null) return false;
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    return (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
}

function zip_attachment_visible_file(int $id, bool $lock = false): array
{
    $file = h('SELECT f.*,p.topic_id FROM plg_zip_remote_attachments_files f JOIN posts p ON p.id=f.post_id JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE f.id=? AND f.status="active" AND p.status="normal" AND t.status="normal" AND c.is_visible=1'.($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    if (!$file) fail(404, '附件不存在');
    return $file;
}

function zip_attachment_has_access(array $file, array $viewer): bool
{
    if (($viewer['role'] ?? '') === 'admin' || (int)$viewer['id'] === (int)$file['user_id'] || $file['access_mode'] === 'login') return true;
    return (bool)h('SELECT 1 FROM plg_zip_remote_attachments_grants WHERE user_id=? AND attachment_id=?', [(int)$viewer['id'], (int)$file['id']])->fetchColumn();
}

function zip_attachment_available_to(int $id, array $viewer): array
{
    $file = zip_attachment_visible_file($id);
    if (!zip_attachment_has_access($file, $viewer)) fail(403, '无权下载附件');
    return $file;
}

function zip_attachment_check_user_quota(int $userId, int $incomingBytes): void
{
    // Lock the user row so concurrent topic submissions cannot bypass this quota.
    h('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId])->fetchColumn();
    $usage = h('SELECT COUNT(*) AS files,COALESCE(SUM(file_size),0) AS bytes FROM plg_zip_remote_attachments_files WHERE user_id=? AND source_kind="upload" AND status="active"', [$userId])->fetch();
    if ((int)$usage['files'] >= 5 || (int)$usage['bytes'] + $incomingBytes > 52428800) fail(413, '本地 ZIP 附件数量或存储配额已达上限');
}

function zip_attachment_unlock(int $id): \LiteBBS\Framework\Response
{
    $viewer = current_user();
    if (!$viewer) redirect('/login');
    $userId = (int)$viewer['id'];
    $db = db();
    $db->beginTransaction();
    try {
        $file = zip_attachment_visible_file($id, true);
        if (!zip_attachment_has_access($file, $viewer)) {
            $legacyKey = 'plugin-attachment-purchase-'.$userId.'-'.$id;
            // Restore purchases whose grants were removed by older plugin versions.
            $purchased = (bool)h('SELECT 1 FROM point_ledger WHERE event_key=? AND created_at>=?', [$legacyKey, $file['created_at']])->fetchColumn();
            $grantKind = 'purchase';
            if (!$purchased && $file['access_mode'] === 'reply') {
                $topicId = (int)$file['topic_id'];
                $replied = (bool)h('SELECT 1 FROM posts WHERE topic_id=? AND user_id=? AND status="normal" AND id<>(SELECT MIN(id) FROM posts WHERE topic_id=? AND status="normal") LIMIT 1', [$topicId, $userId, $topicId])->fetchColumn();
                if (!$replied) fail(403, '回复主题后才能下载');
                $grantKind = 'reply';
            } elseif (!$purchased) {
                $price = (int)$file['point_cost'];
                $points = (int)h('SELECT points FROM users WHERE id=? FOR UPDATE', [$userId])->fetchColumn();
                if ($points < $price) fail(403, '积分不足');
                h('UPDATE users SET points=points-? WHERE id=?', [$price, $userId]);
                $eventKey = 'attachment-purchase:'.hash('sha256', $legacyKey.'|'.$file['created_at']);
                h('INSERT INTO point_ledger(user_id,delta,reason,ref_type,ref_id,event_key,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$userId, -$price, '附件购买', 'plugin_attachment', $id, $eventKey]);
            }
            h('INSERT INTO plg_zip_remote_attachments_grants(user_id,attachment_id,grant_kind,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$userId, $id, $grantKind]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    redirect('/attachments/download/'.$id);
}
