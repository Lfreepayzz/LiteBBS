<?php
declare(strict_types=1);

/** The only application bootstrap. */
const LITEBBS_APP_DIR = __DIR__;
require_once __DIR__.'/version.php';
require_once __DIR__.'/core.php';
require_once __DIR__.'/plugin.php';
require_once __DIR__.'/template.php';
require_once __DIR__.'/share.php';
require_once dirname(__DIR__).'/404.php';
