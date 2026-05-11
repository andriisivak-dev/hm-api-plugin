<?php

declare(strict_types=1);

namespace CSP\Brevo;

class CustomerSyncJobHandler
{
    private CustomerSyncService $sync_service;
    private BrevoSettings $settings;
    private BrevoLogger $logger;

    public function __construct(
        ?CustomerSyncService $sync_service = null,
        ?BrevoSettings $settings = null,
        ?BrevoLogger $logger = null
    ) {
        $this->settings = $settings ?? new BrevoSettings();
        $this->sync_service = $sync_service ?? new CustomerSyncService($this->settings);
        $this->logger = $logger ?? new BrevoLogger($this->settings);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function handle(array $job): array
    {
        $customer_id = (int) ($job['customer_id'] ?? 0);
        $action = sanitize_key((string) ($job['action'] ?? CustomerSyncService::ACTION_UPSERT));
        $source = sanitize_key((string) ($job['source'] ?? 'queue'));
        $customer_snapshot = $job['customer_snapshot'] ?? null;

        if ($customer_id <= 0) {
            return [
                'success' => false,
                'retryable' => false,
                'error' => 'Invalid customer ID in queued job.',
            ];
        }

        $lock_key = $this->get_lock_key($customer_id);
        $lock_ttl = max(30, $this->settings->get_bulk_sync_lock_ttl());

        if (get_transient($lock_key)) {
            $this->logger->warning('brevo_sync_job_locked', [
                'customer_id' => $customer_id,
                'action' => $action,
                'source' => $source,
            ]);

            return [
                'success' => false,
                'retryable' => true,
                'error' => 'Customer sync is already running.',
            ];
        }

        set_transient($lock_key, 1, $lock_ttl);

        try {
            if ($action === CustomerSyncService::ACTION_SOFT_DELETE && (is_array($customer_snapshot) || is_object($customer_snapshot))) {
                return $this->sync_service->sync_customer_snapshot($customer_snapshot, $source, $action, $customer_id);
            }

            return $this->sync_service->sync_customer($customer_id, $source, $action);
        } finally {
            delete_transient($lock_key);
        }
    }

    private function get_lock_key(int $customer_id): string
    {
        return 'csp_brevo_sync_lock_' . $customer_id;
    }
}
