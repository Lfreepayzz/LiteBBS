<?php
declare(strict_types=1);

function register_application_routes(\LiteBBS\Framework\Router $router): void
{
    $module = static function (string $file): callable {
        return static function (\LiteBBS\Framework\Request $request) use ($file): \LiteBBS\Framework\Response {
            $path = $request->path;
            $method = $request->method;
            ob_start();
            try {
                require __DIR__.'/'.$file;
                return new \LiteBBS\Framework\Response((string)ob_get_clean(), http_response_code() ?: 200);
            } catch (\LiteBBS\Framework\ResponseHalt $halt) {
                ob_end_clean();
                return $halt->response;
            } catch (Throwable $error) {
                ob_end_clean();
                throw $error;
            }
        };
    };

    $router->add(['GET', 'POST'], '~^/new$~', $module('plugin-topic.php'));
    $router->add(['GET', 'POST'], '~^/(?:login|register|me)$~', $module('account-routes.php'));
    $router->add('POST', '~^/logout$~', $module('account-routes.php'));
    $router->add('GET', '~^/(?:search|u/[^/]+)$~', $module('account-routes.php'));
    $router->add(['GET', 'POST'], '~^/admin/categories$~', $module('admin-categories.php'));
    $router->add(['GET', 'POST'], '~^/admin/(?:market|extensions)$~', $module('market.php'));
    $router->add(['GET', 'POST'], '~^/market(?:/index\.json|/submit)?$~', $module('market.php'));
    $router->add('GET', '~^/market/package/[0-9]+$~', $module('market.php'));
    $router->add(['GET', 'POST'], '~^/admin/(?:themes|plugins|users|settings|content|reports|audit)$~', static function (\LiteBBS\Framework\Request $request) use ($module): \LiteBBS\Framework\Response {
        $files = [
            '/admin/themes' => 'admin-extensions-manage.php',
            '/admin/plugins' => 'admin-extensions-manage.php',
            '/admin/users' => 'admin-users-settings.php',
            '/admin/settings' => 'admin-users-settings.php',
            '/admin/content' => 'admin-users-settings.php',
            '/admin/reports' => 'admin-reports.php',
            '/admin/audit' => 'admin-audit.php',
        ];
        return $module($files[$request->path])($request);
    });
    $router->add(['GET', 'POST'], '~^/messages$~', $module('messages.php'));
    $router->add(['GET', 'POST'], '~^/avatar/upload$~', $module('community.php'));
    $router->add('GET', '~^/avatar/[0-9]+$~', $module('community.php'));
    $router->add('POST', '~^/(?:topic/[0-9]+/(?:bookmark|subscribe)|post/[0-9]+/(?:like|delete)|report|user/[0-9]+/(?:block|unblock))$~', $module('community.php'));
    $router->add(['GET', 'POST'], '~^/p/[0-9]+/edit$~', $module('post-edit.php'));
    $router->add('GET', '~^/(?:t/[0-9]+(?:-[^/]+)?|c/[0-9]+)$~', $module('read-pages.php'));
    $router->add(['GET', 'POST'], '~^/admin$~', $module('admin-dashboard.php'));
}
