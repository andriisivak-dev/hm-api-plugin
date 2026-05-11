<?php

declare(strict_types=1);

namespace CSP\Brevo;

interface SyncQueueInterface
{
    public function register(): void;

    /**
     * @param array<string,mixed> $job
     */
    public function enqueue(array $job, int $delay_seconds = 0): bool;

    /**
     * @param array<string,mixed> $job
     */
    public function is_job_queued(array $job): bool;
}
