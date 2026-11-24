<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/core.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");
$db=db();
$plugin = h('SELECT enabled FROM plugins WHERE name=?', ['zip-remote-attachments'])->fetch();
if (!$plugin) {
    echo "Attachment plugin is not installed.\n";
    exit(0);
}
$tableExists = (bool)h('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', ['plg_zip_remote_attachments_files'])->fetchColumn();
if (!$tableExists) {
    echo "Attachment plugin schema is unavailable.\n";
    exit(0);
}
$root=dirname(__DIR__).'/uploads/plugin-zip';$removed=0;$cutoff=time()-3600;$batch=[];$removeOrphans=static function(array $files) use ($db,&$removed): void {if(!$files)return;$marks=implode(',',array_fill(0,count($files),'?'));$known=[];$query=$db->prepare('SELECT storage_name FROM plg_zip_remote_attachments_files WHERE status="active" AND source_kind="upload" AND storage_name IN ('.$marks.')');$query->execute(array_column($files,'name'));foreach($query as $row)$known[(string)$row['storage_name']]=true;foreach($files as $file)if(!isset($known[$file['name']])&&@unlink($file['path']))$removed++;};if(is_dir($root)){foreach(new DirectoryIterator($root) as $file){if($file->isDot()||!$file->isFile()||$file->getMTime()>$cutoff)continue;$batch[]=['name'=>$file->getFilename(),'path'=>$file->getPathname()];if(count($batch)===200){$removeOrphans($batch);$batch=[];}}$removeOrphans($batch);}
$removedRows=0;
$removedAttachments=h('SELECT id,storage_name FROM plg_zip_remote_attachments_files WHERE status="removed" AND source_kind="upload"')->fetchAll();
foreach($removedAttachments as $attachment){$name=(string)$attachment['storage_name'];if(!preg_match('/^[a-f0-9]{64}$/',$name))continue;$path=$root.'/'.$name;if(!is_file($path)||@unlink($path))$removedRows+=h('DELETE FROM plg_zip_remote_attachments_files WHERE id=? AND status="removed"',[(int)$attachment['id']])->rowCount();}
$removedRows+=$db->exec('DELETE FROM plg_zip_remote_attachments_files WHERE status="removed" AND source_kind<>"upload"');
echo "Removed {$removed} orphan files and ".(int)$removedRows." removed attachment records.\n";
