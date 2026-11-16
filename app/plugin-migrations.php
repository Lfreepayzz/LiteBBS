<?php
declare(strict_types=1);

function plugin_apply_migrations(PDO $pdo,string $id,string $pluginDirectory,array $manifest,?callable $logger=null,?bool $enabledAfter=null): array
{
    if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$id)||($manifest['id']??'')!==$id||!preg_match('/^\d+\.\d+\.\d+$/',(string)($manifest['version']??'')))throw new RuntimeException('Plugin manifest is invalid.');
    extension_validate_directory($pluginDirectory,'plugin',$id);
    $migrationDirectory=$pluginDirectory.'/migrations';
    $files=is_dir($migrationDirectory)?(glob($migrationDirectory.'/*.php')?:[]):[];
    sort($files,SORT_STRING);
    $lockName='litebbs:plugin-migrate:'.$id;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);
    if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin migration lock.');
    $applied=[];
    try{
        $pdo->exec('CREATE TABLE IF NOT EXISTS plugin_migrations(plugin_name VARCHAR(32) NOT NULL,migration_id VARCHAR(128) NOT NULL,checksum CHAR(64) NOT NULL,applied_at DATETIME(6) NOT NULL,PRIMARY KEY(plugin_name,migration_id),FOREIGN KEY(plugin_name) REFERENCES plugins(name) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $existing=$pdo->prepare('SELECT enabled FROM plugins WHERE name=?');$existing->execute([$id]);$previousEnabled=$existing->fetchColumn();
        $targetEnabled=$enabledAfter??($previousEnabled===false?false:(bool)$previousEnabled);
        $register=$pdo->prepare('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,0,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=0');
        $register->execute([$id,(string)$manifest['version']]);
        foreach($files as $file){
            $migrationId=basename($file,'.php');
            if(!preg_match('/^\d{8}_\d{4}_[a-z0-9_]+$/',$migrationId))throw new RuntimeException('Invalid migration file: '.basename($file));
            $source=file_get_contents($file);if(!is_string($source))throw new RuntimeException('Cannot read migration: '.$migrationId);$checksum=hash('sha256',$source);
            $existing=$pdo->prepare('SELECT checksum FROM plugin_migrations WHERE plugin_name=? AND migration_id=?');$existing->execute([$id,$migrationId]);$recorded=$existing->fetchColumn();
            if($recorded!==false){if(!hash_equals((string)$recorded,$checksum))throw new RuntimeException('Applied migration checksum changed: '.$migrationId);continue;}
            $prefix='plg_'.str_replace('-','_',$id).'_';
            extension_validate_migration_source($source,'migrations/'.basename($file),$prefix);
            $statements=require $file;
            $executedChecksum=hash_file('sha256',$file);if(!is_string($executedChecksum)||!hash_equals($checksum,$executedChecksum))throw new RuntimeException('Migration changed while being executed: '.$migrationId);
            if(!is_array($statements)||!$statements||array_filter($statements,static fn(mixed $sql):bool=>!is_string($sql)||trim($sql)===''))throw new RuntimeException('Migration must return non-empty SQL statement array: '.$migrationId);
            foreach($statements as $sql){
                $normalized=preg_replace('/\s+/',' ',trim($sql));
                if(!is_string($normalized)||str_contains($normalized,';')||preg_match('~/\*|--|#~',$normalized)||!preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?('.preg_quote($prefix,'/').'[a-z][a-z0-9_]*)`?\s*\(/i',$normalized,$target)||preg_match('/\b(?:LIKE|ALTER|DROP|RENAME|TRUNCATE|GRANT|REVOKE|LOAD DATA|INTO OUTFILE|INTO DUMPFILE)\b/i',$normalized))throw new RuntimeException('Automatic plugin migrations only allow one plugin-owned CREATE TABLE statement: '.$migrationId);
                preg_match_all('/\bREFERENCES\s+`?([a-zA-Z0-9_]+)`?/i',$normalized,$references);
                foreach($references[1]??[] as $identifier)if(!str_starts_with(strtolower($identifier),$prefix))throw new RuntimeException('Migration references an out-of-scope table: '.$migrationId);
                $executable=preg_replace('/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i','CREATE TABLE IF NOT EXISTS ',$sql,1);
                $pdo->exec(is_string($executable)?$executable:$sql);
            }
            $insert=$pdo->prepare('INSERT INTO plugin_migrations(plugin_name,migration_id,checksum,applied_at) VALUES(?,?,?,UTC_TIMESTAMP(6))');$insert->execute([$id,$migrationId,$checksum]);
            $applied[]=$migrationId;if($logger)$logger($migrationId);
        }
        $tables=$manifest['database_tables']??[];if(!is_array($tables))throw new RuntimeException('Plugin database table declaration is invalid.');
        $tableExists=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach($tables as $table){if(!is_string($table)||!preg_match('/^plg_[a-z0-9_]+_[a-z][a-z0-9_]*$/',$table))throw new RuntimeException('Plugin database table declaration is invalid.');$tableExists->execute([$table]);if(!$tableExists->fetchColumn())throw new RuntimeException('Plugin migration did not create declared table: '.$table);}
        $finish=$pdo->prepare('UPDATE plugins SET version=?,enabled=? WHERE name=?');$finish->execute([(string)$manifest['version'],$targetEnabled?1:0,$id]);
    }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    return $applied;
}

function plugin_set_enabled_state(PDO $pdo,string $id,bool $enabled): void
{
    $lockName='litebbs:plugin-migrate:'.$id;$lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin state lock.');
    try{$statement=$pdo->prepare('UPDATE plugins SET enabled=? WHERE name=?');$statement->execute([$enabled?1:0,$id]);}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
}
