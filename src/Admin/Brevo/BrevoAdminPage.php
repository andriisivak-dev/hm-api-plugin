<?php

declare(strict_types=1);

namespace CSP\Admin\Brevo;

class BrevoAdminPage
{
    private const MENU_SLUG = 'csp-brevo-settings';
    private const OPTION_GROUP = 'csp_brevo_settings_group';
    private const OPTION_NAME = 'csp_brevo_settings';

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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Brevo Settings', 'hm-case-study-api'); ?></h1>
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
        </div>
        <?php
    }

    private function addTextField(string $key, string $label, string $section): void
    {
        add_settings_field(
            $key,
            __($label, 'hm-case-study-api'),
            function () use ($key): void {
                $value = (string) $this->getOptionValue($key);
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
                $value = (int) $this->getOptionValue($key);
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
                $checked = (int) $this->getOptionValue($key) === 1;
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

    private function getOptionValue(string $key)
    {
        $options = get_option(self::OPTION_NAME, []);
        $defaults = $this->getDefaults();

        if (!is_array($options)) {
            $options = [];
        }

        return $options[$key] ?? $defaults[$key] ?? null;
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
        if (!defined('MTG_BREVO_API_KEY')) {
            return false;
        }

        $value = constant('MTG_BREVO_API_KEY');
        return is_string($value) && trim($value) !== '';
    }
}
