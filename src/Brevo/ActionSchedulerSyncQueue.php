<?php

declare(strict_types=1);

namespace CSP\Brevo;

class ActionSchedulerSyncQueue implements SyncQueueInterface
{
    public const JOB_HOOK = 'csp_brevo_process_sync_job';
    private const GROUP = 'csp-brevo-sync';

    private CustomerSyncJobHandler $job_handler;
    private BrevoSettings $settings;
    private BrevoLogger $logger;

    public function __construct(
        ?CustomerSyncJobHandler $job_handler = null,
        ?BrevoSettings $settings = null,
        ?BrevoLogger $logger = null
    ) {
        $this->settings = $settings ?? new BrevoSettings();
        $this->job_handler = $job_handler ?? new CustomerSyncJobHandler(null, $this->settings);
        $this->logger = $logger ?? new BrevoLogger($this->settings);
    }

    public function register(): void
    {
        add_action(self::JOB_HOOK, [$this, 'handle_job'], 10, 1);
    }

    /**
     * @param array<string,mixed> $job
     */
    public function enqueue(array $job, int $delay_seconds = 0): bool
    {
        if (!self::is_available()) {
            $this->logger->warning('brevo_action_scheduler_unavailable');
            return false;
        }

        $job = $this->normalize_job($job);
        if ($job === null) {
            $this->logger->warning('brevo_queue_invalid_job');
            return false;
        }

        if ($this->is_job_queued($job)) {
            $this->logger->debug('brevo_queue_duplicate_skipped', [
                'customer_id' => $job['customer_id'],
                'action' => $job['action'],
            ]);
            return false;
        }

        $action_id = as_schedule_single_action(
            time() + max(0, $delay_seconds),
            self::JOB_HOOK,
            [$job],
            self::GROUP,
            true
        );

        if ((int) $action_id > 0) {
            $this->set_dedupe_lock($job);
            return true;
        }

        $this->logger->warning('brevo_queue_schedule_failed', [
            'customer_id' => $job['customer_id'],
            'action' => $job['action'],
        ]);

        return false;
    }

    /**
     * @param array<string,mixed> $job
     */
    public function is_job_queued(array $job): bool
    {
        if (!self::is_available()) {
            return false;
        }

        $job = $this->normalize_job($job);
        if ($job === null) {
            return false;
        }

        if ($this->has_dedupe_lock($job)) {
            return true;
        }

        return as_next_scheduled_action(self::JOB_HOOK, [$job], self::GROUP) !== false;
    }

    /**
     * @param mixed $job
     */
    public function handle_job($job): void
    {
        if (!is_array($job)) {
            return;
        }

        $normalized_job = $this->normalize_job($job);
        if ($normalized_job === null) {
            return;
        }

        $this->clear_dedupe_lock($normalized_job);

        $result = $this->job_handler->handle($normalized_job);
        $success = (bool) ($result['success'] ?? false);
        $retryable = (bool) ($result['retryable'] ?? false);

        if ($success || !$retryable) {
            return;
        }

        $retry_count = (int) ($normalized_job['retry_count'] ?? 0);
        $max_retries = max(0, $this->settings->get_max_retries());

        if ($retry_count >= $max_retries) {
            $this->logger->warning('brevo_queue_retries_exhausted', [
                'customer_id' => $normalized_job['customer_id'],
                'action' => $normalized_job['action'],
                'retry_count' => $retry_count,
            ]);
            return;
        }

        $normalized_job['retry_count'] = $retry_count + 1;
        $delay_seconds = $this->get_retry_delay_seconds($retry_count);
        if (!$this->enqueue($normalized_job, $delay_seconds)) {
            $this->logger->warning('brevo_queue_retry_schedule_failed', [
                'customer_id' => $normalized_job['customer_id'],
                'action' => $normalized_job['action'],
                'retry_count' => $normalized_job['retry_count'],
            ]);
        }
    }

    public static function is_available(): bool
    {
        return function_exists('as_schedule_single_action')
            && function_exists('as_next_scheduled_action');
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>|null
     */
    private function normalize_job(array $job): ?array
    {
        $customer_id = (int) ($job['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            return null;
        }

        $action = sanitize_key((string) ($job['action'] ?? CustomerSyncService::ACTION_UPSERT));
        if (!in_array($action, [CustomerSyncService::ACTION_UPSERT, CustomerSyncService::ACTION_SOFT_DELETE], true)) {
            $action = CustomerSyncService::ACTION_UPSERT;
        }

        return [
            'customer_id' => $customer_id,
            'action' => $action,
            'source' => sanitize_key((string) ($job['source'] ?? 'queue')),
            'retry_count' => max(0, (int) ($job['retry_count'] ?? 0)),
        ];
    }

    /**
     * @param array<string,mixed> $job
     */
    private function set_dedupe_lock(array $job): void
    {
        set_transient($this->get_dedupe_key($job), 1, $this->get_dedupe_ttl());
    }

    /**
     * @param array<string,mixed> $job
     */
    private function clear_dedupe_lock(array $job): void
    {
        delete_transient($this->get_dedupe_key($job));
    }

    /**
     * @param array<string,mixed> $job
     */
    private function has_dedupe_lock(array $job): bool
    {
        return (bool) get_transient($this->get_dedupe_key($job));
    }

    /**
     * @param array<string,mixed> $job
     */
    private function get_dedupe_key(array $job): string
    {
        return 'csp_brevo_queue_job_' . md5((string) $job['customer_id'] . '|' . (string) $job['action']);
    }

    private function get_dedupe_ttl(): int
    {
        return max(60, $this->settings->get_bulk_sync_lock_ttl());
    }

    private function get_retry_delay_seconds(int $retry_count): int
    {
        return min(300, 30 * (2 ** max(0, $retry_count)));
    }
}
