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
    public function should_sync_on_update(int $customer_id, $customer): bool
    {
        if ($this->has_relevant_changes($customer_id, $customer)) {
            return true;
        }

        $status = $this->sync_meta_repository->get_meta(
            $customer_id,
            CustomerBrevoSyncMetaRepository::META_SYNC_STATUS
        );

        $status = (string) $status;

        if ($status === 'success' || $status === 'disabled') {
            return false;
        }

        return true;
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
            'ext_id' => $this->normalize_text($this->read_value($customer, ['ext_id', 'external_id', 'wp_customer_id', 'customer_id', 'id'])),
            'email' => $this->normalize_email($this->read_value($customer, ['email'])),
            'phone' => $this->normalize_phone($this->read_value($customer, ['phone'])),
            'company_name' => $this->normalize_text($this->read_value($customer, ['company_name', 'company'])),
            'segment' => $this->normalize_text($this->read_value($customer, ['segment', 'customer_segment'])),
            'landline_number' => $this->normalize_phone($this->read_value($customer, ['landline_number', 'landline'])),
            'contact_timezone' => $this->normalize_text($this->read_value($customer, ['contact_timezone', 'timezone'])),
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

}
