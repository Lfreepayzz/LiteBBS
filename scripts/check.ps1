$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Require-Text([string]$RelativePath, [string]$Text, [string]$Message) {
    $path = Join-Path $root $RelativePath
    if (-not (Test-Path -LiteralPath $path)) { throw "Missing $RelativePath" }
    $content = [System.IO.File]::ReadAllText($path)
    if (-not $content.Contains($Text)) { throw $Message }
}

Require-Text 'index.php' 'Framework\Kernel' 'Application kernel is missing.'
Require-Text 'index.php' 'app/route-dispatcher.php' 'Application routes must enter through the router adapter.'
Require-Text 'index.php' 'home:v3:' 'Cursor-aware home feed cache key is missing.'
Require-Text 'app/reply-handler.php' 'notification_outbox' 'Replies must write notification work in their transaction.'
if (([System.IO.File]::ReadAllText((Join-Path $root 'app/reply-handler.php'))).Contains('process_notification_outbox(1)')) { throw 'Web replies must not synchronously drain notification outbox.' }
Require-Text 'app/notifications.php' 'FOR UPDATE SKIP LOCKED' 'Outbox processor must safely claim work.'
Require-Text 'app/outbox.php' 'FOR UPDATE SKIP LOCKED' 'Generic extension outbox must safely claim work.'
Require-Text 'app/outbox.php' 'failed_outbox_events' 'Generic extension outbox requires a dead-letter queue.'
Require-Text 'scripts/notifications-worker.php' 'process_notification_outbox' 'CLI worker must use shared outbox processing.'
Require-Text 'scripts/outbox-worker.php' 'process_outbox_events' 'CLI worker must process generic extension events.'
Require-Text 'database/schema.sql' 'CREATE TABLE notification_outbox' 'Fresh schema is missing the notification outbox.'
Require-Text 'database/schema.sql' 'CREATE TABLE outbox_events' 'Fresh schema is missing the generic extension outbox.'
Require-Text 'scripts/migrate.php' 'notification_outbox' 'Upgrade migration is missing the notification outbox.'
Require-Text 'plugins/zip-remote-attachments/plugin.json' '"schema": 1' 'Attachment plugin must use the independent plugin format.'
Require-Text 'templates/aurora/theme.json' '"name": "aurora"' 'Independent theme example is missing.'
Require-Text 'app/extension-framework.php' 'PluginContext' 'Versioned plugin API is missing.'
Require-Text 'app/extensions-security.php' 'extension_class_name_valid' 'Plugin class validation helper is missing.'
Require-Text 'app/extensions-security.php' 'extension_v2_entry_file' 'Plugin entry containment validation is missing.'
Require-Text 'app/extension-framework.php' '$entryFile = extension_v2_entry_file' 'Plugin runtime must resolve entries inside their plugin directory.'
Require-Text 'index.php' 'LITEBBS_LEGACY_DISPATCH_COMPLETE' 'Post-kernel redirect handling is missing.'
Require-Text 'app/theme-runtime.php' 'theme_runtime_assets' 'Theme runtime asset registry is missing.'
Require-Text 'app/read-model.php' 'topic_page_viewer_state' 'Topic page batch read model is missing.'
Require-Text 'app/extension-framework.php' 'extension_preload' 'Plugin page preload API is missing.'
Require-Text 'app/extension-framework.php' 'plugin-degraded' 'Plugin fault isolation is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' 'zip_attachment_preload' 'Attachment plugin batch preload is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' 'zip_attachment_check_user_quota' 'Attachment plugin per-user storage quota is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' 'external-download' 'Remote attachment downloads must require an external-site confirmation POST.'
Require-Text 'scripts/migrate.php' 'ix_plg_zip_remote_attachments_user_storage' 'Attachment quota query index is missing.'
Require-Text 'app/read-pages.php' 'category:v3:' 'Category feed cursor cache key is missing.'
Require-Text 'app/read-pages.php' 'LIMIT 31' 'Cursor pagination must use bounded batches.'
Require-Text 'app/route-dispatcher.php' 'dispatch_application_route' 'Application route dispatcher is missing.'
Require-Text 'app/core.php' 'redis_cache_client' 'Shared Redis adapter is missing.'
Require-Text 'app/core.php' 'function remove_tree' 'Shared extension removal helper is missing.'
Require-Text 'app/core.php' 'Redis cache single-flight' 'Distributed cache stampede protection is missing.'
Require-Text 'deployment/README.md' 'Redis latency' 'Shared Redis operations must be documented.'
Require-Text 'scripts/flush-topic-views.php' 'hGetAll' 'Topic view aggregation worker is missing.'
Require-Text 'scripts/flush-topic-views.php' 'topic_view_flush_batches' 'Topic view aggregation must be idempotent after worker failure.'
Require-Text 'scripts/cleanup.php' 'topic_view_flush_batches' 'Topic view batch ledger cleanup is missing.'
Require-Text 'app/plugin-topic.php' 'FOR UPDATE' 'Topic creation must recheck category visibility inside its transaction.'
Require-Text 'app/core.php' 'topic-view-seen' 'Topic view Redis deduplication is missing.'
Require-Text 'scripts/plugin-migrate.php' 'enabled,config_json' 'Plugin migration must register extensions disabled.'
Require-Text 'scripts/migrate.php' 'VALUES(?,?,0,JSON_OBJECT()' 'Core migration must not enable plugin code before plugin migration.'
Require-Text 'deployment/php-fpm.conf.example' 'pm.max_children' 'PHP-FPM capacity baseline is missing.'
Require-Text 'deployment/opcache.ini.example' 'opcache.validate_timestamps=0' 'Production OPcache baseline is missing.'
Require-Text 'deployment/README.md' 'queue age' 'Production queue monitoring is missing.'

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' | ForEach-Object {
        & $php.Source -l $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
    }
    & $php.Source (Join-Path $root 'tests/framework.php')
    if ($LASTEXITCODE -ne 0) { throw 'Framework tests failed.' }
} else {
    Write-Warning "php was not found; PHP lint and tests were skipped."
}

Write-Output "Structural checks passed."
