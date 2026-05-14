<?php

declare(strict_types=1);

namespace CSP\Admin\Brevo;

use CSP\Brevo\BrevoBulkSyncService;

class BrevoBulkSyncController
{
    private const TEMPORARILY_DISABLED = false;

    public const MENU_SLUG = 'csp-brevo-settings';
    public const ADMIN_ACTION = 'csp_brevo_bulk_sync';
    public const NONCE_ACTION = 'csp_brevo_bulk_sync_action';
    public const BULK_ACTION_SYNC_ALL = 'sync_all_customers';
    public const BULK_ACTION_RESYNC_ALL = 'resync_all_customers';
    public const BULK_ACTION_STOP = 'stop_bulk_sync';
    public const BULK_ACTION_RESUME = 'resume_bulk_sync';
    public const QUERY_NOTICE = 'brevo_bulk_sync_notice';
    public const QUERY_COUNT = 'brevo_bulk_sync_count';
    public const QUERY_TOTAL = 'brevo_bulk_sync_total';
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

    public static function isTemporarilyDisabled(): bool
    {
        return self::TEMPORARILY_DISABLED;
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
        if (!in_array($bulk_action, [self::BULK_ACTION_SYNC_ALL, self::BULK_ACTION_RESYNC_ALL, self::BULK_ACTION_STOP, self::BULK_ACTION_RESUME], true)) {
            $this->redirect_with_notice('invalid_action');
        }

        if (in_array($bulk_action, [self::BULK_ACTION_SYNC_ALL, self::BULK_ACTION_RESYNC_ALL], true) && self::isTemporarilyDisabled()) {
            $this->redirect_with_notice('temporarily_disabled');
        }

        if ($bulk_action === self::BULK_ACTION_STOP) {
            $stop_result = $this->bulk_sync_service->request_stop();
            $reason = sanitize_key((string) ($stop_result['reason'] ?? 'failed'));

            if ($reason === 'stopping' || $reason === 'already_stopping') {
                $this->redirect_with_notice('stopping');
            }

            if ($reason === 'stopped') {
                $this->redirect_with_notice('stopped');
            }

            if ($reason === 'no_active_run') {
                $this->redirect_with_notice('no_active_run');
            }

            $this->redirect_with_notice('failed');
        }

        if ($bulk_action === self::BULK_ACTION_RESUME) {
            $resume_result = $this->bulk_sync_service->resume_from_checkpoint('admin_bulk_refresh');
            $reason = sanitize_key((string) ($resume_result['reason'] ?? 'failed'));

            if ($reason === 'resumed') {
                $this->redirect_with_notice('resumed');
            }

            if ($reason === 'not_resumable') {
                $this->redirect_with_notice('not_resumable');
            }

            if ($reason === 'locked') {
                $this->redirect_with_notice('locked');
            }

            if ($reason === 'disabled') {
                $this->redirect_with_notice('disabled');
            }

            $this->redirect_with_notice('failed');
        }

        $is_forced_resync = ($bulk_action === self::BULK_ACTION_RESYNC_ALL);
        $result = $this->bulk_sync_service->schedule_all_customers(
            $is_forced_resync ? 'admin_bulk_resync' : 'admin_bulk',
            $is_forced_resync
        );
        $reason = sanitize_key((string) ($result['reason'] ?? 'failed'));

        if ($reason === 'scheduled') {
            $this->redirect_with_notice($is_forced_resync ? 'resync_scheduled' : 'scheduled', [
                self::QUERY_COUNT => (int) ($result['eligible_count'] ?? 0),
                self::QUERY_TOTAL => (int) ($result['total_count'] ?? 0),
                self::QUERY_SKIPPED => (int) ($result['skipped_invalid_count'] ?? 0),
                self::QUERY_FAILED => (int) ($result['failed_count'] ?? 0),
            ]);
        }

        if ($reason === 'locked') {
            $this->redirect_with_notice('locked');
        }

        if ($reason === 'resume_required') {
            $this->redirect_with_notice('resume_required');
        }

        if ($reason === 'disabled') {
            $this->redirect_with_notice('disabled');
        }

        if ($reason === 'nothing_to_sync') {
            $this->redirect_with_notice('nothing_to_sync');
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
