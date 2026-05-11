<?php

declare(strict_types=1);

namespace CSP\Brevo;

use CSP\Repositories\CustomerRepository;

class BrevoSyncDashboardService
{
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
