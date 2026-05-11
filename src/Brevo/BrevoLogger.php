<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoLogger
{
    private BrevoSettings $settings;
    private BrevoLogSanitizer $sanitizer;

    public function __construct(?BrevoSettings $settings = null, ?BrevoLogSanitizer $sanitizer = null)
    {
        $this->settings = $settings ?? new BrevoSettings();
        $this->sanitizer = $sanitizer ?? new BrevoLogSanitizer();
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
        $safe_context = $this->sanitizer->sanitize_context($context);

        $payload = [
            'channel' => 'brevo',
            'level' => $level,
            'event' => $event,
            'time' => gmdate('c'),
            'context' => $safe_context,
        ];

        $encoded = wp_json_encode($payload);
        if (!is_string($encoded) || $encoded === '') {
            $encoded = '{"channel":"brevo","level":"error","event":"brevo_log_encode_failed"}';
        }

        error_log('[csp_brevo] ' . $encoded);
    }
}
