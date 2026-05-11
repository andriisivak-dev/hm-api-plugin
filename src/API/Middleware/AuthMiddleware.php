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

        if (!$this->hasValidRestNonce($request)) {
            return ApiResponse::error(
                ErrorCodes::FORBIDDEN,
                'Invalid or missing REST nonce.',
                403
            );
        }

        return $next($request);
    }

    private function hasValidRestNonce(WP_REST_Request $request): bool
    {
        if (strtoupper((string) $request->get_method()) === 'OPTIONS') {
            return true;
        }

        $nonce = (string) $request->get_header('x_wp_nonce');
        if ($nonce === '') {
            $nonce = (string) $request->get_header('x-wp-nonce');
        }

        if ($nonce === '') {
            $param_nonce = $request->get_param('_wpnonce');
            $nonce = is_string($param_nonce) ? $param_nonce : '';
        }

        $nonce = sanitize_text_field(wp_unslash($nonce));
        if ($nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, 'wp_rest') !== false;
    }
}
