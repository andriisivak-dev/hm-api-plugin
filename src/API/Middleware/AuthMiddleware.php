<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;

class AuthMiddleware
{
    public function __invoke(WP_REST_Request $request, callable $next)
    {
        if (!is_user_logged_in()) {
            return ApiResponse::error(
                ErrorCodes::UNAUTHORIZED,
                'User is not authenticated',
                401
            );
        }

        return $next($request);
    }
}