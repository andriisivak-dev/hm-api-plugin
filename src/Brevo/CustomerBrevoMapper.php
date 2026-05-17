<?php

declare(strict_types=1);

namespace CSP\Brevo;

class CustomerBrevoMapper
{
    private const ATTR_LANDLINE_NUMBER = 'LANDLINE_NUMBER';
    private const ATTR_CONTACT_TIMEZONE = 'CONTACT_TIMEZONE';
    private const ATTR_COMPANY_NAME = 'COMPANY_NAME';
    private const ATTR_PHONE = 'PHONE';
    private const ATTR_ADDRESS = 'ADDRESS';
    private const ATTR_CITY = 'CITY';
    private const ATTR_STATE = 'STATE';
    private const ATTR_CUSTOMER_SEGMENT = 'CUSTOMER_SEGMENT';
    private const ATTR_BILLING_CENTER = 'BILLING_CENTER';
    private const ATTR_WP_STATUS = 'WP_STATUS';
    private const ATTR_WP_LAST_SYNC_AT = 'WP_LAST_SYNC_AT';
    private const ATTR_SYNC_SOURCE = 'SYNC_SOURCE';

    private BrevoSettings $settings;

    public function __construct(?BrevoSettings $settings = null)
    {
        $this->settings = $settings ?? new BrevoSettings();
    }

    /**
     * @param array<string,mixed>|object $customer
     * @return array<string,mixed>
     */
    public function map_upsert_payload($customer, string $sync_source = 'wordpress'): array
    {
        return $this->map_payload($customer, 'active', $sync_source);
    }

    /**
     * @param array<string,mixed>|object $customer
     * @return array<string,mixed>
     */
    public function map_soft_delete_payload($customer, string $sync_source = 'wordpress'): array
    {
        return $this->map_payload($customer, 'deleted', $sync_source);
    }

    /**
     * @param array<string,mixed>|object $customer
     * @return array<string,mixed>
     */
    private function map_payload($customer, string $status, string $sync_source): array
    {
        $ext_id = $this->normalize_ext_id($this->read_string($customer, ['ext_id', 'external_id', 'wp_customer_id', 'customer_id', 'id']));
        if ($ext_id === '') {
            throw new \InvalidArgumentException('Customer EXT_ID is required for Brevo sync payload.');
        }

        $email = $this->normalize_email($this->read_string($customer, ['email']));

        $attributes = $this->build_attributes($customer, $status, $sync_source);

        $payload = [
            'ext_id' => $ext_id,
            'attributes' => $attributes,
            'updateEnabled' => true,
            'forceMerge' => true,
            'getId' => true,
        ];

        if ($email !== '') {
            $payload['email'] = $email;
        }

        $list_ids = $this->resolve_list_ids($status);
        if ($list_ids !== []) {
            $payload['listIds'] = $list_ids;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed>|object $customer
     * @return array<string,mixed>
     */
    private function build_attributes($customer, string $status, string $sync_source): array
    {
        $raw_phone = $this->normalize_phone($this->read_string($customer, ['phone']));

        $attributes = [
            self::ATTR_LANDLINE_NUMBER => $this->normalize_phone(
                $this->read_string($customer, ['landline_number', 'landline'])
            ),
            self::ATTR_CONTACT_TIMEZONE => $this->normalize_text_value(
                $this->read_string($customer, ['contact_timezone', 'timezone'])
            ),
            self::ATTR_COMPANY_NAME => $this->normalize_text_value(
                $this->read_string($customer, ['company_name', 'company'])
            ),
            self::ATTR_ADDRESS => $this->normalize_text_value($this->read_string($customer, ['address'])),
            self::ATTR_CITY => $this->normalize_text_value($this->read_string($customer, ['city'])),
            self::ATTR_STATE => $this->normalize_text_value($this->read_string($customer, ['state'])),
            self::ATTR_CUSTOMER_SEGMENT => $this->normalize_text_value(
                $this->read_string($customer, ['segment', 'customer_segment'])
            ),
            self::ATTR_BILLING_CENTER => $this->normalize_text_value($this->read_string($customer, ['billing_center'])),
            self::ATTR_WP_STATUS => $status,
            self::ATTR_WP_LAST_SYNC_AT => gmdate('c'),
            self::ATTR_SYNC_SOURCE => sanitize_key($sync_source),
        ];

        if ($raw_phone !== '') {
            $attributes[self::ATTR_PHONE] = $raw_phone;
        }

        return $this->filter_empty_attributes($attributes);
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function filter_empty_attributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed>|object $customer
     * @param string[] $keys
     */
    private function read_string($customer, array $keys): string
    {
        foreach ($keys as $key) {
            $value = null;

            if (is_array($customer) && array_key_exists($key, $customer)) {
                $value = $customer[$key];
            } elseif (is_object($customer) && isset($customer->{$key})) {
                $value = $customer->{$key};
            }

            if ($value === null) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function normalize_email(string $email): string
    {
        $email = strtolower($this->normalize_text_value($email));
        if ($email === '') {
            return '';
        }

        $sanitized = sanitize_email($email);
        if (!is_email($sanitized)) {
            return '';
        }

        return $sanitized;
    }

    private function normalize_ext_id(string $ext_id): string
    {
        return $this->normalize_text_value($ext_id);
    }

    private function normalize_phone(string $phone): string
    {
        $phone = $this->normalize_text_value($phone);
        if ($phone === '') {
            return '';
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '+')) {
            $digits = (string) preg_replace('/\D+/', '', substr($phone, 1));
            $normalized = $digits !== '' ? '+' . $digits : '';
            return $this->is_valid_brevo_phone($normalized) ? $normalized : '';
        }

        $normalized = (string) preg_replace('/\D+/', '', $phone);
        return $this->is_valid_brevo_phone($normalized) ? $normalized : '';
    }

    private function normalize_text_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(sanitize_text_field($decoded));
    }

    private function is_valid_brevo_phone(string $phone): bool
    {
        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }

        $has_plus_prefix = str_starts_with($phone, '+');
        $digits = $has_plus_prefix ? substr($phone, 1) : $phone;

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $length = strlen($digits);
        if ($length < 8 || $length > 15) {
            return false;
        }

        if (!$has_plus_prefix && str_starts_with($digits, '0')) {
            return false;
        }

        return true;
    }

    /**
     * @return int[]
     */
    private function resolve_list_ids(string $status): array
    {
        $list_id = 0;

        if ($status === 'deleted') {
            $list_id = $this->settings->get_deleted_customers_list_id();
        } else {
            $list_id = $this->settings->get_customers_list_id();
        }

        if ($list_id <= 0) {
            return [];
        }

        return [$list_id];
    }
}
