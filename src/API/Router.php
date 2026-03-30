<?php
/**
 * @package CSP\API
 */

declare(strict_types=1);

namespace CSP\API;

use CSP\Core\Container;
use CSP\API\Controllers\CaseController;
use CSP\API\Middleware\MiddlewarePipeline;
use CSP\API\Middleware\AuthMiddleware;
use CSP\API\Middleware\PermissionMiddleware;
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
        $namespace = 'csp/v1';

        // Example route
        register_rest_route($namespace, '/cases', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $request) {
                return $this->handle($request, CaseController::class, 'index');
            },
            'permission_callback' => '__return_true', // @TODO: Add permissions!!!
        ]);
    }

    private function handle(WP_REST_Request $request, string $controller, string $method)
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline
            ->pipe(new AuthMiddleware())
            ->pipe(new PermissionMiddleware());

        return $pipeline->process($request, function ($request) use ($controller, $method) {
            $instance = $this->container->get($controller);
            return $instance->$method($request);
        });
    }
}