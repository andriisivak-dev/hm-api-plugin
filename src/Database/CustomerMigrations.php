<?php

declare(strict_types=1);

namespace CSP\Database;

/**
 * Manages the wp_csp_clients database table schema.
 *
 * This is a port of CSP_Clients_Install from hemant-core plugin.
 * The table is NEVER dropped on plugin deactivation or deletion
 * to preserve customer data.
 *
 * @package CSP\Database
 */
class CustomerMigrations
{
    private const DB_VERSION_OPTION = 'csp_clients_db_version';
    private const DB_VERSION        = '6';

    /**
     * Run on plugin activation.
     */
    public function up(): void
    {
        $this->ensureSchema();
    }

    /**
     * Run on every plugins_loaded to catch upgrades without re-activation.
     */
    public function maybeUpgrade(): void
    {
        $current = (string) get_option(self::DB_VERSION_OPTION, '1');
        if (version_compare($current, self::DB_VERSION, '>=')) {
            return;
        }

        $this->ensureSchema();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function ensureSchema(): void
    {
        $this->createOrUpdateTable();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    private function createOrUpdateTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName      = $wpdb->prefix . 'csp_clients';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id VARCHAR(50) NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            address TEXT NULL,
            city VARCHAR(120) NULL,
            state VARCHAR(120) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(191) NULL,
            customer_segment VARCHAR(100) NULL,
            billing_center VARCHAR(100) NULL,
            logo_id BIGINT UNSIGNED NULL,
            brevo_sync_status VARCHAR(30) NULL,
            brevo_sync_last_attempt_at DATETIME NULL,
            brevo_sync_last_success_at DATETIME NULL,
            brevo_sync_last_error VARCHAR(255) NULL,
            brevo_contact_id VARCHAR(100) NULL,
            brevo_sync_last_payload_hash CHAR(64) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_company (company_name),
            KEY email (email),
            KEY brevo_sync_status (brevo_sync_status),
            KEY brevo_contact_id (brevo_contact_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
}
