<?php

declare(strict_types=1);

namespace CSP\Brevo;

class BrevoContactService
{
    private BrevoApiClient $api_client;

    public function __construct(?BrevoApiClient $api_client = null)
    {
        $this->api_client = $api_client ?? new BrevoApiClient();
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
}
