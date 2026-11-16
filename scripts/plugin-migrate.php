<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if ($argc !== 2 || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $argv[1])) exit("Usage: php scripts/plugin-migrate.php <plugin-id>\n");

require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/extensions-security.php';
require dirname(__DIR__).'/app/plugin-migrations.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");

$id = $argv[1];
$manifestFile = dirname(__DIR__).'/plugins/'.$id.'/plugin.json';
$manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? ''))) exit("Plugin manifest is invalid.\n");
$config = load_config();
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['name'].';charset=utf8mb4', $config['user'], $config['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
try{plugin_apply_migrations($pdo,$id,dirname(__DIR__).'/plugins/'.$id,$manifest,static function(string $migrationId)use($id):void{fwrite(STDOUT,"Applied {$id}:{$migrationId}\n");});}
catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
