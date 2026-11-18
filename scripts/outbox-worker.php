<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (version_compare(PHP_VERSION, '8.0.0', '<')) exit("PHP 8+ is required.\n");
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/framework.php';
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/extensions-security.php';
require dirname(__DIR__).'/app/extension-framework.php';
require dirname(__DIR__).'/app/plugin-migrations.php';
require dirname(__DIR__).'/app/extension-platform.php';
require dirname(__DIR__).'/app/outbox.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");
$serverVersion=(string)db()->getAttribute(PDO::ATTR_SERVER_VERSION);
$maria=str_contains(strtolower($serverVersion),'mariadb');
$numeric=preg_match('/[0-9]+(?:\.[0-9]+){1,2}/',$serverVersion,$match)?$match[0]:'0.0.0';
if((!$maria&&version_compare($numeric,'8.0.0','<'))||($maria&&version_compare($numeric,'10.6.0','<')))exit("Outbox workers require MySQL 8.0+ or MariaDB 10.6+.\n");
$limit = max(1, min(500, (int)($argv[1] ?? 100)));
$processed = process_outbox_events($limit);
fwrite(STDOUT, "Processed {$processed} outbox events.\n");
