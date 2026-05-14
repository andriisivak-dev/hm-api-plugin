<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class CustomerBrevoSyncMetaRepository
{
    public const META_SYNC_STATUS = '_brevo_sync_status';
    public const META_LAST_ATTEMPT_AT = '_brevo_sync_last_attempt_at';
    public const META_LAST_SUCCESS_AT = '_brevo_sync_last_success_at';
    public const META_LAST_ERROR = '_brevo_sync_last_error';
    public const META_CONTACT_ID = '_brevo_contact_id';
    public const META_LAST_PAYLOAD_HASH = '_brevo_sync_last_payload_hash';

    /** @var array<string,string> */
    private const META_TO_COLUMN = [
        self::META_SYNC_STATUS => 'brevo_sync_status',
        self::META_LAST_ATTEMPT_AT => 'brevo_sync_last_attempt_at',
        self::META_LAST_SUCCESS_AT => 'brevo_sync_last_success_at',
        self::META_LAST_ERROR => 'brevo_sync_last_error',
        self::META_CONTACT_ID => 'brevo_contact_id',
        self::META_LAST_PAYLOAD_HASH => 'brevo_sync_last_payload_hash',
    ];

    private const SAFE_ERROR_MAX_LENGTH = 255;

    /**
     * @return array<string,mixed>
     */
    public function get_all_meta(int $customer_id): array
    {
        $defaults = $this->get_default_meta();
        if ($customer_id <= 0) {
            return $defaults;
        }

        $customer = CustomerRepository::getById($customer_id);
        if (!$customer) {
            return $defaults;
        }

        return [
            self::META_SYNC_STATUS => $this->normalize_status((string) ($customer->brevo_sync_status ?? '')),
            self::META_LAST_ATTEMPT_AT => $this->normalize_datetime_for_read($customer->brevo_sync_last_attempt_at ?? null),
            self::META_LAST_SUCCESS_AT => $this->normalize_datetime_for_read($customer->brevo_sync_last_success_at ?? null),
            self::META_LAST_ERROR => $this->sanitize_error_message((string) ($customer->brevo_sync_last_error ?? '')),
            self::META_CONTACT_ID => $this->sanitize_contact_id((string) ($customer->brevo_contact_id ?? '')),
            self::META_LAST_PAYLOAD_HASH => $this->sanitize_payload_hash((string) ($customer->brevo_sync_last_payload_hash ?? '')),
        ];
    }

    /**
     * @return string|null
     */
    public function get_meta(int $customer_id, string $meta_key)
    {
        $all = $this->get_all_meta($customer_id);
        return $all[$meta_key] ?? null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function update_meta_batch(int $customer_id, array $meta): bool
    {
        if ($customer_id <= 0) {
            return false;
        }

        if (!CustomerRepository::getById($customer_id)) {
            return false;
        }

        $update_data = [];
        $formats = [];

        foreach ($meta as $meta_key => $value) {
            if (!isset(self::META_TO_COLUMN[$meta_key])) {
                continue;
            }

            $column = self::META_TO_COLUMN[$meta_key];
            $normalized_value = $this->normalize_value_for_write($meta_key, $value);

            $update_data[$column] = $normalized_value;
            $formats[] = '%s';
        }

        if ($update_data === []) {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->update(
            CustomerRepository::table(),
            $update_data,
            ['id' => $customer_id],
            $formats,
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * @param mixed $value
     */
    public function update_meta(int $customer_id, string $meta_key, $value): bool
    {
        return $this->update_meta_batch($customer_id, [$meta_key => $value]);
    }

    public function mark_sync_attempt(int $customer_id, string $payload_hash = ''): bool
    {
        $meta = [
            self::META_SYNC_STATUS => 'pending',
            self::META_LAST_ATTEMPT_AT => gmdate('c'),
        ];

        if ($payload_hash !== '') {
            $meta[self::META_LAST_PAYLOAD_HASH] = $payload_hash;
        }

        return $this->update_meta_batch($customer_id, $meta);
    }

    public function mark_sync_success(int $customer_id, ?string $contact_id = null, string $payload_hash = ''): bool
    {
        $meta = [
            self::META_SYNC_STATUS => 'success',
            self::META_LAST_ATTEMPT_AT => gmdate('c'),
            self::META_LAST_SUCCESS_AT => gmdate('c'),
            self::META_LAST_ERROR => null,
        ];

        if ($contact_id !== null) {
            $meta[self::META_CONTACT_ID] = $contact_id;
        }

        if ($payload_hash !== '') {
            $meta[self::META_LAST_PAYLOAD_HASH] = $payload_hash;
        }

        return $this->update_meta_batch($customer_id, $meta);
    }

    public function mark_sync_failure(int $customer_id, string $error_message, string $payload_hash = ''): bool
    {
        $meta = [
            self::META_SYNC_STATUS => 'failed',
            self::META_LAST_ATTEMPT_AT => gmdate('c'),
            self::META_LAST_ERROR => $error_message,
        ];

        if ($payload_hash !== '') {
            $meta[self::META_LAST_PAYLOAD_HASH] = $payload_hash;
        }

        return $this->update_meta_batch($customer_id, $meta);
    }

    public function clear_failed_sync_log(): int
    {
        global $wpdb;

        $table = CustomerRepository::table();
        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET brevo_sync_status = NULL,
                 brevo_sync_last_error = NULL
             WHERE brevo_sync_status = %s",
            'failed'
        );

        $updated = $wpdb->query($sql);
        if ($updated === false) {
            return 0;
        }

        return max(0, (int) $updated);
    }

    /**
     * @return array<string,string|null>
     */
    private function get_default_meta(): array
    {
        return [
            self::META_SYNC_STATUS => null,
            self::META_LAST_ATTEMPT_AT => null,
            self::META_LAST_SUCCESS_AT => null,
            self::META_LAST_ERROR => null,
            self::META_CONTACT_ID => null,
            self::META_LAST_PAYLOAD_HASH => null,
        ];
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function normalize_value_for_write(string $meta_key, $value)
    {
        if ($value === null) {
            return null;
        }

        switch ($meta_key) {
            case self::META_SYNC_STATUS:
                return $this->normalize_status((string) $value);
            case self::META_LAST_ATTEMPT_AT:
            case self::META_LAST_SUCCESS_AT:
                return $this->normalize_datetime_for_write((string) $value);
            case self::META_LAST_ERROR:
                return $this->sanitize_error_message((string) $value);
            case self::META_CONTACT_ID:
                return $this->sanitize_contact_id((string) $value);
            case self::META_LAST_PAYLOAD_HASH:
                return $this->sanitize_payload_hash((string) $value);
            default:
                return null;
        }
    }

    /**
     * @return string|null
     */
    private function normalize_status(string $status)
    {
        $status = sanitize_key($status);
        if ($status === '') {
            return null;
        }

        return substr($status, 0, 30);
    }

    /**
     * @return string|null
     */
    private function normalize_datetime_for_write(string $datetime)
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return null;
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $datetime
     * @return string|null
     */
    private function normalize_datetime_for_read($datetime)
    {
        if (!is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    /**
     * @return string|null
     */
    private function sanitize_contact_id(string $contact_id)
    {
        $contact_id = trim(sanitize_text_field($contact_id));
        if ($contact_id === '') {
            return null;
        }

        return substr($contact_id, 0, 100);
    }

    /**
     * @return string|null
     */
    private function sanitize_payload_hash(string $hash)
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        $hash = preg_replace('/[^a-f0-9]/', '', $hash);
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        return substr($hash, 0, 64);
    }

    /**
     * @return string|null
     */
    private function sanitize_error_message(string $message)
    {
        $message = wp_strip_all_tags($message);
        $message = sanitize_text_field($message);
        $message = $this->mask_pii_from_error($message);
        $message = trim($message);

        if ($message === '') {
            return null;
        }

        return substr($message, 0, self::SAFE_ERROR_MAX_LENGTH);
    }

    private function mask_pii_from_error(string $message): string
    {
        $masked = preg_replace(
            '/([A-Z0-9._%+\-])[A-Z0-9._%+\-]*@([A-Z0-9.\-]+\.[A-Z]{2,})/i',
            '$1***@$2',
            $message
        );

        if (!is_string($masked)) {
            return '';
        }

        $masked = preg_replace_callback('/\+?[0-9][0-9\-\s().]{5,}[0-9]/', static function (array $matches): string {
            $phone = preg_replace('/\s+/', '', $matches[0]);
            if (!is_string($phone)) {
                return '[phone_masked]';
            }

            $length = strlen($phone);
            if ($length <= 7) {
                return substr($phone, 0, 1) . '***';
            }

            return substr($phone, 0, 4) . str_repeat('*', max(3, $length - 7)) . substr($phone, -3);
        }, $masked);

        if (!is_string($masked)) {
            return '';
        }

        return $masked;
    }
}
