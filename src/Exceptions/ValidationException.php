<?php

declare(strict_types=1);

namespace CSP\Exceptions;

use CSP\API\Responses\ErrorCodes;

class ValidationException extends ApiException
{
    public function __construct(string $message, $data = null)
    {
        parent::__construct($message, ErrorCodes::VALIDATION_ERROR, 422, $data);
    }
}
