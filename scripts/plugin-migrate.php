<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if ($argc !== 3 || !preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $argv[1]) || $argv[2] !== '--allow-trusted-migrations') {
    fwrite(STDERR, "Usage: php scripts/plugin-migrate.php <plugin-id> --allow-trusted-migrations\n");
    exit(1);
}

require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/extensions-security.php';
require dirname(__DIR__).'/app/plugin-migrations.php';
try {
    plugin_require_trusted_execution();
    if (!is_file(config_file())) throw new RuntimeException('Private configuration is missing.');
    $id = $argv[1];
    $directory = dirname(__DIR__).'/plugins/'.$id;
    $manifestFile = extension_secure_runtime_file($directory, 'plugin.json');
    if ($manifestFile === null || is_link($directory)) throw new RuntimeException('Plugin manifest is missing or linked.');
    $manifest = json_decode((string)file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new RuntimeException('Plugin manifest is invalid.');
    $config = load_config();
    $pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['name'].';charset=utf8mb4', $config['user'], $config['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    plugin_apply_migrations($pdo, $id, $directory, $manifest, static function (string $migrationId) use ($id): void {
        fwrite(STDOUT, "Applied {$id}:{$migrationId}\n");
    }, allowPending: true);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
