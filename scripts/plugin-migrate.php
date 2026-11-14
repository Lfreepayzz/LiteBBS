<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if ($argc !== 2 || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $argv[1])) exit("Usage: php scripts/plugin-migrate.php <plugin-id>\n");

require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/extensions-security.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");

$id = $argv[1];
$directory = dirname(__DIR__).'/plugins/'.$id.'/migrations';
if (!is_dir($directory)) exit("Plugin migrations directory is missing.\n");
$manifestFile = dirname(__DIR__).'/plugins/'.$id.'/plugin.json';
$manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? ''))) exit("Plugin manifest is invalid.\n");
extension_validate_directory(dirname(__DIR__).'/plugins/'.$id, 'plugin', $id);
$config = load_config();
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['name'].';charset=utf8mb4', $config['user'], $config['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE TABLE IF NOT EXISTS plugin_migrations(plugin_name VARCHAR(32) NOT NULL,migration_id VARCHAR(128) NOT NULL,checksum CHAR(64) NOT NULL,applied_at DATETIME(6) NOT NULL,PRIMARY KEY(plugin_name,migration_id),FOREIGN KEY(plugin_name) REFERENCES plugins(name) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$register = $pdo->prepare('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,0,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version)');
$register->execute([$id, (string)$manifest['version']]);

$files = glob($directory.'/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $migrationId = basename($file, '.php');
    if (!preg_match('/^\d{8}_\d{4}_[a-z0-9_]+$/', $migrationId)) exit("Invalid migration file: ".basename($file)."\n");
    $checksum = hash_file('sha256', $file);
    $existing = $pdo->prepare('SELECT checksum FROM plugin_migrations WHERE plugin_name=? AND migration_id=?');
    $existing->execute([$id, $migrationId]);
    $applied = $existing->fetchColumn();
    if ($applied !== false) {
        if (!hash_equals((string)$applied, $checksum)) exit("Applied migration checksum changed: {$migrationId}\n");
        continue;
    }
    $source = (string)file_get_contents($file);
    extension_validate_migration_source($source, 'migrations/'.basename($file), 'plg_'.str_replace('-', '_', $id).'_');
    $statements = require $file;
    if (!is_array($statements) || !$statements || array_filter($statements, static fn(mixed $sql): bool => !is_string($sql) || trim($sql) === '')) exit("Migration must return non-empty SQL statement array: {$migrationId}\n");
    try {
        foreach ($statements as $sql) {
            $normalized = preg_replace('/\s+/', ' ', trim($sql));
            if (!is_string($normalized) || !preg_match('/^(CREATE|ALTER|DROP) TABLE\s+(IF NOT EXISTS\s+|IF EXISTS\s+)?`?plg_[a-z0-9_]+_[a-z][a-z0-9_]*`?/i', $normalized) || preg_match('/\b(RENAME|TRUNCATE|GRANT|REVOKE|LOAD DATA|INTO OUTFILE|INTO DUMPFILE)\b/i', $normalized)) throw new RuntimeException("Migration SQL is outside the plugin table scope: {$migrationId}");
            $identifiers = [];
            preg_match_all('/\b(?:TABLE|JOIN|FROM|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $normalized, $identifiers);
            foreach ($identifiers[1] ?? [] as $identifier) if (!str_starts_with(strtolower($identifier), 'plg_'.str_replace('-', '_', $id).'_')) throw new RuntimeException("Migration references an out-of-scope table: {$migrationId}");
            $pdo->exec($sql);
        }
        $insert = $pdo->prepare('INSERT INTO plugin_migrations(plugin_name,migration_id,checksum,applied_at) VALUES(?,?,?,UTC_TIMESTAMP(6))');
        $insert->execute([$id, $migrationId, $checksum]);
        fwrite(STDOUT, "Applied {$id}:{$migrationId}\n");
    } catch (Throwable $error) {
        throw $error;
    }
}
