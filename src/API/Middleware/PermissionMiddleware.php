<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;
use WP_Error;

class PermissionMiddleware
{
    public function __invoke(WP_REST_Request $request, callable $next)
    {
        // Global minimum capability requirement for all our custom API endpoints
        // Fine-grained ownership and role checks are pushed to the Controllers via CasePermissionService
        if (!current_user_can('read')) {
            return \CSP\API\Responses\ApiResponse::error(
                \CSP\API\Responses\ErrorCodes::FORBIDDEN,
                __('You do not have the required permissions.', 'csp'),
                403
            );
        }

        return $next($request);
    }
}