<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class CustomerSyncHooks
{
    /** @var string[] */
    private const ADMIN_SOURCES = ['admin', 'admin_bulk', 'admin_import'];

    private CustomerSyncService $sync_service;
    private CustomerChangeDetector $change_detector;
    private BrevoLogger $logger;

    /** @var array<string,bool> */
    private array $in_progress = [];

    public function __construct(
        ?CustomerSyncService $sync_service = null,
        ?CustomerChangeDetector $change_detector = null,
        ?BrevoLogger $logger = null
    ) {
        $this->sync_service = $sync_service ?? new CustomerSyncService();
        $this->change_detector = $change_detector ?? new CustomerChangeDetector();
        $this->logger = $logger ?? new BrevoLogger();
    }

    public function register(): void
    {
        add_action('csp_customer_saved', [$this, 'on_customer_saved'], 10, 5);
        add_action('csp_customer_deleting', [$this, 'on_customer_deleting'], 10, 3);
    }

    /**
     * @param mixed $previous_customer
     * @param mixed $current_customer
     */
    public function on_customer_saved(
        int $customer_id,
        string $source = 'system',
        bool $is_new = false,
        $previous_customer = null,
        $current_customer = null
    ): void {
        $source = sanitize_key($source);
        if (!$this->is_admin_source($source) || !$this->can_sync_from_admin_source()) {
            return;
        }

        $lock_key = 'save:' . $customer_id;
        if (!$this->acquire_lock($lock_key)) {
            return;
        }

        try {
            $customer = is_object($current_customer) ? $current_customer : CustomerRepository::getById($customer_id);
            if (!$customer) {
                return;
            }

            if (!$is_new && !$this->change_detector->has_relevant_changes($customer_id, $customer)) {
                $this->logger->debug('brevo_admin_sync_skipped_no_changes', [
                    'customer_id' => $customer_id,
                    'source' => $source,
                    'is_new' => false,
                ]);
                return;
            }

            $result = $this->sync_service->sync_upsert($customer_id, $source);
            if (!($result['success'] ?? false)) {
                $this->logger->warning('brevo_admin_sync_upsert_failed', [
                    'customer_id' => $customer_id,
                    'source' => $source,
                    'error' => (string) ($result['error'] ?? ''),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('brevo_admin_sync_upsert_exception', [
                'customer_id' => $customer_id,
                'source' => $source,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
        } finally {
            $this->release_lock($lock_key);
        }
    }

    /**
     * @param mixed $customer
     */
    public function on_customer_deleting(int $customer_id, string $source = 'system', $customer = null): void
    {
        $source = sanitize_key($source);
        if (!$this->is_admin_source($source) || !$this->can_sync_from_admin_source()) {
            return;
        }

        $lock_key = 'delete:' . $customer_id;
        if (!$this->acquire_lock($lock_key)) {
            return;
        }

        try {
            $result = $this->sync_service->sync_soft_delete($customer_id, $source);
            if (!($result['success'] ?? false)) {
                $this->logger->warning('brevo_admin_sync_soft_delete_failed', [
                    'customer_id' => $customer_id,
                    'source' => $source,
                    'error' => (string) ($result['error'] ?? ''),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('brevo_admin_sync_soft_delete_exception', [
                'customer_id' => $customer_id,
                'source' => $source,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
        } finally {
            $this->release_lock($lock_key);
        }
    }

    private function is_admin_source(string $source): bool
    {
        return in_array($source, self::ADMIN_SOURCES, true);
    }

    private function can_sync_from_admin_source(): bool
    {
        return current_user_can('manage_options');
    }

    private function acquire_lock(string $key): bool
    {
        if (isset($this->in_progress[$key])) {
            return false;
        }

        $this->in_progress[$key] = true;
        return true;
    }

    private function release_lock(string $key): void
    {
        unset($this->in_progress[$key]);
    }
}
