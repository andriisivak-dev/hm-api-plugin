<?php

declare(strict_types=1);

namespace CSP\Admin\Brevo;

use CSP\Brevo\BrevoBulkSyncService;

class BrevoBulkSyncController
{
    public const MENU_SLUG = 'csp-brevo-settings';
    public const ADMIN_ACTION = 'csp_brevo_bulk_sync';
    public const NONCE_ACTION = 'csp_brevo_bulk_sync_action';
    public const BULK_ACTION_SYNC_ALL = 'sync_all_customers';
    public const QUERY_NOTICE = 'brevo_bulk_sync_notice';
    public const QUERY_COUNT = 'brevo_bulk_sync_count';
    public const QUERY_SKIPPED = 'brevo_bulk_sync_skipped';
    public const QUERY_FAILED = 'brevo_bulk_sync_failed';

    private BrevoBulkSyncService $bulk_sync_service;

    public function __construct(?BrevoBulkSyncService $bulk_sync_service = null)
    {
        $this->bulk_sync_service = $bulk_sync_service ?? new BrevoBulkSyncService();
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ADMIN_ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to run this action.', 'hm-case-study-api'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::NONCE_ACTION);

        $bulk_action = isset($_POST['bulk_action']) ? sanitize_key((string) wp_unslash($_POST['bulk_action'])) : '';
        if ($bulk_action !== self::BULK_ACTION_SYNC_ALL) {
            $this->redirect_with_notice('invalid_action');
        }

        $result = $this->bulk_sync_service->schedule_all_customers('admin_bulk');
        $reason = sanitize_key((string) ($result['reason'] ?? 'failed'));

        if ($reason === 'scheduled') {
            $this->redirect_with_notice('scheduled', [
                self::QUERY_COUNT => (int) ($result['eligible_count'] ?? 0),
                self::QUERY_SKIPPED => (int) ($result['skipped_invalid_count'] ?? 0),
                self::QUERY_FAILED => (int) ($result['failed_count'] ?? 0),
            ]);
        }

        if ($reason === 'locked') {
            $this->redirect_with_notice('locked');
        }

        if ($reason === 'disabled') {
            $this->redirect_with_notice('disabled');
        }

        $this->redirect_with_notice('failed');
    }

    /**
     * @param array<string,int|string> $query
     */
    private function redirect_with_notice(string $notice, array $query = []): void
    {
        $args = array_merge([
            'page' => self::MENU_SLUG,
            self::QUERY_NOTICE => sanitize_key($notice),
        ], $query);

        $url = add_query_arg($args, admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
