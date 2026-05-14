<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoLogSanitizer
{
    /** @var string[] */
    private const REDACTED_KEYS = [
        'api_key',
        'apikey',
        'x-api-key',
        'authorization',
        'auth_header',
        'headers',
        'raw_headers',
        'request_headers',
        'response_headers',
        'csp_brevo_settings',
        'settings_dump',
        'acf_settings',
        'db_dump',
        'database_dump',
    ];

    /** @var string[] */
    private const PAYLOAD_KEYS = [
        'payload',
        'request_payload',
        'response_payload',
        'body',
        'request_body',
        'response_body',
        'attributes',
        'jsonbody',
        'filebody',
    ];

    public function sanitize_context(array $context): array
    {
        return $this->sanitize_array($context);
    }

    private function sanitize_array(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalized_key = is_string($key) ? $key : (string) $key;
            $sanitized[$key] = $this->sanitize_value($normalized_key, $value);
        }

        if (count($sanitized) > 40) {
            $sanitized = array_slice($sanitized, 0, 40, true);
            $sanitized['_truncated'] = true;
        }

        return $sanitized;
    }

    private function sanitize_value(string $key, $value)
    {
        if ($this->is_redacted_key($key)) {
            return '[redacted]';
        }

        if ($this->is_payload_key($key)) {
            return '[payload_omitted]';
        }

        if (is_array($value)) {
            if ($this->is_address_key($key)) {
                return ['address_present' => true];
            }

            return $this->sanitize_array($value);
        }

        if (is_object($value)) {
            return '[object]';
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($this->is_email_key($key) || $this->contains_email($value)) {
            return $this->mask_email($value);
        }

        if ($this->is_phone_key($key) || $this->looks_like_phone($value)) {
            return $this->mask_phone($value);
        }

        if ($this->is_linkedin_key($key) || $this->contains_linkedin_url($value)) {
            return 'linkedin.com';
        }

        if ($this->is_address_key($key)) {
            return '[address_present]';
        }

        return $value;
    }

    private function is_redacted_key(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::REDACTED_KEYS, true)) {
            return true;
        }

        return str_contains($key, 'authorization')
            || str_contains($key, 'api_key')
            || str_contains($key, 'header')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'dump');
    }

    private function is_payload_key(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::PAYLOAD_KEYS, true)) {
            return true;
        }

        return str_contains($key, 'payload');
    }

    private function is_email_key(string $key): bool
    {
        return str_contains(strtolower($key), 'email');
    }

    private function is_phone_key(string $key): bool
    {
        $normalized = strtolower($key);
        return str_contains($normalized, 'phone') || str_contains($normalized, 'sms');
    }

    private function is_linkedin_key(string $key): bool
    {
        return str_contains(strtolower($key), 'linkedin');
    }

    private function is_address_key(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'address')
            || str_contains($normalized, 'street')
            || str_contains($normalized, 'zip');
    }

    private function contains_email(string $value): bool
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1;
    }

    private function contains_linkedin_url(string $value): bool
    {
        return str_contains(strtolower($value), 'linkedin.com');
    }

    private function looks_like_phone(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value);
        return is_string($digits) && strlen($digits) >= 7;
    }

    private function mask_email(string $value): string
    {
        $email = trim($value);
        if (!is_email($email)) {
            return '[email_masked]';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '[email_masked]';
        }

        $local = $parts[0];
        $domain = $parts[1];
        $first = $local !== '' ? $local[0] : '*';

        return $first . '***@' . $domain;
    }

    private function mask_phone(string $value): string
    {
        $phone = trim($value);
        if ($phone === '') {
            return '[phone_masked]';
        }

        $length = strlen($phone);
        if ($length <= 7) {
            return substr($phone, 0, 1) . '***';
        }

        $start = substr($phone, 0, 4);
        $end = substr($phone, -3);
        $masked_length = max(3, $length - 7);

        return $start . str_repeat('*', $masked_length) . $end;
    }
}
