<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\Services\GravityFormsService;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;

class FormController
{
    private GravityFormsService $gfService;

    public function __construct(GravityFormsService $gfService)
    {
        $this->gfService = $gfService;
    }

    public function getSchema(WP_REST_Request $request)
    {
        $form_id = (int) $request->get_param('id');
        $schema = $this->gfService->getFormSchema($form_id);

        if (!$schema) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, __('Form schema not found', 'csp'), 404);
        }

        return ApiResponse::success($schema);
    }
}
