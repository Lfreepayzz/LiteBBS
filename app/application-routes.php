<?php
declare(strict_types=1);

function register_application_routes(\LiteBBS\Framework\Router $router): void
{
    $handler = static function (string $file, string $function): callable {
        return new \LiteBBS\Application\LegacyActionController($file, $function);
    };

    $account = $handler('account-routes.php', 'handle_account_routes');
    $router->add(['GET', 'POST'], '~^/new$~', $handler('plugin-topic.php', 'handle_topic_create'));
    $router->add(['GET', 'POST'], '~^/(?:login|register|me)$~', $account);
    $router->add('POST', '~^/logout$~', $account);
    $router->add('GET', '~^/(?:search|u/[^/]+)$~', $account);
    $router->add(['GET', 'POST'], '~^/admin/categories$~', $handler('admin-categories.php', 'handle_admin_categories'));
    $market = $handler('market.php', 'handle_market');
    $router->add(['GET', 'POST'], '~^/admin/(?:market|extensions)$~', $market);
    $router->add(['GET', 'POST'], '~^/market(?:/index\.json|/submit)?$~', $market);
    $router->add('GET', '~^/market/package/[0-9]+$~', $market);
    $router->add(['GET', 'POST'], '~^/admin/(?:themes|plugins)$~', $handler('admin-extensions-manage.php', 'handle_admin_extensions_manage'));
    $router->add(['GET', 'POST'], '~^/admin/(?:users|settings|content)$~', $handler('admin-users-settings.php', 'handle_admin_users_settings'));
    $router->add(['GET', 'POST'], '~^/admin/reports$~', $handler('admin-reports.php', 'handle_admin_reports'));
    $router->add(['GET', 'POST'], '~^/admin/audit$~', $handler('admin-audit.php', 'handle_admin_audit'));
    $router->add(['GET', 'POST'], '~^/messages$~', $handler('messages.php', 'handle_messages'));
    $community = $handler('community.php', 'handle_community_routes');
    $router->add(['GET', 'POST'], '~^/avatar/upload$~', $community);
    $router->add('GET', '~^/avatar/[0-9]+$~', $community);
    $router->add('POST', '~^/(?:topic/[0-9]+/(?:bookmark|subscribe)|post/[0-9]+/(?:like|delete)|report|user/[0-9]+/(?:block|unblock))$~', $community);
    $router->add(['GET', 'POST'], '~^/p/[0-9]+/edit$~', $handler('post-edit.php', 'handle_post_edit'));
    $router->add('GET', '~^/(?:t/[0-9]+(?:-[^/]+)?|c/[0-9]+)$~', $handler('read-pages.php', 'handle_read_pages'));
    $router->add(['GET', 'POST'], '~^/admin$~', $handler('admin-dashboard.php', 'handle_admin_dashboard'));
}
