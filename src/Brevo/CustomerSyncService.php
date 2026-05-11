<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class CustomerSyncService
{
    public const ACTION_UPSERT = 'upsert';
    public const ACTION_SOFT_DELETE = 'soft_delete';

    private BrevoSettings $settings;
    private CustomerBrevoMapper $mapper;
    private BrevoContactService $contact_service;
    private CustomerBrevoSyncMetaRepository $sync_meta_repository;
    private BrevoLogger $logger;

    public function __construct(
        ?BrevoSettings $settings = null,
        ?CustomerBrevoMapper $mapper = null,
        ?BrevoContactService $contact_service = null,
        ?CustomerBrevoSyncMetaRepository $sync_meta_repository = null,
        ?BrevoLogger $logger = null
    ) {
        $this->settings = $settings ?? new BrevoSettings();
        $this->mapper = $mapper ?? new CustomerBrevoMapper($this->settings);
        $this->contact_service = $contact_service ?? new BrevoContactService();
        $this->sync_meta_repository = $sync_meta_repository ?? new CustomerBrevoSyncMetaRepository();
        $this->logger = $logger ?? new BrevoLogger($this->settings);
    }

    /**
     * @return array<string,mixed>
     */
    public function sync_customer(int $customer_id, string $sync_source = 'manual', string $action = self::ACTION_UPSERT): array
    {
        $started_at = microtime(true);
        $action = $this->normalize_action($action);
        $sync_source = sanitize_key($sync_source);

        if ($customer_id <= 0) {
            return $this->result(false, 'Invalid customer ID.', $action);
        }

        $customer = CustomerRepository::getById($customer_id);
        if (!$customer) {
            $this->logger->warning('brevo_sync_customer_not_found', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
            ]);

            return $this->result(false, 'Customer not found.', $action);
        }

        if (!$this->settings->is_sync_enabled()) {
            $this->sync_meta_repository->update_meta_batch($customer_id, [
                CustomerBrevoSyncMetaRepository::META_SYNC_STATUS => 'disabled',
                CustomerBrevoSyncMetaRepository::META_LAST_ATTEMPT_AT => gmdate('c'),
            ]);

            $this->logger->info('brevo_sync_skipped_disabled', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
            ]);

            return $this->result(true, '', $action, [
                'skipped' => true,
                'reason' => 'sync_disabled',
            ]);
        }

        if ($action === self::ACTION_SOFT_DELETE && !$this->settings->is_soft_delete_enabled()) {
            $this->sync_meta_repository->update_meta_batch($customer_id, [
                CustomerBrevoSyncMetaRepository::META_SYNC_STATUS => 'skipped',
                CustomerBrevoSyncMetaRepository::META_LAST_ATTEMPT_AT => gmdate('c'),
            ]);

            $this->logger->info('brevo_soft_delete_skipped_disabled', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
            ]);

            return $this->result(true, '', $action, [
                'skipped' => true,
                'reason' => 'soft_delete_disabled',
            ]);
        }

        try {
            $payload = $this->build_payload($customer, $action, $sync_source);
        } catch (\Throwable $exception) {
            $safe_error = $this->build_safe_error_message($exception);
            $this->sync_meta_repository->mark_sync_failure($customer_id, $safe_error);

            $this->logger->error('brevo_sync_mapper_failed', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
                'error_type' => get_class($exception),
                'error_message' => $safe_error,
            ]);

            return $this->result(false, $safe_error, $action);
        }

        $payload_hash = $this->make_payload_hash($payload);
        $this->sync_meta_repository->mark_sync_attempt($customer_id, $payload_hash);

        try {
            $response = $this->dispatch_sync_action($action, $payload);
            $contact_id = $this->extract_contact_id($response);

            $this->sync_meta_repository->mark_sync_success($customer_id, $contact_id, $payload_hash);

            $duration_ms = (int) round((microtime(true) - $started_at) * 1000);
            $this->logger->info('brevo_sync_completed', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
                'endpoint' => '/contacts',
                'method' => 'POST',
                'response_code' => (int) ($response['status_code'] ?? 0),
                'success' => true,
                'retry_count' => 0,
                'duration_ms' => $duration_ms,
                'email' => (string) ($payload['email'] ?? ''),
            ]);

            return $this->result(true, '', $action, [
                'status_code' => (int) ($response['status_code'] ?? 0),
                'contact_id' => $contact_id,
            ]);
        } catch (\Throwable $exception) {
            $safe_error = $this->build_safe_error_message($exception);
            $this->sync_meta_repository->mark_sync_failure($customer_id, $safe_error, $payload_hash);

            $duration_ms = (int) round((microtime(true) - $started_at) * 1000);
            $this->logger->error('brevo_sync_failed', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $sync_source,
                'endpoint' => '/contacts',
                'method' => 'POST',
                'success' => false,
                'duration_ms' => $duration_ms,
                'error_type' => get_class($exception),
                'error_message' => $safe_error,
                'response_code' => $exception instanceof BrevoApiException ? $exception->get_status_code() : 0,
                'retryable' => $exception instanceof BrevoApiException ? $exception->is_retryable() : false,
                'email' => (string) ($payload['email'] ?? ''),
            ]);

            return $this->result(false, $safe_error, $action, [
                'retryable' => $exception instanceof BrevoApiException ? $exception->is_retryable() : false,
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function sync_upsert(int $customer_id, string $sync_source = 'manual'): array
    {
        return $this->sync_customer($customer_id, $sync_source, self::ACTION_UPSERT);
    }

    /**
     * @return array<string,mixed>
     */
    public function sync_soft_delete(int $customer_id, string $sync_source = 'manual'): array
    {
        return $this->sync_customer($customer_id, $sync_source, self::ACTION_SOFT_DELETE);
    }

    /**
     * @param object $customer
     * @return array<string,mixed>
     */
    private function build_payload(object $customer, string $action, string $sync_source): array
    {
        if ($action === self::ACTION_SOFT_DELETE) {
            return $this->mapper->map_soft_delete_payload($customer, $sync_source);
        }

        return $this->mapper->map_upsert_payload($customer, $sync_source);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function dispatch_sync_action(string $action, array $payload): array
    {
        if ($action === self::ACTION_SOFT_DELETE) {
            return $this->contact_service->mark_contact_deleted(
                $payload,
                $this->settings->get_customers_list_id(),
                $this->settings->get_deleted_customers_list_id()
            );
        }

        return $this->contact_service->upsert_contact($payload);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extract_contact_id(array $response): ?string
    {
        if (!isset($response['body']) || !is_array($response['body'])) {
            return null;
        }

        $body = $response['body'];
        if (!array_key_exists('id', $body)) {
            return null;
        }

        $contact_id = trim((string) $body['id']);
        return $contact_id !== '' ? $contact_id : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function make_payload_hash(array $payload): string
    {
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        return hash('sha256', $encoded);
    }

    private function normalize_action(string $action): string
    {
        $action = sanitize_key($action);
        if (in_array($action, [self::ACTION_UPSERT, self::ACTION_SOFT_DELETE], true)) {
            return $action;
        }

        return self::ACTION_UPSERT;
    }

    private function build_safe_error_message(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return 'Brevo sync failed.';
        }

        return $message;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function result(bool $success, string $error, string $action, array $extra = []): array
    {
        $result = [
            'success' => $success,
            'action' => $action,
            'error' => $error,
        ];

        return array_merge($result, $extra);
    }
}
