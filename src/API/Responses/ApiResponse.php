<?php

declare(strict_types=1);

namespace CSP\API\Responses;

use WP_REST_Response;

class ApiResponse
{
    /**
     * Return a standardized success response.
     *
     * @param mixed $data
     * @param string $message
     * @param array|null $meta
     * @param int $status
     * @return WP_REST_Response
     */
    public static function success($data = null, string $message = '', ?array $meta = null, int $status = 200): WP_REST_Response
    {
        $response = [
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return new WP_REST_Response($response, $status);
    }

    /**
     * Return a standardized error response.
     *
     * @param string $code
     * @param string $message
     * @param int $status
     * @param mixed $data
     * @return WP_REST_Response
     */
    public static function error(string $code, string $message, int $status = 400, $data = null): WP_REST_Response
    {
        $response = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];

        return new WP_REST_Response($response, $status);
    }
}
