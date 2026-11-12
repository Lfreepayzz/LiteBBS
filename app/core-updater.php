<?php
declare(strict_types=1);

function apply_core_update(string $commit, array $config): array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) throw new RuntimeException('更新提交编号无效');
    if (!class_exists('ZipArchive')) throw new RuntimeException('服务器未启用 PHP zip 扩展');
    if (function_exists('set_time_limit')) @set_time_limit(120);
    $remote = official_repository_head();
    if (!hash_equals($remote['commit'], $commit)) throw new RuntimeException('官方分支已经变化，请重新检查更新');

    $private = config_directory();
    $workspace = $private.'/update-work-'.bin2hex(random_bytes(8));
    $backupRoot = $private.'/updates';
    $backup = $backupRoot.'/'.gmdate('Ymd-His').'-'.substr($commit, 0, 12);
    if ((!is_dir($backupRoot) && !@mkdir($backupRoot, 0700, true)) || !@mkdir($workspace, 0700, true)) throw new RuntimeException('无法创建更新工作目录');
    $lock = @fopen($private.'/core-update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        remove_tree($workspace);
        throw new RuntimeException('已有程序更新正在执行');
    }

    $installed = [];
    try {
        $archive = $workspace.'/release.zip';
        $payload = market_download('https://codeload.github.com/Lfreepayzz/LiteBBS/zip/'.$commit, 20971520, 60);
        if (@file_put_contents($archive, $payload, LOCK_EX) === false) throw new RuntimeException('无法保存更新包');
        $source = core_update_unpack($archive, $workspace.'/source');
        foreach (['index.php','app/core.php','app/config.php','database/schema.sql'] as $required) {
            if (!is_file($source.'/'.$required)) throw new RuntimeException('更新包缺少核心文件 '.$required);
        }

        $root = dirname(__DIR__);
        if (!is_file($root.'/database/schema.sql') || !hash_equals(hash_file('sha256', $root.'/database/schema.sql'), hash_file('sha256', $source.'/database/schema.sql'))) {
            throw new RuntimeException('该版本包含数据库结构变化，请先通过命令行迁移后再部署');
        }
        $files = core_update_files($source);
        if (!$files || !@mkdir($backup, 0700, true)) throw new RuntimeException('更新包无文件或无法创建备份');

        foreach ($files as $relative) {
            $target = $root.'/'.$relative;
            if (is_link($target) || (file_exists($target) && !is_file($target))) throw new RuntimeException('目标路径不是普通文件：'.$relative);
            $writeDirectory = is_file($target) ? dirname($target) : core_update_existing_parent(dirname($target));
            if (!is_writable($writeDirectory) || (is_file($target) && !is_writable($target))) throw new RuntimeException('程序文件不可写：'.$relative);
            $existed = is_file($target);
            if ($existed) {
                $saved = $backup.'/'.$relative;
                if (!is_dir(dirname($saved)) && !@mkdir(dirname($saved), 0700, true)) throw new RuntimeException('无法创建备份目录');
                if (!@copy($target, $saved)) throw new RuntimeException('无法备份 '.$relative);
            }
            $installed[] = ['path'=>$relative, 'existed'=>$existed];
        }
        $manifest = json_encode(['commit'=>$commit,'created_at'=>gmdate('c'),'files'=>$installed], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (@file_put_contents($backup.'/manifest.json', $manifest, LOCK_EX) === false) throw new RuntimeException('无法保存备份清单');

        foreach ($files as $relative) core_update_replace($source.'/'.$relative, $root.'/'.$relative);
        $config['official_update_commit'] = $commit;
        write_config($config);
        if (function_exists('opcache_reset')) @opcache_reset();
        try { audit('core.updated', 'core', null, ['commit'=>$commit, 'files'=>count($files), 'backup'=>basename($backup)]); }
        catch (Throwable $auditError) { error_log('core update audit failed: '.$auditError->getMessage()); }
        return ['message'=>'程序已更新到 '.substr($commit,0,12).'，共覆盖 '.count($files).' 个文件。备份：'.basename($backup), 'url'=>$remote['url']];
    } catch (Throwable $error) {
        for ($i=count($installed)-1; $i>=0; $i--) {
            $item=$installed[$i];$target=dirname(__DIR__).'/'.$item['path'];$saved=$backup.'/'.$item['path'];
            if ($item['existed'] && is_file($saved)) { try { core_update_replace($saved, $target); } catch (Throwable $rollbackError) { error_log('core update rollback failed for '.$item['path'].': '.$rollbackError->getMessage()); } }
            elseif (!$item['existed'] && is_file($target)) @unlink($target);
        }
        throw $error;
    } finally {
        remove_tree($workspace);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function core_update_unpack(string $archive, string $output): string
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true || $zip->numFiles < 1 || $zip->numFiles > 500) throw new RuntimeException('更新 ZIP 无效或文件数量超限');
    if (!@mkdir($output, 0700, true)) { $zip->close(); throw new RuntimeException('无法创建更新解压目录'); }
    $total=0;$root='';
    for ($i=0;$i<$zip->numFiles;$i++) {
        $stat=$zip->statIndex($i);$path=(string)($stat['name']??'');$size=(int)($stat['size']??0);$opsys=0;$attrs=0;
        $zip->getExternalAttributesIndex($i,$opsys,$attrs);$mode=($attrs>>16)&0170000;$total+=$size;
        if ($path===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~',$path)||$size>4194304||$total>31457280||($opsys===ZipArchive::OPSYS_UNIX&&$mode===0120000)) { $zip->close(); throw new RuntimeException('更新 ZIP 包含危险路径、链接或超限文件'); }
        $first=strtok($path,'/');if($root===''&&$first!==false)$root=$first;if($first!==$root){$zip->close();throw new RuntimeException('更新 ZIP 根目录结构无效');}
        if (substr($path,-1)==='/') continue;
        $relative=substr($path,strlen($root)+1);if($relative===''||!core_update_path_allowed($relative))continue;
        $target=$output.'/'.$root.'/'.$relative;$parent=dirname($target);
        if(!is_dir($parent)&&!@mkdir($parent,0700,true)){$zip->close();throw new RuntimeException('无法创建更新目录');}
        $stream=$zip->getStream($path);if(!$stream){$zip->close();throw new RuntimeException('更新文件不可读');}
        $data=stream_get_contents($stream,4194305);fclose($stream);
        if(!is_string($data)||strlen($data)!==$size||@file_put_contents($target,$data,LOCK_EX)===false){$zip->close();throw new RuntimeException('更新文件解压失败');}
    }
    $zip->close();
    return $output.'/'.$root;
}

function core_update_path_allowed(string $path): bool
{
    if (strpos($path,"\0")!==false || !preg_match('~^[A-Za-z0-9._/-]+$~',$path)) return false;
    if (in_array($path,['index.php','.htaccess','nginx.conf.example'],true)) return true;
    if (preg_match('~^(?:app|languages)/[A-Za-z0-9._/-]+\.(?:php|css)$~',$path)) return true;
    if (preg_match('~^scripts/[A-Za-z0-9._/-]+\.(?:php|ps1)$~',$path)) return true;
    if ($path === 'database/schema.sql') return true;
    return preg_match('~^templates/[a-z][a-z0-9_-]{1,31}/(?:theme\.json|layout\.php|assets/[A-Za-z0-9._-]+\.(?:css|js|png|jpe?g|gif|webp|svg))$~',$path) === 1;
}

function core_update_files(string $source): array
{
    $files=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if(!$file->isFile()||$file->isLink())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen($source)+1));if(core_update_path_allowed($relative))$files[]=$relative;}
    sort($files,SORT_STRING);return $files;
}

function core_update_replace(string $source, string $target): void
{
    $parent=dirname($target);if(!is_dir($parent)&&!@mkdir($parent,0750,true))throw new RuntimeException('无法创建目标目录');
    $tmp=$target.'.update-'.bin2hex(random_bytes(4));if(!@copy($source,$tmp))throw new RuntimeException('无法写入更新文件 '.basename($target));
    if(!@rename($tmp,$target)){if(is_file($target)&&!@unlink($target)){@unlink($tmp);throw new RuntimeException('程序目录不可写：'.basename($target));}if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('无法替换程序文件 '.basename($target));}}
    @chmod($target,0644);
}

function core_update_existing_parent(string $path): string
{
    while (!is_dir($path)) {
        $parent=dirname($path);if($parent===$path)break;$path=$parent;
    }
    return $path;
}
