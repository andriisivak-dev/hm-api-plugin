<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class BrevoSyncDashboardService
{
    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
     */
    public function get_failed_contacts_page(int $page = 1, int $per_page = 50): array
    {
        global $wpdb;

        $page = max(1, $page);
        $per_page = max(1, min(200, $per_page));
        $offset = ($page - 1) * $per_page;
        $table = CustomerRepository::table();

        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE brevo_sync_status = %s",
            'failed'
        );
        $total = max(0, (int) $wpdb->get_var($count_sql));

        $items = [];
        if ($total > 0) {
            $items_sql = $wpdb->prepare(
                "SELECT id, company_name, email, brevo_sync_last_error, brevo_sync_last_attempt_at
                 FROM {$table}
                 WHERE brevo_sync_status = %s
                 ORDER BY brevo_sync_last_attempt_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                'failed',
                $per_page,
                $offset
            );

            $rows = (array) $wpdb->get_results($items_sql, ARRAY_A);
            foreach ($rows as $row) {
                $attempt_at = isset($row['brevo_sync_last_attempt_at']) ? (string) $row['brevo_sync_last_attempt_at'] : '';
                $attempt_timestamp = strtotime($attempt_at);
                $attempt_iso = $attempt_timestamp !== false ? gmdate('c', $attempt_timestamp) : '';

                $items[] = [
                    'id' => isset($row['id']) ? (int) $row['id'] : 0,
                    'company_name' => isset($row['company_name']) ? (string) $row['company_name'] : '',
                    'email' => isset($row['email']) ? (string) $row['email'] : '',
                    'last_error' => isset($row['brevo_sync_last_error']) ? (string) $row['brevo_sync_last_error'] : '',
                    'last_attempt_at' => $attempt_iso,
                ];
            }
        }

        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => max(1, $total_pages),
        ];
    }

    /**
     * @return array<string,int>
     */
    public function get_summary(): array
    {
        global $wpdb;

        $table = CustomerRepository::table();
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_customers,
                SUM(CASE WHEN brevo_sync_status = 'success' THEN 1 ELSE 0 END) AS synced_success,
                SUM(CASE WHEN brevo_sync_status = 'failed' THEN 1 ELSE 0 END) AS synced_failed,
                SUM(CASE WHEN brevo_sync_status = 'pending' THEN 1 ELSE 0 END) AS pending_or_scheduled,
                SUM(CASE WHEN brevo_sync_last_attempt_at IS NULL THEN 1 ELSE 0 END) AS never_synced,
                SUM(CASE WHEN brevo_sync_status = 'deleted' THEN 1 ELSE 0 END) AS soft_deleted
            FROM {$table}",
            ARRAY_A
        );

        if (!is_array($row)) {
            return $this->get_default_summary();
        }

        return [
            'total_customers' => max(0, (int) ($row['total_customers'] ?? 0)),
            'synced_success' => max(0, (int) ($row['synced_success'] ?? 0)),
            'synced_failed' => max(0, (int) ($row['synced_failed'] ?? 0)),
            'pending_or_scheduled' => max(0, (int) ($row['pending_or_scheduled'] ?? 0)),
            'never_synced' => max(0, (int) ($row['never_synced'] ?? 0)),
            'soft_deleted' => max(0, (int) ($row['soft_deleted'] ?? 0)),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function get_default_summary(): array
    {
        return [
            'total_customers' => 0,
            'synced_success' => 0,
            'synced_failed' => 0,
            'pending_or_scheduled' => 0,
            'never_synced' => 0,
            'soft_deleted' => 0,
        ];
    }
}
