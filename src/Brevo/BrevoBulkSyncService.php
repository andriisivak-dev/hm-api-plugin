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
    private const STATUS_INTERRUPTED = 'interrupted';

    private ?SyncQueueInterface $sync_queue;
    private BrevoSettings $settings;
    private BrevoLogger $logger;
    private BrevoContactService $contact_service;
    private CustomerBrevoMapper $mapper;
    private CustomerChangeDetector $change_detector;
    private CustomerBrevoSyncMetaRepository $sync_meta_repository;

    public function __construct(
        ?SyncQueueInterface $sync_queue = null,
        ?BrevoSettings $settings = null,
        ?BrevoLogger $logger = null,
        ?BrevoContactService $contact_service = null,
        ?CustomerBrevoMapper $mapper = null,
        ?CustomerChangeDetector $change_detector = null,
        ?CustomerBrevoSyncMetaRepository $sync_meta_repository = null
    ) {
        $this->sync_queue = $sync_queue;
        $this->settings = $settings ?? new BrevoSettings();
        $this->logger = $logger ?? new BrevoLogger($this->settings);
        $this->contact_service = $contact_service ?? new BrevoContactService(
            new BrevoApiClient($this->settings, $this->logger),
            $this->logger
        );
        $this->mapper = $mapper ?? new CustomerBrevoMapper($this->settings);
        $this->sync_meta_repository = $sync_meta_repository ?? new CustomerBrevoSyncMetaRepository();
        $this->change_detector = $change_detector ?? new CustomerChangeDetector($this->sync_meta_repository);
    }

    public function register(): void
    {
        add_action(self::BATCH_HOOK, [$this, 'process_bulk_batch'], 10, 1);
    }

    /**
     * @return array<string,mixed>
     */
    public function schedule_all_customers(string $source = 'admin_bulk', bool $force_restart = false): array
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

        if (!$force_restart && $this->is_run_resumable($current_state)) {
            return $this->result(false, 'resume_required', [
                'run_id' => (string) ($current_state['run_id'] ?? ''),
            ]);
        }

        $cleared_failed_count = $this->sync_meta_repository->clear_failed_sync_log();
        if ($cleared_failed_count > 0) {
            $this->logger->info('brevo_failed_sync_log_cleared', [
                'source' => $source,
                'cleared_count' => $cleared_failed_count,
                'success' => true,
            ]);
        }

        $total_count = $this->get_total_customers_count();
        if ($total_count <= 0) {
            return $this->result(true, 'nothing_to_sync', [
                'cleared_failed_count' => $cleared_failed_count,
            ]);
        }

        $state = $this->build_initial_state($source, $total_count);
        $this->save_run_state($state);

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
                'batch_size' => $this->settings->get_bulk_sync_batch_size(),
                'endpoint' => '/contacts/import',
                'method' => 'POST',
                'success' => false,
            ]);

            return $this->result(false, 'failed', [
                'run_id' => (string) $state['run_id'],
                'total_count' => $total_count,
                'queue_failed_count' => 1,
                'failed_count' => 1,
                'cleared_failed_count' => $cleared_failed_count,
            ]);
        }

        $state['scheduled_batches'] = 1;
        $state['updated_at'] = gmdate('c');
        $this->save_run_state($state);

        $this->logger->info('brevo_bulk_sync_started', [
            'run_id' => (string) $state['run_id'],
            'source' => $source,
            'forced_restart' => $force_restart,
            'total_count' => $total_count,
            'batch_size' => $this->settings->get_bulk_sync_batch_size(),
            'success' => true,
        ]);

        return $this->result(true, 'scheduled', [
            'run_id' => (string) $state['run_id'],
            'total_count' => $total_count,
            'scheduled_batches' => 1,
            'cleared_failed_count' => $cleared_failed_count,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function resume_from_checkpoint(string $source = 'admin_bulk_resume'): array
    {
        $source = sanitize_key($source);
        if ($source === '') {
            $source = 'admin_bulk_resume';
        }

        if (!$this->settings->is_bulk_sync_enabled()) {
            return $this->result(false, 'disabled');
        }

        $state = $this->get_run_state();
        if ($this->is_run_active($state)) {
            return $this->result(false, 'locked', [
                'run_id' => (string) ($state['run_id'] ?? ''),
            ]);
        }

        if (!$this->is_run_resumable($state)) {
            return $this->result(false, 'not_resumable');
        }

        $state['status'] = self::STATUS_RUNNING;
        $state['cancel_requested'] = false;
        $state['finished_at'] = null;
        $state['resumed_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $state['message'] = '';
        if ((string) ($state['source'] ?? '') === '') {
            $state['source'] = $source;
        }
        $this->save_run_state($state);

        if (!$this->schedule_next_batch((string) $state['run_id'])) {
            $state['status'] = self::STATUS_FAILED;
            $state['queue_failed_count'] = max(0, (int) ($state['queue_failed_count'] ?? 0)) + 1;
            $state['message'] = 'Failed to schedule resumed bulk batch.';
            $state['updated_at'] = gmdate('c');
            $state['finished_at'] = gmdate('c');
            $this->save_run_state($state);

            $this->logger->error('brevo_bulk_sync_resume_schedule_failed', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
                'processed_count' => (int) ($state['processed_count'] ?? 0),
                'success' => false,
            ]);

            return $this->result(false, 'failed', [
                'run_id' => (string) ($state['run_id'] ?? ''),
            ]);
        }

        $state['scheduled_batches'] = max(0, (int) ($state['scheduled_batches'] ?? 0)) + 1;
        $state['updated_at'] = gmdate('c');
        $this->save_run_state($state);

        $this->logger->info('brevo_bulk_sync_resumed', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'batch_size' => $this->settings->get_bulk_sync_batch_size(),
            'success' => true,
        ]);

        return $this->result(true, 'resumed', [
            'run_id' => (string) ($state['run_id'] ?? ''),
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
            $unscheduled_batches = $this->unschedule_pending_batches((string) ($state['run_id'] ?? ''));
            return $this->result(true, 'already_stopping', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'unscheduled_batches' => $unscheduled_batches,
            ]);
        }

        $state['cancel_requested'] = true;
        $state['status'] = self::STATUS_STOPPING;
        $state['stop_requested_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $this->save_run_state($state);
        $unscheduled_batches = $this->unschedule_pending_batches((string) ($state['run_id'] ?? ''));

        if (
            (string) ($state['run_id'] ?? '') !== ''
            && !$this->has_batch_lock((string) $state['run_id'])
            && !$this->has_scheduled_batch((string) $state['run_id'])
        ) {
            $this->finalize_cancelled_run($state, true);

            return $this->result(true, 'stopped', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'unscheduled_batches' => $unscheduled_batches,
            ]);
        }

        $this->logger->info('brevo_bulk_sync_stop_requested', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'source' => 'admin_button',
            'requested_at' => (string) $state['stop_requested_at'],
            'unscheduled_batches' => $unscheduled_batches,
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'eligible_count' => (int) ($state['eligible_count'] ?? 0),
            'success' => true,
        ]);

        return $this->result(true, 'stopping', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'unscheduled_batches' => $unscheduled_batches,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_run_state(): array
    {
        $raw = get_option(self::OPTION_RUN_STATE, []);
        $state = $this->normalize_state(is_array($raw) ? $raw : []);
        $run_id = (string) ($state['run_id'] ?? '');

        if (
            $run_id !== ''
            && (string) ($state['status'] ?? '') === self::STATUS_STOPPING
            && !$this->has_batch_lock($run_id)
            && !$this->has_scheduled_batch($run_id)
        ) {
            $this->finalize_cancelled_run($state, true);
            return $this->normalize_state($state);
        }

        if ($this->is_run_stale($state)) {
            $state['status'] = self::STATUS_INTERRUPTED;
            $state['updated_at'] = gmdate('c');
            $state['finished_at'] = gmdate('c');
            if ((string) ($state['message'] ?? '') === '') {
                $state['message'] = 'Bulk sync was interrupted before completion.';
            }
            $this->save_run_state($state);

            $this->logger->warning('brevo_bulk_sync_interrupted_detected', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'last_customer_id' => (int) ($state['last_customer_id'] ?? 0),
                'processed_count' => (int) ($state['processed_count'] ?? 0),
                'updated_at' => (string) ($state['updated_at'] ?? ''),
                'success' => false,
            ]);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function is_run_resumable(array $state): bool
    {
        $state = $this->normalize_state($state);
        if ((string) ($state['run_id'] ?? '') === '') {
            return false;
        }

        $status = (string) ($state['status'] ?? '');
        if (!in_array($status, [self::STATUS_INTERRUPTED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        $total_count = max(0, (int) ($state['total_count'] ?? 0));
        if ($total_count <= 0) {
            return false;
        }

        $scanned_count = max(0, (int) ($state['scanned_count'] ?? 0));
        return $scanned_count < $total_count;
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
            $this->logger->warning('brevo_bulk_batch_skipped_missing_run_id', [
                'success' => false,
            ]);
            return;
        }

        $state = $this->get_run_state();
        if ((string) ($state['run_id'] ?? '') !== $run_id || !$this->is_run_active($state)) {
            $this->logger->warning('brevo_bulk_batch_skipped_inactive_run', [
                'run_id' => $run_id,
                'state_run_id' => (string) ($state['run_id'] ?? ''),
                'state_status' => (string) ($state['status'] ?? ''),
                'success' => false,
            ]);
            return;
        }

        if (!$this->acquire_batch_lock($run_id)) {
            $this->logger->warning('brevo_bulk_batch_lock_skipped', [
                'run_id' => $run_id,
                'success' => false,
            ]);
            return;
        }

        try {
            $state = $this->get_run_state();
            if ((string) ($state['run_id'] ?? '') !== $run_id || !$this->is_run_active($state)) {
                return;
            }

            if (!empty($state['cancel_requested'])) {
                $this->finalize_cancelled_run($state, true);
                return;
            }

            $batch_size = $this->settings->get_bulk_sync_batch_size();
            $last_customer_id = (int) ($state['last_customer_id'] ?? 0);
            $rows = $this->get_customer_batch($last_customer_id, $batch_size);

            if ($rows === []) {
                $this->finalize_completed_run($state);
                return;
            }

            $batch_result = $this->process_customer_rows($rows, $state);
            $state = $batch_result['state'];

            if (!$batch_result['success']) {
                $this->save_run_state($state);
                return;
            }

            $latest_state = $this->get_run_state();
            if ((string) ($latest_state['run_id'] ?? '') === $run_id && !empty($latest_state['cancel_requested'])) {
                $state['cancel_requested'] = true;
            }

            $state['updated_at'] = gmdate('c');
            if (!empty($state['cancel_requested'])) {
                $this->finalize_cancelled_run($state, false);
                return;
            }

            if (!$this->schedule_next_batch($run_id, 1)) {
                $state['status'] = self::STATUS_INTERRUPTED;
                $state['queue_failed_count'] = max(0, (int) ($state['queue_failed_count'] ?? 0)) + 1;
                $state['message'] = 'Failed to schedule next bulk batch.';
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $this->save_run_state($state);

                $this->logger->warning('brevo_bulk_sync_schedule_next_batch_failed', [
                    'run_id' => $run_id,
                    'processed_count' => (int) ($state['processed_count'] ?? 0),
                    'success_count' => (int) ($state['success_count'] ?? 0),
                    'failed_count' => (int) ($state['failed_count'] ?? 0),
                    'success' => false,
                ]);
                return;
            }

            $state['scheduled_batches'] = max(0, (int) ($state['scheduled_batches'] ?? 0)) + 1;
            $state['updated_at'] = gmdate('c');
            $this->save_run_state($state);
        } catch (\Throwable $exception) {
            $state = $this->get_run_state();
            if ((string) ($state['run_id'] ?? '') === $run_id) {
                $state['status'] = self::STATUS_INTERRUPTED;
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Bulk sync batch crashed.';
                $this->save_run_state($state);
            }

            $this->logger->error('brevo_bulk_sync_batch_exception', [
                'run_id' => $run_id,
                'endpoint' => '/contacts/import',
                'method' => 'POST',
                'success' => false,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
        } finally {
            $this->release_batch_lock($run_id);
        }
    }

    /**
     * @param object[] $rows
     * @param array<string,mixed> $state
     * @return array{success:bool,state:array<string,mixed>}
     */
    private function process_customer_rows(array $rows, array $state): array
    {
        $source = (string) ($state['source'] ?? 'admin_bulk');

        $import_contacts = [];
        $batch_attributes_total = 0;
        /** @var array<string,array{customer_id:int,fingerprint:string,email:string}> $contacts_meta */
        $contacts_meta = [];

        foreach ($rows as $row) {
            $customer_id = isset($row->id) ? (int) $row->id : 0;
            if ($customer_id <= 0) {
                continue;
            }

            $state['last_customer_id'] = max((int) ($state['last_customer_id'] ?? 0), $customer_id);
            $state['scanned_count'] = max(0, (int) ($state['scanned_count'] ?? 0)) + 1;

            $email = sanitize_email((string) ($row->email ?? ''));
            if ($email === '' || !is_email($email)) {
                $state['skipped_invalid_count'] = max(0, (int) ($state['skipped_invalid_count'] ?? 0)) + 1;
                $this->sync_meta_repository->mark_sync_failure(
                    $customer_id,
                    'Invalid or missing email address for Brevo sync.'
                );
                continue;
            }

            try {
                $mapped_payload = $this->mapper->map_upsert_payload((array) $row, $source);
            } catch (\Throwable $exception) {
                $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
                $this->sync_meta_repository->mark_sync_failure($customer_id, 'Brevo payload mapping failed.');

                $this->logger->warning('brevo_bulk_sync_mapper_failed', [
                    'customer_id' => $customer_id,
                    'source' => $source,
                    'success' => false,
                    'error_type' => get_class($exception),
                    'error_message' => $exception->getMessage(),
                ]);
                continue;
            }

            $fingerprint = $this->change_detector->build_sync_fingerprint((array) $row);
            $this->sync_meta_repository->mark_sync_attempt($customer_id, $fingerprint);

            $state['eligible_count'] = max(0, (int) ($state['eligible_count'] ?? 0)) + 1;
            $state['processed_count'] = max(0, (int) ($state['processed_count'] ?? 0)) + 1;

            $mapped_email = strtolower(trim((string) ($mapped_payload['email'] ?? $email)));
            if ($mapped_email === '') {
                $mapped_email = strtolower($email);
            }

            $contact_attributes = is_array($mapped_payload['attributes'] ?? null) ? $mapped_payload['attributes'] : [];
            $import_contacts[] = [
                'email' => $mapped_email,
                'attributes' => $contact_attributes,
            ];
            $batch_attributes_total += count($contact_attributes);

            $contacts_meta[$mapped_email] = [
                'customer_id' => $customer_id,
                'fingerprint' => $fingerprint,
                'email' => $mapped_email,
            ];
        }

        if ($import_contacts === []) {
            return ['success' => true, 'state' => $state];
        }

        $latest_state_before_import = $this->get_run_state();
        if (
            (string) ($latest_state_before_import['run_id'] ?? '') === (string) ($state['run_id'] ?? '')
            && !empty($latest_state_before_import['cancel_requested'])
        ) {
            $state['cancel_requested'] = true;
            $state['updated_at'] = gmdate('c');

            $this->logger->info('brevo_bulk_sync_stop_respected_before_import', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'source' => (string) ($state['source'] ?? 'admin_bulk'),
                'batch_contacts' => count($import_contacts),
                'success' => true,
            ]);

            return ['success' => true, 'state' => $state];
        }

        $started_at = microtime(true);
        try {
            $response = $this->contact_service->import_contacts(
                $import_contacts,
                $this->settings->get_customers_list_id()
            );
            $process_id = max(0, (int) ($response['process_id'] ?? 0));

            if ($process_id <= 0) {
                foreach ($contacts_meta as $meta) {
                    $this->sync_meta_repository->mark_sync_failure(
                        (int) $meta['customer_id'],
                        'Brevo import did not return a process ID.',
                        (string) $meta['fingerprint']
                    );
                    $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
                }

                $state['status'] = self::STATUS_INTERRUPTED;
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Brevo import process ID was missing.';

                $this->logger->error('brevo_bulk_import_missing_process_id', [
                    'run_id' => (string) ($state['run_id'] ?? ''),
                    'endpoint' => '/contacts/import',
                    'method' => 'POST',
                    'response_code' => (int) ($response['status_code'] ?? 0),
                    'success' => false,
                    'batch_contacts' => count($import_contacts),
                    'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
                ]);

                return ['success' => false, 'state' => $state];
            }

            $process_result = $this->contact_service->wait_for_process($process_id, 120, 3);
            $process_status = sanitize_key((string) ($process_result['status'] ?? ''));
            $duration_ms = (int) round((microtime(true) - $started_at) * 1000);

            if (!empty($process_result['timed_out'])) {
                foreach ($contacts_meta as $meta) {
                    $this->sync_meta_repository->mark_sync_failure(
                        (int) $meta['customer_id'],
                        'Brevo import process timed out.',
                        (string) $meta['fingerprint']
                    );
                    $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
                }

                $state['status'] = self::STATUS_INTERRUPTED;
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Brevo import process timed out.';

                $this->logger->error('brevo_bulk_import_process_timeout', [
                    'run_id' => (string) ($state['run_id'] ?? ''),
                    'endpoint' => '/contacts/import',
                    'method' => 'POST',
                    'response_code' => (int) ($response['status_code'] ?? 0),
                    'process_id' => $process_id,
                    'success' => false,
                    'duration_ms' => $duration_ms,
                ]);

                return ['success' => false, 'state' => $state];
            }

            if (!$this->is_success_process_status($process_status)) {
                foreach ($contacts_meta as $meta) {
                    $this->sync_meta_repository->mark_sync_failure(
                        (int) $meta['customer_id'],
                        'Brevo import process failed.',
                        (string) $meta['fingerprint']
                    );
                    $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
                }

                $state['status'] = self::STATUS_INTERRUPTED;
                $state['updated_at'] = gmdate('c');
                $state['finished_at'] = gmdate('c');
                $state['message'] = 'Brevo import process failed.';

                $this->logger->error('brevo_bulk_import_process_failed', [
                    'run_id' => (string) ($state['run_id'] ?? ''),
                    'endpoint' => '/contacts/import',
                    'method' => 'POST',
                    'response_code' => (int) ($response['status_code'] ?? 0),
                    'process_id' => $process_id,
                    'process_status' => $process_status,
                    'success' => false,
                    'duration_ms' => $duration_ms,
                ]);

                return ['success' => false, 'state' => $state];
            }

            $failed_by_email = $this->extract_failed_contacts_from_process(
                is_array($process_result['body'] ?? null) ? $process_result['body'] : [],
                $contacts_meta
            );
            $failed_count_hint = $this->extract_failed_count_hint(
                is_array($process_result['body'] ?? null) ? $process_result['body'] : []
            );
            if ($failed_by_email === [] && $failed_count_hint > 0) {
                foreach ($contacts_meta as $email => $meta) {
                    $failed_by_email[$email] = 'Brevo import reported failed rows.';
                }
            }

            foreach ($contacts_meta as $email => $meta) {
                if (isset($failed_by_email[$email])) {
                    $this->sync_meta_repository->mark_sync_failure(
                        (int) $meta['customer_id'],
                        (string) $failed_by_email[$email],
                        (string) $meta['fingerprint']
                    );
                    $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
                    continue;
                }

                $this->sync_meta_repository->mark_sync_success(
                    (int) $meta['customer_id'],
                    null,
                    (string) $meta['fingerprint']
                );
                $state['success_count'] = max(0, (int) ($state['success_count'] ?? 0)) + 1;
            }

            $this->logger->info('brevo_bulk_import_batch_completed', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'action' => 'bulk_import_contacts',
                'source' => (string) ($state['source'] ?? 'admin_bulk'),
                'endpoint' => '/contacts/import',
                'method' => 'POST',
                'response_code' => (int) ($response['status_code'] ?? 0),
                'process_id' => $process_id,
                'process_status' => $process_status,
                'success' => true,
                'retry_count' => 0,
                'duration_ms' => $duration_ms,
                'batch_contacts' => count($contacts_meta),
                'batch_attributes_total' => $batch_attributes_total,
                'batch_failed' => count($failed_by_email),
            ]);
        } catch (\Throwable $exception) {
            foreach ($contacts_meta as $meta) {
                $this->sync_meta_repository->mark_sync_failure(
                    (int) $meta['customer_id'],
                    'Brevo import request failed.',
                    (string) $meta['fingerprint']
                );
                $state['failed_count'] = max(0, (int) ($state['failed_count'] ?? 0)) + 1;
            }

            $state['status'] = self::STATUS_INTERRUPTED;
            $state['updated_at'] = gmdate('c');
            $state['finished_at'] = gmdate('c');
            $state['message'] = 'Brevo import request failed.';

            $this->logger->error('brevo_bulk_import_batch_exception', [
                'run_id' => (string) ($state['run_id'] ?? ''),
                'action' => 'bulk_import_contacts',
                'source' => (string) ($state['source'] ?? 'admin_bulk'),
                'endpoint' => '/contacts/import',
                'method' => 'POST',
                'success' => false,
                'retry_count' => 0,
                'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);

            return ['success' => false, 'state' => $state];
        }

        return ['success' => true, 'state' => $state];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function finalize_completed_run(array $state): void
    {
        $state['status'] = self::STATUS_COMPLETED;
        $state['finished_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $state['message'] = '';
        $this->save_run_state($state);

        $this->logger->info('brevo_bulk_sync_completed', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'source' => (string) ($state['source'] ?? 'admin_bulk'),
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'eligible_count' => (int) ($state['eligible_count'] ?? 0),
            'success_count' => (int) ($state['success_count'] ?? 0),
            'failed_count' => (int) ($state['failed_count'] ?? 0),
            'skipped_invalid_count' => (int) ($state['skipped_invalid_count'] ?? 0),
            'queue_failed_count' => (int) ($state['queue_failed_count'] ?? 0),
            'success' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function finalize_cancelled_run(array $state, bool $stop_before_batch_start): void
    {
        $state['status'] = self::STATUS_CANCELLED;
        $state['finished_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $state['message'] = 'Bulk sync stopped by user.';
        $this->save_run_state($state);

        $this->logger->info('brevo_bulk_sync_stopped_by_button', [
            'run_id' => (string) ($state['run_id'] ?? ''),
            'source' => 'admin_button',
            'stopped_at' => gmdate('c'),
            'stop_before_batch_start' => $stop_before_batch_start,
            'processed_count' => (int) ($state['processed_count'] ?? 0),
            'eligible_count' => (int) ($state['eligible_count'] ?? 0),
            'success_count' => (int) ($state['success_count'] ?? 0),
            'failed_count' => (int) ($state['failed_count'] ?? 0),
            'success' => true,
        ]);
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
            "SELECT id, external_id, company_name, address, city, state, phone, email, customer_segment, billing_center
             FROM {$table}
             WHERE id > %d
             ORDER BY id ASC
             LIMIT %d",
            max(0, $after_id),
            max(1, $limit)
        );

        return (array) $wpdb->get_results($sql);
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
            'resumed_at' => $this->normalize_datetime_string($state['resumed_at'] ?? null),
            'stop_requested_at' => $this->normalize_datetime_string($state['stop_requested_at'] ?? null),
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

        $allowed_statuses = [
            self::STATUS_IDLE,
            self::STATUS_RUNNING,
            self::STATUS_STOPPING,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_INTERRUPTED,
        ];

        if (!in_array($normalized['status'], $allowed_statuses, true)) {
            $normalized['status'] = self::STATUS_IDLE;
        }

        return $normalized;
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
            'resumed_at' => null,
            'stop_requested_at' => null,
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

    /**
     * @param array<string,mixed> $state
     */
    private function is_run_stale(array $state): bool
    {
        if (!$this->is_run_active($state)) {
            return false;
        }

        $run_id = (string) ($state['run_id'] ?? '');
        if ($run_id === '') {
            return false;
        }

        if ($this->has_batch_lock($run_id)) {
            return false;
        }

        if ($this->has_scheduled_batch($run_id)) {
            return false;
        }

        $updated_at = (string) ($state['updated_at'] ?? '');
        $updated_ts = strtotime($updated_at);
        if ($updated_ts === false) {
            return true;
        }

        $stale_after_seconds = max(120, $this->settings->get_bulk_sync_lock_ttl() * 2);
        return (time() - $updated_ts) >= $stale_after_seconds;
    }

    private function schedule_next_batch(string $run_id, int $delay_seconds = 0): bool
    {
        $payload = ['run_id' => sanitize_key($run_id)];
        if ((string) $payload['run_id'] === '') {
            return false;
        }

        $scheduled_at = time() + max(0, $delay_seconds);

        if (ActionSchedulerSyncQueue::is_available()) {
            $action_id = as_schedule_single_action(
                $scheduled_at,
                self::BATCH_HOOK,
                [$payload],
                self::BATCH_GROUP,
                false
            );

            return (int) $action_id > 0;
        }

        return wp_schedule_single_event(
            $scheduled_at,
            self::BATCH_HOOK,
            [$payload]
        ) !== false;
    }

    private function has_scheduled_batch(string $run_id): bool
    {
        $payload = ['run_id' => sanitize_key($run_id)];
        if ((string) $payload['run_id'] === '') {
            return false;
        }

        if (ActionSchedulerSyncQueue::is_available() && function_exists('as_next_scheduled_action')) {
            return as_next_scheduled_action(self::BATCH_HOOK, [$payload], self::BATCH_GROUP) !== false;
        }

        return wp_next_scheduled(self::BATCH_HOOK, [$payload]) !== false;
    }

    private function unschedule_pending_batches(string $run_id): int
    {
        $payload = ['run_id' => sanitize_key($run_id)];
        if ((string) $payload['run_id'] === '') {
            return 0;
        }

        $removed = 0;
        if (ActionSchedulerSyncQueue::is_available()) {
            if (function_exists('as_unschedule_all_actions')) {
                $result = as_unschedule_all_actions(self::BATCH_HOOK, [$payload], self::BATCH_GROUP);
                if (is_numeric($result)) {
                    $removed += max(0, (int) $result);
                }
                return $removed;
            }

            if (function_exists('as_unschedule_action') && function_exists('as_next_scheduled_action')) {
                while (as_next_scheduled_action(self::BATCH_HOOK, [$payload], self::BATCH_GROUP) !== false) {
                    $unscheduled = as_unschedule_action(self::BATCH_HOOK, [$payload], self::BATCH_GROUP);
                    if (!is_numeric($unscheduled) || (int) $unscheduled <= 0) {
                        break;
                    }
                    $removed++;
                }
                return $removed;
            }
        }

        while (($timestamp = wp_next_scheduled(self::BATCH_HOOK, [$payload])) !== false) {
            $unscheduled = wp_unschedule_event($timestamp, self::BATCH_HOOK, [$payload]);
            if ($unscheduled === false) {
                break;
            }
            $removed++;
        }

        return $removed;
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

    private function has_batch_lock(string $run_id): bool
    {
        return (bool) get_transient($this->get_batch_lock_key($run_id));
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
     * @param mixed $value
     */
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
     * @param array<string,mixed> $process_body
     * @param array<string,array{customer_id:int,fingerprint:string,email:string}> $contacts_meta
     * @return array<string,string>
     */
    private function extract_failed_contacts_from_process(array $process_body, array $contacts_meta): array
    {
        $failed = [];

        $process_urls = [];
        $this->collect_urls_recursively($process_body, $process_urls);
        foreach ($process_urls as $url) {
            $failed = array_merge($failed, $this->parse_failed_contacts_csv_report($url));
        }

        $this->collect_failed_emails_recursively($process_body, '', $failed);

        $filtered = [];
        foreach ($failed as $email => $reason) {
            $email_key = strtolower(trim((string) $email));
            if (!isset($contacts_meta[$email_key])) {
                continue;
            }

            $reason = sanitize_text_field((string) $reason);
            if ($reason === '') {
                $reason = 'Brevo import reported a row error.';
            }
            $filtered[$email_key] = $reason;
        }

        return $filtered;
    }

    /**
     * @param mixed $node
     * @param string[] $urls
     */
    private function collect_urls_recursively($node, array &$urls): void
    {
        if (is_string($node)) {
            $trimmed = trim($node);
            if (preg_match('#^https?://#i', $trimmed) === 1 && str_contains(strtolower($trimmed), '.csv')) {
                $urls[] = $trimmed;
            }
            return;
        }

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $value) {
            $this->collect_urls_recursively($value, $urls);
        }
    }

    /**
     * @param mixed $node
     * @param array<string,string> $failed
     */
    private function collect_failed_emails_recursively($node, string $path, array &$failed): void
    {
        if (is_string($node)) {
            $value = trim($node);
            if (is_email($value) && $this->is_failure_path($path)) {
                $failed[strtolower($value)] = 'Brevo import rejected this contact.';
            }
            return;
        }

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $key_part = sanitize_key((string) $key);
            $next_path = $path === '' ? $key_part : ($path . '.' . $key_part);
            $this->collect_failed_emails_recursively($value, $next_path, $failed);
        }
    }

    private function is_failure_path(string $path): bool
    {
        $path = strtolower($path);
        if ($path === '') {
            return false;
        }

        return str_contains($path, 'fail')
            || str_contains($path, 'invalid')
            || str_contains($path, 'error')
            || str_contains($path, 'reject')
            || str_contains($path, 'blacklist');
    }

    /**
     * @param array<string,mixed> $process_body
     */
    private function extract_failed_count_hint(array $process_body): int
    {
        $count = 0;
        $this->collect_failed_count_recursively($process_body, '', $count);
        return max(0, $count);
    }

    /**
     * @param mixed $node
     */
    private function collect_failed_count_recursively($node, string $path, int &$count): void
    {
        if (is_numeric($node) && $this->is_failure_path($path)) {
            $count += max(0, (int) $node);
            return;
        }

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $key_part = sanitize_key((string) $key);
            $next_path = $path === '' ? $key_part : ($path . '.' . $key_part);
            $this->collect_failed_count_recursively($value, $next_path, $count);
        }
    }

    /**
     * @return array<string,string>
     */
    private function parse_failed_contacts_csv_report(string $url): array
    {
        $failed = [];
        $report_context = $this->build_report_log_context($url);

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            $this->logger->warning('brevo_bulk_import_report_fetch_failed', array_merge([
                'endpoint' => '/processes',
                'method' => 'GET',
                'success' => false,
                'response_code' => 0,
                'error_code' => sanitize_key((string) $response->get_error_code()),
                'error_message' => 'Failed to fetch Brevo process report CSV.',
            ], $report_context));
            return $failed;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->warning('brevo_bulk_import_report_http_error', array_merge([
                'endpoint' => '/processes',
                'method' => 'GET',
                'success' => false,
                'response_code' => $status_code,
            ], $report_context));
            return $failed;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return $failed;
        }

        $rows = preg_split('/\r\n|\r|\n/', trim($body));
        if (!is_array($rows) || $rows === []) {
            return $failed;
        }

        $delimiter = $this->detect_csv_delimiter((string) $rows[0]);
        $headers = str_getcsv((string) $rows[0], $delimiter);
        if (!is_array($headers) || $headers === []) {
            return $failed;
        }

        $email_index = null;
        $reason_index = null;
        foreach ($headers as $index => $header) {
            $header_key = strtolower(trim((string) $header));
            if ($email_index === null && str_contains($header_key, 'email')) {
                $email_index = $index;
            }
            if ($reason_index === null && (
                str_contains($header_key, 'reason')
                || str_contains($header_key, 'error')
                || str_contains($header_key, 'message')
                || str_contains($header_key, 'status')
            )) {
                $reason_index = $index;
            }
        }

        if ($email_index === null) {
            return $failed;
        }

        for ($i = 1, $len = count($rows); $i < $len; $i++) {
            $line = trim((string) $rows[$i]);
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line, $delimiter);
            if (!is_array($columns)) {
                continue;
            }

            $email = isset($columns[$email_index]) ? sanitize_email((string) $columns[$email_index]) : '';
            if ($email === '' || !is_email($email)) {
                continue;
            }

            $reason = $reason_index !== null && isset($columns[$reason_index])
                ? sanitize_text_field((string) $columns[$reason_index])
                : '';

            if ($reason === '') {
                $reason = 'Brevo import reported a row error.';
            }

            $failed[strtolower($email)] = $reason;
        }

        return $failed;
    }

    /**
     * @return array<string,string>
     */
    private function build_report_log_context(string $url): array
    {
        $parts = wp_parse_url($url);
        $host = isset($parts['host']) ? sanitize_text_field((string) $parts['host']) : '';
        $path = isset($parts['path']) ? sanitize_text_field((string) $parts['path']) : '';

        return [
            'report_host' => $host,
            'report_path' => $path !== '' ? $path : '/',
        ];
    }

    private function detect_csv_delimiter(string $header_line): string
    {
        $candidates = [';', ',', "\t"];
        $best = ';';
        $best_count = -1;

        foreach ($candidates as $candidate) {
            $count = substr_count($header_line, $candidate);
            if ($count > $best_count) {
                $best = $candidate;
                $best_count = $count;
            }
        }

        return $best;
    }

    private function is_success_process_status(string $status): bool
    {
        return in_array($status, ['completed', 'done', 'success'], true);
    }
}
