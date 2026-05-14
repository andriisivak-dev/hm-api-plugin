<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoLogger
{
    private BrevoSettings $settings;
    private BrevoLogSanitizer $sanitizer;
    private string $log_file_path;

    public function __construct(?BrevoSettings $settings = null, ?BrevoLogSanitizer $sanitizer = null)
    {
        $this->settings = $settings ?? new BrevoSettings();
        $this->sanitizer = $sanitizer ?? new BrevoLogSanitizer();
        $this->log_file_path = $this->resolve_log_file_path();
    }

    /**
     * Example:
     * $logger->info('brevo_sync_completed', ['customer_id' => 123, 'email' => 'john@example.com']);
     */
    public function info(string $event, array $context = []): void
    {
        $this->log('info', $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->log('warning', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->log('error', $event, $context);
    }

    public function debug(string $event, array $context = []): void
    {
        if (!$this->settings->is_debug_logging_enabled()) {
            return;
        }

        $this->log('debug', $event, $context);
    }

    private function log(string $level, string $event, array $context): void
    {
        $safe_context = $this->normalize_context_for_log($this->sanitizer->sanitize_context($context));
        $event = sanitize_key($event);
        if ($event === '') {
            $event = 'brevo_unknown_event';
        }

        $entry = array_merge([
            'timestamp' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'channel' => 'brevo',
        ], $safe_context);

        $encoded = wp_json_encode($entry, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            $encoded = '{"timestamp":"' . gmdate('c') . '","level":"error","event":"brevo_log_encode_failed","channel":"brevo"}';
        }

        $this->write_log_line($encoded);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalize_context_for_log(array $context): array
    {
        $normalized = $context;
        unset(
            $normalized['timestamp'],
            $normalized['level'],
            $normalized['event'],
            $normalized['channel']
        );

        if (isset($normalized['email'])) {
            $normalized['email_masked'] = (string) $normalized['email'];
            unset($normalized['email']);
        }

        if (isset($normalized['phone'])) {
            $normalized['phone_masked'] = (string) $normalized['phone'];
            unset($normalized['phone']);
        }

        if (isset($normalized['sms'])) {
            $normalized['sms_masked'] = (string) $normalized['sms'];
            unset($normalized['sms']);
        }

        if (isset($normalized['address'])) {
            $normalized['address_present'] = true;
            unset($normalized['address']);
        }

        if (isset($normalized['linkedin'])) {
            $normalized['linkedin_masked'] = 'linkedin.com';
            unset($normalized['linkedin']);
        }

        return $normalized;
    }

    private function resolve_log_file_path(): string
    {
        $base_dir = defined('WP_CONTENT_DIR')
            ? (string) WP_CONTENT_DIR
            : untrailingslashit(ABSPATH) . '/wp-content';

        return trailingslashit($base_dir) . 'brevo-sync.log';
    }

    private function write_log_line(string $line): void
    {
        $written = @file_put_contents($this->log_file_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('[csp_brevo] ' . $line);
        }
    }
}
