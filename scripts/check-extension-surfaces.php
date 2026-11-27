<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/kernel.php';

function check_surface(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$selection = new ReadSelection('home', 2);
check_surface($selection->accepts([]) && $selection->accepts([1, 2]), 'Valid ID selections were rejected.');
foreach ([[1, 1], [0], ['1'], [1, 2, 3], ['id'=>1]] as $invalid) {
    check_surface(!$selection->accepts($invalid), 'An invalid ID selection was accepted.');
}

$profileDraft = new UserProfileDraft('before@example.test', 'before', 'zh-CN');
$updatedProfileDraft = $profileDraft->with('after@example.test', 'after', 'en-US');
check_surface(
    $updatedProfileDraft instanceof UserProfileDraft
    && $updatedProfileDraft->email === 'after@example.test'
    && $updatedProfileDraft->bio === 'after'
    && $updatedProfileDraft->locale === 'en-US'
    && $profileDraft->email === 'before@example.test',
    'Profile command drafts must be immutable and retain supplied values.'
);

$migrationSource = "<?php\ndeclare(strict_types=1);\nreturn ['CREATE TABLE plg_surface_test_items (id INT)'];\n";
extension_validate_migration_source($migrationSource, 'migrations/20261127_0001_surface.php', 'plg_surface_test_');
try {
    extension_validate_migration_source("<?php\ndb()->query('CREATE TABLE users (id INT)');\nreturn ['CREATE TABLE plg_surface_test_items (id INT)'];\n", 'migrations/20261127_0001_surface.php', 'plg_surface_test_');
    throw new LogicException('Executable plugin migration source was accepted.');
} catch (RuntimeException) {
}

$profilePageRequest = new PublicProfilePageRequest([], null, 2, 20);
$profilePage = new PublicProfilePage([['id'=>1]], true);
check_surface($profilePageRequest->page === 2 && $profilePage->hasMore(), 'Public profile pagination must use a page number and hasMore flag.');

check_surface(extension_avatar_url_valid('https://avatars.example.test/user.png'), 'A valid HTTPS avatar URL was rejected.');
check_surface(!extension_avatar_url_valid('http://avatars.example.test/user.png') && !extension_avatar_url_valid('javascript:alert(1)'), 'An unsafe avatar URL was accepted.');
check_surface(extension_seo_canonical_valid('https://forum.example.test/topic') && !extension_seo_canonical_valid('http://forum.example.test/topic'), 'SEO canonical URL validation is invalid.');
check_surface(AuthenticationDecision::allow()->state === 'allow' && AuthenticationDecision::deny()->state === 'deny', 'Authentication decisions are invalid.');

$policyRegistry = [];
$policyContext = new PluginContext('policy-test', ['views'], $policyRegistry);
$policyContext->policies()->allow('category.view', static fn(PolicyContext $context): string => PolicyDecision::Abstain);
check_surface(isset($policyRegistry['policies']['category.view']), 'Category read policies were not registered.');

$registry = [];
$context = new PluginContext('surface-test', ['views'], $registry);
$context->readSelections()->provide('home', static fn(ReadSelection $view): array => []);
$context->readSelections()->provide('topic', static fn(ReadSelection $view): array => [11, 20, 30]);
$context->readTransforms()->provide('topic', static fn(ReadSelection $view, array $rows): ?array => null);
$context->views()->provide('layout', static fn(PluginView $view): ?string => null);
foreach (['selection'=>'home', 'read_transform'=>'topic', 'view'=>'layout'] as $kind => $surface) {
    $key = extension_manifest_hook_keys()[$kind];
    $declared = extension_manifest_hooks(['hooks'=>[$key=>$kind === 'selection' ? [$surface, 'topic'] : [$surface]]], $kind);
    check_surface($declared === array_keys($registry[$key]), 'Manifest declarations and runtime registrations differ.');
}

$replySelection = new ReadSelection('topic', 3, 1, ['after_id'=>10, 'after_floor'=>3]);
check_surface($replySelection->accepts($registry['selections']['topic'][0]['handler']($replySelection)), 'Reply providers must accommodate one lookahead ID.');

$assets = extension_manifest_assets(['assets'=>[['path'=>'assets/common.css', 'common'=>true], 'assets/standalone.js']]);
check_surface($assets[0]['common'] && !$assets[1]['common'], 'Common assets must be explicitly declared.');
try {
    extension_manifest_assets(['assets'=>[['path'=>'assets/common.css', 'common'=>true, 'pages'=>['manual']]]]);
    throw new LogicException('A manual asset was accepted into the common bundle.');
} catch (RuntimeException $error) {
    check_surface($error->getMessage() === 'Common assets must be declared for all pages.', 'Unexpected manifest error.');
}
check_surface(ExtensionAssetBundle::canBundleCss('body { color: red; }'), 'Standalone CSS should be bundleable.');
foreach (['@import "child.css";', 'body { background: url(icon.svg); }', "\xef\xbb\xbf".'body {}'] as $source) {
    check_surface(!ExtensionAssetBundle::canBundleCss($source), 'CSS with source dependencies must keep its individual URL.');
}

$_SERVER['HTTP_IF_NONE_MATCH'] = '"fixture"';
$notModified = extension_asset_http_response('css', 'fixture', static function (): never {
    throw new LogicException('A conditional cache hit must skip reading the asset body.');
});
unset($_SERVER['HTTP_IF_NONE_MATCH']);
check_surface($notModified->status === 304 && $notModified->body === '', 'Conditional assets must return 304 without a body.');

$link = new ExtensionLink('profile.header.after', 'test-link', 'Test', '/directory?tab=profile#user', 0, 'member', ['name'=>'profile_username']);
check_surface($link->url([]) === null, 'A link resolved without its required context.');
check_surface($link->url(['profile_username'=>'a & b']) === '/directory?tab=profile&name=a%20%26%20b#user', 'The link query was encoded incorrectly.');
check_surface(!ExtensionLink::validHref('//example.test') && !ExtensionLink::validHref('/\\example.test'), 'Non-local links were accepted.');

foreach (['/forum-2'=>'home', '/category-1-2'=>'category', '/topic-1-2'=>'topic.view'] as $path => $surface) {
    check_surface(extension_asset_matches(['pages'=>[$surface]], $path), 'A numbered route did not match its asset scope.');
}

$pagination = \LiteBBS\Application\Pagination::forView(2, null, 20, '/', '/forum');
check_surface($pagination === ['current_page'=>2, 'page_count'=>2, 'pages'=>[]], 'Cursor-only pagination changed the theme contract.');

$partials = new ThemePartials(null);
check_surface($partials->render('shared.card') === '', 'Core fallback must not resolve theme partials.');
try {
    $partials->render('../unsafe');
    throw new LogicException('An unsafe theme partial name was accepted.');
} catch (InvalidArgumentException) {
}

echo "Extension surface checks passed.\n";
