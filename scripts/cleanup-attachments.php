<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/core.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");
$db=db();
$root=dirname(__DIR__).'/uploads/plugin-zip';$removed=0;$cutoff=time()-3600;$batch=[];$removeOrphans=static function(array $files) use ($db,&$removed): void {if(!$files)return;$marks=implode(',',array_fill(0,count($files),'?'));$known=[];$query=$db->prepare('SELECT storage_name FROM plg_zip_remote_attachments_files WHERE status="active" AND source_kind="upload" AND storage_name IN ('.$marks.')');$query->execute(array_column($files,'name'));foreach($query as $row)$known[(string)$row['storage_name']]=true;foreach($files as $file)if(!isset($known[$file['name']])&&@unlink($file['path']))$removed++;};if(is_dir($root)){foreach(new DirectoryIterator($root) as $file){if($file->isDot()||!$file->isFile()||$file->getMTime()>$cutoff)continue;$batch[]=['name'=>$file->getFilename(),'path'=>$file->getPathname()];if(count($batch)===200){$removeOrphans($batch);$batch=[];}}$removeOrphans($batch);}
$removedRows=$db->exec('DELETE FROM plg_zip_remote_attachments_files WHERE status="removed"');
echo "Removed {$removed} orphan files and ".(int)$removedRows." removed attachment records.\n";
