<?php

declare(strict_types=1);

namespace CSP\API\Middleware;

use WP_REST_Request;

class SanitizeMiddleware
{
    public function __invoke(WP_REST_Request $request, callable $next)
    {
        $params = $request->get_params();
        $sanitized_params = $this->sanitizeArray($params);

        // WP_REST_Request doesn't have a bulk set_params method, we update body/query depending on origin
        // To keep it simple, we use the set_param for each individual parameter
        foreach ($sanitized_params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $next($request);
    }

    private function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                // We use wp_kses_post instead of sanitize_text_field because 
                // form values might legitimately contain line breaks or safe HTML in textareas
                $sanitized[$key] = wp_kses_post($value);
            } else {
                $sanitized[$key] = $value; // Ints, bools passed as-is
            }
        }
        return $sanitized;
    }
}
