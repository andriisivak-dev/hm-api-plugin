<?php

declare(strict_types=1);

namespace CSP\Admin\Brevo;

use CSP\Brevo\BrevoBulkSyncService;
use CSP\Brevo\BrevoSyncDashboardService;
use CSP\Brevo\BrevoSettings;

class BrevoAdminPage
{
    private const MENU_SLUG = 'csp-brevo-settings';
    private const OPTION_GROUP = 'csp_brevo_settings_group';
    private const OPTION_NAME = 'csp_brevo_settings';

    private BrevoSettings $settings;
    private BrevoSyncDashboardService $dashboard_service;
    private BrevoBulkSyncService $bulk_sync_service;

    public function __construct(
        ?BrevoSettings $settings = null,
        ?BrevoSyncDashboardService $dashboard_service = null,
        ?BrevoBulkSyncService $bulk_sync_service = null
    )
    {
        $this->settings = $settings ?? new BrevoSettings();
        $this->dashboard_service = $dashboard_service ?? new BrevoSyncDashboardService();
        $this->bulk_sync_service = $bulk_sync_service ?? new BrevoBulkSyncService();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=hm_case',
            __('Brevo Settings', 'hm-case-study-api'),
            __('Brevo Settings', 'hm-case-study-api'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->getDefaults(),
            ]
        );

        add_settings_section('csp_brevo_api_section', __('API Configuration', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);
        add_settings_section('csp_brevo_lists_section', __('Lists', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);
        add_settings_section('csp_brevo_sync_section', __('Sync Behavior', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);
        add_settings_section('csp_brevo_http_section', __('HTTP / Retry', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);
        add_settings_section('csp_brevo_logging_section', __('Logging', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);
        add_settings_section('csp_brevo_bulk_section', __('Bulk Sync', 'hm-case-study-api'), '__return_null', self::MENU_SLUG);

        $this->addTextField('brevo_api_base_url', 'Brevo API Base URL', 'csp_brevo_api_section');
        $this->addNumberField('brevo_customers_list_id', 'Customers List ID', 'csp_brevo_lists_section');
        $this->addNumberField('brevo_deleted_customers_list_id', 'Deleted Customers List ID', 'csp_brevo_lists_section');
        $this->addCheckboxField('brevo_sync_enabled', 'Sync Enabled', 'csp_brevo_sync_section');
        $this->addCheckboxField('brevo_soft_delete_enabled', 'Soft Delete Enabled', 'csp_brevo_sync_section');
        $this->addCheckboxField('brevo_use_phone_field', 'Use PHONE Field', 'csp_brevo_sync_section');
        $this->addCheckboxField('brevo_use_sms_field', 'Use SMS Field', 'csp_brevo_sync_section');
        $this->addNumberField('brevo_timeout', 'HTTP Timeout (seconds)', 'csp_brevo_http_section', 5, 60);
        $this->addNumberField('brevo_max_retries', 'Max Retries', 'csp_brevo_http_section', 0, 5);
        $this->addCheckboxField('brevo_debug_logging', 'Debug Logging', 'csp_brevo_logging_section');
        $this->addCheckboxField('brevo_bulk_sync_enabled', 'Bulk Sync Enabled', 'csp_brevo_bulk_section');
        $this->addNumberField('brevo_bulk_sync_batch_size', 'Bulk Sync Batch Size', 'csp_brevo_bulk_section', 10, 200);
        $this->addNumberField('brevo_bulk_sync_lock_ttl', 'Bulk Sync Lock TTL (seconds)', 'csp_brevo_bulk_section', 1, null);
    }

    public function sanitizeSettings($input): array
    {
        $defaults = $this->getDefaults();
        $input = is_array($input) ? $input : [];

        return [
            'brevo_api_base_url' => esc_url_raw((string) ($input['brevo_api_base_url'] ?? $defaults['brevo_api_base_url'])),
            'brevo_customers_list_id' => max(0, (int) ($input['brevo_customers_list_id'] ?? 0)),
            'brevo_deleted_customers_list_id' => max(0, (int) ($input['brevo_deleted_customers_list_id'] ?? 0)),
            'brevo_sync_enabled' => !empty($input['brevo_sync_enabled']) ? 1 : 0,
            'brevo_soft_delete_enabled' => !empty($input['brevo_soft_delete_enabled']) ? 1 : 0,
            'brevo_use_phone_field' => !empty($input['brevo_use_phone_field']) ? 1 : 0,
            'brevo_use_sms_field' => !empty($input['brevo_use_sms_field']) ? 1 : 0,
            'brevo_timeout' => $this->clampInt((int) ($input['brevo_timeout'] ?? $defaults['brevo_timeout']), 5, 60),
            'brevo_max_retries' => $this->clampInt((int) ($input['brevo_max_retries'] ?? $defaults['brevo_max_retries']), 0, 5),
            'brevo_debug_logging' => !empty($input['brevo_debug_logging']) ? 1 : 0,
            'brevo_bulk_sync_enabled' => !empty($input['brevo_bulk_sync_enabled']) ? 1 : 0,
            'brevo_bulk_sync_batch_size' => $this->clampInt((int) ($input['brevo_bulk_sync_batch_size'] ?? $defaults['brevo_bulk_sync_batch_size']), 10, 200),
            'brevo_bulk_sync_lock_ttl' => max(1, (int) ($input['brevo_bulk_sync_lock_ttl'] ?? $defaults['brevo_bulk_sync_lock_ttl'])),
        ];
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $run_state = $this->bulk_sync_service->get_run_state();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Brevo Settings', 'hm-case-study-api'); ?></h1>
            <?php $this->renderBulkSyncAutoRefresh($run_state); ?>
            <?php $this->renderBulkSyncNotice(); ?>
            <p>
                <strong><?php esc_html_e('API key configured:', 'hm-case-study-api'); ?></strong>
                <?php echo $this->isApiKeyConfigured() ? esc_html__('Yes', 'hm-case-study-api') : esc_html__('No', 'hm-case-study-api'); ?>
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::MENU_SLUG);
                submit_button();
                ?>
            </form>
            <?php $this->renderBulkSyncActions($run_state); ?>
            <?php $this->renderFailedContactsList(); ?>
        </div>
        <?php
    }

    private function addTextField(string $key, string $label, string $section): void
    {
        add_settings_field(
            $key,
            __($label, 'hm-case-study-api'),
            function () use ($key): void {
                $value = (string) $this->getResolvedSettingValue($key);
                printf(
                    '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
                    esc_attr(self::OPTION_NAME),
                    esc_attr($key),
                    esc_attr($value)
                );
            },
            self::MENU_SLUG,
            $section
        );
    }

    private function addNumberField(string $key, string $label, string $section, ?int $min = null, ?int $max = null): void
    {
        add_settings_field(
            $key,
            __($label, 'hm-case-study-api'),
            function () use ($key, $min, $max): void {
                $value = (int) $this->getResolvedSettingValue($key);
                printf(
                    '<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$d" %4$s %5$s step="1" />',
                    esc_attr(self::OPTION_NAME),
                    esc_attr($key),
                    $value,
                    null !== $min ? 'min="' . esc_attr((string) $min) . '"' : '',
                    null !== $max ? 'max="' . esc_attr((string) $max) . '"' : ''
                );
            },
            self::MENU_SLUG,
            $section
        );
    }

    private function addCheckboxField(string $key, string $label, string $section): void
    {
        add_settings_field(
            $key,
            __($label, 'hm-case-study-api'),
            function () use ($key): void {
                $checked = (bool) $this->getResolvedSettingValue($key);
                printf(
                    '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
                    esc_attr(self::OPTION_NAME),
                    esc_attr($key),
                    checked($checked, true, false),
                    esc_html__('Enabled', 'hm-case-study-api')
                );
            },
            self::MENU_SLUG,
            $section
        );
    }

    private function getResolvedSettingValue(string $key)
    {
        switch ($key) {
            case 'brevo_api_base_url':
                return $this->settings->get_api_base_url();
            case 'brevo_customers_list_id':
                return $this->settings->get_customers_list_id();
            case 'brevo_deleted_customers_list_id':
                return $this->settings->get_deleted_customers_list_id();
            case 'brevo_sync_enabled':
                return $this->settings->is_sync_enabled();
            case 'brevo_soft_delete_enabled':
                return $this->settings->is_soft_delete_enabled();
            case 'brevo_use_phone_field':
                return $this->settings->use_phone_field();
            case 'brevo_use_sms_field':
                return $this->settings->use_sms_field();
            case 'brevo_timeout':
                return $this->settings->get_timeout();
            case 'brevo_max_retries':
                return $this->settings->get_max_retries();
            case 'brevo_debug_logging':
                return $this->settings->is_debug_logging_enabled();
            case 'brevo_bulk_sync_enabled':
                return $this->settings->is_bulk_sync_enabled();
            case 'brevo_bulk_sync_batch_size':
                return $this->settings->get_bulk_sync_batch_size();
            case 'brevo_bulk_sync_lock_ttl':
                return $this->settings->get_bulk_sync_lock_ttl();
        }

        $defaults = $this->getDefaults();
        return $defaults[$key] ?? null;
    }

    private function getDefaults(): array
    {
        return [
            'brevo_api_base_url' => 'https://api.brevo.com/v3',
            'brevo_customers_list_id' => 0,
            'brevo_deleted_customers_list_id' => 0,
            'brevo_sync_enabled' => 0,
            'brevo_soft_delete_enabled' => 1,
            'brevo_use_phone_field' => 1,
            'brevo_use_sms_field' => 0,
            'brevo_timeout' => 15,
            'brevo_max_retries' => 3,
            'brevo_debug_logging' => 0,
            'brevo_bulk_sync_enabled' => 1,
            'brevo_bulk_sync_batch_size' => 50,
            'brevo_bulk_sync_lock_ttl' => 300,
        ];
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function isApiKeyConfigured(): bool
    {
        return $this->settings->get_api_key() !== '';
    }

    /**
     * @param array<string,mixed> $run_state
     */
    private function renderBulkSyncActions(array $run_state): void
    {
        $is_enabled = $this->settings->is_bulk_sync_enabled();
        $is_running = in_array((string) ($run_state['status'] ?? ''), ['running', 'stopping'], true);
        $is_stopping = (string) ($run_state['status'] ?? '') === 'stopping';
        $confirm_start = __('Are you sure you want to start Brevo sync for all Customers?', 'hm-case-study-api');
        $confirm_stop = __('Stop the active Brevo bulk sync after current batch?', 'hm-case-study-api');
        ?>
        <hr />
        <h2><?php esc_html_e('Bulk Sync Actions', 'hm-case-study-api'); ?></h2>
        <p>
            <?php esc_html_e('This will process all Customers in background batches. Existing Brevo contacts with the same email may be updated.', 'hm-case-study-api'); ?>
        </p>

        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(BrevoBulkSyncController::ADMIN_ACTION); ?>" />
                <input type="hidden" name="bulk_action" value="<?php echo esc_attr(BrevoBulkSyncController::BULK_ACTION_SYNC_ALL); ?>" />
                <?php wp_nonce_field(BrevoBulkSyncController::NONCE_ACTION); ?>
                <?php
                $start_attributes = [
                    'onclick' => "return confirm('" . esc_js($confirm_start) . "');",
                ];

                if (!$is_enabled || $is_running) {
                    $start_attributes['disabled'] = 'disabled';
                }

                submit_button(
                    __('Sync all Customers', 'hm-case-study-api'),
                    'secondary',
                    'submit',
                    false,
                    $start_attributes
                );
                ?>
            </form>

            <?php if ($is_running): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(BrevoBulkSyncController::ADMIN_ACTION); ?>" />
                    <input type="hidden" name="bulk_action" value="<?php echo esc_attr(BrevoBulkSyncController::BULK_ACTION_STOP); ?>" />
                    <?php wp_nonce_field(BrevoBulkSyncController::NONCE_ACTION); ?>
                    <?php
                    $stop_attributes = [
                        'onclick' => "return confirm('" . esc_js($confirm_stop) . "');",
                    ];

                    if ($is_stopping) {
                        $stop_attributes['disabled'] = 'disabled';
                    }

                    submit_button(
                        __('Stop Bulk Sync', 'hm-case-study-api'),
                        'delete',
                        'submit',
                        false,
                        $stop_attributes
                    );
                    ?>
                </form>
            <?php endif; ?>

            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'))); ?>">
                <?php esc_html_e('Refresh Progress', 'hm-case-study-api'); ?>
            </a>
        </div>

        <?php if (!$is_enabled): ?>
            <p>
                <em><?php esc_html_e('Bulk sync is disabled. Enable "Bulk Sync Enabled" in settings to use this action.', 'hm-case-study-api'); ?></em>
            </p>
        <?php endif; ?>
        <?php
    }

    private function renderBulkSyncNotice(): void
    {
        $notice = isset($_GET[BrevoBulkSyncController::QUERY_NOTICE])
            ? sanitize_key((string) wp_unslash($_GET[BrevoBulkSyncController::QUERY_NOTICE]))
            : '';

        if ($notice === '') {
            return;
        }

        $count = isset($_GET[BrevoBulkSyncController::QUERY_COUNT])
            ? max(0, (int) wp_unslash($_GET[BrevoBulkSyncController::QUERY_COUNT]))
            : 0;

        $total = isset($_GET[BrevoBulkSyncController::QUERY_TOTAL])
            ? max(0, (int) wp_unslash($_GET[BrevoBulkSyncController::QUERY_TOTAL]))
            : 0;

        $skipped = isset($_GET[BrevoBulkSyncController::QUERY_SKIPPED])
            ? max(0, (int) wp_unslash($_GET[BrevoBulkSyncController::QUERY_SKIPPED]))
            : 0;

        $failed = isset($_GET[BrevoBulkSyncController::QUERY_FAILED])
            ? max(0, (int) wp_unslash($_GET[BrevoBulkSyncController::QUERY_FAILED]))
            : 0;

        $class = 'notice notice-info';
        $message = '';

        switch ($notice) {
            case 'scheduled':
                $class = 'notice notice-success';
                $scheduled_base = $total > 0 ? $total : $count;
                $message = sprintf(__('Brevo bulk sync started for %d Customers.', 'hm-case-study-api'), $scheduled_base);

                if ($skipped > 0) {
                    $message .= ' ' . sprintf(
                        __('Skipped invalid records: %d.', 'hm-case-study-api'),
                        $skipped
                    );
                }

                if ($failed > 0) {
                    $message .= ' ' . sprintf(
                        __('Immediate queue errors: %d. Check logs and failed contacts list.', 'hm-case-study-api'),
                        $failed
                    );
                }
                break;
            case 'locked':
                $class = 'notice notice-warning';
                $message = __('A bulk sync run is already active.', 'hm-case-study-api');
                break;
            case 'disabled':
                $class = 'notice notice-warning';
                $message = __('Bulk sync is disabled in settings.', 'hm-case-study-api');
                break;
            case 'stopping':
                $class = 'notice notice-info';
                $message = __('Stop requested. Bulk sync will stop after the current batch.', 'hm-case-study-api');
                break;
            case 'no_active_run':
                $class = 'notice notice-warning';
                $message = __('No active bulk sync run to stop.', 'hm-case-study-api');
                break;
            case 'nothing_to_sync':
                $class = 'notice notice-info';
                $message = __('No Customers available for bulk sync.', 'hm-case-study-api');
                break;
            case 'invalid_action':
                $class = 'notice notice-error';
                $message = __('Invalid bulk sync action.', 'hm-case-study-api');
                break;
            case 'failed':
                $class = 'notice notice-error';
                $message = __('Failed to schedule Brevo bulk sync. Check logs for details.', 'hm-case-study-api');
                break;
        }

        if ($message === '') {
            return;
        }
        ?>
        <div class="<?php echo esc_attr($class); ?>"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    /**
     * @param array<string,mixed> $run_state
     */
    private function renderBulkSyncAutoRefresh(array $run_state): void
    {
        $status = sanitize_key((string) ($run_state['status'] ?? ''));
        if (!in_array($status, ['running', 'stopping'], true)) {
            return;
        }
        ?>
        <script>
            window.setTimeout(function () {
                window.location.reload();
            }, 5000);
        </script>
        <?php
    }

    private function renderFailedContactsList(): void
    {
        $page = isset($_GET['brevo_failed_page']) ? max(1, (int) wp_unslash($_GET['brevo_failed_page'])) : 1;
        $report = $this->dashboard_service->get_failed_contacts_page($page, 50);
        $items = (array) ($report['items'] ?? []);
        $total = max(0, (int) ($report['total'] ?? 0));
        $current_page = max(1, (int) ($report['page'] ?? 1));
        $total_pages = max(1, (int) ($report['total_pages'] ?? 1));
        $per_page = max(1, (int) ($report['per_page'] ?? 50));
        $start_index = (($current_page - 1) * $per_page) + 1;
        ?>
        <hr />
        <h2><?php esc_html_e('Failed Contact Sync Log', 'hm-case-study-api'); ?></h2>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    __('Total failed contacts: %s', 'hm-case-study-api'),
                    number_format_i18n($total)
                )
            );
            ?>
        </p>

        <?php if ($items === []): ?>
            <p><?php esc_html_e('No failed contacts right now.', 'hm-case-study-api'); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <ol start="<?php echo esc_attr((string) $start_index); ?>">
            <?php foreach ($items as $item): ?>
                <?php
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $company = isset($item['company_name']) ? (string) $item['company_name'] : '';
                $email = isset($item['email']) ? (string) $item['email'] : '';
                $error = isset($item['last_error']) ? (string) $item['last_error'] : '';
                $attempt_at = $this->formatFailedAttemptDate((string) ($item['last_attempt_at'] ?? ''));
                ?>
                <li style="margin-bottom:10px;">
                    <strong><?php echo esc_html($company !== '' ? $company : __('Unknown company', 'hm-case-study-api')); ?></strong>
                    <?php if ($email !== ''): ?>
                        <span> (<?php echo esc_html($email); ?>)</span>
                    <?php endif; ?>
                    <?php if ($id > 0): ?>
                        <span> #<?php echo esc_html((string) $id); ?></span>
                    <?php endif; ?>
                    <br />
                    <span><?php echo esc_html(sprintf(__('Reason: %s', 'hm-case-study-api'), $error !== '' ? $error : __('Unknown error', 'hm-case-study-api'))); ?></span>
                    <br />
                    <span><?php echo esc_html(sprintf(__('Last attempt: %s', 'hm-case-study-api'), $attempt_at)); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($total_pages > 1): ?>
            <div style="display:flex; gap:8px; align-items:center;">
                <?php if ($current_page > 1): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg([
                        'page' => self::MENU_SLUG,
                        'brevo_failed_page' => $current_page - 1,
                    ], admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Previous', 'hm-case-study-api'); ?>
                    </a>
                <?php endif; ?>
                <span>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('Page %1$s of %2$s', 'hm-case-study-api'),
                            number_format_i18n($current_page),
                            number_format_i18n($total_pages)
                        )
                    );
                    ?>
                </span>
                <?php if ($current_page < $total_pages): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg([
                        'page' => self::MENU_SLUG,
                        'brevo_failed_page' => $current_page + 1,
                    ], admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Next', 'hm-case-study-api'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    private function formatFailedAttemptDate(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '-';
        }

        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return $iso;
        }

        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $timestamp
        );
    }
}
