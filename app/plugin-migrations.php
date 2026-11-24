<?php
declare(strict_types=1);

function plugin_require_trusted_execution(): void
{
    if (getenv('LITEBBS_ALLOW_TRUSTED_PLUGINS', true) !== '1') {
        throw new RuntimeException('Set LITEBBS_ALLOW_TRUSTED_PLUGINS=1 after reviewing the plugin PHP code.');
    }
}

function plugin_apply_migrations(PDO $pdo,string $id,string $pluginDirectory,array $manifest,?callable $logger=null,?bool $enabledAfter=null,bool $allowPending=false,bool $approvedByAdmin=false): array
{
    if (!$approvedByAdmin) plugin_require_trusted_execution();
    if(!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/',$id)||($manifest['id']??'')!==$id||!preg_match('/\A\d+\.\d+\.\d+\z/',(string)($manifest['version']??'')))throw new RuntimeException('Plugin manifest is invalid.');
    if (is_link($pluginDirectory) || is_link($pluginDirectory.'/migrations')) throw new RuntimeException('Plugin migration directories cannot be symbolic links.');
    extension_validate_directory($pluginDirectory,'plugin',$id);
    $migrationDirectory=$pluginDirectory.'/migrations';
    $files=is_dir($migrationDirectory)?glob($migrationDirectory.'/*.php'):[];
    if ($files === false) throw new RuntimeException('Cannot list plugin migrations.');
    sort($files,SORT_STRING);
    $lockName='litebbs:plugin-migrate:'.$id;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);
    if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin migration lock.');
    $applied=[];
    try{
        $pdo->exec('CREATE TABLE IF NOT EXISTS plugin_migrations(plugin_name VARCHAR(32) NOT NULL,migration_id VARCHAR(128) NOT NULL,checksum CHAR(64) NOT NULL,applied_at DATETIME(6) NOT NULL,PRIMARY KEY(plugin_name,migration_id),FOREIGN KEY(plugin_name) REFERENCES plugins(name) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $prefix = 'plg_'.str_replace('-', '_', $id).'_';
        $tables = $manifest['database_tables'] ?? [];
        if (!is_array($tables)) throw new RuntimeException('Plugin database table declaration is invalid.');
        foreach ($tables as $table) {
            if (!is_string($table) || strlen($table) > 64 || !preg_match('/\A'.preg_quote($prefix, '/').'[a-z][a-z0-9_]*\z/', $table)) throw new RuntimeException('Plugin database table declaration is invalid.');
        }

        $recorded = $pdo->prepare('SELECT migration_id,checksum FROM plugin_migrations WHERE plugin_name=?');
        $recorded->execute([$id]);
        $checksums = $recorded->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = [];
        foreach ($files as $file) {
            $migrationId = basename($file, '.php');
            if (strlen($migrationId) > 128 || !preg_match('/\A\d{8}_\d{4}_[a-z0-9_]+\z/', $migrationId)) throw new RuntimeException('Invalid migration file: '.basename($file));
            $relative = 'migrations/'.basename($file);
            $resolved = extension_secure_runtime_file($pluginDirectory, $relative);
            $source = $resolved === null ? false : file_get_contents($resolved);
            if (!is_string($source)) throw new RuntimeException('Cannot read migration: '.$migrationId);
            $checksum = hash('sha256', $source);
            if (isset($checksums[$migrationId])) {
                if (!hash_equals($checksums[$migrationId], $checksum)) throw new RuntimeException('Applied migration checksum changed: '.$migrationId);
                continue;
            }
            extension_validate_migration_source($source, $relative, $prefix);
            $pending[$migrationId] = [$resolved, $checksum];
        }
        if ($pending && !$approvedByAdmin && (PHP_SAPI !== 'cli' || !$allowPending)) {
            throw new RuntimeException('Pending migrations require operator review and CLI approval: php scripts/plugin-migrate.php '.$id.' --allow-trusted-migrations');
        }

        $existing=$pdo->prepare('SELECT enabled FROM plugins WHERE name=?');$existing->execute([$id]);$previousEnabled=$existing->fetchColumn();
        $targetEnabled=$enabledAfter??($previousEnabled===false?false:(bool)$previousEnabled);
        $register=$pdo->prepare('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,0,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=0');
        $register->execute([$id,(string)$manifest['version']]);
        foreach($pending as $migrationId => [$file, $checksum]){
            // Migrations execute as trusted PHP code before their returned SQL is checked.
            $currentChecksum = hash_file('sha256', $file);
            if (!is_string($currentChecksum) || !hash_equals($checksum, $currentChecksum)) throw new RuntimeException('Migration changed before execution: '.$migrationId);
            $statements=require $file;
            $executedChecksum=hash_file('sha256',$file);if(!is_string($executedChecksum)||!hash_equals($checksum,$executedChecksum))throw new RuntimeException('Migration changed while being executed: '.$migrationId);
            if(!is_array($statements)||!$statements||array_filter($statements,static fn(mixed $sql):bool=>!is_string($sql)||trim($sql)===''))throw new RuntimeException('Migration must return non-empty SQL statement array: '.$migrationId);
            $executableStatements = [];
            foreach($statements as $sql){
                $normalized=preg_replace('/\s+/',' ',trim($sql));
                if(!is_string($normalized)||str_contains($normalized,';')||preg_match('~/\*|--|#~',$normalized)||!preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?('.preg_quote($prefix,'/').'[a-z][a-z0-9_]*)`?\s*\(/i',$normalized,$target)||preg_match('/\b(?:LIKE|ALTER|DROP|RENAME|TRUNCATE|GRANT|REVOKE|LOAD DATA|INTO OUTFILE|INTO DUMPFILE)\b/i',$normalized))throw new RuntimeException('Automatic plugin migrations only allow one plugin-owned CREATE TABLE statement: '.$migrationId);
                if (!in_array($target[1], $tables, true)) throw new RuntimeException('Migration creates an undeclared table: '.$migrationId);
                preg_match_all('/\bREFERENCES\s+`?([a-zA-Z0-9_]+)`?/i',$normalized,$references);
                foreach($references[1]??[] as $identifier)if(!str_starts_with(strtolower($identifier),$prefix))throw new RuntimeException('Migration references an out-of-scope table: '.$migrationId);
                $executable=preg_replace('/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i','CREATE TABLE IF NOT EXISTS ',$sql,1);
                $executableStatements[] = is_string($executable) ? $executable : $sql;
            }
            foreach ($executableStatements as $sql) $pdo->exec($sql);
            $insert=$pdo->prepare('INSERT INTO plugin_migrations(plugin_name,migration_id,checksum,applied_at) VALUES(?,?,?,UTC_TIMESTAMP(6))');$insert->execute([$id,$migrationId,$checksum]);
            $applied[]=$migrationId;if($logger)$logger($migrationId);
        }
        $tableExists=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach($tables as $table){$tableExists->execute([$table]);if(!$tableExists->fetchColumn())throw new RuntimeException('Plugin migration did not create declared table: '.$table);}
        $finish=$pdo->prepare('UPDATE plugins SET version=?,enabled=? WHERE name=?');$finish->execute([(string)$manifest['version'],$targetEnabled?1:0,$id]);
    }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    return $applied;
}

function plugin_set_enabled_state(PDO $pdo,string $id,bool $enabled): void
{
    $lockName='litebbs:plugin-migrate:'.$id;$lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin state lock.');
    try{$statement=$pdo->prepare('UPDATE plugins SET enabled=? WHERE name=?');$statement->execute([$enabled?1:0,$id]);}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
}
