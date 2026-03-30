<?php

declare(strict_types=1);

namespace CSP\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private $data;

    public function __construct(string $message, string $errorCode, int $httpStatus = 400, $data = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getData()
    {
        return $this->data;
    }
}
