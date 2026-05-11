<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoSettings
{
    private const OPTION_NAME = 'csp_brevo_settings';

    private const DEFAULTS = [
        'brevo_api_base_url' => 'https://api.brevo.com/v3',
        'brevo_customers_list_id' => 0,
        'brevo_deleted_customers_list_id' => 0,
        'brevo_sync_enabled' => false,
        'brevo_soft_delete_enabled' => true,
        'brevo_use_phone_field' => true,
        'brevo_use_sms_field' => false,
        'brevo_timeout' => 15,
        'brevo_max_retries' => 3,
        'brevo_debug_logging' => false,
        'brevo_bulk_sync_enabled' => true,
        'brevo_bulk_sync_batch_size' => 50,
        'brevo_bulk_sync_lock_ttl' => 300,
    ];

    private ?array $options = null;

    public function get_api_key(): string
    {
        $value = $this->get_defined_constant('MTG_BREVO_API_KEY');

        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    public function get_api_base_url(): string
    {
        $value = $this->resolve_value(
            'MTG_BREVO_API_BASE_URL',
            'brevo_api_base_url',
            self::DEFAULTS['brevo_api_base_url']
        );

        $url = untrailingslashit(esc_url_raw(trim((string) $value)));

        if ($url === '') {
            return self::DEFAULTS['brevo_api_base_url'];
        }

        return $url;
    }

    public function get_customers_list_id(): int
    {
        return $this->resolve_int('MTG_BREVO_CUSTOMERS_LIST_ID', 'brevo_customers_list_id', 0, 0);
    }

    public function get_deleted_customers_list_id(): int
    {
        return $this->resolve_int('MTG_BREVO_DELETED_CUSTOMERS_LIST_ID', 'brevo_deleted_customers_list_id', 0, 0);
    }

    public function is_sync_enabled(): bool
    {
        return $this->resolve_bool('MTG_BREVO_SYNC_ENABLED', 'brevo_sync_enabled', false);
    }

    public function is_debug_logging_enabled(): bool
    {
        return $this->resolve_bool('MTG_BREVO_DEBUG_LOGGING', 'brevo_debug_logging', false);
    }

    public function get_timeout(): int
    {
        return $this->resolve_int('MTG_BREVO_TIMEOUT', 'brevo_timeout', 15, 5, 60);
    }

    public function get_max_retries(): int
    {
        return $this->resolve_int('MTG_BREVO_MAX_RETRIES', 'brevo_max_retries', 3, 0, 5);
    }

    public function use_sms_field(): bool
    {
        return $this->resolve_bool('MTG_BREVO_USE_SMS_FIELD', 'brevo_use_sms_field', false);
    }

    public function use_phone_field(): bool
    {
        return $this->resolve_bool('MTG_BREVO_USE_PHONE_FIELD', 'brevo_use_phone_field', true);
    }

    public function is_soft_delete_enabled(): bool
    {
        return $this->resolve_bool('MTG_BREVO_SOFT_DELETE_ENABLED', 'brevo_soft_delete_enabled', true);
    }

    public function is_bulk_sync_enabled(): bool
    {
        return $this->resolve_bool('MTG_BREVO_BULK_SYNC_ENABLED', 'brevo_bulk_sync_enabled', true);
    }

    public function get_bulk_sync_batch_size(): int
    {
        return $this->resolve_int('MTG_BREVO_BULK_SYNC_BATCH_SIZE', 'brevo_bulk_sync_batch_size', 50, 10, 200);
    }

    public function get_bulk_sync_lock_ttl(): int
    {
        return $this->resolve_int('MTG_BREVO_BULK_SYNC_LOCK_TTL', 'brevo_bulk_sync_lock_ttl', 300, 1);
    }

    private function resolve_int(string $constant_name, string $option_key, int $default, int $min, ?int $max = null): int
    {
        $value = $this->resolve_value($constant_name, $option_key, $default);
        $value = is_numeric($value) ? (int) $value : $default;
        $value = max($min, $value);

        if (null !== $max) {
            $value = min($max, $value);
        }

        return $value;
    }

    private function resolve_bool(string $constant_name, string $option_key, bool $default): bool
    {
        return $this->to_bool(
            $this->resolve_value($constant_name, $option_key, $default ? 1 : 0),
            $default
        );
    }

    private function resolve_value(string $constant_name, string $option_key, $default)
    {
        $constant_value = $this->get_defined_constant($constant_name);
        if (null !== $constant_value) {
            return $constant_value;
        }

        $options = $this->get_options();
        if (array_key_exists($option_key, $options)) {
            return $options[$option_key];
        }

        return $default;
    }

    private function get_defined_constant(string $name)
    {
        if (!defined($name)) {
            return null;
        }

        return constant($name);
    }

    private function get_options(): array
    {
        if (null !== $this->options) {
            return $this->options;
        }

        $options = get_option(self::OPTION_NAME, []);
        $this->options = is_array($options) ? $options : [];

        return $this->options;
    }

    private function to_bool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if (1 === $value) {
                return true;
            }
            if (0 === $value) {
                return false;
            }
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }
}
