<?php
declare(strict_types=1);

function handle_market(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
$files = $request->files;
    if ($path === '/market/index.json' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $envelope = cached('market:index:v1:'.cache_version('market'), 60, static function (): array {
        $items=h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id LIMIT 5000')->fetchAll();
        $data=['schema'=>1,'generated'=>gmdate('c'),'items'=>$items];
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $private=market_signing_key();
        $signature='';if($private&&openssl_sign($json,$raw,$private,OPENSSL_ALGO_SHA256))$signature=base64_encode($raw);
        return ['payload'=>$data,'signature'=>$signature,'algorithm'=>'RSA-SHA256'];
    });
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);return;
}
if (preg_match('~^/market/package/(\d+)$~',$path,$m)&&$method==='GET') {
    // Revocation must be checked before a conditional response or file offload.
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    $item=h('SELECT package_file,sha256,package_name FROM market_submissions WHERE id=? AND status="approved"',[(int)$m[1]])->fetch();
    if(!$item)fail(404,'审核通过的扩展不存在');
    if(!preg_match('/^[a-f0-9]{48}\.zip$/',$item['package_file']))fail(404,'扩展包不存在');$file=__DIR__.'/../market-packages/'.$item['package_file'];
    if(!is_file($file)||!market_package_hash_valid($file,(string)$item['sha256']))fail(410,'扩展包校验失败');
    $etag='"'.strtolower($item['sha256']).'"';header('ETag: '.$etag);header('X-Content-Type-Options: nosniff');if(hash_equals($etag,(string)($request->server['HTTP_IF_NONE_MATCH']??''))){http_response_code(304);return;}header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($item['package_name']).'.zip"');if(getenv('LITEBBS_X_ACCEL_REDIRECT')==='1'){header('X-Accel-Redirect: /__litebbs-market-packages/'.rawurlencode((string)$item['package_file']));return;}header('Content-Length: '.filesize($file));readfile($file);return;
}
if ($path === '/market' && $method === 'GET') {
    $items = h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id DESC LIMIT 100')->fetchAll();
    $user = current_user();
    $body = '<header class="market-heading"><div><h1>开发者</h1><p>发现社区开发者提交的插件与主题。所有作品均经过管理员审核后公开。</p></div><a class="primary market-submit-link" href="'.($user ? '/market/submit' : '/login').'">'.($user ? '发布作品' : '登录后发布').'</a></header><section class="market-grid" aria-label="已审核开发者作品">';
    if (!$items) $body .= '<div class="market-empty"><h2>还没有公开作品</h2><p>成为第一个向社区分享插件或主题的开发者。</p>'.($user ? '<a href="/market/submit">提交一个作品</a>' : '<a href="/login">登录后提交</a>').'</div>';
    foreach ($items as $item) {
        $kind = $item['kind'] === 'theme' ? '主题' : '插件';
        $body .= '<article class="market-card"><div class="market-card-top"><span class="market-kind">'.e($kind).'</span><span class="market-version">v'.e($item['version']).'</span></div><h2>'.e($item['title']).'</h2><p class="market-package">'.e($item['package_name']).'</p><p>'.e($item['summary']).'</p><a class="market-download" href="/market/package/'.(int)$item['id'].'">下载作品 <span aria-hidden="true">↓</span></a></article>';
    }
    $body .= '</section>';
    page('开发者',$body,'社区开发者作品',false);
    return;
}
if ($path === '/market/submit' && in_array($method,['GET','POST'],true)) {
    $user=current_user();
    if (!$user) redirect('/login');
    if($method==='POST'){
        require_post();limit_action('market-submit',3,3600,'user:'.$user['id']);limit_action('market-submit-ip',10,3600,hash('sha256',(string)($request->server['REMOTE_ADDR']??'')));
        $kind=(string)($post['kind']??'');$name=(string)($post['package_name']??'');$title=trim((string)($post['title']??''));$version=trim((string)($post['version']??''));$summary=trim((string)($post['summary']??''));$upload=$files['package']??null;
        if(!in_array($kind,['plugin','theme'],true)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||text_length($title)<2||text_length($title)>100||text_length($summary)>500||!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($upload['size']??0)>5242880||!is_uploaded_file($upload['tmp_name'])||strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION))!=='zip')fail(400,'提交信息无效，ZIP 文件上限为 5 MiB');
         if((int)h('SELECT COUNT(*) FROM market_submissions WHERE user_id=? AND status="pending"',[$user['id']])->fetchColumn()>=10) fail(429,'待审核提交数量已达上限');
          $dir=__DIR__.'/../market-packages';if(!is_dir($dir)&&!mkdir($dir,0750,true))fail(500,'市场存储目录不可写');$stored=bin2hex(random_bytes(24)).'.zip';$target=$dir.'/'.$stored;if(!move_uploaded_file($upload['tmp_name'],$target))fail(500,'无法保存扩展包');try{$tmp=dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));try{$root=market_unpack($target,$tmp);$metadata=extension_market_manifest($root,$kind,$name);}finally{remove_tree($tmp);}if((string)$metadata['version']!==$version)throw new RuntimeException('提交版本必须与清单一致');$hash=hash_file('sha256',$target);$db=db();$db->beginTransaction();h('INSERT INTO market_submissions(user_id,kind,package_name,title,version,summary,manifest_json,package_file,sha256,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,"pending",UTC_TIMESTAMP(6))',[$user['id'],$kind,$name,$title,$version,$summary,$metadata['manifest_json'],$stored,$hash]);$id=(int)$db->lastInsertId();audit('market.submitted','market',$id,['kind'=>$kind,'name'=>$name,'version'=>$version]);$db->commit();}catch(Throwable $e){if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();@unlink($target);error_log('market submission failed: '.$e->getMessage());fail(400,'扩展包无效，请检查后重试');}redirect('/market/submit');
    }
    $rows=h('SELECT kind,title,version,status,review_note,created_at FROM market_submissions WHERE user_id=? ORDER BY id DESC LIMIT 50',[$user['id']])->fetchAll();
    $fields='<label>类型<select name="kind"><option value="plugin">插件</option><option value="theme">主题</option></select></label><label>作品 ID<input name="package_name" pattern="[a-z][a-z0-9_-]{1,31}" required></label><label>显示名称<input name="title" maxlength="100" required></label><label>版本（x.y.z）<input name="version" pattern="[0-9]+\\.[0-9]+\\.[0-9]+" required></label><label>简介<input name="summary" maxlength="500"></label><label>ZIP 包<input type="file" name="package" accept=".zip" required></label>';
    $body='<section class="market-submit"><a class="market-back" href="/market">← 返回开发者</a><h1>发布作品</h1><p>提交插件或主题后，管理员会进行审核。审核通过后会显示在开发者页面。ZIP 文件上限为 5 MiB，作品代码会以 PHP 进程权限运行，请只提交自己审查过的代码。</p><form class="form" method="post" enctype="multipart/form-data" action="/market/submit"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.$fields.'<button class="primary">提交审核</button></form><h2>我的提交</h2>';
    if (!$rows) $body .= '<p class="admin-intro">你还没有提交过作品。</p>';
     foreach ($rows as $r) { $status = ['pending'=>'审核中','approved'=>'已通过','rejected'=>'已拒绝','revoked'=>'已撤销'][$r['status']] ?? $r['status']; $body .= '<p class="market-submission">'.e($r['title']).' · '.e($r['version']).' · '.e($status).($r['review_note'] !== '' ? ' · '.e($r['review_note']) : '').'</p>'; }
    page('发布作品',$body.'</section>');
}

if ($path==='/admin/extensions'&&in_array($method,['GET','POST'],true)) {
    admin();
    if ($method==='GET' && (string)($query['action']??'')==='check') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $remote=program_update_remote();
            $local=program_update_local_files();
            $changed=[];
            foreach($remote['files'] as $file=>$sha) if (($local[$file]??null)!==$sha) $changed[]=$file;
            $state=program_update_state();
            $downloaded=is_array($state)
                && ($state['status']??'')==='downloaded'
                && ($state['version']??'')===$remote['version']
                && is_file(program_update_package());
            echo json_encode(['ok'=>true,'version'=>$remote['version'],'files'=>$changed,'downloaded'=>$downloaded],JSON_UNESCAPED_UNICODE);
        } catch(Throwable $error) { http_response_code(502); echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE); }
        return;
    }
    if($method==='POST'){
        require_post();$action=(string)($post['action']??'');
        if($action==='cleanup_cache_locks'){
            $removed=clean_old_cache_locks();
            audit('cache.locks.cleaned','cache',null,['removed'=>$removed]);
            redirect('/admin/extensions?cache_locks_cleaned='.$removed);
        }
        if($action==='cleanup_legacy_database'){
            try {
                if($action==='cleanup_legacy_database'){
                    if(empty($post['confirm_token'])) confirmation_page('确认清理残留数据库','仅删除已完成迁移且新表数据不少于旧表的旧附件表。此操作不可恢复。','database.legacy.cleanup',['action'=>'cleanup_legacy_database'],'legacy-attachments');
                    require_confirmation('database.legacy.cleanup','legacy-attachments',(string)$post['confirm_token']);
                }
                $removed=cleanup_legacy_database_tables();audit('database.legacy.cleaned','schema',null,['tables'=>$removed]);$result=$removed?'已清理旧表：'.implode('、',$removed):'没有可清理的旧表。';
                redirect('/admin/extensions?database_result='.rawurlencode($result));
            } catch(Throwable $error){fail(503,'数据库维护失败：'.$error->getMessage());}
        }
        if($action==='download') {
            $lock=program_update_lock();
            try { $remote=program_update_remote(); $target=program_update_package(); $tmp=$target.'.tmp'; program_update_download($remote['archive'],$tmp); if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('更新包保存失败');} program_update_state(['status'=>'downloaded','version'=>$remote['version'],'files'=>count($remote['files']),'sha256'=>hash_file('sha256',$target)]); redirect('/admin/extensions'); }
            catch (\LiteBBS\Framework\ResponseHalt $halt) { throw $halt; }
            catch(Throwable $error){@unlink(program_update_package().'.tmp');fail(502,'更新包下载失败：'.$error->getMessage());}
            finally { fclose($lock); }
        }
        if($action==='apply') {
            fail(403,'网页覆盖更新已禁用。请通过部署流程安装经过验证的版本。');
        }
        if($action==='migrate_database') {
            try {
                if(database_maintenance_report()['ready']) redirect('/admin/extensions');
                @set_time_limit(0);
                $changed=migrate_performance_indexes();
                audit('database.migrated','schema',null,['migration'=>'performance-indexes','changed'=>$changed]);
                redirect('/admin/extensions');
            }
            catch (\LiteBBS\Framework\ResponseHalt $halt) { throw $halt; }
            catch(Throwable $error){fail(503,'数据库优化升级失败：'.$error->getMessage());}
        }
        fail(400,'扩展操作无效');
    }
    $state=program_update_state();
    $ready=is_array($state)&&($state['status']??'')==='downloaded'&&is_file(program_update_package());
    $databaseReport=database_maintenance_report();
    $databaseCurrent=$databaseReport['ready'];
    $csrf = e(csrf());
    $cacheMaintenance = '<section class="program-update-panel" aria-labelledby="cache-maintenance-heading">'
        .'<h2 id="cache-maintenance-heading">缓存维护</h2><div class="program-update-panel-body">'
        .'<p>清理超过 48 小时的旧缓存锁文件，每次最多清理 200 个。</p>'
        .(isset($query['cache_locks_cleaned']) && is_string($query['cache_locks_cleaned']) && ctype_digit($query['cache_locks_cleaned']) ? '<p class="program-update-notice" data-state="success">已清理 '.(int)$query['cache_locks_cleaned'].' 个旧缓存锁文件。</p>' : '')
        .'<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="cleanup_cache_locks"><button type="submit">清理旧缓存锁</button></form>'
        .'</div></section>';
    $databaseResult=isset($query['database_result'])&&is_string($query['database_result'])?'<p class="program-update-notice" data-state="success">'.e($query['database_result']).'</p>':'';
    $databaseChecks='';foreach($databaseReport['checks'] as $label=>$checkReady)$databaseChecks.='<li>'.e($label).'：'.($checkReady?'已完成':'待处理').'</li>';
    $databaseContent = $databaseResult.($databaseCurrent
        ? '<p class="program-update-notice" data-state="success"><strong>已完成</strong> · 数据库结构已完成当前性能优化迁移。</p>'
        : '<p class="program-update-notice" data-state="warning"><strong>待升级</strong> · 点击下方按钮补齐待处理的数据库结构和索引。</p>'
            .'<p>不会删除论坛内容、插件数据或模板文件。数据量较大时请保持本页打开，直至自动跳转。</p>').'<ul class="database-maintenance-checks">'.$databaseChecks.'</ul>'
             .'<form method="post" class="database-upgrade-form"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="migrate_database"><button class="primary" type="submit"'.($databaseCurrent ? ' disabled' : '').'>立即升级数据库结构</button></form>'
            .'<p>表重建和碎片整理属于高负载维护操作，请在维护窗口由运维人员通过数据库工具执行。</p>';
    if($databaseReport['legacy_tables'])$databaseContent.='<p>检测到已迁移的旧附件表：'.e(implode('、',$databaseReport['legacy_tables'])).'。确认新表数据无误后再执行清理。</p><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="cleanup_legacy_database"><button type="submit">清理残留数据库表</button></form>';
    $status = $ready ? '已下载更新包，等待通过部署流程安装（GitHub 文件树 '.e((string)($state['version'] ?? '')).'）。' : '尚未检查更新。';
    $body = '<div class="program-update-page">'
        .'<section class="program-update-panel" aria-labelledby="program-update-heading">'
        .'<h2 id="program-update-heading">程序文件</h2>'
        .'<div class="program-update-panel-body" id="program-update">'
        .'<dl class="program-update-info"><div><dt>核心版本</dt><dd><code>'.e(LITEBBS_VERSION).'</code></dd></div>'
        .'<div><dt>更新源</dt><dd><a href="https://github.com/'.e(program_update_repository()).'">GitHub <code>'.e(program_update_repository()).'</code></a></dd></div></dl>'
        .'<p>可查看文件差异并下载更新包。网页覆盖更新已禁用；下载不会修改程序文件，请通过部署流程安装经过验证的版本。</p>'
        .'<input type="hidden" id="program-update-csrf" value="'.$csrf.'">'
        .'<p id="program-update-status" class="program-update-notice" data-state="'.($ready ? 'success' : 'idle').'" role="status" aria-atomic="true">'.$status.'</p>'
        .'<progress id="program-update-progress" max="100" aria-label="程序更新进度" hidden></progress>'
        .'<div class="program-update-actions"><button type="button" class="primary" id="program-update-check">检查更新</button></div>'
        .'<div id="program-update-files"></div></div></section>'
        .$cacheMaintenance
        .'<section class="program-update-panel" aria-labelledby="database-upgrade-heading">'
        .'<h2 id="database-upgrade-heading">数据库状态</h2>'
        .'<div class="program-update-panel-body">'.$databaseContent.'</div></section></div>'
        .'<script src="/assets/js/admin-update.js?v='.asset_version(__DIR__.'/../assets/js/admin-update.js').'" defer></script>';
    page('程序更新',$body);return;
}

if($path==='/admin/market'&&in_array($method,['GET','POST'],true)){
    $actor=admin();
    if($method==='POST'){require_post();$id=(int)($post['id']??0);$status=(string)($post['status']??'');$note=text_slice(trim((string)($post['review_note']??'')),0,500);if(!$id||!in_array($status,['approved','rejected','revoked'],true))fail(400,'审核操作无效');$db=db();$db->beginTransaction();try{$row=h('SELECT * FROM market_submissions WHERE id=? FOR UPDATE',[$id])->fetch();$allowed=['pending'=>['approved','rejected'],'approved'=>['revoked']];if(!$row||!in_array($status,$allowed[$row['status']]??[],true))throw new RuntimeException('状态转换无效');$package=__DIR__.'/../market-packages/'.$row['package_file'];if($status==='approved'){if(!is_file($package)||!preg_match('/^[a-f0-9]{48}\.zip$/',$row['package_file'])||!hash_equals($row['sha256'],hash_file('sha256',$package)))throw new RuntimeException('扩展包缺失或哈希不一致');$tmp=dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));try{$root=market_unpack($package,$tmp);$metadata=extension_market_manifest($root,$row['kind'],$row['package_name']);}finally{remove_tree($tmp);}if($metadata['version']!==$row['version']||$metadata['manifest_json']!==$row['manifest_json'])throw new RuntimeException('扩展清单已变化');}h('UPDATE market_submissions SET status=?,reviewer_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?',[$status,$actor['id'],$note,$id]);audit('market.reviewed','market',$id,['status'=>$status,'reviewer_id'=>(int)$actor['id']]);$db->commit();invalidate('market');}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fail(400,$e->getMessage());}redirect('/admin/market');}
    $queues = ['pending'=>'待审核','approved'=>'已通过','rejected'=>'已拒绝','revoked'=>'已撤销'];
    $queue = $query['status'] ?? 'pending';
    if (!is_string($queue) || !isset($queues[$queue])) fail(400,'审核状态无效');
    $before = $query['before'] ?? null;
    if ($before !== null && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,19}$/D', $before) || (strlen($before) === 20 && strcmp($before, '18446744073709551615') > 0))) fail(400,'分页游标无效');
    $rows = h('SELECT m.id,m.title,m.kind,m.package_name,m.version,m.summary,m.manifest_json,m.sha256,
        m.status,m.review_note,u.username,r.username AS reviewer_name
        FROM (SELECT id FROM market_submissions WHERE status=?'.($before === null ? '' : ' AND id<CAST(? AS UNSIGNED)').' ORDER BY id DESC LIMIT 101) batch
        JOIN market_submissions m ON m.id=batch.id
        JOIN users u ON u.id=m.user_id
        LEFT JOIN users r ON r.id=m.reviewer_id
        ORDER BY batch.id DESC', $before === null ? [$queue] : [$queue, $before])->fetchAll();
    $hasMore = count($rows) > 100;
    if ($hasMore) array_pop($rows);
    $body = '<p class="admin-intro">审核仅代表本站信任该代码。获批扩展在安装站点以 PHP 进程权限运行，并非沙箱。</p><nav class="dashboard-actions" aria-label="审核状态">';
    foreach ($queues as $key=>$label) $body .= '<a href="/admin/market?status='.$key.'"'.($key === $queue ? ' aria-current="page"' : '').'>'.e($label).'</a> ';
    $body .= '</nav>';
    if (!$rows) $body .= '<p class="admin-empty">暂无提交记录。</p>';
    foreach ($rows as $r) {
        $manifest = json_decode((string)$r['manifest_json'],true);
        $basics = is_array($manifest) ? ' · 清单版本 '.e((string)($manifest['version']??'')).' · 清单 ID '.e((string)($manifest['id']??$manifest['name']??'')) : '';
        $body .= '<section class="market-review"><h2>'.e($r['title']).' <small>'.e($r['kind']).' · '.e($r['package_name']).' · '.e($r['version']).'</small></h2><p>'.e($r['summary']).$basics.' · 提交者 '.e($r['username']).' · SHA-256 <code>'.e($r['sha256']).'</code></p><p>状态：'.e($r['status']).' · 审核人：'.e((string)($r['reviewer_name']??'')).' '.e($r['review_note']).'</p>';
        foreach ((['pending'=>['approved'=>'审核通过','rejected'=>'拒绝'],'approved'=>['revoked'=>'撤销公开']][$r['status']]??[]) as $st=>$label) $body .= '<form method="post" class="inline"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="'.$st.'"><input name="review_note" maxlength="500" placeholder="审核说明"><button>'.$label.'</button></form>';
        $body .= '</section>';
    }
    if ($hasMore) $body .= '<p><a rel="next" href="/admin/market?status='.$queue.'&amp;before='.e((string)end($rows)['id']).'">加载更早的记录</a></p>';
    page('扩展市场审核',$body);return;
}
}

function program_update_root(): string { return dirname(__DIR__); }
function program_update_repository(): string { return 'Lfreepayzz/LiteBBS'; }
function market_package_hash_valid(string $file, string $expectedHash): bool {
    $modified=@filemtime($file);$size=@filesize($file);
    if($modified===false||$size===false||!preg_match('/^[a-f0-9]{64}$/i',$expectedHash))return false;
    $key='market-package-hash:v1:'.strtolower($expectedHash).':'.$modified.':'.$size;
    $result=cached($key,300,static function()use($file,$expectedHash):array{$hash=hash_file('sha256',$file);return [$hash!==false&&hash_equals(strtolower($expectedHash),strtolower($hash))];});
    return !empty($result[0]);
}
function program_update_package(): string { return config_directory().'/program-update.zip'; }
function program_update_lock() {
    $file=config_directory().'/program-update.lock';$handle=@fopen($file,'c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){if($handle)fclose($handle);throw new RuntimeException('已有另一个更新任务正在执行');}return $handle;
}
function program_update_state_file(): string { return config_directory().'/program-update-state.php'; }
function program_update_state(?array $next=null): ?array {
    $file=program_update_state_file();
    if($next!==null){$tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';if(@file_put_contents($tmp,"<?php\nreturn ".var_export($next,true).";\n",LOCK_EX)===false||!@rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('无法保存更新状态');}return $next;}
    if(!is_file($file))return null;
    $value=require $file;return is_array($value)?$value:null;
}
function program_update_http(string $url): string {
    $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'ignore_errors'=>false,'header'=>"Accept: application/vnd.github+json\r\nUser-Agent: LiteBBS-updater\r\n"]]);
    $data=@file_get_contents($url,false,$context);if($data===false)throw new RuntimeException('无法连接 GitHub');return $data;
}
function program_update_remote(): array {
    $repository = program_update_repository();
    $tree=json_decode(program_update_http('https://api.github.com/repos/'.$repository.'/git/trees/main?recursive=1'),true);
    if(!is_array($tree)||!isset($tree['sha'],$tree['tree'])||!is_array($tree['tree']))throw new RuntimeException('GitHub 更新清单无效');
    $files=[];foreach($tree['tree'] as $item){$path=(string)($item['path']??'');if(($item['type']??'')!=='blob'||!program_update_path_allowed($path))continue;$sha=(string)($item['sha']??'');if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('GitHub 文件哈希无效');$files[$path]=$sha;}
    if(!$files)throw new RuntimeException('GitHub 更新清单为空');
    return ['version'=>(string)$tree['sha'],'files'=>$files,'archive'=>'https://github.com/'.$repository.'/archive/'.rawurlencode((string)$tree['sha']).'.zip'];
}
function program_update_path_allowed(string $path): bool {
    if($path===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~',$path))return false;
    foreach(['private/','uploads/','cache/','market-packages/','.git/'] as $blocked)if(str_starts_with($path,$blocked))return false;
    return !in_array($path,['config.php','install.lock'],true);
}
function program_update_local_files(): array {
    $root=program_update_root();$files=[];$walk=function(string $dir,string $prefix)use(&$walk,&$files,$root):void{foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..')continue;$relative=$prefix.$name;$full=$dir.'/'.$name;if(is_dir($full)){if(in_array($relative,['private','uploads','cache','market-packages','.git'],true))continue;$walk($full,$relative.'/');continue;}if(is_file($full)&&program_update_path_allowed($relative)){$data=@file_get_contents($full);if($data!==false)$files[$relative]=sha1('blob '.strlen($data)."\0".$data);}}};$walk($root,'');return $files;
}
function program_update_download(string $url,string $target): void {
    $in=@fopen($url,'rb',false,stream_context_create(['http'=>['timeout'=>60,'follow_location'=>1,'header'=>"User-Agent: LiteBBS-updater\r\n"]]));if(!$in)throw new RuntimeException('无法下载 GitHub 更新包');$out=@fopen($target,'wb');if(!$out){fclose($in);throw new RuntimeException('无法创建更新临时文件');}$total=0;while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false){fclose($in);fclose($out);throw new RuntimeException('更新包读取失败');}$total+=strlen($chunk);if($total>104857600){fclose($in);fclose($out);@unlink($target);throw new RuntimeException('更新包超过 100 MiB 限制');}fwrite($out,$chunk);}fclose($in);fclose($out);if($total<100)throw new RuntimeException('更新包为空');
}

function market_signing_key(): string {
    $config=load_config();
    if (!empty($config['market_signing_key'])) return (string)$config['market_signing_key'];
    $legacy=(string)h('SELECT setting_value FROM settings WHERE setting_key="market_signing_key"')->fetchColumn();
    if ($legacy==='') return '';
    $config['market_signing_key']=$legacy;
    write_config($config);
    h('DELETE FROM settings WHERE setting_key="market_signing_key"');
    return $legacy;
}
function market_unpack(string $archive,string $out):string{
    if(!class_exists('ZipArchive'))throw new RuntimeException('启用 PHP zip 扩展后才能处理扩展包');$z=new ZipArchive();if($z->open($archive)!==true)throw new RuntimeException('ZIP 包不可读');if($z->numFiles<1||$z->numFiles>200){$z->close();throw new RuntimeException('ZIP 文件数量超限');}$entries=[];$total=0;
    for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$path=(string)($s['name']??'');$size=(int)($s['size']??0);$directory=str_ends_with($path,'/');$normalized=$directory?rtrim($path,'/'):$path;$parts=explode('/',$normalized);$opsys=0;$attrs=0;$z->getExternalAttributesIndex($i,$opsys,$attrs);$unixMode=($attrs>>16)&0170000;$type=$unixMode&0170000;$total+=$size;if($normalized===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$)|//)~',$path)||count($parts)>16||isset($entries[$normalized])||$size>2097152||$total>10485760||($opsys===ZipArchive::OPSYS_UNIX&&$type!==0&&$type!==0100000&&$type!==0040000)){$z->close();throw new RuntimeException('ZIP 含重复路径、特殊文件、路径穿越或超出解压限额');}$entries[$normalized]=['directory'=>$directory,'size'=>$size,'index'=>$i];}
    foreach($entries as $path=>$entry){$parts=explode('/',$path);for($i=1;$i<count($parts);$i++){$parent=implode('/',array_slice($parts,0,$i));if(isset($entries[$parent])&&!$entries[$parent]['directory']){$z->close();throw new RuntimeException('ZIP 文件与目录路径冲突');}}if(!$entry['directory'])foreach($entries as $other=>$candidate)if($candidate['directory']&&str_starts_with($other,$path.'/')){$z->close();throw new RuntimeException('ZIP 文件与目录路径冲突');}}
    if(!mkdir($out,0700,true)){$z->close();throw new RuntimeException('临时目录创建失败');}foreach($entries as $path=>$entry){if($entry['directory'])continue;$parent=dirname($out.'/'.$path);if(!is_dir($parent)&&!mkdir($parent,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}$stat=$z->statIndex($entry['index']);$stream=$z->getStream($stat['name']);if(!$stream){$z->close();throw new RuntimeException('ZIP 文件项无法读取');}$data=stream_get_contents($stream,2097153);fclose($stream);if($data===false||strlen($data)!==$entry['size']){$z->close();throw new RuntimeException('ZIP 文件项大小不一致');}if(file_put_contents($out.'/'.$path,$data,LOCK_EX)===false){$z->close();throw new RuntimeException('无法写入扩展文件');}}$z->close();$children=array_values(array_diff(scandir($out)?:[],['.','..']));return count($children)===1&&is_dir($out.'/'.$children[0])?$out.'/'.$children[0]:$out;
}
