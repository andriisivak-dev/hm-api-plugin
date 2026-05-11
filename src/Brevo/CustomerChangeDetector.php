<?php

declare(strict_types=1);

namespace CSP\Brevo;

class CustomerChangeDetector
{
    private CustomerBrevoSyncMetaRepository $sync_meta_repository;

    public function __construct(?CustomerBrevoSyncMetaRepository $sync_meta_repository = null)
    {
        $this->sync_meta_repository = $sync_meta_repository ?? new CustomerBrevoSyncMetaRepository();
    }

    /**
     * @param array<string,mixed>|object $customer
     */
    public function build_sync_fingerprint($customer): string
    {
        $snapshot = $this->build_normalized_snapshot($customer);
        $encoded = wp_json_encode($snapshot);

        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<string,mixed>|object $customer
     */
    public function has_relevant_changes(int $customer_id, $customer): bool
    {
        $new_fingerprint = $this->build_sync_fingerprint($customer);
        if ($new_fingerprint === '') {
            return false;
        }

        $stored_fingerprint = $this->get_stored_fingerprint($customer_id);
        if ($stored_fingerprint === null) {
            return true;
        }

        return !hash_equals($stored_fingerprint, $new_fingerprint);
    }

    /**
     * @param array<string,mixed>|object $customer
     */
    public function store_fingerprint(int $customer_id, $customer): bool
    {
        $fingerprint = $this->build_sync_fingerprint($customer);
        if ($fingerprint === '') {
            return false;
        }

        return $this->sync_meta_repository->update_meta(
            $customer_id,
            CustomerBrevoSyncMetaRepository::META_LAST_PAYLOAD_HASH,
            $fingerprint
        );
    }

    public function get_stored_fingerprint(int $customer_id): ?string
    {
        $value = $this->sync_meta_repository->get_meta(
            $customer_id,
            CustomerBrevoSyncMetaRepository::META_LAST_PAYLOAD_HASH
        );

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed>|object $customer
     * @return array<string,string>
     */
    private function build_normalized_snapshot($customer): array
    {
        $snapshot = [
            'email' => $this->normalize_email($this->read_value($customer, ['email'])),
            'first_name' => $this->normalize_text($this->read_value($customer, ['first_name', 'firstname'])),
            'last_name' => $this->normalize_text($this->read_value($customer, ['last_name', 'lastname'])),
            'phone' => $this->normalize_phone($this->read_value($customer, ['phone'])),
            'sms_phone' => $this->normalize_phone($this->read_value($customer, ['mobile_phone', 'sms_phone', 'sms', 'phone_sms'])),
            'company_name' => $this->normalize_text($this->read_value($customer, ['company_name', 'company'])),
            'segment' => $this->normalize_text($this->read_value($customer, ['segment', 'customer_segment'])),
            'subsegment' => $this->normalize_text($this->read_value($customer, ['subsegment', 'customer_subsegment'])),
            'landline_number' => $this->normalize_phone($this->read_value($customer, ['landline_number', 'landline'])),
            'contact_timezone' => $this->normalize_text($this->read_value($customer, ['contact_timezone', 'timezone'])),
            'job_title' => $this->normalize_text($this->read_value($customer, ['job_title'])),
            'linkedin' => $this->normalize_linkedin($this->read_value($customer, ['linkedin', 'linkedin_url'])),
            'address' => $this->normalize_text($this->read_value($customer, ['address'])),
            'city' => $this->normalize_text($this->read_value($customer, ['city'])),
            'state' => $this->normalize_text($this->read_value($customer, ['state'])),
            'billing_center' => $this->normalize_text($this->read_value($customer, ['billing_center'])),
        ];

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string,mixed>|object $customer
     * @param string[] $keys
     */
    private function read_value($customer, array $keys): string
    {
        foreach ($keys as $key) {
            if (is_array($customer) && array_key_exists($key, $customer)) {
                return (string) $customer[$key];
            }

            if (is_object($customer) && isset($customer->{$key})) {
                return (string) $customer->{$key};
            }
        }

        return '';
    }

    private function normalize_text(string $value): string
    {
        return trim((string) sanitize_text_field($value));
    }

    private function normalize_email(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return '';
        }

        $email = sanitize_email($email);
        return is_email($email) ? $email : '';
    }

    private function normalize_phone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '+')) {
            $digits = (string) preg_replace('/\D+/', '', substr($phone, 1));
            return $digits !== '' ? '+' . $digits : '';
        }

        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function normalize_linkedin(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $url = esc_url_raw($value);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? trim((string) $parts['path']) : '';

        if ($host === '' && $path === '') {
            return '';
        }

        return trim($host . $path, '/');
    }
}
