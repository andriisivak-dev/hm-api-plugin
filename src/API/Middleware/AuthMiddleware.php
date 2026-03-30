<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;
use WP_Error;

class AuthMiddleware
{
    public function __invoke(WP_REST_Request $request, callable $next)
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'csp_unauthorized',
                'User is not authenticated',
                ['status' => 401]
            );
        }

        return $next($request);
    }
}