<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class BrevoBulkSyncService
{
    public const BATCH_HOOK = 'csp_brevo_process_bulk_batch';

    private const BATCH_GROUP = 'csp-brevo-bulk-sync';
    private const OPTION_RUN_STATE = 'csp_brevo_bulk_sync_run_state';
    private const LOCK_KEY_PREFIX = 'csp_brevo_bulk_sync_batch_lock_';

    private const STATUS_IDLE = 'idle';
    private const STATUS_RUNNING = 'running';
    private const STATUS_STOPPING = 'stopping';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_FAILED = 'failed';
    private const TMP_DEBUG_PREFIX = 'tmp_bulk_dbg_';

    private ?SyncQueueInterface $sync_queue;
    private BrevoSettings $settings;
    private BrevoLogger $logger;
    private CustomerSyncService $sync_service;

    public function __construct(
        ?SyncQueueInterface $sync_queue = null,
        ?BrevoSettings $settings = null,
        ?BrevoLogger $logger = null,
        ?CustomerSyncService $sync_service = null
    ) {
        $this->settings = $settings ?? new BrevoSettings();
        $this->sync_queue = $sync_queue;
        $this->logger = $logger ?? new BrevoLogger($this->settings);
        $this->sync_service = $sync_service ?? new CustomerSyncService($this->settings);
    }

    public function register(): void
    {
        add_action(self::BATCH_HOOK, [$this, 'process_bulk_batch'], 10, 1);
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

        $current_state = $this->get_run_state();
        if ($this->is_run_active($current_state)) {
            return $this->result(false, 'locked', [
                'run_id' => (string) ($current_state['run_id'] ?? ''),
            ]);
        }

        $total_count = $this->get_total_customers_count();
        if ($total_count <= 0) {
            return $this->result(true, 'nothing_to_sync');
        }

        $state = $this->build_initial_state($source, $total_count);
        $this->save_run_state($state);
        $this->tmp_debug('run_initialized', [
            'run_id' => (string) $state['run_id'],
            'source' => $source,
            'total_count' => $total_count,
            'batch_size' => $this->settings->get_bulk_sync_batch_size(),
            'action_scheduler_available' => ActionSchedulerSyncQueue::is_available(),
        ]);

        if (!$this->schedule_next_batch((string) $state['run_id'])) {
            $state['status'] = self::STATUS_FAILED;
            $state['queue_failed_count'] = 1;
            $state['message'] = 'Failed to schedule first bulk batch.';
            $state['updated_at'] = gmdate('c');
            $state['finished_at'] = gmdate('c');
            $this->save_run_state($state);

            $this->logger->error('brevo_bulk_sync_schedule_first_batch_failed', [
                'run_id' => (string) $state['run_id'],
                'source' => $source,
                'total_count' => $total_count,
            ]);

            return $this->result(false, 'failed', [
                'run_id' => (string) $state['run_id'],
                'total_count' => $total_count,
                'queue_failed_count' => 1,
                'failed_count' => 1,
            ]);
        }

        $state['scheduled_batches'] = 1;
        $state['updated_at'] = gmdate('c');
        $this->save_run_state($state);

        $this->logger->info('brevo_bulk_sync_started', [
            'run_id' => (string) $state['run_id'],
            'source' => $source,
            'total_count' => $total_count,
            'batch_size' => $this->settings->get_bulk_sync_batch_size(),
        ]);

        return $this->result(true, 'scheduled', [
            'run_id' => (string) $state['run_id'],
            'total_count' => $total_count,
            'scheduled_batches' => 1,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function request_stop(): array
    {
        $state = $this->get_run_state();
        if (!$this->is_run_active($state)) {
            return $this->result(false, 'no_active_run');
        }

        if (!empty($state['cancel_requested'])) {
            return $this->result(true, 'already_stopping', [
                'run_id' => (string) ($state['run_id'] ?? ''),
            ]);
        }

        $state['cancel_requested'] = true;
        $state['status'] = self::STATUS_STOPPING;
        $state['updated_at'] = gmdate('c');
        $this->save_run_state($state);
        $this->tmp_debug('stop_requested', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'eligible_count' => (int) ($state['eligible_count'] ?? 0),
            'status' => (string) ($state['status'] ?? ''),
        ]);

        $this->logger->info('brevo_bulk_sync_stop_requested', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'eligible_count' => (int) ($state['eligible_count'] ?? 0),
        ]);

        return $this->result(true, 'stopping', [
            'run_id' => (string) ($state['run_id'] ?? ''),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_run_state(): array
    {
        $raw = get_option(self::OPTION_RUN_STATE, []);
        $state = is_array($raw) ? $raw : [];

        return $this->normalize_state($state);
    }

    /**
     * @param mixed $payload
     */
    public function process_bulk_batch($payload): void
    {
        $run_id = '';
        if (is_array($payload) && isset($payload['run_id'])) {
            $run_id = sanitize_key((string) $payload['run_id']);
        }

        if ($run_id === '') {
            $this->tmp_debug('batch_skipped_missing_run_id', [
                'payload_type' => gettype($payload),
            ]);
            return;
        }

        $state = $this->get_run_state();
        if ((string) ($state['run_id'] ?? '') !== $run_id) {
            $this->tmp_debug('batch_skipped_run_mismatch', [
                'incoming_run_id' => $run_id,
                'state_run_id' => (string) ($state['run_id'] ?? ''),
                'state_status' => (string) ($state['status'] ?? ''),
            ]);
            return;
        }

        if (!$this->is_run_active($state)) {
            $this->tmp_debug('batch_skipped_inactive', [
                'run_id' => $run_id,
                'state_status' => (string) ($state['status'] ?? ''),
            ]);
            return;
        }

        if (!$this->acquire_batch_lock($run_id)) {
            $this->logger->warning('brevo_bulk_batch_lock_skipped', [
                'run_id' => $run_id,
            ]);

            return;
        }

        try {
            $state = $this->get_run_state();
            if ((string) ($state['run_id'] ?? '') !== $run_id || !$this->is_run_active($state)) {
                $this->tmp_debug('batch_skipped_after_lock', [
                    'run_id' => $run_id,
                    'state_run_id' => (string) ($state['run_id'] ?? ''),
                    'state_status' => (string) ($state['status'] ?? ''),
                ]);
                return;
            }

            $this->tmp_debug('batch_started', [
                'run_id' => $run_id,
                'last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
                'batch_size' => max(1, $this->settings->get_bulk_sync_batch_size()),
                'scanned_count' => (int) ($state['scanned_count'] ?? 0),
                'processed_count' => (int) ($state['processed_count'] ?? 0),
                'eligible_count' => (int) ($state['eligible_count'] ?? 0),
                'success_count' => (int) ($state['success_count'] ?? 0),
                'failed_count' => (int) ($state['failed_count'] ?? 0),
                'skipped_invalid_count' => (int) ($state['skipped_invalid_count'] ?? 0),
            ]);

            if (!empty($state['cancel_requested'])) {
                $state['status'] = self::STATUS_CANCELLED;
                $state['finished_at'] = gmdate('c');
                $state['updated_at'] = gmdate('c');
                $state['message'] = 'Bulk sync stopped by user.';
                $this->save_run_state($state);

                $this->logger->info('brevo_bulk_sync_cancelled', [
                    'run_id' => $run_id,
                    'processed_count' => (int) ($state['processed_count'] ?? 0),
                    'eligible_count' => (int) ($state['eligible_count'] ?? 0),
                    'success_count' => (int) ($state['success_count'] ?? 0),
                    'failed_count' => (int) ($state['failed_count'] ?? 0),
                ]);

                return;
            }

            $batch_size = max(1, $this->settings->get_bulk_sync_batch_size());
            $last_customer_id = (int) ($state['last_customer_id'] ?? 0);
            $rows = $this->get_customer_batch($last_customer_id, $batch_size);
            $this->tmp_debug('batch_rows_loaded', [
                'run_id' => $run_id,
                'last_customer_id' => $last_customer_id,
                'batch_size' => $batch_size,
                'rows_count' => count($rows),
            ]);

            if ($rows === []) {
                $state['status'] = self::STATUS_COMPLETED;
                $state['finished_at'] = gmdate('c');
                $state['updated_at'] = gmdate('c');
                $state['message'] = '';
                $this->save_run_state($state);

                $this->logger->info('brevo_bulk_sync_completed', [
                    'run_id' => $run_id,
                    'source' => (string) ($state['source'] ?? 'admin_bulk'),
                    'processed_count' => (int) ($state['processed_count'] ?? 0),
                    'eligible_count' => (int) ($state['eligible_count'] ?? 0),
                    'success_count' => (int) ($state['success_count'] ?? 0),
                    'failed_count' => (int) ($state['failed_count'] ?? 0),
                    'skipped_invalid_count' => (int) ($state['skipped_invalid_count'] ?? 0),
                    'queue_failed_count' => (int) ($state['queue_failed_count'] ?? 0),
                ]);

                return;
            }

            $batch_scanned = 0;
            $batch_eligible = 0;
            $batch_processed = 0;
            $batch_success = 0;
            $batch_failed = 0;
            $batch_skipped_invalid = 0;

            foreach ($rows as $row) {
                $customer_id = isset($row->id) ? (int) $row->id : 0;
                if ($customer_id <= 0) {
                    continue;
                }

                $state['last_customer_id'] = max((int) $state['last_customer_id'], $customer_id);
                $state['scanned_count']++;
                $batch_scanned++;

                $email = sanitize_email((string) ($row->email ?? ''));
                if ($email === '' || !is_email($email)) {
                    $state['skipped_invalid_count']++;
                    $batch_skipped_invalid++;
                    continue;
                }

                $state['eligible_count']++;
                $state['processed_count']++;
                $batch_eligible++;
                $batch_processed++;

                $result = $this->sync_service->sync_upsert($customer_id, (string) ($state['source'] ?? 'admin_bulk'));
                if (!empty($result['success']) && empty($result['skipped'])) {
                    $state['success_count']++;
                    $batch_success++;
                    continue;
                }

                if (!empty($result['success']) && !empty($result['skipped'])) {
                    continue;
                }

                $state['failed_count']++;
                $batch_failed++;
            }

            $this->tmp_debug('batch_processed', [
                'run_id' => $run_id,
                'batch_scanned' => $batch_scanned,
                'batch_eligible' => $batch_eligible,
                'batch_processed' => $batch_processed,
                'batch_success' => $batch_success,
                'batch_failed' => $batch_failed,
                'batch_skipped_invalid' => $batch_skipped_invalid,
                'state_last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
                'state_scanned_count' => (int) ($state['scanned_count'] ?? 0),
                'state_eligible_count' => (int) ($state['eligible_count'] ?? 0),
                'state_processed_count' => (int) ($state['processed_count'] ?? 0),
                'state_success_count' => (int) ($state['success_count'] ?? 0),
                'state_failed_count' => (int) ($state['failed_count'] ?? 0),
                'state_skipped_invalid_count' => (int) ($state['skipped_invalid_count'] ?? 0),
            ]);

            $latest_state = $this->get_run_state();
            if ((string) ($latest_state['run_id'] ?? '') === $run_id && !empty($latest_state['cancel_requested'])) {
                $state['cancel_requested'] = true;
            }

            $state['updated_at'] = gmdate('c');

            if (!empty($state['cancel_requested'])) {
                $state['status'] = self::STATUS_CANCELLED;
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Bulk sync stopped by user.';
                $this->save_run_state($state);

                $this->logger->info('brevo_bulk_sync_cancelled', [
                    'run_id' => $run_id,
                    'processed_count' => (int) ($state['processed_count'] ?? 0),
                    'eligible_count' => (int) ($state['eligible_count'] ?? 0),
                    'success_count' => (int) ($state['success_count'] ?? 0),
                    'failed_count' => (int) ($state['failed_count'] ?? 0),
                ]);

                return;
            }

            if (!$this->schedule_next_batch($run_id)) {
                $state['status'] = self::STATUS_FAILED;
                $state['queue_failed_count']++;
                $state['message'] = 'Failed to schedule next bulk batch.';
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $this->save_run_state($state);

                $this->logger->warning('brevo_bulk_sync_schedule_next_batch_failed', [
                    'run_id' => $run_id,
                    'processed_count' => (int) ($state['processed_count'] ?? 0),
                    'eligible_count' => (int) ($state['eligible_count'] ?? 0),
                    'success_count' => (int) ($state['success_count'] ?? 0),
                    'failed_count' => (int) ($state['failed_count'] ?? 0),
                ]);

                return;
            }

            $state['scheduled_batches']++;
            $state['updated_at'] = gmdate('c');
            $this->save_run_state($state);
            $this->tmp_debug('batch_scheduled_next_success', [
                'run_id' => $run_id,
                'scheduled_batches' => (int) ($state['scheduled_batches'] ?? 0),
                'last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
                'processed_count' => (int) ($state['processed_count'] ?? 0),
                'eligible_count' => (int) ($state['eligible_count'] ?? 0),
            ]);
        } catch (\Throwable $exception) {
            $state = $this->get_run_state();
            if ((string) ($state['run_id'] ?? '') === $run_id) {
                $state['status'] = self::STATUS_FAILED;
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Bulk sync batch crashed.';
                $this->save_run_state($state);
            }

            $this->logger->error('brevo_bulk_sync_batch_exception', [
                'run_id' => $run_id,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
            $this->tmp_debug('batch_exception', [
                'run_id' => $run_id,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ], 'error');
        } finally {
            $this->release_batch_lock($run_id);
            $this->tmp_debug('batch_finished', [
                'run_id' => $run_id,
            ]);
        }
    }

    private function get_total_customers_count(): int
    {
        global $wpdb;

        $table = CustomerRepository::table();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return max(0, (int) $count);
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

    /**
     * @return array<string,mixed>
     */
    private function build_initial_state(string $source, int $total_count): array
    {
        return [
            'run_id' => $this->generate_run_id(),
            'status' => self::STATUS_RUNNING,
            'source' => $source,
            'started_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'finished_at' => null,
            'total_count' => max(0, $total_count),
            'scanned_count' => 0,
            'eligible_count' => 0,
            'processed_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_invalid_count' => 0,
            'queue_failed_count' => 0,
            'scheduled_batches' => 0,
            'last_customer_id' => 0,
            'cancel_requested' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function save_run_state(array $state): void
    {
        update_option(self::OPTION_RUN_STATE, $this->normalize_state($state), false);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function normalize_state(array $state): array
    {
        $normalized = [
            'run_id' => sanitize_key((string) ($state['run_id'] ?? '')),
            'status' => sanitize_key((string) ($state['status'] ?? self::STATUS_IDLE)),
            'source' => sanitize_key((string) ($state['source'] ?? '')),
            'started_at' => $this->normalize_datetime_string($state['started_at'] ?? null),
            'updated_at' => $this->normalize_datetime_string($state['updated_at'] ?? null),
            'finished_at' => $this->normalize_datetime_string($state['finished_at'] ?? null),
            'total_count' => max(0, (int) ($state['total_count'] ?? 0)),
            'scanned_count' => max(0, (int) ($state['scanned_count'] ?? 0)),
            'eligible_count' => max(0, (int) ($state['eligible_count'] ?? 0)),
            'processed_count' => max(0, (int) ($state['processed_count'] ?? 0)),
            'success_count' => max(0, (int) ($state['success_count'] ?? 0)),
            'failed_count' => max(0, (int) ($state['failed_count'] ?? 0)),
            'skipped_invalid_count' => max(0, (int) ($state['skipped_invalid_count'] ?? 0)),
            'queue_failed_count' => max(0, (int) ($state['queue_failed_count'] ?? 0)),
            'scheduled_batches' => max(0, (int) ($state['scheduled_batches'] ?? 0)),
            'last_customer_id' => max(0, (int) ($state['last_customer_id'] ?? 0)),
            'cancel_requested' => !empty($state['cancel_requested']),
            'message' => sanitize_text_field((string) ($state['message'] ?? '')),
        ];

        $active_statuses = [
            self::STATUS_RUNNING,
            self::STATUS_STOPPING,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_IDLE,
        ];

        if (!in_array($normalized['status'], $active_statuses, true)) {
            $normalized['status'] = self::STATUS_IDLE;
        }

        return $normalized;
    }

    private function normalize_datetime_string($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(bool $success, string $reason, array $extra = []): array
    {
        return array_merge([
            'success' => $success,
            'reason' => $reason,
            'run_id' => '',
            'total_count' => 0,
            'eligible_count' => 0,
            'processed_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_invalid_count' => 0,
            'queue_failed_count' => 0,
            'scheduled_batches' => 0,
        ], $extra);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function is_run_active(array $state): bool
    {
        return in_array((string) ($state['status'] ?? ''), [self::STATUS_RUNNING, self::STATUS_STOPPING], true)
            && (string) ($state['run_id'] ?? '') !== '';
    }

    private function schedule_next_batch(string $run_id, int $delay_seconds = 0): bool
    {
        $payload = ['run_id' => sanitize_key($run_id)];
        if ((string) $payload['run_id'] === '') {
            $this->tmp_debug('schedule_next_invalid_run_id', [
                'raw_run_id' => $run_id,
            ], 'warning');
            return false;
        }

        $scheduled_at = time() + max(0, $delay_seconds);
        $scheduler = ActionSchedulerSyncQueue::is_available() ? 'action_scheduler' : 'wp_cron';
        $this->tmp_debug('schedule_next_attempt', [
            'run_id' => (string) $payload['run_id'],
            'scheduler' => $scheduler,
            'delay_seconds' => max(0, $delay_seconds),
            'scheduled_at' => gmdate('c', $scheduled_at),
            'hook' => self::BATCH_HOOK,
            'group' => self::BATCH_GROUP,
            'unique_flag' => true,
        ]);

        if (ActionSchedulerSyncQueue::is_available()) {
            $existing_next = function_exists('as_next_scheduled_action')
                ? as_next_scheduled_action(self::BATCH_HOOK, [$payload], self::BATCH_GROUP)
                : false;

            $action_id = as_schedule_single_action(
                $scheduled_at,
                self::BATCH_HOOK,
                [$payload],
                self::BATCH_GROUP,
                true
            );
            $success = (int) $action_id > 0;

            if (!$success) {
                $this->tmp_debug('schedule_next_failed', [
                    'run_id' => (string) $payload['run_id'],
                    'scheduler' => $scheduler,
                    'existing_next' => $existing_next,
                    'returned_action_id' => (int) $action_id,
                ], 'warning');
                return false;
            }

            $this->tmp_debug('schedule_next_success', [
                'run_id' => (string) $payload['run_id'],
                'scheduler' => $scheduler,
                'action_id' => (int) $action_id,
                'existing_next_before' => $existing_next,
            ]);
            return true;
        }

        $existing_next = wp_next_scheduled(self::BATCH_HOOK, [$payload]);
        $scheduled = wp_schedule_single_event(
            $scheduled_at,
            self::BATCH_HOOK,
            [$payload]
        ) !== false;

        if (!$scheduled) {
            $this->tmp_debug('schedule_next_failed', [
                'run_id' => (string) $payload['run_id'],
                'scheduler' => $scheduler,
                'existing_next' => $existing_next,
            ], 'warning');
            return false;
        }

        $this->tmp_debug('schedule_next_success', [
            'run_id' => (string) $payload['run_id'],
            'scheduler' => $scheduler,
            'existing_next_before' => $existing_next,
        ]);

        return true;
    }

    private function acquire_batch_lock(string $run_id): bool
    {
        $lock_key = $this->get_batch_lock_key($run_id);
        if ((bool) get_transient($lock_key)) {
            return false;
        }

        return set_transient(
            $lock_key,
            1,
            max(30, $this->settings->get_bulk_sync_lock_ttl())
        );
    }

    private function release_batch_lock(string $run_id): void
    {
        delete_transient($this->get_batch_lock_key($run_id));
    }

    private function get_batch_lock_key(string $run_id): string
    {
        return self::LOCK_KEY_PREFIX . sanitize_key($run_id);
    }

    private function generate_run_id(): string
    {
        $run_id = sanitize_key(str_replace('-', '', wp_generate_uuid4()));
        if ($run_id !== '') {
            return $run_id;
        }

        return sanitize_key('bulk' . gmdate('YmdHis') . wp_rand(1000, 9999));
    }

    /**
     * Temporary debugging instrumentation for bulk sync. Remove after diagnosis.
     *
     * @param array<string,mixed> $context
     */
    private function tmp_debug(string $event, array $context = [], string $level = 'info'): void
    {
        $event_name = self::TMP_DEBUG_PREFIX . sanitize_key($event);
        $context['tmp_debug'] = true;

        if ($level === 'warning') {
            $this->logger->warning($event_name, $context);
            return;
        }

        if ($level === 'error') {
            $this->logger->error($event_name, $context);
            return;
        }

        $this->logger->info($event_name, $context);
    }
}
