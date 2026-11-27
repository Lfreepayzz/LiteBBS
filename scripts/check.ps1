$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$app = Join-Path $root 'app'
$expected = @('admin.php', 'core.php', 'kernel.php', 'plugin.php', 'scanner.php', 'share.php', 'template.php', 'update.php', 'version.php')
$actual = @(Get-ChildItem -LiteralPath $app -File -Filter '*.php' | ForEach-Object Name | Sort-Object)

if ($actual.Count -ne $expected.Count -or (Compare-Object $actual $expected)) {
    throw 'app must contain exactly admin.php, core.php, kernel.php, plugin.php, scanner.php, share.php, template.php, update.php, and version.php.'
}

function Require-Text([string]$RelativePath, [string]$Text, [string]$Message) {
    $path = Join-Path $root $RelativePath
    if (-not (Test-Path -LiteralPath $path)) { throw "Missing $RelativePath" }
    if (-not ([System.IO.File]::ReadAllText($path)).Contains($Text)) { throw $Message }
}

Require-Text 'app/kernel.php' "__DIR__.'/core.php'" 'Kernel must load the single core file.'
Require-Text 'app/kernel.php' "__DIR__.'/version.php'" 'Kernel must load the version file.'
Require-Text 'app/version.php' 'LITEBBS_VERSION' 'Version constant is missing.'
Require-Text 'app/core.php' "require_once __DIR__.'/admin.php'" 'Core must lazy-load the single admin file.'
Require-Text 'app/kernel.php' "__DIR__.'/plugin.php'" 'Kernel must load the single plugin runtime.'
Require-Text 'app/kernel.php' "__DIR__.'/template.php'" 'Kernel must load the single template runtime.'
Require-Text 'app/kernel.php' "__DIR__.'/share.php'" 'Kernel must load the extension share runtime.'
Require-Text 'app/share.php' 'final class ExtensionShareService' 'Extension sharing must use its dedicated service.'
Require-Text 'app/share.php' "FORUM_ORIGIN = 'https://bolgk.eu.org'" 'Extension sharing must use its trusted forum origin.'
Require-Text 'assets/js/admin-forum-share.js' 'await response.text()' 'Share creation failures must expose the server-safe diagnostic message.'
Require-Text 'app/kernel.php' "dirname(__DIR__).'/404.php'" 'Kernel must load the root 404 response module.'
Require-Text '404.php' 'litebbs_not_found_response' 'Root 404 response module is missing.'
Require-Text '404.php' 'LiteBBSNotFound' 'Root 404 message catalog is missing.'
Require-Text '404.php' 'litebbs_error_response' 'Root error response factory is missing.'
Require-Text 'app/admin.php' "__DIR__.'/update.php'" 'Admin dispatcher must lazy-load the update runtime.'
Require-Text 'app/admin.php' "__DIR__.'/scanner.php'" 'Admin dispatcher must lazy-load the security scanner runtime.'
Require-Text 'app/scanner.php' 'extension_security_scan_all' 'Extension security scanner is missing.'
Require-Text 'assets/css/admin-scanner.css' '.security-console' 'Security scanner console styles are missing.'
Require-Text 'assets/js/admin-scanner.js' 'data-security-scan-form' 'Security scanner submission progress is missing.'
Require-Text 'app/scanner.php' "'extensions'=>['js']" 'JavaScript scanner rules must be limited to JavaScript files.'
Require-Text 'plugins/eicar-scan-test/scanner-test.txt' 'LITEBBS-SCANNER-TEST-MARKER' 'Harmless scanner test marker is missing.'
Require-Text 'app/scanner.php' 'www.virustotal.com/api/v3/files/' 'VirusTotal hash lookup is missing.'
Require-Text 'app/scanner.php' "'malicious'" 'VirusTotal malware verdict handling is missing.'
Require-Text 'app/admin.php' 'virustotal_api_key' 'VirusTotal API key configuration is missing.'
Require-Text 'app/scanner.php' "'limit'=>4" 'VirusTotal request rate limit is missing.'
Require-Text 'app/scanner.php' 'extension_security_virustotal_malicious_threshold' 'VirusTotal multi-engine confirmation threshold is missing.'
Require-Text 'app/scanner.php' 'mb-api.abuse.ch/api/v1/' 'MalwareBazaar hash lookup is missing.'
Require-Text 'app/admin.php' 'security_scanner_provider' 'Online scanner provider selection is missing.'
Require-Text 'app/admin.php' 'malwarebazaar_api_key' 'MalwareBazaar API key configuration is missing.'
Require-Text 'app/admin.php' "`$actionForm(`$name, 'purge'" 'Disabled plugins must expose a permanent deletion action.'
Require-Text 'app/scanner.php' 'security_scanner_enabled' 'Security scanner must require its site setting.'
Require-Text 'app/admin.php' 'security_scanner_enabled' 'Site settings must control the security scanner.'
Require-Text 'app/update.php' 'app/scanner.php' 'Program update manifest must include the security scanner runtime.'
Require-Text 'app/core.php' 'final class TransactionManager' 'Core transaction manager is missing.'
Require-Text 'app/core.php' 'final class AdminAuthorizationMiddleware' 'Core admin authorization middleware is missing.'
Require-Text 'app/core.php' 'final class PluginRequestMiddleware' 'Core plugin request middleware is missing.'
Require-Text 'app/core.php' 'register_application_routes' 'Core route registration is missing.'
Require-Text 'app/core.php' 'process_notification_outbox' 'Core notification worker is missing.'
Require-Text 'app/core.php' 'process_outbox_events' 'Core extension outbox worker is missing.'
Require-Text 'app/admin.php' 'handle_backend_routes' 'Admin dispatcher is missing.'
Require-Text 'app/admin.php' 'render_admin_layout' 'Admin layout is missing.'
Require-Text 'app/plugin.php' 'interface LiteBBSPlugin' 'Plugin contract is missing.'
Require-Text 'app/plugin.php' 'extension_plugin_asset_response' 'Plugin asset runtime is missing.'
Require-Text 'app/plugin.php' 'plugin_apply_migrations' 'Plugin migration runtime is missing.'
Require-Text 'app/plugin.php' 'Declarative migration guard' 'Plugin migrations must be declarative.'
Require-Text 'app/plugin.php' 'extension_request_before' 'Plugin request middleware runtime is missing.'
Require-Text 'app/plugin.php' 'extension_require_asset' 'Plugin conditional asset runtime is missing.'
Require-Text 'app/plugin.php' 'ContentDraft' 'Plugin command draft runtime is missing.'
Require-Text 'app/plugin.php' 'PostAction' 'Plugin post action runtime is missing.'
Require-Text 'app/plugin.php' 'ProfileTab' 'Plugin profile tab runtime is missing.'
Require-Text 'app/plugin.php' 'NotificationTab' 'Plugin notification tab runtime is missing.'
Require-Text 'app/plugin.php' 'NavigationLink' 'Plugin navigation link runtime is missing.'
Require-Text 'app/plugin.php' 'extension_manifest_extension_flags' 'Plugin extension declaration validation is missing.'
Require-Text 'app/plugin.php' 'extension_emit_signal' 'Plugin signal runtime is missing.'
Require-Text 'app/plugin.php' 'PluginSignalRegistrar' 'Plugin signal registrar is missing.'
Require-Text 'app/plugin.php' 'Pending migrations require administrator approval.' 'Plugin migrations must be approved by an administrator, not a CLI script.'
Require-Text 'app/plugin.php' 'extension_apply_profile_command' 'Plugin profile command runtime is missing.'
Require-Text 'app/plugin.php' 'PostUpdateDraft' 'Plugin post update command draft is missing.'
Require-Text 'app/plugin.php' 'DeleteRequest' 'Plugin delete request contract is missing.'
Require-Text 'app/plugin.php' 'PolicyContext' 'Plugin policy context is missing.'
Require-Text 'app/plugin.php' 'PolicyDecision' 'Plugin policy decision contract is missing.'
Require-Text 'app/plugin.php' 'extension_authorize' 'Plugin policy runtime is missing.'
Require-Text 'app/plugin.php' 'extension_render_post_actions' 'Plugin post action renderer is missing.'
Require-Text 'app/plugin.php' 'extension_profile_tabs' 'Plugin profile tab renderer is missing.'
Require-Text 'app/plugin.php' 'extension_notification_tabs' 'Plugin notification tab renderer is missing.'
Require-Text 'app/plugin.php' 'extension_has_active_slots' 'Plugin slot page-cache safety check is missing.'
Require-Text 'app/plugin.php' "'category.view'" 'Category read policy is missing.'
Require-Text 'app/plugin.php' "'attachment.download'" 'Attachment download policy is missing.'
Require-Text 'app/plugin.php' "'topic.deleting.v2'" 'Strict topic deletion lifecycle event is missing.'
Require-Text 'app/plugin.php' "'post.deleting.v2'" 'Strict post deletion lifecycle event is missing.'
Require-Text 'app/plugin.php' 'extension_permits' 'Non-throwing extension policy evaluation is missing.'
Require-Text 'app/plugin.php' 'AvatarProvider' 'Avatar provider extension API is missing.'
Require-Text 'app/plugin.php' 'extension_avatar_url' 'Avatar provider resolver is missing.'
Require-Text 'app/plugin.php' 'SeoContribution' 'Structured SEO extension API is missing.'
Require-Text 'app/plugin.php' 'AuthenticationDecision' 'Authentication challenge extension API is missing.'
Require-Text 'app/plugin.php' 'extension_authentication_pending' 'Authentication challenge state API is missing.'
Require-Text 'app/plugin.php' 'extension_authentication_complete' 'Authentication challenge completion API is missing.'
Require-Text 'app/plugin.php' 'PluginSeoRegistrar' 'Structured SEO provider registrar is missing.'
Require-Text 'app/plugin.php' 'PluginAuthenticationRegistrar' 'Authentication provider registrar is missing.'
Require-Text 'app/template.php' 'core_page_assets' 'Core conditional asset runtime is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' 'zip_attachment_validate_archive' 'Attachment plugin ZIP validation is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' 'zip_attachment_remote_url_valid' 'Attachment plugin remote URL validation is missing.'
Require-Text 'plugins/zip-remote-attachments/src/Plugin.php' "'csrf'=>true" 'Attachment state-changing routes must require CSRF protection.'
Require-Text 'app/plugin.php' 'extension_search' 'Plugin search aggregation is missing.'
Require-Text 'app/plugin.php' 'login.form.fields.after' 'Login form extension slot is missing.'
Require-Text 'app/plugin.php' 'register.form.fields.after' 'Registration form extension slot is missing.'
Require-Text 'app/plugin.php' 'member.profile.form.after' 'Member profile extension slot is missing.'
Require-Text 'app/plugin.php' 'CoreReadRepository' 'Plugin core read repository is missing.'
Require-Text 'app/plugin.php' 'PluginUrlGenerator' 'Plugin URL generator is missing.'
Require-Text 'app/core.php' "extension_authorize('profile.view'" 'Public profile policy integration is missing.'
Require-Text 'app/core.php' 'extension_apply_update_command' 'Post update command integration is missing.'
Require-Text 'app/core.php' 'extension_apply_delete_command' 'Delete command integration is missing.'
Require-Text 'app/core.php' "extension_slot('topic.thread.before'" 'Topic thread-before extension slot is not rendered.'
Require-Text 'app/template.php' 'theme_render_standard' 'Template render runtime is missing.'
Require-Text 'app/template.php' 'theme_render_page' 'Theme page fallback runtime is missing.'
Require-Text 'app/template.php' 'template_pagination_fallback' 'Template pagination fallback is missing.'
Require-Text 'app/template.php' "'content_before'" 'Theme content slot contract is missing.'
Require-Text 'app/template.php' 'final class ThemePartials' 'Theme partial rendering API is missing.'
Require-Text 'app/template.php' "`$relative = 'partials/'" 'Theme partial paths must remain package-local.'
Require-Text 'app/core.php' 'template_unavailable_document' 'Missing-template response is missing.'
Require-Text 'app/core.php' "<meta name=`"robots`" content=`"noindex`">" 'Missing-template response must not be indexable.'
Require-Text 'app/admin.php' 'function admin_schema_sync' 'Incremental schema synchronizer is missing.'
Require-Text 'app/admin.php' 'function admin_schema_sync_report' 'Schema difference report is missing.'
Require-Text 'app/admin.php' 'incremental-schema-sync' 'Admin database upgrade must audit schema synchronization.'
Require-Text 'app/update.php' 'handle_admin_program_update' 'Program update handler is missing.'

# Public and internal pagination must remain keyset-based. OFFSET and MySQL's
# LIMIT offset,count both degrade as tables grow and are forbidden everywhere.
$offsetPatterns = @('\bOFFSET\s+\d', '\bLIMIT\s+(?:\d+|\?)\s*,\s*(?:\d+|\?)')
foreach ($sourceFile in Get-ChildItem -LiteralPath $root -Recurse -File -Include '*.php', '*.sql') {
    $source = [System.IO.File]::ReadAllText($sourceFile.FullName)
    foreach ($pattern in $offsetPatterns) {
        if ($source -match $pattern) { throw "Offset pagination is forbidden: $($sourceFile.FullName)" }
    }
}

$pluginRoot = Join-Path $root 'plugins'
if (Test-Path -LiteralPath $pluginRoot) {
    foreach ($manifestFile in Get-ChildItem -LiteralPath $pluginRoot -Filter 'plugin.json' -Recurse -File) {
        try {
            $manifest = [System.IO.File]::ReadAllText($manifestFile.FullName, [System.Text.Encoding]::UTF8) | ConvertFrom-Json -ErrorAction Stop
        } catch {
            throw "Invalid UTF-8 plugin JSON: $($manifestFile.FullName)"
        }
        $pluginDirectory = Split-Path -Parent $manifestFile.FullName
        $pluginId = Split-Path -Leaf $pluginDirectory
        if ([string]$manifest.id -ne $pluginId) { throw "Plugin ID must match its directory: $pluginId" }
            if (@($manifest.permissions).Count -ne @($manifest.permissions | Select-Object -Unique).Count) { throw "Plugin permissions must not contain duplicates: $pluginId" }
        foreach ($permission in @($manifest.permissions)) {
            if ($permission -notin @('database', 'storage', 'routes', 'views', 'assets', 'security')) { throw "Invalid plugin permission '$permission': $pluginId" }
        }
        if ($null -ne $manifest.autoload) {
            foreach ($property in @($manifest.autoload.PSObject.Properties)) {
                if ($property.Name -notmatch '^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*\\$' -or [string]$property.Value -notmatch '^src(?:/[A-Za-z0-9_-]+)*/$') {
                    throw "Invalid plugin autoload declaration: $pluginId"
                }
            }
        }
        foreach ($asset in @($manifest.assets)) {
            $assetPath = if ($asset -is [string]) { $asset } else { [string]$asset.path }
            if ($assetPath -eq '' -or $assetPath -match '(^|[\\/])\.\.([\\/]|$)' -or -not (Test-Path -LiteralPath (Join-Path $pluginDirectory $assetPath) -PathType Leaf)) {
                throw "Declared plugin asset is missing or unsafe: $pluginId/$assetPath"
            }
        }
    }
}

$themeRoot = Join-Path $root 'templates'
if (Test-Path -LiteralPath $themeRoot) {
    foreach ($manifestFile in Get-ChildItem -LiteralPath $themeRoot -Filter 'theme.json' -Recurse -File) {
        try {
            $themeManifest = [System.IO.File]::ReadAllText($manifestFile.FullName, [System.Text.Encoding]::UTF8) | ConvertFrom-Json -ErrorAction Stop
        } catch {
            throw "Invalid UTF-8 theme JSON: $($manifestFile.FullName)"
        }
        $themeDirectory = Split-Path -Parent $manifestFile.FullName
        if ([string]$themeManifest.entry -ne 'layout.php' -or -not (Test-Path -LiteralPath (Join-Path $themeDirectory 'layout.php') -PathType Leaf)) {
            throw "Theme layout is missing or invalid: $themeDirectory"
        }
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $lintFiles = @(
        Get-Item -LiteralPath (Join-Path $root 'index.php'), (Join-Path $root 'install.php')
        Get-ChildItem -LiteralPath $app -File -Filter '*.php'
        Get-ChildItem -LiteralPath (Join-Path $root 'scripts') -File -Filter '*.php'
    )
    if (Test-Path -LiteralPath $themeRoot) { $lintFiles += Get-ChildItem -LiteralPath $themeRoot -File -Filter '*.php' -Recurse }
    if (Test-Path -LiteralPath $pluginRoot) { $lintFiles += Get-ChildItem -LiteralPath $pluginRoot -File -Filter '*.php' -Recurse }
    $testRoot = Join-Path $root 'tests'
    if (Test-Path -LiteralPath $testRoot) { $lintFiles += Get-ChildItem -LiteralPath $testRoot -File -Filter '*.php' -Recurse }
    foreach ($file in $lintFiles) {
        & $php.Source -l $file.FullName
        if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.Name)" }
    }
    & $php.Source (Join-Path $PSScriptRoot 'check-extension-surfaces.php')
    if ($LASTEXITCODE -ne 0) { throw 'Extension surface checks failed.' }
} else {
    Write-Warning 'php was not found; PHP lint was skipped.'
}

Write-Output 'Application structure and plugin manifest checks passed.'
