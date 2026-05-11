<?php

declare(strict_types=1);

namespace CSP\Brevo;

use RuntimeException;

class BrevoApiException extends RuntimeException
{
    private int $status_code;
    private bool $retryable;
    private ?string $error_code;
    private array $error_data;

    public function __construct(
        string $message,
        int $status_code = 0,
        bool $retryable = false,
        ?string $error_code = null,
        array $error_data = []
    ) {
        parent::__construct($message, $status_code);
        $this->status_code = $status_code;
        $this->retryable = $retryable;
        $this->error_code = $error_code;
        $this->error_data = $error_data;
    }

    public function get_status_code(): int
    {
        return $this->status_code;
    }

    public function is_retryable(): bool
    {
        return $this->retryable;
    }

    public function get_error_code(): ?string
    {
        return $this->error_code;
    }

    public function get_error_data(): array
    {
        return $this->error_data;
    }
}
