<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/framework.php';
require dirname(__DIR__).'/app/extensions-security.php';
require dirname(__DIR__).'/app/extension-framework.php';
require dirname(__DIR__).'/app/plugin-migrations.php';
require dirname(__DIR__).'/app/extension-platform.php';
$limit=max(1,min(1000,(int)($argv[1]??100)));
echo 'Processed '.extension_process_jobs($limit)." extension jobs.\n";
