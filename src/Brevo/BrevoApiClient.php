<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoApiClient
{
    /** @var int[] */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /** @var string[] */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

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

    public function put(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PUT', $path, $body, $query);
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
                'method' => $method,
                'endpoint' => $this->normalize_endpoint($path),
                'success' => false,
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
                    'retry_count' => max(0, $attempt - 1),
                    'retryable' => $is_retryable,
                    'duration_ms' => $duration_ms,
                    'wp_error_code' => $response->get_error_code(),
                    'wp_error_message' => $response->get_error_message(),
                    'success' => false,
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
                    'response_code' => $status_code,
                    'attempt' => $attempt,
                    'retry_count' => max(0, $attempt - 1),
                    'duration_ms' => $duration_ms,
                    'success' => true,
                ]);

                return [
                    'status_code' => $status_code,
                    'body' => $parsed_body,
                    'headers' => $headers,
                ];
            }

            $is_retryable = $this->is_retryable_status($status_code);
            $error_summary = $this->extract_error_summary($parsed_body);
            $log_context = [
                'endpoint' => $endpoint,
                'method' => $method,
                'response_code' => $status_code,
                'attempt' => $attempt,
                'max_attempts' => $max_attempts,
                'retry_count' => max(0, $attempt - 1),
                'retryable' => $is_retryable,
                'duration_ms' => $duration_ms,
                'response_body' => $parsed_body,
                'brevo_error_code' => $error_summary['code'],
                'brevo_error_message' => $error_summary['message'],
                'brevo_error_details' => $error_summary['details'],
                'success' => false,
            ];

            if ($is_retryable && $attempt < $max_attempts) {
                $this->logger->warning('brevo_api_request_retry', $log_context);
                $this->sleep_before_retry($attempt);
                continue;
            }

            $this->logger->error('brevo_api_request_failed', $log_context);

            $exception_message = sprintf('Brevo API request failed with status code %d.', $status_code);
            if ($error_summary['message'] !== '') {
                $exception_message .= ' ' . $error_summary['message'];
            }

            throw new BrevoApiException(
                $exception_message,
                $status_code,
                $is_retryable,
                'http_error',
                [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'brevo_error_code' => $error_summary['code'],
                    'brevo_error_message' => $error_summary['message'],
                    'brevo_error_details' => $error_summary['details'],
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
     * @param mixed $parsed_body
     * @return array{code:string,message:string,details:string}
     */
    private function extract_error_summary($parsed_body): array
    {
        $summary = [
            'code' => '',
            'message' => '',
            'details' => '',
        ];

        if (is_string($parsed_body)) {
            $summary['message'] = $this->sanitize_error_text($parsed_body);
            return $summary;
        }

        if (!is_array($parsed_body)) {
            return $summary;
        }

        $summary['code'] = $this->sanitize_error_text(
            $this->read_first_error_field($parsed_body, ['code', 'errorCode', 'error_code'])
        );

        $summary['message'] = $this->sanitize_error_text(
            $this->read_first_error_field($parsed_body, ['message', 'msg', 'description', 'error_description'])
        );

        if ($summary['message'] === '' && isset($parsed_body['error'])) {
            $summary['message'] = $this->sanitize_error_text($this->stringify_error_value($parsed_body['error']));
        }

        if (isset($parsed_body['details'])) {
            $summary['details'] = $this->sanitize_error_text($this->stringify_error_value($parsed_body['details']));
        } elseif (isset($parsed_body['errors'])) {
            $summary['details'] = $this->sanitize_error_text($this->stringify_error_value($parsed_body['errors']));
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $body
     * @param string[] $keys
     */
    private function read_first_error_field(array $body, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            $value = $body[$key];
            if (is_string($value) || is_numeric($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function stringify_error_value($value, int $depth = 0): string
    {
        if ($depth >= 3) {
            return '';
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $fragments = [];
            foreach ($value as $item) {
                $fragment = $this->stringify_error_value($item, $depth + 1);
                if ($fragment === '') {
                    continue;
                }
                $fragments[] = $fragment;
                if (count($fragments) >= 5) {
                    break;
                }
            }

            return implode(' | ', $fragments);
        }

        return '';
    }

    private function sanitize_error_text(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = wp_strip_all_tags($text);
        $text = sanitize_text_field($text);

        return substr($text, 0, 300);
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
