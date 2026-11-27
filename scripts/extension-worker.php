<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require dirname(__DIR__).'/app/kernel.php';
if (!is_file(config_file())) exit("Database configuration is missing.\n");
$serverVersion=(string)db()->getAttribute(PDO::ATTR_SERVER_VERSION);
$maria=str_contains(strtolower($serverVersion),'mariadb');
$numeric=preg_match('/[0-9]+(?:\.[0-9]+){1,2}/',$serverVersion,$match)?$match[0]:'0.0.0';
if((!$maria&&version_compare($numeric,'8.0.0','<'))||($maria&&version_compare($numeric,'10.6.0','<')))exit("Extension workers require MySQL 8.0+ or MariaDB 10.6+.\n");
$limit=max(1,min(1000,(int)($argv[1]??100)));
echo 'Processed '.extension_process_jobs($limit)." extension jobs.\n";
