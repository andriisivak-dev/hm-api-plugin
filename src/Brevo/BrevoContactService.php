<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoContactService
{
    private BrevoApiClient $api_client;
    private BrevoLogger $logger;

    public function __construct(?BrevoApiClient $api_client = null, ?BrevoLogger $logger = null)
    {
        $this->api_client = $api_client ?? new BrevoApiClient();
        $this->logger = $logger ?? new BrevoLogger();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function upsert_contact(array $payload): array
    {
        $payload['email'] = $this->extract_required_email($payload);
        $payload['updateEnabled'] = true;

        return $this->api_client->post('/contacts', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function mark_contact_deleted(
        array $payload,
        int $active_customers_list_id = 0,
        int $deleted_customers_list_id = 0
    ): array {
        $email = $this->extract_required_email($payload);
        $result = $this->upsert_contact($payload);

        if ($active_customers_list_id > 0) {
            $this->remove_from_list($active_customers_list_id, [$email]);
        }

        if ($deleted_customers_list_id > 0) {
            $this->add_to_list($deleted_customers_list_id, [$email]);
        }

        return $result;
    }

    /**
     * @param string[] $emails
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function remove_from_list(int $list_id, array $emails): array
    {
        $this->assert_valid_list_id($list_id);

        return $this->api_client->post(
            sprintf('/contacts/lists/%d/contacts/remove', $list_id),
            ['emails' => $this->normalize_emails($emails)]
        );
    }

    /**
     * @param string[] $emails
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function add_to_list(int $list_id, array $emails): array
    {
        $this->assert_valid_list_id($list_id);

        return $this->api_client->post(
            sprintf('/contacts/lists/%d/contacts/add', $list_id),
            ['emails' => $this->normalize_emails($emails)]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @return array{status_code:int,body:mixed,headers:array,process_id:int}
     */
    public function import_contacts(array $contacts, int $customers_list_id = 0): array
    {
        $json_contacts = $this->build_json_contacts($contacts);

        $json_payload = [
            'jsonBody' => $json_contacts,
            'updateExistingContacts' => true,
            'emptyContactsAttributes' => false,
        ];

        if ($customers_list_id > 0) {
            $json_payload['listIds'] = [$customers_list_id];
        }

        try {
            $response = $this->api_client->post('/contacts/import', $json_payload);
        } catch (BrevoApiException $exception) {
            if ($exception->get_status_code() !== 400) {
                throw $exception;
            }

            $fallback_payload = [
                'fileBody' => $this->build_csv_body($this->build_csv_rows_from_contacts($json_contacts)),
                'updateExistingContacts' => true,
                'emptyContactsAttributes' => false,
            ];

            if ($customers_list_id > 0) {
                $fallback_payload['listIds'] = [$customers_list_id];
            }

            $this->logger->warning('brevo_import_json_fallback_to_csv', [
                'endpoint' => '/contacts/import',
                'method' => 'POST',
                'response_code' => $exception->get_status_code(),
                'success' => false,
                'rows_count' => count($json_contacts),
            ]);

            $response = $this->api_client->post('/contacts/import', $fallback_payload);
        }

        return [
            'status_code' => (int) ($response['status_code'] ?? 0),
            'body' => $response['body'] ?? [],
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'process_id' => $this->extract_process_id($response),
        ];
    }

    /**
     * @return array{status_code:int,body:mixed,headers:array}
     */
    public function get_process(int $process_id): array
    {
        if ($process_id <= 0) {
            throw new \InvalidArgumentException('Valid Brevo process ID is required.');
        }

        return $this->api_client->get(sprintf('/processes/%d', $process_id));
    }

    /**
     * @return array{status:string,body:array<string,mixed>,terminal:bool,timed_out:bool,attempts:int,last_response_code:int}
     */
    public function wait_for_process(
        int $process_id,
        int $max_wait_seconds = 120,
        int $poll_interval_seconds = 3
    ): array {
        $max_wait_seconds = max(10, $max_wait_seconds);
        $poll_interval_seconds = max(1, $poll_interval_seconds);
        $started_at = microtime(true);
        $attempts = 0;
        $last_body = [];
        $last_status_code = 0;

        while ((microtime(true) - $started_at) <= $max_wait_seconds) {
            $attempts++;
            $response = $this->get_process($process_id);
            $last_status_code = (int) ($response['status_code'] ?? 0);
            $last_body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $status = $this->extract_process_status($last_body);

            if ($this->is_terminal_process_status($status)) {
                return [
                    'status' => $status,
                    'body' => $last_body,
                    'terminal' => true,
                    'timed_out' => false,
                    'attempts' => $attempts,
                    'last_response_code' => $last_status_code,
                ];
            }

            sleep($poll_interval_seconds);
        }

        return [
            'status' => $this->extract_process_status($last_body),
            'body' => $last_body,
            'terminal' => false,
            'timed_out' => true,
            'attempts' => $attempts,
            'last_response_code' => $last_status_code,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extract_required_email(array $payload): string
    {
        $email = isset($payload['email']) ? (string) $payload['email'] : '';
        $email = strtolower(trim($email));

        if ($email === '' || !is_email($email)) {
            throw new \InvalidArgumentException('Valid contact email is required.');
        }

        return $email;
    }

    private function assert_valid_list_id(int $list_id): void
    {
        if ($list_id > 0) {
            return;
        }

        throw new \InvalidArgumentException('Brevo list ID must be greater than zero.');
    }

    /**
     * @param string[] $emails
     * @return string[]
     */
    private function normalize_emails(array $emails): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            $candidate = strtolower(trim((string) $email));
            if ($candidate === '' || !is_email($candidate)) {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one valid email is required.');
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @return array<int,array<string,mixed>>
     */
    private function build_json_contacts(array $contacts): array
    {
        $json_contacts = [];

        foreach ($contacts as $contact) {
            $payload = is_array($contact) ? $contact : [];
            $email = $this->extract_required_email($payload);
            $attributes = isset($payload['attributes']) && is_array($payload['attributes'])
                ? $payload['attributes']
                : [];

            $normalized_attributes = [];
            foreach ($attributes as $key => $value) {
                $attribute_key = $this->normalize_attribute_key((string) $key);
                if ($attribute_key === '') {
                    continue;
                }

                $normalized_attributes[$attribute_key] = $this->normalize_json_attribute_value($value);
            }

            $contact_row = ['email' => $email];
            if ($normalized_attributes !== []) {
                $contact_row['attributes'] = $normalized_attributes;
            }

            $json_contacts[] = $contact_row;
        }

        if ($json_contacts === []) {
            throw new \InvalidArgumentException('At least one valid contact is required for import.');
        }

        return $json_contacts;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @return array<int,array<string,string>>
     */
    private function build_csv_rows_from_contacts(array $contacts): array
    {
        $rows = [];

        foreach ($contacts as $contact) {
            $email = isset($contact['email']) ? $this->extract_required_email((array) $contact) : '';
            if ($email === '') {
                continue;
            }

            $row = ['EMAIL' => $email];
            $attributes = isset($contact['attributes']) && is_array($contact['attributes'])
                ? $contact['attributes']
                : [];

            foreach ($attributes as $key => $value) {
                $attribute_key = $this->normalize_attribute_key((string) $key);
                if ($attribute_key === '') {
                    continue;
                }

                $row[$attribute_key] = $this->normalize_csv_value($value);
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new \InvalidArgumentException('At least one valid contact is required for import.');
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,string>> $rows
     */
    private function build_csv_body(array $rows): string
    {
        $headers = ['EMAIL'];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if ($column === 'EMAIL' || in_array($column, $headers, true)) {
                    continue;
                }
                $headers[] = $column;
            }
        }

        $lines = [implode(';', $headers)];
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? (string) $row[$header] : '';
                $value = str_replace('"', '""', $value);
                $values[] = '"' . $value . '"';
            }
            $lines[] = implode(';', $values);
        }

        return implode("\n", $lines);
    }

    private function normalize_attribute_key(string $key): string
    {
        $key = strtoupper(sanitize_key($key));
        if ($key === '' || $key === 'EMAIL') {
            return '';
        }

        return $key;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalize_json_attribute_value($value)
    {
        if (is_array($value)) {
            $encoded = wp_json_encode($value);
            return is_string($encoded) ? sanitize_text_field($encoded) : '';
        }

        if (is_object($value)) {
            return '[object]';
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param mixed $value
     */
    private function normalize_csv_value($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extract_process_id(array $response): int
    {
        $body = isset($response['body']) && is_array($response['body'])
            ? $response['body']
            : [];

        if (isset($body['processId'])) {
            return max(0, (int) $body['processId']);
        }

        if (isset($body['id'])) {
            return max(0, (int) $body['id']);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function extract_process_status(array $body): string
    {
        $status = isset($body['status']) ? sanitize_key((string) $body['status']) : '';
        if ($status !== '') {
            return $status;
        }

        if (isset($body['process']['status'])) {
            return sanitize_key((string) $body['process']['status']);
        }

        return '';
    }

    private function is_terminal_process_status(string $status): bool
    {
        return in_array($status, ['completed', 'done', 'success', 'failed', 'error', 'aborted', 'cancelled'], true);
    }
}
