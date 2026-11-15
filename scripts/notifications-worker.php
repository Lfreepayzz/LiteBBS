<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (version_compare(PHP_VERSION, '8.0.0', '<')) exit("PHP 8.0+ is required for SKIP LOCKED notification workers.\n");
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/notifications.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");

$limit=max(1,min(500,(int)($argv[1]??100)));
$processed=process_notification_outbox($limit);
fwrite(STDOUT,"Processed {$processed} notification batches.\n");
