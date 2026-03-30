<?php
/**
 * @package CSP\Core
 */

declare(strict_types=1);

namespace CSP\Core;

use CSP\API\Controllers\CaseController;
use CSP\API\Middleware\AuthMiddleware;
use CSP\API\Middleware\PermissionMiddleware;
use CSP\API\Router;

class Plugin
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function init(): void
    {
        $this->registerBindings();
        $this->boot();
    }

    private function registerBindings(): void
    {
        // Router
        $this->container->singleton(Router::class, function ($c) {
            return new Router($c);
        });

        // Controllers (example)
        $this->container->bind(CaseController::class, function () {
            return new CaseController();
        });

        // Middleware (for future DI)
        $this->container->bind(AuthMiddleware::class, function () {
            return new AuthMiddleware();
        });

        $this->container->bind(PermissionMiddleware::class, function () {
            return new PermissionMiddleware();
        });
    }

    private function boot(): void
    {
        // Register API
        $this->container->get(Router::class)->register();
    }
}