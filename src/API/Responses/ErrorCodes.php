<?php

declare(strict_types=1);

namespace CSP\API\Responses;

class ErrorCodes
{
    public const FORBIDDEN = 'CSP_FORBIDDEN';
    public const UNAUTHORIZED = 'CSP_UNAUTHORIZED';
    public const NOT_FOUND = 'CSP_NOT_FOUND';
    public const BAD_REQUEST = 'CSP_BAD_REQUEST';
    public const VALIDATION_ERROR = 'CSP_VALIDATION_ERROR';
    public const INTERNAL_ERROR = 'CSP_INTERNAL_ERROR';
}
