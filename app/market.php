<?php
declare(strict_types=1);

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
    $item=h('SELECT package_file,sha256,kind,package_name FROM market_submissions WHERE id=? AND status="approved"',[(int)$m[1]])->fetch();
    if(!$item)fail(404,'审核通过的扩展不存在');
    if(!preg_match('/^[a-f0-9]{48}\.zip$/',$item['package_file']))fail(404,'扩展包不存在');$file=__DIR__.'/../market-packages/'.$item['package_file'];
    if(!is_file($file)||!hash_equals($item['sha256'],hash_file('sha256',$file)))fail(410,'扩展包校验失败');
    $etag='"'.strtolower($item['sha256']).'"';header('ETag: '.$etag);header('Cache-Control: public, max-age=300');header('X-Content-Type-Options: nosniff');if(hash_equals($etag,(string)($_SERVER['HTTP_IF_NONE_MATCH']??''))){http_response_code(304);return;}header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($item['package_name']).'.zip"');if(getenv('LITEBBS_X_ACCEL_REDIRECT')==='1'){header('X-Accel-Redirect: /__litebbs-market-packages/'.rawurlencode((string)$item['package_file']));return;}header('Content-Length: '.filesize($file));readfile($file);return;
}
if ($path === '/market' && $method === 'GET') {
    $items = h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id DESC LIMIT 100')->fetchAll();
    $user = current_user();
    $body = '<header class="market-heading"><div><p class="eyebrow">DEVELOPER COMMUNITY</p><h1>开发者</h1><p>发现社区开发者提交的插件与主题。所有作品均经过管理员审核后公开。</p></div><a class="primary market-submit-link" href="'.($user ? '/market/submit' : '/login').'">'.($user ? '发布作品' : '登录后发布').'</a></header><section class="market-grid" aria-label="已审核开发者作品">';
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
        require_post();limit_action('market-submit',3,3600,'user:'.$user['id']);limit_action('market-submit-ip',10,3600,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')));
        $kind=(string)($_POST['kind']??'');$name=(string)($_POST['package_name']??'');$title=trim((string)($_POST['title']??''));$version=trim((string)($_POST['version']??''));$summary=trim((string)($_POST['summary']??''));$upload=$_FILES['package']??null;
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
    $actor=admin();$config=load_config();
    if ($method==='GET' && (string)($_GET['action']??'')==='check') {
        try {
            $remote=program_update_remote();
            $local=program_update_local_files();
            $changed=[];
            foreach($remote['files'] as $file=>$sha) if (($local[$file]??null)!==$sha) $changed[]=$file;
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok'=>true,'version'=>$remote['version'],'files'=>$changed,'downloaded'=>is_file(program_update_package())],JSON_UNESCAPED_UNICODE);
        } catch(Throwable $error) { http_response_code(502); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if($method==='POST'){
        require_post();$action=(string)($_POST['action']??'');
        if($action==='download') {
            $lock=program_update_lock();
            try { $remote=program_update_remote(); $target=program_update_package(); $tmp=$target.'.tmp'; program_update_download($remote['archive'],$tmp); if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('更新包保存失败');} program_update_state(['status'=>'downloaded','version'=>$remote['version'],'files'=>count($remote['files']),'sha256'=>hash_file('sha256',$target)]); redirect('/admin/extensions'); }
            catch(Throwable $error){@unlink(program_update_package().'.tmp');fclose($lock);fail(502,'更新包下载失败：'.$error->getMessage());}
            fclose($lock);
        }
        if($action==='apply') {
            $lock=program_update_lock();
            try { $remote=program_update_remote(); $state=program_update_state(); if(!is_file(program_update_package())||!is_array($state)||($state['status']??'')!=='downloaded'||($state['version']??'')!==$remote['version']||($state['sha256']??'')!==hash_file('sha256',program_update_package())) throw new RuntimeException('更新包已过期，请重新检查并下载'); program_update_apply(program_update_package(),$remote); fclose($lock); redirect('/admin/extensions'); }
            catch(Throwable $error){fclose($lock);fail(500,'程序更新失败：'.$error->getMessage());}
        }
        fail(400,'扩展操作无效');
    }
    $state=program_update_state();
    $ready=is_array($state)&&($state['status']??'')==='downloaded'&&is_file(program_update_package());
    $body='<section class="market-review"><h2>程序更新</h2><p>更新源：GitHub <code>Lfreepayzz/LiteBBS</code>。检查后会显示远程文件清单，确认后下载并覆盖程序文件；私有配置、上传文件、缓存和数据库不会被覆盖。</p><div id="program-update"><button type="button" class="primary" id="program-update-check">检查更新</button><p id="program-update-status" class="admin-intro">'.($ready?'已准备版本 '.e((string)($state['version']??'')).'，可以覆盖更新。':'尚未检查。').'</p><progress id="program-update-progress" max="100" value="'.($ready?'45':'0').'" '.($ready?'':'hidden').'></progress><div id="program-update-files">'.($ready?'<form method="post" id="program-update-apply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="apply"><button class="primary">立即覆盖更新</button></form>':'').'</div></div></section><script>const check=document.getElementById("program-update-check"),status=document.getElementById("program-update-status"),progress=document.getElementById("program-update-progress"),files=document.getElementById("program-update-files");check.onclick=async()=>{check.disabled=true;progress.hidden=false;progress.value=15;status.textContent="正在读取 GitHub 文件清单...";try{const response=await fetch("/admin/extensions?action=check",{cache:"no-store"}),data=await response.json();if(!data.ok)throw new Error(data.error||"检查失败");progress.value=35;if(!data.files.length){status.textContent="当前已是最新版本（"+data.version.slice(0,12)+"）。";return;}status.textContent="发现 "+data.files.length+" 个更新文件，版本 "+data.version.slice(0,12)+"。";const escaped=data.files.map(x=>x.replace(/[&<>]/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[m]))).join("\\n");files.innerHTML="<details open><summary>更新文件清单</summary><pre>"+escaped+"</pre></details><form method=post id=program-update-download><input type=hidden name=csrf value="+document.querySelector("input[name=csrf]").value+"><input type=hidden name=action value=download><button class=primary>下载并准备更新</button></form>";progress.value=45;}catch(error){status.textContent=error.message;progress.value=0;}finally{check.disabled=false;}};document.addEventListener("submit",event=>{if(event.target.matches("#program-update-download")){progress.hidden=false;progress.value=60;status.textContent="正在下载 GitHub 更新包，请不要关闭页面...";event.target.querySelector("button").disabled=true;}if(event.target.matches("#program-update-apply")){progress.hidden=false;progress.removeAttribute("value");status.textContent="正在覆盖文件，请不要关闭页面...";event.target.querySelector("button").disabled=true;}});</script>';
    $body=str_replace('<div id="program-update">','<div id="program-update"><input type="hidden" id="program-update-csrf" value="'.e(csrf()).'">',$body);
    $body=preg_replace('~<script>(?:(?!</script>).)*</script>~s','',$body)??$body;
    $body='<script src="/assets/js/admin-update.js"></script>'.$body;
    page('程序更新',$body);return;
}

if($path==='/admin/market'&&in_array($method,['GET','POST'],true)){
    $actor=admin();
    if($method==='POST'){require_post();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');$note=text_slice(trim((string)($_POST['review_note']??'')),0,500);if(!$id||!in_array($status,['approved','rejected','revoked'],true))fail(400,'审核操作无效');$db=db();$db->beginTransaction();try{$row=h('SELECT * FROM market_submissions WHERE id=? FOR UPDATE',[$id])->fetch();$allowed=['pending'=>['approved','rejected'],'approved'=>['revoked']];if(!$row||!in_array($status,$allowed[$row['status']]??[],true))throw new RuntimeException('状态转换无效');$package=__DIR__.'/../market-packages/'.$row['package_file'];if($status==='approved'){if(!is_file($package)||!preg_match('/^[a-f0-9]{48}\.zip$/',$row['package_file'])||!hash_equals($row['sha256'],hash_file('sha256',$package)))throw new RuntimeException('扩展包缺失或哈希不一致');$tmp=dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));try{$root=market_unpack($package,$tmp);$metadata=extension_market_manifest($root,$row['kind'],$row['package_name']);}finally{remove_tree($tmp);}if($metadata['version']!==$row['version']||$metadata['manifest_json']!==$row['manifest_json'])throw new RuntimeException('扩展清单已变化');}h('UPDATE market_submissions SET status=?,reviewer_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?',[$status,$actor['id'],$note,$id]);audit('market.reviewed','market',$id,['status'=>$status,'reviewer_id'=>(int)$actor['id']]);$db->commit();invalidate('market');}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fail(400,$e->getMessage());}redirect('/admin/market');}
    $rows=h('SELECT m.*,u.username,r.username AS reviewer_name FROM market_submissions m JOIN users u ON u.id=m.user_id LEFT JOIN users r ON r.id=m.reviewer_id ORDER BY FIELD(m.status,"pending","approved","rejected","revoked"),m.id DESC LIMIT 100')->fetchAll();$body='<p class="admin-intro">审核仅代表本站信任该代码。获批扩展在安装站点以 PHP 进程权限运行，并非沙箱。</p>';foreach($rows as$r){$manifest=json_decode((string)$r['manifest_json'],true);$basics=is_array($manifest)?' · 清单版本 '.e((string)($manifest['version']??'')).' · 清单 ID '.e((string)($manifest['id']??$manifest['name']??'')):'';$body.='<section class="market-review"><h2>'.e($r['title']).' <small>'.e($r['kind']).' · '.e($r['package_name']).' · '.e($r['version']).'</small></h2><p>'.e($r['summary']).$basics.' · 提交者 '.e($r['username']).' · SHA-256 <code>'.e($r['sha256']).'</code></p><p>状态：'.e($r['status']).' · 审核人：'.e((string)($r['reviewer_name']??'')).' '.e($r['review_note']).'</p>';foreach((['pending'=>['approved'=>'审核通过','rejected'=>'拒绝'],'approved'=>['revoked'=>'撤销公开']][$r['status']]??[])as$st=>$label)$body.='<form method="post" class="inline"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="'.$st.'"><input name="review_note" maxlength="500" placeholder="审核说明"><button>'.$label.'</button></form>';$body.='</section>';}
    page('扩展市场审核',$body);return;
}

function program_update_root(): string { return dirname(__DIR__); }
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
    $tree=json_decode(program_update_http('https://api.github.com/repos/Lfreepayzz/LiteBBS/git/trees/main?recursive=1'),true);
    if(!is_array($tree)||!isset($tree['sha'],$tree['tree'])||!is_array($tree['tree']))throw new RuntimeException('GitHub 更新清单无效');
    $files=[];foreach($tree['tree'] as $item){$path=(string)($item['path']??'');if(($item['type']??'')!=='blob'||!program_update_path_allowed($path))continue;$sha=(string)($item['sha']??'');if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('GitHub 文件哈希无效');$files[$path]=$sha;}
    if(!$files)throw new RuntimeException('GitHub 更新清单为空');
    return ['version'=>(string)$tree['sha'],'files'=>$files,'archive'=>'https://github.com/Lfreepayzz/LiteBBS/archive/'.rawurlencode((string)$tree['sha']).'.zip'];
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
function program_update_restore(string $backup): void {
    if(!is_dir($backup))return;
    $root=program_update_root();$walk=function(string $dir,string $prefix)use(&$walk,$root):void{foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..')continue;$relative=$prefix.$name;$source=$dir.'/'.$name;$target=$root.'/'.$relative;if(is_dir($source)){$walk($source,$relative.'/');continue;}if(is_file($source)){if(!is_dir(dirname($target))&&!mkdir(dirname($target),0750,true))throw new RuntimeException('回滚目录创建失败');if(!copy($source,$target))throw new RuntimeException('回滚文件失败：'.$relative);}}};$walk($backup,'');
}
function program_update_apply(string $archive,array $remote): void {
    if(!class_exists('ZipArchive'))throw new RuntimeException('启用 PHP zip 扩展后才能更新程序');$tmp=program_update_root().'/cache/update-'.bin2hex(random_bytes(8));$backup=config_directory().'/update-backup-'.date('YmdHis');$zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('更新包不可读');if($zip->numFiles<1||$zip->numFiles>2000)throw new RuntimeException('更新包文件数量超限');if(!mkdir($tmp,0700,true)){$zip->close();throw new RuntimeException('更新临时目录创建失败');}$seen=[];$total=0;try{for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$name=(string)($stat['name']??'');if($name===''||strpos($name,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~',$name))throw new RuntimeException('更新包含非法路径');if(substr($name,-1)==='/')continue;$size=(int)($stat['size']??0);$total+=$size;if($total>104857600||$size>10485760)throw new RuntimeException('更新包文件过大');$parts=explode('/',$name);if(count($parts)>1)array_shift($parts);$relative=implode('/',$parts);if(!program_update_path_allowed($relative)||!isset($remote['files'][$relative]))continue;$target=$tmp.'/'.$relative;$parent=dirname($target);if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('更新临时目录不可写');$stream=$zip->getStream($name);if(!$stream)throw new RuntimeException('更新文件无法读取');$data=stream_get_contents($stream);fclose($stream);if($data===false||sha1('blob '.strlen($data)."\0".$data)!==$remote['files'][$relative])throw new RuntimeException('更新文件校验失败：'.$relative);if(file_put_contents($target,$data,LOCK_EX)===false)throw new RuntimeException('更新文件写入失败：'.$relative);$seen[$relative]=true;} $zip->close();foreach($remote['files'] as $relative=>$sha){if(!isset($seen[$relative]))throw new RuntimeException('更新包缺少文件：'.$relative);}$applied=0;foreach($seen as $relative=>$_){$source=$tmp.'/'.$relative;$target=program_update_root().'/'.$relative;if(is_file($target)){ $backupFile=$backup.'/'.$relative;if(!is_dir(dirname($backupFile))&&!mkdir(dirname($backupFile),0700,true))throw new RuntimeException('无法创建更新备份');if(!copy($target,$backupFile))throw new RuntimeException('无法备份更新文件');}if(!is_dir(dirname($target))&&!mkdir(dirname($target),0750,true))throw new RuntimeException('无法创建更新目录');$replace=$target.'.'.bin2hex(random_bytes(5)).'.tmp';if(!copy($source,$replace)||!@rename($replace,$target)){@unlink($replace);throw new RuntimeException('无法覆盖更新文件：'.$relative);}$applied++;}program_update_state(['status'=>'applied','version'=>$remote['version'],'files'=>$applied,'backup'=>$backup]);@unlink($archive);remove_tree($tmp);}catch(Throwable $error){$zip->close();remove_tree($tmp);try{program_update_restore($backup);}catch(Throwable $restoreError){error_log('program update rollback failed: '.$restoreError->getMessage());}$state=program_update_state()??[];program_update_state(array_merge($state,['status'=>'failed','error'=>$error->getMessage(),'backup'=>$backup]));throw $error;}
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
    if(!class_exists('ZipArchive'))throw new RuntimeException('启用 PHP zip 扩展后才能处理扩展包');$z=new ZipArchive();if($z->open($archive)!==true)throw new RuntimeException('ZIP 包不可读');if($z->numFiles<1||$z->numFiles>200){$z->close();throw new RuntimeException('ZIP 文件数量超限');}if(!mkdir($out,0700,true)){$z->close();throw new RuntimeException('临时目录创建失败');}$seen=[];$total=0;for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$path=(string)($s['name']??'');$size=(int)($s['size']??0);$opsys=0;$attrs=0;$z->getExternalAttributesIndex($i,$opsys,$attrs);$unixMode=($attrs>>16)&0170000;$total+=$size;if($path===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~',$path)||isset($seen[$path])||$size>2097152||$total>10485760||($opsys===ZipArchive::OPSYS_UNIX&&$unixMode===0120000)){$z->close();throw new RuntimeException('ZIP 含重复路径、链接、路径穿越或超出解压限额');}$seen[$path]=true;if(substr($path,-1)==='/'){if(!is_dir($out.'/'.$path)&&!mkdir($out.'/'.$path,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}continue;}$parent=dirname($out.'/'.$path);if(!is_dir($parent)&&!mkdir($parent,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}$stream=$z->getStream($s['name']);if(!$stream){$z->close();throw new RuntimeException('ZIP 文件项无法读取');}$data=stream_get_contents($stream,2097153);fclose($stream);if($data===false||strlen($data)!==$size){$z->close();throw new RuntimeException('ZIP 文件项大小不一致');}if(file_put_contents($out.'/'.$path,$data,LOCK_EX)===false){$z->close();throw new RuntimeException('无法写入扩展文件');}}$z->close();$children=array_values(array_diff(scandir($out)?:[],['.','..']));return count($children)===1&&is_dir($out.'/'.$children[0])?$out.'/'.$children[0]:$out;
}
