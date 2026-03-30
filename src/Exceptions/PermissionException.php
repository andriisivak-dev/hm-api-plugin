<?php

declare(strict_types=1);

namespace CSP\Exceptions;

use CSP\API\Responses\ErrorCodes;

class PermissionException extends ApiException
{
    public function __construct(string $message = 'You do not have permission to perform this action.', $data = null)
    {
        parent::__construct($message, ErrorCodes::FORBIDDEN, 403, $data);
    }
}
