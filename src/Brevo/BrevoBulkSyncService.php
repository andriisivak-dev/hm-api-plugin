<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class BrevoBulkSyncService
{
    private const LOCK_KEY = 'csp_brevo_bulk_sync_lock';

    private SyncQueueInterface $sync_queue;
    private BrevoSettings $settings;
    private BrevoLogger $logger;

    public function __construct(
        ?SyncQueueInterface $sync_queue = null,
        ?BrevoSettings $settings = null,
        ?BrevoLogger $logger = null
    ) {
        $this->settings = $settings ?? new BrevoSettings();
        $this->sync_queue = $sync_queue ?? SyncQueueFactory::create();
        $this->logger = $logger ?? new BrevoLogger($this->settings);
    }

    /**
     * @return array<string,mixed>
     */
    public function schedule_all_customers(string $source = 'admin_bulk'): array
    {
        $source = sanitize_key($source);
        if ($source === '') {
            $source = 'admin_bulk';
        }

        if (!$this->settings->is_bulk_sync_enabled()) {
            return $this->result(false, 'disabled');
        }

        if (!$this->acquire_lock()) {
            return $this->result(false, 'locked');
        }

        $processed_count = 0;
        $eligible_count = 0;
        $scheduled_count = 0;
        $already_queued_count = 0;
        $skipped_invalid_count = 0;
        $failed_count = 0;

        try {
            $batch_size = max(10, $this->settings->get_bulk_sync_batch_size());
            $last_id = 0;

            while (true) {
                $batch = $this->get_customer_batch($last_id, $batch_size);
                if ($batch === []) {
                    break;
                }

                foreach ($batch as $row) {
                    $customer_id = isset($row->id) ? (int) $row->id : 0;
                    if ($customer_id <= 0) {
                        continue;
                    }

                    $last_id = max($last_id, $customer_id);
                    $processed_count++;

                    $email = sanitize_email((string) ($row->email ?? ''));
                    if ($email === '' || !is_email($email)) {
                        $skipped_invalid_count++;
                        continue;
                    }

                    $eligible_count++;

                    $enqueue_result = $this->enqueue_customer($customer_id, $source);
                    if ($enqueue_result === 'scheduled') {
                        $scheduled_count++;
                        continue;
                    }

                    if ($enqueue_result === 'already_queued') {
                        $already_queued_count++;
                        continue;
                    }

                    $failed_count++;
                }
            }

            $this->logger->info('brevo_bulk_sync_scheduled', [
                'source' => $source,
                'processed_count' => $processed_count,
                'eligible_count' => $eligible_count,
                'scheduled_count' => $scheduled_count,
                'already_queued_count' => $already_queued_count,
                'skipped_invalid_count' => $skipped_invalid_count,
                'failed_count' => $failed_count,
            ]);

            return $this->result(true, 'scheduled', [
                'processed_count' => $processed_count,
                'eligible_count' => $eligible_count,
                'scheduled_count' => $scheduled_count,
                'already_queued_count' => $already_queued_count,
                'skipped_invalid_count' => $skipped_invalid_count,
                'failed_count' => $failed_count,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('brevo_bulk_sync_schedule_failed', [
                'source' => $source,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
                'processed_count' => $processed_count,
                'eligible_count' => $eligible_count,
                'scheduled_count' => $scheduled_count,
                'already_queued_count' => $already_queued_count,
                'skipped_invalid_count' => $skipped_invalid_count,
                'failed_count' => $failed_count,
            ]);

            return $this->result(false, 'failed', [
                'processed_count' => $processed_count,
                'eligible_count' => $eligible_count,
                'scheduled_count' => $scheduled_count,
                'already_queued_count' => $already_queued_count,
                'skipped_invalid_count' => $skipped_invalid_count,
                'failed_count' => $failed_count,
            ]);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * @return object[]
     */
    private function get_customer_batch(int $after_id, int $limit): array
    {
        global $wpdb;

        $table = CustomerRepository::table();
        $sql = $wpdb->prepare(
            "SELECT id, email FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            max(0, $after_id),
            max(1, $limit)
        );

        return (array) $wpdb->get_results($sql);
    }

    private function enqueue_customer(int $customer_id, string $source): string
    {
        $job = [
            'customer_id' => $customer_id,
            'action' => CustomerSyncService::ACTION_UPSERT,
            'source' => $source,
        ];

        try {
            if ($this->sync_queue->enqueue($job)) {
                return 'scheduled';
            }

            if ($this->sync_queue->is_job_queued($job)) {
                return 'already_queued';
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('brevo_bulk_sync_enqueue_exception', [
                'customer_id' => $customer_id,
                'source' => $source,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
        }

        return 'failed';
    }

    private function acquire_lock(): bool
    {
        $lock_key = $this->get_lock_key();
        if (get_transient($lock_key)) {
            return false;
        }

        return set_transient($lock_key, 1, max(1, $this->settings->get_bulk_sync_lock_ttl()));
    }

    private function release_lock(): void
    {
        delete_transient($this->get_lock_key());
    }

    private function get_lock_key(): string
    {
        return self::LOCK_KEY;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function result(bool $success, string $reason, array $extra = []): array
    {
        return array_merge([
            'success' => $success,
            'reason' => $reason,
            'processed_count' => 0,
            'eligible_count' => 0,
            'scheduled_count' => 0,
            'already_queued_count' => 0,
            'skipped_invalid_count' => 0,
            'failed_count' => 0,
        ], $extra);
    }
}
