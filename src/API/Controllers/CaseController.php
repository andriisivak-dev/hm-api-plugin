<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use WP_REST_Response;

use CSP\API\Responses\ApiResponse;

class CaseController
{
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return ApiResponse::success([
            'message' => 'Cases endpoint works',
        ]);
    }
}