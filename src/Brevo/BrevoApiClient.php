<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoApiClient
{
    /** @var int[] */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /** @var string[] */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];

    private BrevoSettings $settings;
    private BrevoLogger $logger;

    public function __construct(?BrevoSettings $settings = null, ?BrevoLogger $logger = null)
    {
        $this->settings = $settings ?? new BrevoSettings();
        $this->logger = $logger ?? new BrevoLogger($this->settings);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [], $query);
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $body, $query);
    }

    public function patch(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    public function delete(string $path, array $body = [], array $query = []): array
    {
        return $this->request('DELETE', $path, $body, $query);
    }

    /**
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $method = strtoupper(trim($method));
        $this->assert_allowed_method($method);

        $api_key = $this->settings->get_api_key();
        if ($api_key === '') {
            $this->logger->error('brevo_api_key_missing', [
                'event' => 'brevo_api_request_failed',
                'method' => $method,
                'endpoint' => $this->normalize_endpoint($path),
            ]);

            throw new BrevoApiException(
                'Brevo API key is not configured.',
                0,
                false,
                'api_key_missing'
            );
        }

        $max_attempts = 1 + max(0, $this->settings->get_max_retries());
        $endpoint = $this->normalize_endpoint($path);
        $url = $this->build_url($path, $query);

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $started_at = microtime(true);

            $response = wp_remote_request($url, $this->build_args($method, $body, $api_key));
            $duration_ms = (int) round((microtime(true) - $started_at) * 1000);

            if (is_wp_error($response)) {
                $is_retryable = $this->is_retryable_wp_error($response);
                $log_context = [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'attempt' => $attempt,
                    'max_attempts' => $max_attempts,
                    'retryable' => $is_retryable,
                    'duration_ms' => $duration_ms,
                    'wp_error_code' => $response->get_error_code(),
                    'wp_error_message' => $response->get_error_message(),
                ];

                if ($is_retryable && $attempt < $max_attempts) {
                    $this->logger->warning('brevo_http_wp_error_retry', $log_context);
                    $this->sleep_before_retry($attempt);
                    continue;
                }

                $this->logger->error('brevo_http_wp_error_failed', $log_context);

                throw new BrevoApiException(
                    'Brevo API request failed due to a network error.',
                    0,
                    $is_retryable,
                    is_string($response->get_error_code()) ? $response->get_error_code() : null,
                    [
                        'endpoint' => $endpoint,
                        'method' => $method,
                    ]
                );
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);
            $parsed_body = $this->parse_body($raw_body);
            $headers = $this->normalize_headers(wp_remote_retrieve_headers($response));

            if ($status_code >= 200 && $status_code < 300) {
                $this->logger->info('brevo_api_request_success', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'duration_ms' => $duration_ms,
                ]);

                return [
                    'status_code' => $status_code,
                    'body' => $parsed_body,
                    'headers' => $headers,
                ];
            }

            $is_retryable = $this->is_retryable_status($status_code);
            $log_context = [
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code,
                'attempt' => $attempt,
                'max_attempts' => $max_attempts,
                'retryable' => $is_retryable,
                'duration_ms' => $duration_ms,
                'response_body' => $parsed_body,
            ];

            if ($is_retryable && $attempt < $max_attempts) {
                $this->logger->warning('brevo_api_request_retry', $log_context);
                $this->sleep_before_retry($attempt);
                continue;
            }

            $this->logger->error('brevo_api_request_failed', $log_context);

            throw new BrevoApiException(
                sprintf('Brevo API request failed with status code %d.', $status_code),
                $status_code,
                $is_retryable,
                'http_error',
                [
                    'endpoint' => $endpoint,
                    'method' => $method,
                ]
            );
        }

        throw new BrevoApiException(
            'Brevo API request failed after retries.',
            0,
            false,
            'retries_exhausted'
        );
    }

    private function assert_allowed_method(string $method): void
    {
        if (in_array($method, self::ALLOWED_METHODS, true)) {
            return;
        }

        throw new BrevoApiException(
            sprintf('HTTP method %s is not supported by BrevoApiClient.', $method),
            0,
            false,
            'unsupported_method'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function build_args(string $method, array $body, string $api_key): array
    {
        $args = [
            'method' => $method,
            'timeout' => $this->settings->get_timeout(),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'api-key' => $api_key,
            ],
        ];

        if ($method !== 'GET' && $body !== []) {
            $args['body'] = wp_json_encode($body);
        }

        return $args;
    }

    private function build_url(string $path, array $query): string
    {
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            $url = $path;
        } else {
            $url = trailingslashit($this->settings->get_api_base_url()) . ltrim($path, '/');
        }

        if ($query === []) {
            return $url;
        }

        return add_query_arg($query, $url);
    }

    private function normalize_endpoint(string $path): string
    {
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            $parts = wp_parse_url($path);
            $endpoint = isset($parts['path']) ? (string) $parts['path'] : '/';
            return $endpoint !== '' ? $endpoint : '/';
        }

        $endpoint = '/' . ltrim($path, '/');
        return $endpoint !== '' ? $endpoint : '/';
    }

    private function parse_body(string $raw_body)
    {
        if ($raw_body === '') {
            return [];
        }

        $decoded = json_decode($raw_body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw_body;
    }

    /**
     * @param mixed $headers
     * @return array<string,mixed>
     */
    private function normalize_headers($headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }

        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $all = $headers->getAll();
            return is_array($all) ? $all : [];
        }

        return [];
    }

    private function is_retryable_status(int $status_code): bool
    {
        return in_array($status_code, self::RETRYABLE_STATUS_CODES, true);
    }

    private function is_retryable_wp_error(\WP_Error $error): bool
    {
        $code = (string) $error->get_error_code();
        $message = strtolower((string) $error->get_error_message());

        if (str_contains($code, 'timeout') || str_contains($message, 'timed out')) {
            return true;
        }

        if (str_contains($message, 'could not resolve host')) {
            return true;
        }

        if (str_contains($message, 'connection refused')) {
            return true;
        }

        return false;
    }

    private function sleep_before_retry(int $attempt): void
    {
        $delay_ms = min(2000, 200 * (2 ** max(0, $attempt - 1)));
        usleep($delay_ms * 1000);
    }
}
