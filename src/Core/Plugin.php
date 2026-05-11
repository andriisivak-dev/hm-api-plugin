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

        // Domain Foundation
        $this->container->singleton(\CSP\Services\UserService::class, function () {
            return new \CSP\Services\UserService();
        });

        $this->container->singleton(\CSP\Hooks\UserHooks::class, function ($c) {
            return new \CSP\Hooks\UserHooks($c->get(\CSP\Services\UserService::class));
        });

        $this->container->singleton(\CSP\Hooks\CaseMediaHooks::class, function () {
            return new \CSP\Hooks\CaseMediaHooks();
        });

        $this->container->singleton(\CSP\Services\CaseService::class, function () {
            return new \CSP\Services\CaseService();
        });

        $this->container->singleton(\CSP\Services\GravityFormsService::class, function () {
            return new \CSP\Services\GravityFormsService();
        });

        $this->container->singleton(\CSP\Services\CaseFormDataService::class, function ($c) {
            return new \CSP\Services\CaseFormDataService($c->get(\CSP\Services\CaseService::class));
        });

        $this->container->singleton(\CSP\PostTypes\CasePostType::class, function () {
            return new \CSP\PostTypes\CasePostType();
        });

        $this->container->singleton(\CSP\Roles\RoleManager::class, function () {
            return new \CSP\Roles\RoleManager();
        });

        $this->container->singleton(\CSP\Services\TaxonomyService::class, function () {
            return new \CSP\Services\TaxonomyService();
        });

        $this->container->singleton(\CSP\Services\CasePermissionService::class, function ($c) {
            return new \CSP\Services\CasePermissionService($c->get(\CSP\Services\CaseService::class));
        });

        $this->container->singleton(\CSP\Services\NotificationService::class, function () {
            return new \CSP\Services\NotificationService();
        });

        $this->container->singleton(\CSP\Services\CaseStatusService::class, function ($c) {
            return new \CSP\Services\CaseStatusService(
                $c->get(\CSP\Services\CaseService::class),
                $c->get(\CSP\Services\CasePermissionService::class),
                $c->get(\CSP\Services\TaxonomyService::class),
                $c->get(\CSP\Services\NotificationService::class)
            );
        });

        $this->container->singleton(\CSP\Repositories\CaseRepository::class, function () {
            return new \CSP\Repositories\CaseRepository();
        });

        $this->container->singleton(\CSP\Repositories\UserRepository::class, function () {
            return new \CSP\Repositories\UserRepository();
        });

        $this->container->singleton(\CSP\Repositories\NotificationRepository::class, function () {
            return new \CSP\Repositories\NotificationRepository();
        });

        $this->container->singleton(\CSP\DTO\DTOMapper::class, function () {
            return new \CSP\DTO\DTOMapper();
        });

        // -------------------------
        // REGISTER CONTROLLERS
        // -------------------------
        $this->container->singleton(\CSP\API\Controllers\FormController::class, function ($c) {
            return new \CSP\API\Controllers\FormController(
                $c->get(\CSP\Services\GravityFormsService::class)
            );
        });

        $this->container->singleton(\CSP\API\Controllers\CaseController::class, function ($c) {
            return new \CSP\API\Controllers\CaseController(
                $c->get(\CSP\Services\CaseService::class),
                $c->get(\CSP\Services\CaseFormDataService::class),
                $c->get(\CSP\Services\CaseStatusService::class),
                $c->get(\CSP\Services\CasePermissionService::class),
                $c->get(\CSP\Repositories\CaseRepository::class),
                $c->get(\CSP\DTO\DTOMapper::class)
            );
        });

        $this->container->singleton(\CSP\API\Controllers\DashboardController::class, function ($c) {
            return new \CSP\API\Controllers\DashboardController(
                $c->get(\CSP\Repositories\CaseRepository::class),
                $c->get(\CSP\Services\GravityFormsService::class)
            );
        });

        $this->container->singleton(\CSP\API\Controllers\UserController::class, function ($c) {
            return new \CSP\API\Controllers\UserController(
                $c->get(\CSP\Repositories\UserRepository::class),
                $c->get(\CSP\DTO\DTOMapper::class),
                $c->get(\CSP\Services\UserService::class)
            );
        });

        $this->container->singleton(\CSP\API\Controllers\NotificationController::class, function ($c) {
            return new \CSP\API\Controllers\NotificationController(
                $c->get(\CSP\Repositories\NotificationRepository::class),
                $c->get(\CSP\DTO\DTOMapper::class)
            );
        });

        // Customers
        $this->container->singleton(\CSP\API\Controllers\CustomerController::class, function () {
            return new \CSP\API\Controllers\CustomerController();
        });
    }

    private function boot(): void
    {
        // Register API
        $this->container->get(Router::class)->register();
        $this->container->get(\CSP\Hooks\UserHooks::class)->register();
        $this->container->get(\CSP\Hooks\CaseMediaHooks::class)->register();

        add_action('init', function () {
            $this->container->get(\CSP\PostTypes\CasePostType::class)->register();
            $this->container->get(\CSP\Roles\RoleManager::class)->register();

            // Register Taxonomies
            (new \CSP\Taxonomies\ProductTypeTaxonomy())->register();
            (new \CSP\Taxonomies\IndustrySegmentTaxonomy())->register();
            (new \CSP\Taxonomies\MachineTypeTaxonomy())->register();
            (new \CSP\Taxonomies\MachineMakeTaxonomy())->register();
            (new \CSP\Taxonomies\ToolBrandTaxonomy())->register();
            (new \CSP\Taxonomies\SolutionTypeTaxonomy())->register();
        });

        // Customer DB schema auto-upgrade on every boot (non-destructive)
        (new \CSP\Database\CustomerMigrations())->maybeUpgrade();

        $sync_queue = \CSP\Brevo\SyncQueueFactory::create();
        $sync_queue->register();

        // Admin UI (menu pages, list table, CSV importer)
        if (is_admin()) {
            (new \CSP\Admin\Customers\CustomerAdminUI())->register();
            (new \CSP\Admin\Brevo\BrevoBulkSyncController(new \CSP\Brevo\BrevoBulkSyncService($sync_queue)))->register();
            (new \CSP\Admin\Brevo\BrevoAdminPage())->register();
            (new \CSP\Brevo\CustomerSyncHooks($sync_queue))->register();
        }

        // Gravity Forms integration (autocomplete, validation, location pre-fill)
        // Registered directly — gform_enqueue_scripts fires before 'init'
        (new \CSP\Admin\Customers\CustomerGravityForms())->register();
    }
}
