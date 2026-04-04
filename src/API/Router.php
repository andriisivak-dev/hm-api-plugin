<?php
/**
 * @package CSP\API
 */

declare(strict_types=1);

namespace CSP\API;

use CSP\Core\Container;
use CSP\API\Controllers\FormController;
use CSP\API\Controllers\CaseController;
use CSP\API\Controllers\DashboardController;
use CSP\API\Controllers\UserController;
use CSP\API\Controllers\NotificationController;
use CSP\API\Middleware\MiddlewarePipeline;
use CSP\API\Middleware\AuthMiddleware;
use CSP\API\Middleware\PermissionMiddleware;
use CSP\API\Middleware\SanitizeMiddleware;
use WP_REST_Request;

class Router
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register(): void
    {
        add_action('rest_api_init', function () {
            $this->registerRoutes();
        });
    }

    private function registerRoutes(): void
    {
        $ns = 'csp/v1';

        // 6.1 Forms
        $this->addRoute($ns, 'GET', '/forms/(?P<id>\d+)/schema', FormController::class, 'getSchema');

        // 6.2 Cases
        $this->addRoute($ns, 'GET', '/cases', CaseController::class, 'index');
        $this->addRoute($ns, 'POST', '/cases', CaseController::class, 'create');
        $this->addRoute($ns, 'GET', '/cases/(?P<id>\d+)', CaseController::class, 'show');
        $this->addRoute($ns, 'DELETE', '/cases/(?P<id>\d+)', CaseController::class, 'delete');

        // 6.3 Case Form Data
        $this->addRoute($ns, 'GET', '/cases/activities', CaseController::class, 'getActivities');
        $this->addRoute($ns, 'GET', '/cases/(?P<id>\d+)/form-data', CaseController::class, 'getFormData');
        $this->addRoute($ns, 'PATCH', '/cases/(?P<id>\d+)/form-data', CaseController::class, 'updateFormData');

        // 6.4 Case Status
        $this->addRoute($ns, 'POST', '/cases/(?P<id>\d+)/submit', CaseController::class, 'submit');
        $this->addRoute($ns, 'POST', '/cases/(?P<id>\d+)/approve', CaseController::class, 'approve');
        $this->addRoute($ns, 'POST', '/cases/(?P<id>\d+)/reject', CaseController::class, 'reject');
        $this->addRoute($ns, 'POST', '/cases/(?P<id>\d+)/return', CaseController::class, 'returnForRevision');
        $this->addRoute($ns, 'PATCH', '/cases/(?P<id>\d+)/status', CaseController::class, 'overrideStatus');

        // 6.5 Dashboard
        $this->addRoute($ns, 'GET', '/dashboard/stats', DashboardController::class, 'getStats');
        $this->addRoute($ns, 'GET', '/dashboard/filters', DashboardController::class, 'getFilters');

        // 6.7 Users
        $this->addRoute($ns, 'GET', '/users', UserController::class, 'index');
        $this->addRoute($ns, 'POST', '/users', UserController::class, 'create');
        $this->addRoute($ns, 'PATCH', '/users/(?P<id>\d+)', UserController::class, 'update');
        $this->addRoute($ns, 'DELETE', '/users/(?P<id>\d+)', UserController::class, 'delete');
        $this->addRoute($ns, 'POST', '/profile/avatar', UserController::class, 'updateAvatar');

        // 6.8 Notifications
        $this->addRoute($ns, 'GET', '/notifications', NotificationController::class, 'index');
        $this->addRoute($ns, 'PATCH', '/notifications/(?P<id>\d+)/read', NotificationController::class, 'markAsRead');
        $this->addRoute($ns, 'POST', '/notifications/read-all', NotificationController::class, 'readAll');
        $this->addRoute($ns, 'GET', '/notifications/unread-count', NotificationController::class, 'getUnreadCount');
    }

    private function addRoute(string $namespace, string $method, string $route, string $controller, string $action): void
    {
        register_rest_route($namespace, $route, [
            'methods'  => $method,
            'callback' => function (WP_REST_Request $request) use ($controller, $action) {
                return $this->handle($request, $controller, $action);
            },
            'permission_callback' => function () {
                return is_user_logged_in(); // Base security. Middlewares and controllers do strict role/ACL checking.
            },
        ]);
    }

    private function handle(WP_REST_Request $request, string $controller, string $method)
    {
        // Add middlewares explicitly per pipeline
        $pipeline = new MiddlewarePipeline();
        
        // Middleware registration could be injected via container, but we'll instantiate for simplicity based on stub
        if (class_exists(SanitizeMiddleware::class)) {
            $pipeline->pipe(new SanitizeMiddleware());
        }
        if (class_exists(AuthMiddleware::class)) {
            $pipeline->pipe(new AuthMiddleware());
        }
        if (class_exists(PermissionMiddleware::class)) {
            $pipeline->pipe(new PermissionMiddleware());
        }

        try {
            return $pipeline->process($request, function ($request) use ($controller, $method) {
                // Instantiated through our DI container
                $instance = $this->container->get($controller);
                return $instance->$method($request);
            });
        } catch (\CSP\Exceptions\ApiException $e) {
            return \CSP\API\Responses\ApiResponse::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatus(),
                $e->getData()
            );
        } catch (\Throwable $e) {
            return \CSP\API\Responses\ApiResponse::error(
                \CSP\API\Responses\ErrorCodes::INTERNAL_ERROR,
                'Server error: ' . $e->getMessage(),
                500
            );
        }
    }
}