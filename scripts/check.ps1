$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Require-Text([string]$Path, [string]$Pattern, [string]$Message) {
    $text = Get-Content -LiteralPath (Join-Path $root $Path) -Raw
    if ($text -notmatch $Pattern) { throw $Message }
}

Require-Text 'app/config.php' 'function config_file\(' 'Private config helper is missing.'
Require-Text 'app/config.php' 'function load_config\(' 'Config loader is missing.'
Require-Text 'app/config.php' 'function write_config\(' 'Atomic config writer is missing.'
Require-Text 'app/config.php' 'function install_lock_file\(' 'Private installation lock helper is missing.'
Require-Text 'app/config.php' 'function write_install_lock\(' 'Private installation lock writer is missing.'
Require-Text 'install.php' 'write_config\(' 'Installer must use the private config writer.'
Require-Text 'install.php' 'write_install_lock\(' 'Installer must use the private lock writer.'
Require-Text 'install.php' 'install-shell' 'Installer must render a standalone setup page.'
Require-Text 'install.php' 'install.css' 'Installer must use dedicated setup styles.'
Require-Text 'install.php' "dbuser" 'Installer must store the database account used by the forum.'
$indexSource = Get-Content -LiteralPath (Join-Path $root 'index.php') -Raw
if ($indexSource -match '(?i)install-state|安装') { throw 'Forum entrypoint must not contain installer code.' }
if ($indexSource -notmatch "!is_file\(install_lock_file\(\)\).*in_array\(.*\['/install',\s*'/install\.php',\s*'/install\.css'\].*redirect\('/install'\)") { throw 'Uninstalled sites must be routed to the installer without redirecting installer assets.' }
if ($indexSource.IndexOf("redirect('/install');") -gt $indexSource.IndexOf('try { $pdo = db();')) { throw 'Installer routing must happen before database initialization.' }
Require-Text 'app/core.php' 'function database_host_is_valid\(' 'Installer must validate database host syntax.'
Require-Text 'app/core.php' 'function cached_topic_count\(' 'Topic counts must use bounded cache entries.'
Require-Text 'app/extensions-security.php' 'function extension_theme_runtime_valid\(' 'Runtime theme loading must validate its manifest.'
Require-Text 'install.php' 'install-state\.php' 'Installer must retain private resumable install state.'
Require-Text 'install.php' 'recoveringConfiguredInstall' 'Installer must safely recover a missing state file after config write.'
Require-Text 'install.php' 'sameDatabase' 'Installer must distinguish same-database recovery from a new installation.'
Require-Text 'install.php' '数据库已包含论坛运行数据' 'Installer must reject resuming over populated forum data.'
Require-Text 'install.php' 'request_hash' 'Installer must refresh resumable state after validating the database.'
Require-Text 'install.php' 'extension_loaded' 'Installer must require mbstring.'
Require-Text 'install.php' 'pdo_mysql' 'Installer must require the PDO MySQL driver.'
Require-Text 'install.php' 'PDOException' 'Installer must provide safe database diagnostics.'
Require-Text 'app/market.php' "\$path === '/market'" 'Frontend must expose the extension market.'
Require-Text 'app/market.php' "\$user=current_user\(\)" 'Logged-in users must be able to submit extensions.'
Require-Text 'templates/default/layout.php' 'href="/market"' 'Default theme must link to the extension market.'
Require-Text 'index.php' 'database_error_message' 'Entrypoint must handle database connection failures safely.'
Require-Text 'index.php' 'load_config\(\)' 'Root config reads remain in the entrypoint.'
Require-Text 'app/core.php' 'install_lock_file\(\)' 'Page rendering must use private installation state.'
Require-Text 'app/config.php' 'unlink\(' 'Legacy public config must be removed after migration.'
Require-Text 'index.php' 'LIMIT 5000' 'Sitemap must have a bounded streamed query.'
Require-Text 'app/admin-users-settings.php' 'permanently_delete_topic\(' 'Content deletion must be permanent and centralized.'
Require-Text 'app/admin-audit.php' 'ORDER BY id DESC LIMIT 100' 'Audit log must use bounded keyset pagination.'
Require-Text 'app/core.php' 'function setting\(' 'TTL settings helper is missing.'
Require-Text 'app/core.php' 'function database_error_message\(' 'Database failure diagnostics are missing.'
Require-Text 'app/core.php' 'function render_template\(' 'Templates must be rendered with an explicit variable scope.'
Require-Text 'app/core.php' 'HTTP_ORIGIN' 'POST requests must validate same-origin HTTPS sources.'
Require-Text 'app/core.php' 'load_config' 'Installed POST origin checks must use the configured URL, not HTTP_HOST.'
Require-Text 'app/core.php' 'same_https_origin' 'POST origin checks must compare scheme, host, and port.'
Require-Text 'app/core.php' 'SELECT id FROM topics WHERE id=\? FOR UPDATE' 'Permanent topic deletion must lock the topic.'
Require-Text 'app/core.php' 'SELECT id FROM posts WHERE topic_id=\? FOR UPDATE' 'Permanent topic deletion must lock and enumerate posts.'
Require-Text 'app/core.php' 'SUM\(delta\)' 'Permanent topic deletion must aggregate ledger deltas.'
Require-Text 'app/core.php' 'points=points-\?' 'Permanent topic deletion must reverse ledger points before deletion.'
Require-Text 'app/core.php' 'ref_type' 'Permanent topic deletion must remove post ledger entries.'
Require-Text 'app/core.php' 'function confirmation_token\(' 'Destructive actions must use one-time confirmation tokens.'
Require-Text 'index.php' 'enforce_post_interval' 'Post interval must be enforced before writes.'
Require-Text 'app/admin-users-settings.php' 'settings' 'Settings updates must invalidate the settings cache.'
Require-Text 'database/schema.sql' 'ix_sessions_expires' 'Session expiry index is missing from the new schema.'
Require-Text 'database/schema.sql' 'ix_topics_user_last_post' 'Performance indexes must be included in the installation schema.'
Require-Text 'scripts/migrate.php' 'PHP_SAPI' 'Migration must be CLI-only.'
Require-Text 'scripts/migrate.php' 'information_schema' 'Migration must inspect schema metadata.'
Require-Text 'database/schema.sql' 'market_submissions' 'New schema must retain market submissions.'
Require-Text '.htaccess' 'tmp\|md\|ps1\|sh\|ini' 'Apache must deny temporary files.'
Require-Text '.htaccess' '\\\.\.\*' 'Apache must deny dot-prefixed files.'
Require-Text '.htaccess' 'md\|ps1\|sh\|ini' 'Apache must deny source documentation and scripts.'
Require-Text '.htaccess' 'scripts' 'Apache must deny the scripts directory.'
Require-Text 'nginx.conf.example' 'tmp\|md\|ps1\|sh\|ini' 'Nginx must deny temporary files.'
Require-Text 'nginx.conf.example' 'Strict-Transport-Security' 'Nginx must set HSTS.'
Require-Text 'nginx.conf.example' 'Content-Security-Policy' 'Nginx must set CSP.'
Require-Text 'nginx.conf.example' 'Cross-Origin-Opener-Policy' 'Nginx must set COOP.'
Require-Text 'nginx.conf.example' '/scripts/' 'Nginx must deny the scripts directory.'
Require-Text 'nginx.conf.example' 'location = /install\.php' 'Nginx must allow the standalone installer entrypoint.'
Require-Text 'nginx.conf.example' 'location = /install\s*\{' 'Nginx must route /install directly to the standalone installer.'
Require-Text '.htaccess' 'RewriteRule \^install/\?\$ install\.php' 'Apache must route /install directly to the standalone installer.'
Require-Text '.htaccess' 'install\)\\\.php' 'Apache must allow the standalone installer entrypoint.'
Require-Text 'app/market.php' 'admin\(\)' 'Executable extension submission must be restricted to administrators.'
Require-Text 'app/market.php' 'market_signing_key' 'Market signing key handling is missing.'
Require-Text 'app/market.php' 'write_config\(\$config\)' 'Market signing private key must use private configuration.'
Require-Text 'app/extensions-security.php' 'extension_structure_preflight_php' 'PHP package checking must be named structural preflight.'
Require-Text 'install.php' 'schema\.sql' 'Installer must restrict schema.sql to simple statements.'
Require-Text 'OPERATIONS.md' 'Host allowlist' 'Operations documentation must require a Host allowlist.'
Require-Text 'OPERATIONS.md' 'Do not expose an uninstalled site' 'Operations documentation must protect uninstalled deployments.'
Require-Text 'index.php' "limit_action\('search',10,300" 'Search must use the stricter request limit.'
Require-Text 'index.php' 'MATCH\(t\.title\)' 'Search must retain indexed title matching.'
Require-Text 'index.php' 'cached\(\$key, 3600' 'Sitemap must use a bounded cache.'
Require-Text 'app/market.php' "action==='apply-update'" 'Web-triggered core update action is missing.'
Require-Text 'app/core-updater.php' 'function apply_core_update\(' 'Core update installer is missing.'
Require-Text 'app/core-updater.php' 'official_update_commit' 'Core updates must record the installed commit.'

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' | ForEach-Object {
        & $php.Source -l $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
    }
} else {
    Write-Warning 'php was not found; structural checks completed but PHP lint was skipped.'
}
Write-Output 'Structural checks passed.'
