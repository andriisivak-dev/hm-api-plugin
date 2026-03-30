<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use WP_REST_Response;

class CaseController
{
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'message' => 'Cases endpoint works',
            ],
        ]);
    }
}