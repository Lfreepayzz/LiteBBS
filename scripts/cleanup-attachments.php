<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require dirname(__DIR__).'/app/config.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");
$c=load_config();$db=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$root=dirname(__DIR__).'/uploads/plugin-zip';$known=[];foreach($db->query('SELECT storage_name FROM plg_zip_remote_attachments_files WHERE status="active" AND source_kind="upload"') as $row)$known[(string)$row['storage_name']]=true;
$removed=0;$cutoff=time()-3600;if(is_dir($root)){foreach(new DirectoryIterator($root) as $file){if($file->isDot()||!$file->isFile()||$file->getMTime()>$cutoff)continue;if(!isset($known[$file->getFilename()])&&@unlink($file->getPathname()))$removed++;}}
$db->exec('DELETE FROM plg_zip_remote_attachments_files WHERE status="removed"');
$deletedNotifications=0;$deletedMessages=0;do{$count=$db->exec('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 180 DAY) ORDER BY id LIMIT 1000');$deletedNotifications+=(int)$count;}while($count===1000);do{$count=$db->exec('DELETE FROM private_messages WHERE created_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 365 DAY) ORDER BY id LIMIT 1000');$deletedMessages+=(int)$count;}while($count===1000);
echo "Removed {$removed} orphan files, {$deletedNotifications} old notifications and {$deletedMessages} old messages.\n";
