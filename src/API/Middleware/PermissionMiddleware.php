<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;
use WP_Error;

class PermissionMiddleware
{
    public function __invoke(WP_REST_Request $request, callable $next)
    {
        // temporarily skip everything
        // later here will be:
        // - role checks
        // - ownership checks
        // - status checks

        return $next($request);
    }
}